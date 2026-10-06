<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

// Dependência explícita: aprovador/destinatários vêm de kanpro_config_*().
// Sem isso, o cron do GLPI (que autoloada só esta classe) cairia no fail
// closed e os lembretes parariam em silêncio.
require_once __DIR__ . '/acting.php';

/**
 * PluginKanproMaintenanceZap — WhatsApp automático da manutenção de TI (KanPro)
 *
 * Usa a MESMA Evolution API do plugin whatsappsimples (lê server_url/api_token/
 * instance_name de glpi_plugin_whatsappsimples_configs). Nada novo p/ instalar.
 *
 * Templates em plugins/kanpro/templates_whatsapp/*.txt (texto puro, *negrito*).
 * Linhas iniciadas com # são comentários e NÃO são enviadas.
 *
 * Gatilhos:
 *   entrada   setup_maintenance_machines (1ª configuração) — uma vez por card
 *   retirada  retirada_machine / finalize_maintenance — uma vez por card
 *   atraso    cron diário: card em Retirada recebe lembrete a cada 5 dias
 *   cancelado revert_maintenance
 *   pendencia request_chamado / pegar_pending_card — 1 msg por card novo em Pendência Chamado
 *             (destinatário: aprovador configurado em 'zap_approver')
 *   liberado  confirm_chamado_created + 5s — 1 msg por técnico membro da origem
 *             (avisa chamado criado + máquinas liberadas, com nº/nome do chamado)
 *
 * Anti-duplicado: tabela glpi_plugin_kanpro_maintenance_zaplog (milestone por card).
 * Falha de envio NUNCA quebra o fluxo principal (tudo em try/catch + log).
 */
class PluginKanproMaintenanceZap extends CommonDBTM {
    static $rightname = 'plugin_kanpro';

    static function getTypeName($nb = 0) {
        return 'WhatsApp da manutenção';
    }

    static function allowedTypes(): array {
        return ['entrada', 'retirada', 'atraso', 'cancelado', 'pendencia', 'liberado', 'tablet_liberado', 'lembrete', 'card_alerta', 'quadro_alerta', 'chamado_abrir'];
    }

    /** Detecta se o card origem é 100% Tablet/Smartphone/Celular (fluxo Tablet). */
    static function isTabletCard(int $cards_id): bool {
        global $DB;
        try {
            if ($cards_id <= 0) return false;
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) return false;
            $total = 0; $tab = 0;
            foreach ($DB->request(['SELECT'=>['model'],'FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cards_id]]) as $r) {
                $total++;
                $m = (string)($r['model'] ?? '');
                if (function_exists('mb_strtolower')) $m = mb_strtolower($m, 'UTF-8');
                else $m = strtolower($m);
                $m = strtr($m, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
                if (strpos($m, 'tablet') !== false || strpos($m, 'smartphone') !== false || strpos($m, 'smart phone') !== false || strpos($m, 'smartfone') !== false || strpos($m, 'celular') !== false) $tab++;
            }
            return ($total > 0 && $tab === $total);
        } catch (Throwable $e) { return false; }
    }

    /** Login/e-mail do aprovador da Pendência Chamado (config 'zap_approver'). */
    static function pendenciaApprover(): string {
        if (function_exists('kanpro_config_get')) {
            return trim(kanpro_config_get('zap_approver', ''));
        }
        return '';
    }

    /** Lê lista de destinatários do config (JSON array). Inválido/ausente = []. */
    static function configRecipients(string $key): array {
        if (!function_exists('kanpro_config_get')) {
            return [];
        }
        $raw = trim(kanpro_config_get($key, ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $v) {
            $v = trim((string)$v);
            if ($v !== '' && strpos($v, ' ') === false && strpos($v, "\n") === false) {
                $out[] = $v;
            }
        }
        return array_values(array_unique($out));
    }

    /** Destinatários do lembrete 8h/10h/13h (CARDS AGUARDANDO) — enviado p/ todos */
    static function lembreteRecipients(): array {
        return self::configRecipients('zap_reminder_recipients');
    }

    /** Milestone por destinatário (1º mantém base p/ compat com envios antigos) */
    static function lembreteMilestone(string $base, string $login): string {
        return self::recipientMilestone($base, $login);
    }

    /** Sufixa a base por destinatário (cabe em 30 chars; aprovador mantém a base). */
    static function recipientMilestone(string $base, string $login): string {
        $login = trim(strtolower((string)$login));
        if ($login === '' || $login === trim(strtolower(self::pendenciaApprover()))) return $base;
        // sufixo curto p/ caber no limite de 30 chars do zaplog (base tem 22)
        $short = preg_replace('/[^a-z0-9]/', '', explode('@', $login)[0] ?? '');
        $short = mb_substr((string)$short, 0, 7);
        if ($short === '') $short = 'extra';
        return mb_substr($base . '_' . $short, 0, 30);
    }

    /**
     * Lembrete só em dias úteis (seg-sex, sem feriado).
     * Feriados: nacionais fixos + móveis (Páscoa) + estadual SP (09/07)
     * + extras em templates_whatsapp/feriados.txt (um por linha: YYYY-MM-DD ou DD/MM).
     * Nunca joga exceção.
     */
    static function lembreteExtraHolidays(): array {
        $out = [];
        try {
            $dir = self::templateDir();
            if (!$dir) return $out;
            foreach (['feriados.txt', 'feriados_extras.txt'] as $fn) {
                $f = $dir . '/' . $fn;
                if (!is_file($f)) continue;
                $lines = @file($f, FILE_IGNORE_NEW_LINES);
                if ($lines === false) continue;
                foreach ($lines as $ln) {
                    $ln = trim((string)$ln);
                    if ($ln === '' || $ln[0] === '#') continue;
                    $ln = preg_replace('/\s+#.*$/', '', $ln);
                    $ln = trim($ln);
                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ln, $m)) {
                        $out[sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3])] = $fn;
                    } elseif (preg_match('/^(\d{2})\/(\d{2})(?:\/(\d{4}))?$/', $ln, $m)) {
                        $y = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : (int)date('Y');
                        $out[sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1])] = $fn;
                    }
                }
            }
        } catch (Throwable $e) {}
        return $out;
    }

    /** Domingo de Páscoa (algoritmo gregoriano, sem depender da extensão calendar) */
    static function easterSunday(int $year): ?string {
        try {
            if (function_exists('easter_date')) {
                return date('Y-m-d', easter_date($year));
            }
            $a = $year % 19; $b = (int)floor($year / 100); $c = $year % 100;
            $d = (int)floor($b / 4); $e = $b % 4; $f = (int)floor(($b + 8) / 25);
            $g = (int)floor(($b - $f + 1) / 3);
            $h = (19 * $a + $b - $d - $g + 15) % 30;
            $i = (int)floor($c / 4); $k = $c % 4;
            $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
            $m = (int)floor(($a + 11 * $h + 22 * $l) / 451);
            $month = (int)floor(($h + $l - 7 * $m + 114) / 31);
            $day = (($h + $l - 7 * $m + 114) % 31) + 1;
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        } catch (Throwable $e) { return null; }
    }

    /** Mapa YYYY-MM-DD => motivo do feriado p/ o ano (fixos + móveis + extras) */
    static function lembreteHolidayMap(?int $year = null): array {
        $year = $year ?: (int)date('Y');
        $map = [
            sprintf('%04d-01-01', $year) => 'Confraternização Universal',
            sprintf('%04d-04-15', $year) => 'Fundação de Jales / Santo Expedito (municipal)',
            sprintf('%04d-04-21', $year) => 'Tiradentes',
            sprintf('%04d-05-01', $year) => 'Dia do Trabalho',
            sprintf('%04d-07-09', $year) => 'Revolução Constitucionalista (SP)',
            sprintf('%04d-08-15', $year) => 'Assunção de N. Sra. (municipal Jales)',
            sprintf('%04d-09-07', $year) => 'Independência',
            sprintf('%04d-10-12', $year) => 'N. Sra. Aparecida',
            sprintf('%04d-11-02', $year) => 'Finados',
            sprintf('%04d-11-15', $year) => 'Proclamação da República',
            sprintf('%04d-11-20', $year) => 'Consciência Negra',
            sprintf('%04d-12-25', $year) => 'Natal',
        ];
        try {
            $easter = self::easterSunday($year);
            if ($easter !== null && $easter !== '') {
                $dt = new DateTime($easter);
                $carnSeg = (clone $dt)->modify('-48 days')->format('Y-m-d');
                $carnTer = (clone $dt)->modify('-47 days')->format('Y-m-d');
                $sexta = (clone $dt)->modify('-2 days')->format('Y-m-d');
                $corpus = (clone $dt)->modify('+60 days')->format('Y-m-d');
                $map[$carnSeg] = 'Carnaval (seg)';
                $map[$carnTer] = 'Carnaval (ter)';
                $map[$sexta] = 'Sexta-feira Santa';
                $map[$easter] = 'Páscoa';
                $map[$corpus] = 'Corpus Christi';
            }
        } catch (Throwable $e) {}
        try {
            foreach (self::lembreteExtraHolidays() as $d => $why) {
                if (substr($d, 0, 4) === sprintf('%04d', $year)) $map[$d] = 'Extra (' . $why . ')';
            }
        } catch (Throwable $e) {}
        return $map;
    }

    /** ['business'=>bool, 'reason'=>string, 'date'=>Y-m-d, 'dow'=>1-7] — nunca joga exceção */
    static function lembreteBusinessCheck(?int $ts = null): array {
        try {
            $ts = $ts ?: time();
            $ymd = date('Y-m-d', $ts);
            $dow = (int)date('N', $ts); // 1=seg ... 7=dom
            if ($dow >= 6) return ['business' => false, 'reason' => ($dow === 6 ? 'sábado' : 'domingo'), 'date' => $ymd, 'dow' => $dow];
            $map = self::lembreteHolidayMap((int)date('Y', $ts));
            if (isset($map[$ymd])) return ['business' => false, 'reason' => 'feriado: ' . $map[$ymd], 'date' => $ymd, 'dow' => $dow];
            return ['business' => true, 'reason' => '', 'date' => $ymd, 'dow' => $dow];
        } catch (Throwable $e) {
            return ['business' => true, 'reason' => '', 'date' => date('Y-m-d'), 'dow' => (int)date('N')];
        }
    }

    static function isLembreteBusinessDay(?int $ts = null): bool {
        return (bool)(self::lembreteBusinessCheck($ts)['business'] ?? true);
    }

    /** Tabela de exceções do calendário (tirar do envio / forçar). Cria se não existir. */
    static function ensureLembreteDaysTable(): void {
        global $DB;
        try {
            if ($DB->tableExists('glpi_plugin_kanpro_lembrete_days')) return;
            $sign = method_exists($DB, 'getSignedChar') ? '' : '';
            $charset = 'utf8mb4'; $collation = 'utf8mb4_unicode_ci';
            try {
                global $CFG_GLPI;
                if (!empty($CFG_GLPI['db_default_charset'])) $charset = $CFG_GLPI['db_default_charset'];
                if (!empty($CFG_GLPI['db_default_collation'])) $collation = $CFG_GLPI['db_default_collation'];
            } catch (Throwable $e) {}
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_kanpro_lembrete_days` (
                    `id`                INT NOT NULL AUTO_INCREMENT,
                    `date`              DATE NOT NULL COMMENT 'YYYY-MM-DD',
                    `mode`              VARCHAR(10) NOT NULL DEFAULT 'skip' COMMENT 'skip=tirar do envio, force=forcar envio',
                    `reason`            VARCHAR(255) DEFAULT NULL,
                    `users_id`          INT NOT NULL DEFAULT '0',
                    `date_creation`     DATETIME DEFAULT NULL,
                    `date_mod`          DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_date` (`date`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        } catch (Throwable $e) {}
    }

    /** mode do dia: '' (automático) | 'skip' (tirado) | 'force' (forçado). Nunca joga exceção. */
    static function lembreteDayOverride(string $ymd): string {
        global $DB;
        try {
            $ymd = trim($ymd);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return '';
            if (!$DB->tableExists('glpi_plugin_kanpro_lembrete_days')) return '';
            $row = $DB->request(['SELECT' => ['mode'], 'FROM' => 'glpi_plugin_kanpro_lembrete_days', 'WHERE' => ['date' => $ymd], 'LIMIT' => 1])->current();
            $m = trim(strtolower((string)($row['mode'] ?? '')));
            return ($m === 'skip' || $m === 'force') ? $m : '';
        } catch (Throwable $e) { return ''; }
    }

    /** Mapa YMD => mode p/ um intervalo (p/ o calendário do mês). */
    static function lembreteDayOverrides(string $from, string $to): array {
        global $DB;
        $out = [];
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_lembrete_days')) return $out;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) return $out;
            foreach ($DB->request(['SELECT' => ['date', 'mode'], 'FROM' => 'glpi_plugin_kanpro_lembrete_days', 'WHERE' => ['date' => ['>=', $from], 'AND' => ['date' => ['<=', $to]]]]) as $r) {
                // GLPI pode devolver DateTime ou string
                $d = $r['date'] ?? '';
                if ($d instanceof DateTimeInterface) $d = $d->format('Y-m-d');
                else $d = substr((string)$d, 0, 10);
                $m = trim(strtolower((string)($r['mode'] ?? '')));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && ($m === 'skip' || $m === 'force')) $out[$d] = $m;
            }
        } catch (Throwable $e) {}
        return $out;
    }

    /**
     * Define exceção do dia: 'skip' (tirar do envio), 'force' (enviar mesmo se fds/feriado),
     * ''/'auto' (voltar ao automático = apaga a linha). Retorna ['ok'=>bool].
     * Nunca joga exceção.
     */
    static function setLembreteDayOverride(string $ymd, string $mode, string $reason = ''): array {
        global $DB;
        try {
            $ymd = trim($ymd);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return ['ok' => false, 'error' => 'Data inválida (use YYYY-MM-DD)'];
            [$yy, $mm, $dd] = array_map('intval', explode('-', $ymd));
            if (!checkdate($mm, $dd, $yy)) return ['ok' => false, 'error' => 'Data inválida'];
            $mode = trim(strtolower($mode));
            if (in_array($mode, ['', 'auto', 'clear', 'none'], true)) $mode = '';
            if ($mode !== '' && $mode !== 'skip' && $mode !== 'force') return ['ok' => false, 'error' => "mode inválido (use skip, force ou auto)"];
            self::ensureLembreteDaysTable();
            if (!$DB->tableExists('glpi_plugin_kanpro_lembrete_days')) return ['ok' => false, 'error' => 'Tabela indisponível'];
            $uid = 0;
            try { $uid = (int)Session::getLoginUserID(); } catch (Throwable $e) {}
            $now = date('Y-m-d H:i:s');
            if ($mode === '') {
                try { $DB->delete('glpi_plugin_kanpro_lembrete_days', ['date' => $ymd]); } catch (Throwable $e) {}
                return ['ok' => true, 'date' => $ymd, 'mode' => ''];
            }
            $found = null;
            try {
                $found = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_kanpro_lembrete_days', 'WHERE' => ['date' => $ymd], 'LIMIT' => 1])->current();
            } catch (Throwable $e) {}
            if (!empty($found['id'])) {
                $DB->update('glpi_plugin_kanpro_lembrete_days', ['mode' => $mode, 'reason' => mb_substr($reason, 0, 255), 'users_id' => $uid, 'date_mod' => $now], ['id' => (int)$found['id']]);
            } else {
                $DB->insert('glpi_plugin_kanpro_lembrete_days', ['date' => $ymd, 'mode' => $mode, 'reason' => mb_substr($reason, 0, 255), 'users_id' => $uid, 'date_creation' => $now, 'date_mod' => $now]);
            }
            return ['ok' => true, 'date' => $ymd, 'mode' => $mode];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Aplica overrides em lote. $dates = [ymd...]. Retorna ['ok'=>bool,'applied'=>int]. */
    static function setLembreteDayOverrides(array $dates, string $mode, string $reason = ''): array {
        $applied = 0; $errors = [];
        $seen = [];
        foreach ($dates as $d) {
            $d = substr(trim((string)$d), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || isset($seen[$d])) continue;
            $seen[$d] = true;
            $r = self::setLembreteDayOverride($d, $mode, $reason);
            if (!empty($r['ok'])) $applied++;
            else $errors[] = $d . ': ' . ($r['error'] ?? 'falha');
        }
        return ['ok' => ($applied > 0), 'applied' => $applied, 'errors' => $errors];
    }

    /**
     * Calendário do lembrete p/ o modal (board.php): dias do mês com
     * business/reason + já enviado em cada turno (8/10/13).
     * Nunca joga exceção. $month = YYYY-MM (padrão: mês atual).
     */
    static function lembreteCalendar(?string $month = null): array {
        try {
            $month = trim((string)($month ?? ''));
            if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) $month = date('Y-m');
            $y = (int)$m[1]; $mo = (int)$m[2];
            if ($mo < 1 || $mo > 12) { $y = (int)date('Y'); $mo = (int)date('m'); $month = sprintf('%04d-%02d', $y, $mo); }
            if ($y < 2020 || $y > 2100) { $y = (int)date('Y'); $mo = (int)date('m'); $month = sprintf('%04d-%02d', $y, $mo); }
            $firstTs = strtotime(sprintf('%04d-%02d-01', $y, $mo));
            $daysInMonth = (int)date('t', $firstTs);
            $today = date('Y-m-d');
            $from = sprintf('%04d-%02d-01', $y, $mo);
            $to = sprintf('%04d-%02d-%02d', $y, $mo, (int)date('t', $firstTs));
            $overrides = self::lembreteDayOverrides($from, $to);
            $days = [];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $ymd = sprintf('%04d-%02d-%02d', $y, $mo, $d);
                $ts = strtotime($ymd);
                $biz = self::lembreteBusinessCheck($ts);
                $ov = $overrides[$ymd] ?? '';
                $willSend = ($ov === 'skip') ? false : (($ov === 'force') ? true : (bool)($biz['business'] ?? true));
                $slots = [];
                foreach ([8, 10, 13] as $s) {
                    $base = 'lembrete_' . $ymd . '_' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
                    $perRcp = [];
                    $sentAny = false; $sentAll = true;
                    try {
                        foreach (self::lembreteRecipients() as $rcp) {
                            $ms = self::lembreteMilestone($base, (string)$rcp);
                            $sent = self::alreadySent(0, $ms);
                            $perRcp[(string)$rcp] = $sent;
                            if ($sent) $sentAny = true; else $sentAll = false;
                        }
                    } catch (Throwable $e) { $sentAll = false; }
                    // compat: envio antigo gravava só a base (cristian) — conta como enviado
                    try { if (!$sentAny && self::alreadySent(0, $base)) { $sentAny = true; } } catch (Throwable $e) {}
                    if (empty($perRcp)) $sentAll = $sentAny;
                    $slots[(string)$s] = ['sent_any' => $sentAny, 'sent_all' => $sentAll, 'per_recipient' => $perRcp];
                }
                $days[] = [
                    'ymd' => $ymd, 'day' => $d, 'dow' => (int)date('N', $ts),
                    'business' => (bool)($biz['business'] ?? true),
                    'reason' => (string)($biz['reason'] ?? ''),
                    'override' => $ov,
                    'will_send' => $willSend,
                    'is_today' => ($ymd === $today),
                    'is_past' => ($ymd < $today),
                    'is_future' => ($ymd > $today),
                    'slots' => $slots,
                ];
            }
            $prev = date('Y-m', strtotime($month . '-01 -1 month'));
            $next = date('Y-m', strtotime($month . '-01 +1 month'));
            return [
                'month' => $month, 'year' => $y, 'mon' => $mo,
                'prev' => $prev, 'next' => $next, 'today' => $today,
                'recipients' => self::lembreteRecipients(),
                'days' => $days,
            ];
        } catch (Throwable $e) {
            return ['month' => date('Y-m'), 'days' => [], 'error' => $e->getMessage()];
        }
    }

    static function templateDir(): ?string {
        $base = method_exists('Plugin', 'getPhpDir') ? Plugin::getPhpDir('kanpro') : (defined('GLPI_ROOT') ? GLPI_ROOT . '/plugins/kanpro' : null);
        if (!$base) return null;
        $dir = $base . '/templates_whatsapp';
        return is_dir($dir) ? $dir : null;
    }

    /** Rótulo curto do status (p/ lista em texto puro) */
    static function statusLabel($st): string {
        $s = mb_strtolower(trim((string)$st), 'UTF-8');
        $map = [
            'garantia' => 'Garantia', 'ok' => 'OK',
            'inservivel' => 'Inservível', 'inservível' => 'Inservível',
            'pendente' => 'Pendente', 'pending' => 'Pendente', '' => 'Sem status',
        ];
        return $map[$s] ?? (string)$st;
    }

    /** Lista em texto puro: "#seq — modelo (Status)" por linha */
    static function machinesListText(int $cards_id): string {
        global $DB;
        $lines = [];
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) return '(sem máquinas vinculadas)';
            $iter = $DB->request([
                'FROM'  => 'glpi_plugin_kanpro_maintenance_machines',
                'WHERE' => ['plugin_kanpro_cards_id' => $cards_id],
                'ORDER' => 'seq ASC, id ASC',
            ]);
            foreach ($iter as $m) {
                $seq   = (int)($m['seq'] ?? 0);
                $model = trim((string)($m['model'] ?? ('Máquina #' . $seq)));
                $lines[] = "#{$seq} — {$model} (" . self::statusLabel($m['status'] ?? '') . ')';
            }
        } catch (Throwable $e) {
            return '(não foi possível listar)';
        }
        return $lines ? implode("\n", $lines) : '(sem máquinas vinculadas)';
    }

    static function machinesCount(int $cards_id): int {
        global $DB;
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) return 0;
            return countElementsInTable('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id' => $cards_id]);
        } catch (Throwable $e) { return 0; }
    }

    /** Renderiza o .txt trocando {chave} (pula linhas de comentário #...) */
    static function renderTxt(string $type, array $data): ?string {
        if (!in_array($type, self::allowedTypes(), true)) return null;
        $dir = self::templateDir();
        if (!$dir) return null;
        $file = $dir . '/' . $type . '.txt';
        if (!is_file($file)) return null;
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) return null;
        $out = [];
        foreach ($lines as $ln) {
            if (isset($ln[0]) && $ln[0] === '#') continue;
            $out[] = $ln;
        }
        $txt = trim(implode("\n", $out));
        foreach ($data as $k => $v) {
            $k = preg_replace('/[^a-z_]/', '', (string)$k);
            if ($k === '') continue;
            $txt = str_replace('{' . $k . '}', (string)$v, $txt);
        }
        return $txt;
    }

    /** Dados base do card (escola = nome do card na manutenção) */
    static function baseData(int $cards_id): array {
        $card = new PluginKanproCard();
        $escola = '';
        $receb  = '';
        if ($card->getFromDB($cards_id)) {
            $escola = (string)($card->fields['name'] ?? '');
            $receb  = (string)($card->fields['maintenance_date'] ?? $card->fields['date_creation'] ?? '');
            if ($receb !== '' && $receb !== '0000-00-00 00:00:00') {
                try { $receb = (new DateTime($receb))->format('d/m/Y H:i'); } catch (Throwable $e) {}
            } else {
                $receb = '';
            }
        }
        return [
            'escola'            => $escola,
            'card_id'           => (string)$cards_id,
            'card_nome'         => $escola,
            'data_recebimento'  => $receb,
            'data_pronto'       => date('d/m/Y H:i'),
            'data_cancelamento' => date('d/m/Y H:i'),
            'quantidade'        => (string)self::machinesCount($cards_id),
            'maquinas'          => self::machinesListText($cards_id),
            'observacao'        => '',
            'motivo'            => '',
            'dias'              => '',
        ];
    }

    /** Telefone de um usuário GLPI pelo login (phone, senão mobile). '' = ausente/inválido */
    static function resolveUserPhone(string $login): string {
        global $DB;
        try {
            $row = $DB->request(['SELECT' => ['phone', 'mobile'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => trim($login), 'is_deleted' => 0], 'LIMIT' => 1])->current();
            if (!$row) return '';
            $p = trim((string)($row['phone'] ?? ''));
            if ($p === '') $p = trim((string)($row['mobile'] ?? ''));
            return self::normalizeBRPhone($p);
        } catch (Throwable $e) { return ''; }
    }

    /** Telefone do aprovador configurado (aceita login OU e-mail cadastrado). '' = ausente/inválido */
    static function resolveApproverPhone(?string $login = null): string {
        global $DB;
        $login = trim((string)($login ?? self::pendenciaApprover()));
        if ($login === '') return '';
        try {
            // 1) login exato (glpi_users.name)
            $row = $DB->request(['SELECT' => ['phone', 'mobile'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => $login, 'is_deleted' => 0], 'LIMIT' => 1])->current();
            if ($row) {
                $p = trim((string)($row['phone'] ?? ''));
                if ($p === '') $p = trim((string)($row['mobile'] ?? ''));
                $n = self::normalizeBRPhone($p);
                if ($n !== '') return $n;
            }
            // 2) e-mail na tabela de e-mails (glpi_useremails.email)
            if ($DB->tableExists('glpi_useremails')) {
                $em = $DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_useremails', 'WHERE' => ['email' => $login], 'LIMIT' => 1])->current();
                if ($em && (int)($em['users_id'] ?? 0) > 0) {
                    $u = $DB->request(['SELECT' => ['phone', 'mobile'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => (int)$em['users_id'], 'is_deleted' => 0], 'LIMIT' => 1])->current();
                    if ($u) {
                        $p = trim((string)($u['phone'] ?? ''));
                        if ($p === '') $p = trim((string)($u['mobile'] ?? ''));
                        $n = self::normalizeBRPhone($p);
                        if ($n !== '') return $n;
                    }
                }
            }
            // 3) coluna direta glpi_users.email (quando existir)
            try {
                if ($DB->fieldExists('glpi_users', 'email')) {
                    $row2 = $DB->request(['SELECT' => ['phone', 'mobile'], 'FROM' => 'glpi_users', 'WHERE' => ['email' => $login, 'is_deleted' => 0], 'LIMIT' => 1])->current();
                    if ($row2) {
                        $p = trim((string)($row2['phone'] ?? ''));
                        if ($p === '') $p = trim((string)($row2['mobile'] ?? ''));
                        $n = self::normalizeBRPhone($p);
                        if ($n !== '') return $n;
                    }
                }
            } catch (Throwable $e) {}
        } catch (Throwable $e) { return ''; }
        return '';
    }

    /** Telefone da escola = phonenumber da entidade vinculada ao card (só BR válido) */
    static function resolvePhone(int $cards_id): string {
        global $DB;
        try {
            $card = new PluginKanproCard();
            if (!$card->getFromDB($cards_id)) return '';
            $eid = (int)($card->fields['entities_id'] ?? 0);
            if ($eid <= 0) return '';
            if (!$DB->fieldExists('glpi_entities', 'phonenumber')) return '';
            $ent = $DB->request(['SELECT' => ['phonenumber'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $eid]])->current();
            return self::normalizeBRPhone((string)($ent['phonenumber'] ?? ''));
        } catch (Throwable $e) { return ''; }
    }

    /** Normaliza p/ padrão Evolution (só dígitos, com DDI 55). '' = inválido */
    static function normalizeBRPhone(string $raw): string {
        $d = preg_replace('/[^0-9]/', '', $raw);
        if (str_starts_with($d, '0055')) $d = substr($d, 2); // 0055 -> 55
        if (str_starts_with($d, '55') && (strlen($d) === 12 || strlen($d) === 13)) return $d;
        if (strlen($d) === 10 || strlen($d) === 11) return '55' . $d;
        if (strlen($d) === 12 || strlen($d) === 13) return $d; // outro DDI, aceita
        return '';
    }

    static function evoConfig(): ?array {
        global $DB;
        try {
            if (!$DB->tableExists('glpi_plugin_whatsappsimples_configs')) return null;
            $cfg = [];
            foreach (['server_url', 'api_token', 'instance_name'] as $k) {
                $row = $DB->request(['SELECT' => ['value'], 'FROM' => 'glpi_plugin_whatsappsimples_configs', 'WHERE' => ['name' => $k], 'LIMIT' => 1])->current();
                $cfg[$k] = trim((string)($row['value'] ?? ''));
            }
            if ($cfg['server_url'] === '' || $cfg['api_token'] === '' || $cfg['instance_name'] === '') return null;
            return $cfg;
        } catch (Throwable $e) { return null; }
    }

    /** POST /message/sendText/{instance} — retorna ['ok'=>bool,'error'=>?] */
    static function evoSend(string $phone, string $text, int $timeout = 20): array {
        try {
            $cfg = static::evoConfig();
            if (!$cfg) return ['ok' => false, 'error' => 'EvolutionAPI não configurada (whatsappsimples)'];
            $endpoint = rtrim($cfg['server_url'], '/') . '/message/sendText/' . $cfg['instance_name'];
            $http = static::evoHttpPost($endpoint, ['Content-Type: application/json', 'apikey: ' . $cfg['api_token']], (string)json_encode(['number' => $phone, 'text' => $text, 'textMessage' => ['text' => $text]], JSON_UNESCAPED_UNICODE), $timeout);
            $resp = $http['resp'];
            $code = (int)$http['code'];
            $err = (string)$http['err'];
            if ($resp === false) return ['ok' => false, 'error' => 'cURL: ' . $err];
            if ($code >= 200 && $code < 300) return ['ok' => true];
            return ['ok' => false, 'error' => "HTTP {$code}: " . mb_substr((string)$resp, 0, 300)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Camada HTTP do envio (seam p/ testes: subclass sobrescreve via static::).
     * Devolve ['resp'=>string|false, 'code'=>int, 'err'=>string].
     *
     * @param array<int,string> $headers
     * @return array{resp:mixed,code:int,err:string}
     */
    static function evoHttpPost(string $endpoint, array $headers, string $body, int $timeout): array {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['resp' => $resp, 'code' => $code, 'err' => (string)$err];
    }

    /** Kinds que podem ir p/ a fila (envios automáticos; alertas manuais seguem síncronos). */
    static function queueableKinds(): array {
        return array_values(array_diff(self::allowedTypes(), ['card_alerta', 'quadro_alerta']));
    }

    static function hasQueued(int $cards_id, string $milestone): bool {
        global $DB;
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_zapqueue')) return false;
            foreach ($DB->request([
                'SELECT' => ['id', 'status'],
                'FROM'   => 'glpi_plugin_kanpro_zapqueue',
                'WHERE'  => ['plugin_kanpro_cards_id' => $cards_id, 'milestone' => mb_substr($milestone, 0, 30)],
            ]) as $r) {
                if (in_array($r['status'] ?? '', ['pending', 'sending'], true)) return true;
            }
        } catch (Throwable $e) {}
        return false;
    }

    /**
     * Enfileira um envio (produtor). Rápido: só valida e insere.
     * $replace=true apaga pendente anterior da mesma chave (forceResend).
     * $force=true pula a trava alreadySent (ação explícita do usuário).
     * Devolve ['ok'=>true,'queued'=>true,'phone'=>?] ou ['ok'=>false,'error'=>?].
     */
    static function enqueue(string $kind, int $cards_id, string $milestone, string $phone, string $text, bool $replace = false, bool $force = false): array {
        global $DB;
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_zapqueue')) return ['ok' => false, 'error' => 'fila indisponível'];
            if (!in_array($kind, self::queueableKinds(), true)) return ['ok' => false, 'error' => 'Tipo inválido p/ fila'];
            $phone = trim($phone);
            $text = trim($text);
            if ($phone === '' || $text === '') return ['ok' => false, 'error' => 'sem telefone/texto'];
            $milestone = mb_substr(trim($milestone), 0, 30);
            if (!$force && self::alreadySent($cards_id, $milestone)) return ['ok' => false, 'error' => 'duplicate'];
            if ($replace) {
                try { $DB->delete('glpi_plugin_kanpro_zapqueue', ['plugin_kanpro_cards_id' => $cards_id, 'milestone' => $milestone, 'status' => 'pending']); } catch (Throwable $e) {}
            } elseif (self::hasQueued($cards_id, $milestone)) {
                return ['ok' => false, 'error' => 'duplicate'];
            }
            $now = date('Y-m-d H:i:s');
            $id = $DB->insert('glpi_plugin_kanpro_zapqueue', [
                'kind' => $kind,
                'plugin_kanpro_cards_id' => $cards_id,
                'milestone' => $milestone,
                'phone' => mb_substr($phone, 0, 30),
                'message' => $text,
                'attempts' => 0,
                'max_attempts' => 5,
                'status' => 'pending',
                'next_try_at' => $now,
                'date_creation' => $now,
            ]);
            if ($id === false) return ['ok' => false, 'error' => 'falha ao enfileirar'];
            return ['ok' => true, 'queued' => true, 'phone' => $phone];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Minutos até a próxima tentativa após a N-ésima falha (1-based). */
    static function queueBackoffMinutes(int $attempts): int {
        return (int)min(5 * (2 ** max(0, $attempts - 1)), 180);
    }

    /**
     * Consumidor da fila (cron zapqueue): envia devidos com retry + backoff.
     * Recupera 'sending' travado (>15min), pula duplicate tardio, grava zaplog
     * só no sucesso ou na falha final (sem spam de retry). Nunca joga exceção.
     * Devolve ['processed'=>N,'sent'=>S,'failed'=>F].
     */
    static function processQueue(int $limit = 20): array {
        global $DB;
        $out = ['processed' => 0, 'sent' => 0, 'failed' => 0];
        try {
            if ($limit <= 0) return $out;
            if (!isset($DB) || !$DB->tableExists('glpi_plugin_kanpro_zapqueue')) return $out;
            $now = time();
            $nowStr = date('Y-m-d H:i:s', $now);
            // reclaim: sending sem heartbeat há >15min volta p/ pending
            try {
                foreach ($DB->request(['SELECT' => ['id', 'date_mod'], 'FROM' => 'glpi_plugin_kanpro_zapqueue', 'WHERE' => ['status' => 'sending']]) as $stuck) {
                    $mod = strtotime((string)($stuck['date_mod'] ?? ''));
                    if ($mod !== false && $mod < $now - 900) {
                        $DB->update('glpi_plugin_kanpro_zapqueue', ['status' => 'pending', 'next_try_at' => $nowStr, 'date_mod' => $nowStr], ['id' => (int)$stuck['id']]);
                    }
                }
            } catch (Throwable $e) {}
            // devidos: pending com next_try_at <= agora, mais antigos primeiro
            $due = [];
            try {
                foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_zapqueue', 'WHERE' => ['status' => 'pending'], 'ORDER' => 'id ASC']) as $row) {
                    $nta = $row['next_try_at'] ?? null;
                    if ($nta === null || $nta === '' || (string)$nta <= $nowStr) {
                        $due[] = $row;
                    }
                    if (count($due) >= $limit) break;
                }
            } catch (Throwable $e) { return $out; }
            foreach ($due as $job) {
                $out['processed']++;
                $jid = (int)($job['id'] ?? 0);
                $cardsId = (int)($job['plugin_kanpro_cards_id'] ?? 0);
                $milestone = (string)($job['milestone'] ?? '');
                $kind = (string)($job['kind'] ?? '');
                $phone = (string)($job['phone'] ?? '');
                $text = (string)($job['message'] ?? '');
                try {
                    $DB->update('glpi_plugin_kanpro_zapqueue', ['status' => 'sending', 'date_mod' => $nowStr], ['id' => $jid]);
                    // duplicate tardio: enviado por outro caminho enquanto aguardava
                    if ($milestone !== '' && self::alreadySent($cardsId, $milestone)) {
                        $DB->update('glpi_plugin_kanpro_zapqueue', ['status' => 'done', 'last_error' => 'duplicate tardio', 'date_mod' => $nowStr], ['id' => $jid]);
                        continue;
                    }
                    $res = static::evoSend($phone, $text, 20);
                    if (!empty($res['ok'])) {
                        $DB->update('glpi_plugin_kanpro_zapqueue', ['status' => 'done', 'last_error' => null, 'date_mod' => $nowStr], ['id' => $jid]);
                        self::markSent($cardsId, $milestone, $phone, true, '');
                        if ($cardsId > 0) self::logCard($cardsId, "WhatsApp {$kind} enviado para {$phone} (fila)");
                        $out['sent']++;
                    } else {
                        $attempts = (int)($job['attempts'] ?? 0) + 1;
                        $err = mb_substr((string)($res['error'] ?? 'falha'), 0, 255);
                        if ($attempts >= (int)($job['max_attempts'] ?? 5)) {
                            $DB->update('glpi_plugin_kanpro_zapqueue', ['status' => 'failed', 'attempts' => $attempts, 'last_error' => $err, 'date_mod' => $nowStr], ['id' => $jid]);
                            self::markSent($cardsId, $milestone, $phone, false, $err);
                            if ($cardsId > 0) self::logCard($cardsId, "WhatsApp {$kind} FALHOU para {$phone} após {$attempts} tentativas: {$err}");
                        } else {
                            $next = date('Y-m-d H:i:s', $now + self::queueBackoffMinutes($attempts) * 60);
                            $DB->update('glpi_plugin_kanpro_zapqueue', ['status' => 'pending', 'attempts' => $attempts, 'next_try_at' => $next, 'last_error' => $err, 'date_mod' => $nowStr], ['id' => $jid]);
                        }
                        $out['failed']++;
                    }
                } catch (Throwable $e) {
                    try {
                        $attempts = (int)($job['attempts'] ?? 0) + 1;
                        $err = mb_substr($e->getMessage(), 0, 255);
                        $next = date('Y-m-d H:i:s', $now + self::queueBackoffMinutes($attempts) * 60);
                        $DB->update('glpi_plugin_kanpro_zapqueue', ['status' => 'pending', 'attempts' => $attempts, 'next_try_at' => $next, 'last_error' => $err, 'date_mod' => $nowStr], ['id' => $jid]);
                    } catch (Throwable $e2) {}
                    $out['failed']++;
                }
            }
        } catch (Throwable $e) {}
        return $out;
    }

    /** Cron consumidor da fila (a cada 5min, modo interno). param = lote. */
    public static function cronZapqueue($task = null): int {
        $limit = 20;
        try {
            if (is_object($task) && isset($task->fields['param'])) {
                $limit = max(1, (int)$task->fields['param']);
            }
            $r = self::processQueue($limit);
            if (is_object($task) && method_exists($task, 'log')) {
                $task->log('KanPro Zap fila: ' . ((int)($r['processed'] ?? 0)) . ' processados, ' . ((int)($r['sent'] ?? 0)) . ' enviados, ' . ((int)($r['failed'] ?? 0)) . ' falhas');
            }
        } catch (Throwable $e) { return 1; }
        return 1;
    }

    static function alreadySent(int $cards_id, string $milestone): bool {
        global $DB;
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_zaplog')) return false;
            return countElementsInTable('glpi_plugin_kanpro_maintenance_zaplog', [
                'plugin_kanpro_cards_id' => $cards_id, 'milestone' => $milestone, 'success' => 1,
            ]) > 0;
        } catch (Throwable $e) { return false; }
    }

    static function markSent(int $cards_id, string $milestone, string $phone, bool $success, string $detail = ''): void {
        global $DB;
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_zaplog')) return;
            $DB->insert('glpi_plugin_kanpro_maintenance_zaplog', [
                'plugin_kanpro_cards_id' => $cards_id,
                'milestone'              => mb_substr($milestone, 0, 30),
                'phone'                  => mb_substr($phone, 0, 30),
                'success'                => $success ? 1 : 0,
                'detail'                 => mb_substr($detail, 0, 255),
                'date_creation'          => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {}
    }

    /**
     * Fluxo completo: monta dados, resolve fone, renderiza e ENFILEIRA
     * (o envio real acontece no cron zapqueue, com retry + backoff).
     * $milestone (entrada|retirada|atraso_7|atraso_14|atraso_30|cancelado) trava duplicado.
     * Nunca joga exceção — falha vira ['ok'=>false] + log na atividade do card.
     */
    static function send(string $type, int $cards_id, array $extra = [], ?string $milestone = null, ?string $phone = null, int $timeout = 20): array {
        global $DB;
        $milestone = $milestone ?? $type;
        try {
            if (!in_array($type, self::allowedTypes(), true)) return ['ok' => false, 'error' => 'Tipo inválido'];
            if ($cards_id <= 0) return ['ok' => false, 'error' => 'Card inválido'];
            if (self::alreadySent($cards_id, $milestone)) return ['ok' => false, 'error' => 'duplicate'];
            $data = array_merge(self::baseData($cards_id), $extra);
            $phone = $phone ?? self::resolvePhone($cards_id);
            $phone = self::normalizeBRPhone((string)$phone);
            if ($phone === '') {
                self::markSent($cards_id, $milestone, '', false, 'sem telefone da escola');
                self::logCard($cards_id, "WhatsApp {$type} NÃO enviado: escola sem telefone cadastrado");
                return ['ok' => false, 'error' => 'sem telefone'];
            }
            $txt = self::renderTxt($type, $data);
            if ($txt === null || $txt === '') {
                self::markSent($cards_id, $milestone, $phone, false, 'template vazio');
                return ['ok' => false, 'error' => 'template vazio'];
            }
            // fila: envio real no cron zapqueue (zaplog + atividade saem no consumidor).
            $q = self::enqueue($type, $cards_id, $milestone, $phone, $txt);
            if (empty($q['ok'])) {
                return ['ok' => false, 'error' => (string)($q['error'] ?? 'falha')] + ['phone' => $phone];
            }
            return ['ok' => true, 'queued' => true, 'phone' => $phone];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Atalho com trava de duplicado (entrada/retirada/cancelado) */
    static function sendOnce(string $type, int $cards_id, array $extra = [], ?string $phone = null, int $timeout = 8): array {
        return self::send($type, $cards_id, $extra, $type, $phone, $timeout);
    }

    /**
     * Junta destinatários (phone => label), primeiro vence, sem vazios.
     * Usado pelo Notificar da Retirada: contatos da entidade + fone da escola.
     *
     * @param array<int,array<string,mixed>> $labeled
     * @return array<string,string>
     */
    static function mergeNotifyPhones(array $labeled): array {
        $out = [];
        foreach ($labeled as $item) {
            if (!is_array($item)) continue;
            $phone = trim((string)($item['phone'] ?? ''));
            if ($phone === '' || isset($out[$phone])) continue;
            $label = trim((string)($item['label'] ?? ''));
            $out[$phone] = $label !== '' ? $label : $phone;
        }
        return $out;
    }

    /**
     * Alvos do Notificar da Retirada: contatos WhatsApp da entidade (ativos)
     * + telefone da escola, deduplicados. Não envia nada, só resolve.
     * Devolve ['ok'=>true,'card_name'=>,'entity_name'=>,'is_notified'=>,'queued'=>,'recipients'=>[...]].
     */
    static function retiradaNotifyTargets(int $cards_id): array {
        global $DB;
        try {
            if ($cards_id <= 0) return ['ok' => false, 'error' => 'Cartão inválido'];
            if (!class_exists('PluginKanproCard')) return ['ok' => false, 'error' => 'Cartão indisponível'];
            $card = new PluginKanproCard();
            if (!$card->getFromDB($cards_id)) return ['ok' => false, 'error' => 'Cartão não encontrado'];
            $eid = (int)($card->fields['entities_id'] ?? 0);
            $labeled = [];
            try {
                if ($eid > 0 && class_exists('PluginKanproEntityContact')) {
                    foreach (PluginKanproEntityContact::getForEntity($eid, true, 'phone') as $c) {
                        $p = self::normalizeBRPhone((string)($c['phone'] ?? ''));
                        if ($p === '') continue;
                        $label = trim((string)($c['name'] ?? ''));
                        if ($label === '') $label = $p;
                        $labeled[] = ['phone' => $p, 'label' => $label];
                    }
                }
            } catch (Throwable $e) {}
            try {
                $school = self::normalizeBRPhone((string)self::resolvePhone($cards_id));
                if ($school !== '') $labeled[] = ['phone' => $school, 'label' => 'Telefone da escola'];
            } catch (Throwable $e) {}
            $entityName = '';
            try {
                if ($eid > 0 && isset($DB)) {
                    $er = $DB->request(['SELECT' => ['completename', 'name'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $eid], 'LIMIT' => 1])->current();
                    if (is_array($er)) $entityName = trim((string)($er['completename'] ?? $er['name'] ?? ''));
                }
            } catch (Throwable $e) {}
            $recipients = [];
            foreach (self::mergeNotifyPhones($labeled) as $phone => $label) {
                $recipients[] = ['phone' => $phone, 'label' => $label];
            }
            return [
                'ok'          => true,
                'card_name'   => trim((string)($card->fields['name'] ?? '')) !== '' ? (string)$card->fields['name'] : ('Card #' . $cards_id),
                'entity_name' => $entityName,
                'is_notified' => !empty($card->fields['is_notified']) ? 1 : 0,
                'queued'      => self::hasQueued($cards_id, 'retirada_notify'),
                'recipients'  => $recipients,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Notificar da Retirada (botão do card): enfileira 1 job por destino
     * (contatos da entidade + fone da escola) e marca notificado.
     * Ação explícita: ignora alreadySent (usa force + replace).
     * Devolve ['ok'=>true,'queued'=>N,'phones'=>[...]] ou ['ok'=>false,'error'=>?].
     */
    static function sendRetiradaNotify(int $cards_id, int $users_id = 0): array {
        global $DB;
        try {
            $t = self::retiradaNotifyTargets($cards_id);
            if (empty($t['ok'])) return $t;
            if (empty($t['recipients'])) {
                return ['ok' => false, 'error' => 'sem telefone (cadastre contatos na entidade ou o fone da escola)'];
            }
            $txt = null;
            try {
                $txt = self::renderTxt('retirada', self::baseData($cards_id));
            } catch (Throwable $e) { $txt = null; }
            if ($txt === null || trim((string)$txt) === '') {
                $txt = "Card ({$t['card_name']}) disponível para retirada" . ($t['entity_name'] !== '' ? " — {$t['entity_name']}" : '');
            }
            $sent = 0; $errors = []; $phones = [];
            foreach ($t['recipients'] as $rcp) {
                $q = self::enqueue('retirada', $cards_id, 'retirada_notify', (string)$rcp['phone'], (string)$txt, true, true);
                if (!empty($q['ok'])) { $sent++; $phones[] = (string)$rcp['phone']; }
                else $errors[] = (string)$rcp['label'] . ': ' . ($q['error'] ?? 'falha');
            }
            if ($sent > 0) {
                try {
                    $DB->update('glpi_plugin_kanpro_cards', [
                        'is_notified' => 1, 'notified_by' => $users_id,
                        'notified_date' => date('Y-m-d H:i:s'), 'date_mod' => date('Y-m-d H:i:s'),
                    ], ['id' => $cards_id]);
                } catch (Throwable $e) {}
                self::logCard($cards_id, "WhatsApp retirada na fila para {$sent} destino(s): " . implode(',', $phones));
            } else {
                return ['ok' => false, 'error' => implode('; ', $errors) ?: 'falha'];
            }
            return ['ok' => true, 'queued' => $sent, 'phones' => $phones];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Aviso de Pendência Chamado: 1 msg por card novo na lista Pendência Chamado.
     * Destinatário fixo = fone do aprovador (nunca quebra o fluxo).
     */
    static function sendPendencia(int $pendenciaId): array {
        global $DB;
        try {
            if ($pendenciaId <= 0) return ['ok' => false, 'error' => 'Card inválido'];
            if (self::alreadySent($pendenciaId, 'pendencia')) return ['ok' => false, 'error' => 'duplicate'];
            $pc = new PluginKanproCard();
            if (!$pc->getFromDB($pendenciaId)) return ['ok' => false, 'error' => 'Card não encontrado'];
            $srcId = (int)($pc->fields['chamado_source_id'] ?? 0);
            if ($srcId <= 0) return ['ok' => false, 'error' => 'Sem origem'];
            $src = new PluginKanproCard();
            $srcName = '';
            if ($src->getFromDB($srcId)) $srcName = (string)($src->fields['name'] ?? '');
            $boardName = '';
            $b = new PluginKanproBoard();
            if ($b->getFromDB((int)($pc->fields['plugin_kanpro_boards_id'] ?? 0))) $boardName = (string)($b->fields['name'] ?? '');
            $listName = '';
            $l = new PluginKanproList();
            if ($l->getFromDB((int)($pc->fields['plugin_kanpro_lists_id'] ?? 0))) $listName = (string)($l->fields['name'] ?? '');
            // máquinas da solicitação (ids guardados no card da pendência)
            $mids = [];
            try { $mids = json_decode((string)($pc->fields['chamado_machines'] ?? '[]'), true) ?: []; } catch (Throwable $e) { $mids = []; }
            $mids = array_values(array_filter(array_map('intval', (array)$mids)));
            $lines = [];
            if (!empty($mids) && $DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
                try {
                    foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['id' => $mids], 'ORDER' => 'seq ASC']) as $m) {
                        $lines[] = '#' . (int)($m['seq'] ?? 0) . ' — ' . trim((string)($m['model'] ?? '')) . ' (' . self::statusLabel($m['status'] ?? '') . ')';
                    }
                } catch (Throwable $e) {}
            }
            $cardNome = (string)($pc->fields['name'] ?? '');
            $solNome = '';
            $solId = (int)($pc->fields['chamado_by'] ?? 0);
            if ($solId > 0) {
                try {
                    $su = new User();
                    if ($su->getFromDB($solId)) $solNome = $su->getFriendlyName();
                    else $solNome = 'Usuário #' . $solId;
                } catch (Throwable $e) { $solNome = 'Usuário #' . $solId; }
            }
            $data = [
                'card_id'     => (string)$pendenciaId,
                'card_nome'   => $cardNome,
                'escola'      => $cardNome !== '' ? $cardNome : $srcName,
                'quadro'      => $boardName,
                'lista'       => $listName !== '' ? $listName : 'Pendência Chamado',
                'origem_id'   => (string)$srcId,
                'origem_nome' => $srcName,
                'quantidade'  => (string)count($mids),
                'maquinas'    => $lines ? implode("\n", $lines) : '(sem máquinas vinculadas)',
                'data'        => date('d/m/Y H:i'),
                'solicitado_por' => $solNome !== '' ? $solNome : '—',
            ];
            $phone = self::resolveApproverPhone();
            $phone = self::normalizeBRPhone((string)$phone);
            if ($phone === '') {
                self::markSent($pendenciaId, 'pendencia', '', false, 'sem telefone do aprovador');
                self::logCard($pendenciaId, 'WhatsApp pendencia NÃO enviado: aprovador sem telefone cadastrado (' . self::pendenciaApprover() . ')');
                return ['ok' => false, 'error' => 'sem telefone'];
            }
            $txt = self::renderTxt('pendencia', $data);
            if ($txt === null || $txt === '') {
                self::markSent($pendenciaId, 'pendencia', $phone, false, 'template vazio');
                return ['ok' => false, 'error' => 'template vazio'];
            }
            // fila: envio real no cron zapqueue (zaplog + atividade saem no consumidor).
            $q = self::enqueue('pendencia', $pendenciaId, 'pendencia', $phone, $txt);
            if (empty($q['ok'])) {
                return ['ok' => false, 'error' => (string)($q['error'] ?? 'falha')] + ['phone' => $phone];
            }
            return ['ok' => true, 'queued' => true, 'phone' => $phone];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Aviso de liberado: 1 msg por técnico membro da origem, 5s após Chamado criado.
     * Anti-duplicado por técnico (milestone liberado_<uid>). Nunca joga exceção.
     */
    static function sendLiberado(int $pendenciaId): array {
        global $DB;
        try {
            if ($pendenciaId <= 0) return ['ok' => false, 'error' => 'Card inválido'];
            $pc = new PluginKanproCard();
            if (!$pc->getFromDB($pendenciaId)) return ['ok' => false, 'error' => 'duplicate'];
            $srcId = (int)($pc->fields['chamado_source_id'] ?? 0);
            if ($srcId <= 0) return ['ok' => false, 'error' => 'Sem origem'];
            if (($pc->fields['chamado_status'] ?? '') !== 'liberado') return ['ok' => false, 'error' => 'Ainda não liberado'];
            $src = new PluginKanproCard();
            $srcName = '';
            $srcTid = 0;
            if ($src->getFromDB($srcId)) {
                $srcName = (string)($src->fields['name'] ?? '');
                $srcTid = (int)($src->fields['tickets_id'] ?? 0);
            }
            // técnicos = membros do card origem (fallback: quem solicitou/pegou)
            $uids = [];
            try {
                if ($DB->tableExists('glpi_plugin_kanpro_cards_members')) {
                    foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_plugin_kanpro_cards_members', 'WHERE' => ['plugin_kanpro_cards_id' => $srcId]]) as $r) {
                        $uid = (int)($r['users_id'] ?? 0);
                        if ($uid > 0) $uids[$uid] = true;
                    }
                }
            } catch (Throwable $e) {}
            if (empty($uids) && (int)($pc->fields['chamado_by'] ?? 0) > 0) $uids[(int)$pc->fields['chamado_by']] = true;
            if (empty($uids)) {
                self::logCard($pendenciaId, 'WhatsApp liberado NÃO enviado: origem sem técnico atribuído');
                return ['ok' => false, 'error' => 'sem tecnico'];
            }
            // chamado (nº + nome)
            $chId = $srcTid > 0 ? (string)$srcTid : '—';
            $chNome = '';
            if ($srcTid > 0 && class_exists('Ticket')) {
                try {
                    $tk = new Ticket();
                    if ($tk->getFromDB($srcTid)) $chNome = (string)($tk->fields['name'] ?? '');
                } catch (Throwable $e) {}
            }
            $boardName = '';
            $b = new PluginKanproBoard();
            if ($b->getFromDB((int)($pc->fields['plugin_kanpro_boards_id'] ?? 0))) $boardName = (string)($b->fields['name'] ?? '');
            $cardNome = (string)($pc->fields['name'] ?? '');
            $solNome = '';
            $solId = (int)($pc->fields['chamado_by'] ?? 0);
            if ($solId > 0) {
                try {
                    $su = new User();
                    if ($su->getFromDB($solId)) $solNome = $su->getFriendlyName();
                    else $solNome = 'Usuário #' . $solId;
                } catch (Throwable $e) { $solNome = 'Usuário #' . $solId; }
            }
            // máquinas liberadas (as da solicitação; fallback p/ origem se snapshot defasado)
            $mids = [];
            try { $mids = json_decode((string)($pc->fields['chamado_machines'] ?? '[]'), true) ?: []; } catch (Throwable $e) { $mids = []; }
            $mids = array_values(array_filter(array_map('intval', (array)$mids)));
            $lines = [];
            if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
                try {
                    if (!empty($mids)) {
                        foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['id' => $mids], 'ORDER' => 'seq ASC']) as $m) {
                            $label = trim((string)($m['label'] ?? '')) !== '' ? trim((string)$m['label']) : trim((string)($m['model'] ?? ''));
                            // label já vem como "Máquina N - Modelo"; extrai só o modelo p/ msg curta
                            $lines[] = '#' . (int)($m['seq'] ?? 0) . ' — ' . $label . ' (' . self::statusLabel($m['status'] ?? '') . ')';
                        }
                    }
                    // fallback: snapshot vazio ou IDs apagados → lista o que está na origem agora
                    if (empty($lines) && $srcId > 0) {
                        foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['plugin_kanpro_cards_id' => $srcId], 'ORDER' => 'seq ASC']) as $m) {
                            $label = trim((string)($m['label'] ?? '')) !== '' ? trim((string)$m['label']) : trim((string)($m['model'] ?? ''));
                            $lines[] = '#' . (int)($m['seq'] ?? 0) . ' — ' . $label . ' (' . self::statusLabel($m['status'] ?? '') . ')';
                        }
                    }
                } catch (Throwable $e) {}
            }
            // data de solicitação = criação da pendência (não o agora do liberado)
            $solData = date('d/m/Y H:i');
            try {
                $dc = (string)($pc->fields['date_creation'] ?? '');
                if ($dc !== '' && $dc !== '0000-00-00 00:00:00') $solData = (new DateTime($dc))->format('d/m/Y H:i');
            } catch (Throwable $e) {}
            // Tablet: usa template próprio (avisa CRM + pode finalizar de novo no KanPRO)
            $isTabletSrc = false;
            try { $isTabletSrc = self::isTabletCard($srcId); } catch (Throwable $e) {}
            $tplType = $isTabletSrc ? 'tablet_liberado' : 'liberado';
            $msPrefix = $isTabletSrc ? 'tablet_liberado_' : 'liberado_';
            $sent = 0; $skipped = 0; $errors = [];
            foreach (array_keys($uids) as $uid) {
                $ms = $msPrefix . (int)$uid;
                if (self::alreadySent($pendenciaId, $ms)) { $skipped++; continue; }
                $tecNome = 'Técnico';
                $phone = '';
                try {
                    $u = new User();
                    if ($u->getFromDB((int)$uid)) {
                        $tecNome = $u->getFriendlyName();
                        $p = trim((string)($u->fields['phone'] ?? ''));
                        if ($p === '') $p = trim((string)($u->fields['mobile'] ?? ''));
                        $phone = self::normalizeBRPhone($p);
                    } else { $tecNome = 'Usuário #' . (int)$uid; }
                } catch (Throwable $e) {}
                if ($phone === '') {
                    self::markSent($pendenciaId, $ms, '', false, 'tecnico sem telefone');
                    $errors[] = 'sem telefone (' . $tecNome . ')';
                    continue;
                }
                $data = [
                    'tecnico'        => $tecNome,
                    'card_id'        => (string)$pendenciaId,
                    'card_nome'      => $cardNome,
                    'escola'         => $cardNome !== '' ? $cardNome : $srcName,
                    'quadro'         => $boardName,
                    'origem_id'      => (string)$srcId,
                    'origem_nome'    => $srcName,
                    'chamado_id'     => $chId,
                    'chamado_nome'   => $chNome !== '' ? $chNome : '—',
                    'quantidade'     => (string)count($lines),
                    'maquinas'       => $lines ? implode("\n", $lines) : '(sem máquinas vinculadas)',
                    'solicitado_por' => $solNome !== '' ? $solNome : '—',
                    'data'           => $solData,
                ];
                $txt = self::renderTxt($tplType, $data);
                // fallback: se template tablet ainda não existe, usa liberado + linha extra
                if (($txt === null || $txt === '') && $isTabletSrc) {
                    $txt = self::renderTxt('liberado', $data);
                    if ($txt !== null && $txt !== '') {
                        $txt .= "\n\n📱 *Card de Tablet*: chamado criado no CRM — pode finalizar novamente no KanPRO para ir à Assinatura/Retirada.";
                    }
                }
                if ($txt === null || $txt === '') {
                    self::markSent($pendenciaId, $ms, $phone, false, 'template vazio');
                    $errors[] = 'template vazio';
                    continue;
                }
                // fila: envio real no cron zapqueue (um job por técnico).
                $q = self::enqueue($isTabletSrc ? 'tablet_liberado' : 'liberado', $pendenciaId, $ms, $phone, $txt);
                if (!empty($q['ok'])) $sent++;
                elseif (($q['error'] ?? '') === 'duplicate') $skipped++;
                else $errors[] = (string)($q['error'] ?? 'falha');
            }
            if ($sent > 0) {
                self::logCard($pendenciaId, "WhatsApp liberado na fila para {$sent} técnico(s)");
                return ['ok' => true, 'sent' => $sent, 'skipped' => $skipped];
            }
            if ($skipped > 0 && empty($errors)) return ['ok' => false, 'error' => 'duplicate'];
            return ['ok' => false, 'error' => implode(' | ', array_slice($errors, 0, 3)) ?: 'falha'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    static function logCard(int $cards_id, string $details): void {
        try {
            $card = new PluginKanproCard();
            if (!$card->getFromDB($cards_id)) return;
            PluginKanproBoard::logActivity(
                (int)($card->fields['plugin_kanpro_boards_id'] ?? 0),
                $cards_id,
                (int)($card->fields['plugin_kanpro_lists_id'] ?? 0),
                'maintenance_zap',
                $details
            );
        } catch (Throwable $e) {}
    }

    static function logBoard(int $boards_id, string $details): void {
        try {
            PluginKanproBoard::logActivity($boards_id, null, null, 'board_zap', $details);
        } catch (Throwable $e) {}
    }

    /**
     * Alerta manual do QUADRO: botão no header do quadro (Membro ou Admin do quadro,
     * com Notificação WhatsApp ligada) envia "No quadro (NOME) tem alterações
     * realizadas para voce verificar" para o aprovador configurado.
     * Sem trava de duplicado (cada aperto envia). Nunca joga exceção.
     */
    static function sendBoardAlerta(int $boards_id): array {
        try {
            if ($boards_id <= 0) return ['ok' => false, 'error' => 'Quadro inválido'];
            $b = new PluginKanproBoard();
            if (!$b->getFromDB($boards_id)) return ['ok' => false, 'error' => 'Quadro não encontrado'];
            $quadroNome = trim((string)($b->fields['name'] ?? ''));
            if ($quadroNome === '') $quadroNome = 'Quadro #' . $boards_id;
            $phone = self::resolveApproverPhone();
            $phone = self::normalizeBRPhone((string)$phone);
            if ($phone === '') {
                self::markSent(0, 'quadro_alerta', '', false, 'sem telefone do aprovador');
                self::logBoard($boards_id, 'WhatsApp quadro_alerta NÃO enviado: aprovador sem telefone cadastrado (' . self::pendenciaApprover() . ')');
                return ['ok' => false, 'error' => 'sem telefone'];
            }
            $txt = self::renderTxt('quadro_alerta', [
                'board_id'  => (string)$boards_id,
                'quadro'    => $quadroNome,
                'quadro_nome' => $quadroNome,
                'data'      => date('d/m/Y H:i'),
            ]);
            // fallback se template ausente: mensagem pedida pelo usuário
            if ($txt === null || $txt === '') {
                $txt = "No quadro ({$quadroNome}) tem alterações realizadas para voce verificar";
            }
            $res = self::evoSend($phone, $txt, 20);
            self::markSent($boards_id, 'quadro_alerta', $phone, (bool)$res['ok'], (string)($res['error'] ?? ''));
            self::logBoard($boards_id, $res['ok']
                ? "WhatsApp quadro_alerta enviado para {$phone} (aprovador)"
                : "WhatsApp quadro_alerta FALHOU para {$phone}: " . ($res['error'] ?? ''));
            return $res + ['phone' => $phone];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Alerta manual do cartão: botão no header do card (Membro ou Admin do card)
     * envia "No card (NOME) tem alterações realizadas para voce verificar"
     * para o aprovador configurado.
     * Sem trava de duplicado (cada aperto envia). Nunca joga exceção.
     */
    static function sendCardAlerta(int $cards_id): array {
        try {
            if ($cards_id <= 0) return ['ok' => false, 'error' => 'Card inválido'];
            $card = new PluginKanproCard();
            if (!$card->getFromDB($cards_id)) return ['ok' => false, 'error' => 'Card não encontrado'];
            $cardNome = trim((string)($card->fields['name'] ?? ''));
            if ($cardNome === '') $cardNome = 'Card #' . $cards_id;
            $boardNome = '';
            $b = new PluginKanproBoard();
            if ($b->getFromDB((int)($card->fields['plugin_kanpro_boards_id'] ?? 0))) $boardNome = (string)($b->fields['name'] ?? '');
            $phone = self::resolveApproverPhone();
            $phone = self::normalizeBRPhone((string)$phone);
            if ($phone === '') {
                self::markSent($cards_id, 'card_alerta', '', false, 'sem telefone do aprovador');
                self::logCard($cards_id, 'WhatsApp card_alerta NÃO enviado: aprovador sem telefone cadastrado (' . self::pendenciaApprover() . ')');
                return ['ok' => false, 'error' => 'sem telefone'];
            }
            $txt = self::renderTxt('card_alerta', [
                'card_id'   => (string)$cards_id,
                'card_nome' => $cardNome,
                'quadro'    => $boardNome,
                'data'      => date('d/m/Y H:i'),
            ]);
            // fallback se template ausente: mensagem pedida pelo usuário
            if ($txt === null || $txt === '') {
                $txt = "No card ({$cardNome}) tem alterações realizadas para voce verificar";
            }
            $res = self::evoSend($phone, $txt, 20);
            // registra cada envio (milestone com timestamp p/ não bloquear o próximo aperto)
            self::markSent($cards_id, 'card_alerta', $phone, (bool)$res['ok'], (string)($res['error'] ?? ''));
            self::logCard($cards_id, $res['ok']
                ? "WhatsApp card_alerta enviado para {$phone} (aprovador)"
                : "WhatsApp card_alerta FALHOU para {$phone}: " . ($res['error'] ?? ''));
            return $res + ['phone' => $phone];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Aviso de novo chamado em Abrir chamado: 1 msg por clone novo, com dados do
     * card + quem criou, para os responsáveis configurados (config 'zap_chamado_recipients').
     * Trava de duplicado por clone (milestone chamado_abrir). Nunca joga exceção.
     */
    static function chamadoAbrirRecipients(): array {
        return self::configRecipients('zap_chamado_recipients');
    }

    static function sendChamadoAbrir(int $cloneId): array {
        try {
            if ($cloneId <= 0) return ['ok' => false, 'error' => 'Card inválido'];
            if (self::alreadySent($cloneId, 'chamado_abrir')) return ['ok' => false, 'error' => 'duplicate'];
            $clone = new PluginKanproCard();
            if (!$clone->getFromDB($cloneId)) return ['ok' => false, 'error' => 'Card não encontrado'];
            $cardNome = trim((string)($clone->fields['name'] ?? ''));
            if ($cardNome === '') $cardNome = 'Card #' . $cloneId;
            $desc = trim(strip_tags((string)($clone->fields['description'] ?? '')));
            if ($desc === '') $desc = '(sem descrição)';
            if (function_exists('mb_substr')) $desc = mb_substr($desc, 0, 500);
            else $desc = substr($desc, 0, 500);
            $boardNome = '';
            $b = new PluginKanproBoard();
            if ($b->getFromDB((int)($clone->fields['plugin_kanpro_boards_id'] ?? 0))) $boardNome = (string)($b->fields['name'] ?? '');
            $ticket = (int)($clone->fields['tickets_id'] ?? 0);
            // autor = quem criou o card original (users_id do clone)
            $autor = 'desconhecido';
            try {
                $au = new User();
                if ($au->getFromDB((int)($clone->fields['users_id'] ?? 0))) {
                    $autor = $au->getFriendlyName();
                    if (trim($autor) === '') $autor = (string)($au->fields['name'] ?? 'desconhecido');
                }
            } catch (Throwable $e) {}
            $txt = self::renderTxt('chamado_abrir', [
                'card_id'   => (string)$cloneId,
                'card_nome' => $cardNome,
                'descricao' => $desc,
                'quadro'    => $boardNome,
                'ticket'    => $ticket > 0 ? (string)$ticket : '-',
                'autor'     => $autor,
                'data'      => date('d/m/Y H:i'),
            ]);
            if ($txt === null || $txt === '') {
                $txt = "Novo chamado em Abrir chamado: #{$cloneId} \"{$cardNome}\" (ticket #{$ticket}), criado por {$autor}";
            }
            $sent = 0; $errors = []; $phones = [];
            foreach (self::chamadoAbrirRecipients() as $login) {
                $ms = self::recipientMilestone('chamado_abrir', (string)$login);
                if (self::alreadySent($cloneId, $ms)) continue;
                $phone = self::normalizeBRPhone((string)self::resolveApproverPhone($login));
                if ($phone === '') { $errors[] = $login . ': sem telefone'; continue; }
                // fila: um job por destinatário (milestone por destinatário).
                $q = self::enqueue('chamado_abrir', $cloneId, $ms, $phone, $txt);
                if (!empty($q['ok'])) { $sent++; $phones[] = $phone; }
                elseif (($q['error'] ?? '') !== 'duplicate') $errors[] = $login . ': ' . ($q['error'] ?? 'falha');
            }
            self::logCard($cloneId, $sent > 0
                ? "WhatsApp chamado_abrir na fila para " . implode(',', $phones) . " ({$sent})"
                : "WhatsApp chamado_abrir FALHOU: " . implode('; ', $errors));
            if ($sent <= 0) return ['ok' => false, 'error' => implode('; ', $errors) ?: 'sem telefone'];
            return ['ok' => true, 'phones' => $phones];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lembrete 8h/10h/13h: quantos cards há nas listas Pendência Chamado, Pendente e
     * Em Andamento (todos os quadros ativos). Só envia se total > 0.
     * Só em dias úteis (seg-sex, sem feriado) salvo exceção do calendário (force),
     * e nunca em dia tirado do envio no calendário (skip) — forceResend ignora tudo.
     * Anti-duplicado por dia+turno+destinatário (milestone lembrete_Y-m-d_08 / _10 / _13).
     * Destinatários = lembreteRecipients() (config). Enfileira um job por
     * destinatário (envio no cron zapqueue).
     * Nunca joga exceção.
     */
    static function sendLembrete(?int $slot = null, array $opts = []): array {
        global $DB;
        try {
            $hour = (int)date('H');
            if ($slot === null || !in_array($slot, [8, 9, 10, 13], true)) $slot = ($hour < 9) ? 8 : (($hour < 12) ? 10 : 13);
            $forceResend = !empty($opts['forceResend']);
            if (!$forceResend) {
                $ov = self::lembreteDayOverride(date('Y-m-d'));
                if ($ov === 'skip') return ['ok' => false, 'error' => 'dia tirado do envio no calendário (' . date('Y-m-d') . ') — volte ao automático para enviar'];
                if ($ov !== 'force') {
                    $biz = self::lembreteBusinessCheck();
                    if (empty($biz['business'])) return ['ok' => false, 'error' => 'dia não útil (' . ($biz['reason'] ?? '') . ' ' . ($biz['date'] ?? '') . ') — lembrete só em dias úteis'];
                }
            }
            $milestone = 'lembrete_' . date('Y-m-d') . '_' . str_pad((string)$slot, 2, '0', STR_PAD_LEFT);
            $recipients = self::lembreteRecipients();
            if (empty($recipients)) $recipients = [self::pendenciaApprover()];
            if (!$forceResend) {
                $allSent = true;
                foreach ($recipients as $rcp) {
                    if (!self::alreadySent(0, self::lembreteMilestone($milestone, (string)$rcp))) { $allSent = false; break; }
                }
                if ($allSent) return ['ok' => false, 'error' => 'duplicate (já enviado hoje neste turno)'];
            }
            // trava distribuída: cron do GLPI + fallback do polling podem disparar juntos —
            // só um prossegue (o outro sai como 'envio em andamento').
            $lemLock = 'kanpro_lembrete_' . date('Y-m-d') . '_' . str_pad((string)$slot, 2, '0', STR_PAD_LEFT);
            $lemGotLock = false;
            try { $lemGotLock = (bool)$DB->getLock($lemLock, 0); } catch (Throwable $e) { $lemGotLock = true; }
            if (!$lemGotLock) return ['ok' => false, 'error' => 'envio em andamento (outro processo)'];
            try {
            if (!$forceResend) {
                $allSentInner = true;
                foreach ($recipients as $rcpInner) {
                    if (!self::alreadySent(0, self::lembreteMilestone($milestone, (string)$rcpInner))) { $allSentInner = false; break; }
                }
                if ($allSentInner) return ['ok' => false, 'error' => 'duplicate (já enviado hoje neste turno)'];
            }
            // classifica listas (tipo ou nome legado, sem acento)
            $norm = function ($s) {
                $s = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$s), 'UTF-8') : strtolower(trim((string)$s));
                return strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
            };
            $pendLists = []; $andLists = []; $pchamLists = [];
            try {
                foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['is_archived' => 0]]) as $l) {
                    $lt = trim(strtolower((string)($l['list_type'] ?? '')));
                    if ($lt === '' || $lt === 'none') {
                        $nm = $norm($l['name'] ?? '');
                        if ($nm === 'pendente') $lt = 'pending';
                        elseif ($nm === 'em andamento') $lt = 'andamento';
                        elseif (strpos($nm, 'pendencia') !== false && strpos($nm, 'chamado') !== false) $lt = 'pend_chamado';
                    }
                    if ($lt === 'pending') $pendLists[(int)$l['id']] = $l;
                    elseif ($lt === 'andamento') $andLists[(int)$l['id']] = $l;
                    elseif ($lt === 'pend_chamado') $pchamLists[(int)$l['id']] = $l;
                }
            } catch (Throwable $e) {}
            if (empty($pendLists) && empty($andLists) && empty($pchamLists)) return ['ok' => false, 'error' => 'sem listas'];
            $countByList = [];
            try {
                $allIds = array_merge(array_keys($pendLists), array_keys($andLists), array_keys($pchamLists));
                if (!empty($allIds)) {
                    foreach ($DB->request(['SELECT' => ['plugin_kanpro_lists_id', 'COUNT' => 'id AS total'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['plugin_kanpro_lists_id' => $allIds, 'is_archived' => 0], 'GROUPBY' => ['plugin_kanpro_lists_id']]) as $r) {
                        $n = (int)($r['total'] ?? 0);
                        if ($n === 0) { foreach ($r as $k => $v) { if (is_string($k) && (stripos($k, 'total') !== false || $k === 'COUNT_id')) { $n = (int)$v; break; } } }
                        $countByList[(int)$r['plugin_kanpro_lists_id']] = $n;
                    }
                }
            } catch (Throwable $e) {}
            $boards = [];
            try {
                foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_plugin_kanpro_boards', 'WHERE' => ['is_archived' => 0]]) as $b) {
                    $boards[(int)$b['id']] = (string)($b['name'] ?? ('Quadro #' . (int)$b['id']));
                }
            } catch (Throwable $e) {}
            $totP = 0; $totA = 0; $totC = 0; $lines = [];
            $perBoard = [];
            // ATENÇÃO: array_merge renumera chaves inteiras (IDs virariam 0,1,2) — usar união que preserva.
            $allLists = $pendLists + $andLists + $pchamLists;
            foreach ($allLists as $lid => $l) {
                $n = $countByList[$lid] ?? 0;
                if ($n <= 0) continue;
                $bid = (int)($l['plugin_kanpro_boards_id'] ?? 0);
                if (!isset($perBoard[$bid])) $perBoard[$bid] = ['p' => 0, 'a' => 0, 'c' => 0];
                if (isset($pendLists[$lid])) { $perBoard[$bid]['p'] += $n; $totP += $n; }
                elseif (isset($andLists[$lid])) { $perBoard[$bid]['a'] += $n; $totA += $n; }
                else { $perBoard[$bid]['c'] += $n; $totC += $n; }
            }
            $total = $totP + $totA + $totC;
            if ($total <= 0) return ['ok' => false, 'error' => 'nada pendente (0 cards nas 3 listas)'];
            foreach ($perBoard as $bid => $c) {
                $bn = $boards[$bid] ?? ('Quadro #' . $bid);
                $lines[] = '• ' . $bn . ' — Aguardando aprovação: ' . ($c['c'] ?? 0) . ' | Pendente: ' . $c['p'] . ' | Andamento: ' . $c['a'];
            }
            $txt = self::renderTxt('lembrete', [
                'total'            => (string)$total,
                'pendentes'        => (string)$totP,
                'andamento'        => (string)$totA,
                'pend_chamado'     => (string)$totC,
                'pendencia_chamado' => (string)$totC,
                'detalhes'         => implode("\n", $lines),
                'data'             => date('d/m/Y H:i'),
            ]);
            if ($txt === null || $txt === '') {
                foreach ($recipients as $rcp) {
                    self::markSent(0, self::lembreteMilestone($milestone, (string)$rcp), '', false, 'template vazio');
                }
                return ['ok' => false, 'error' => 'template vazio'];
            }
            $sent = 0; $failed = []; $phones = []; $lastRes = ['ok' => false, 'error' => 'nada enviado'];
            foreach ($recipients as $rcp) {
                $rcp = (string)$rcp;
                $ms = self::lembreteMilestone($milestone, $rcp);
                if (!$forceResend && self::alreadySent(0, $ms)) continue;
                if (!$forceResend && self::hasQueued(0, $ms)) continue;
                $phone = self::normalizeBRPhone((string)self::resolveApproverPhone($rcp));
                if ($phone === '') {
                    self::markSent(0, $ms, '', false, 'sem telefone do aprovador');
                    $failed[] = $rcp . ' (sem phone/mobile válido no GLPI)';
                    continue;
                }
                // fila (forceResend troca o pendente em vez de duplicar); envio no cron zapqueue.
                $q = self::enqueue('lembrete', 0, $ms, $phone, $txt, $forceResend);
                $phones[] = $phone;
                $lastRes = ['ok' => true, 'queued' => true];
                if (!empty($q['ok'])) $sent++;
                elseif (($q['error'] ?? '') !== 'duplicate') $failed[] = $rcp . ': ' . ($q['error'] ?? 'falha');
            }
            if ($sent <= 0 && empty($failed)) return ['ok' => false, 'error' => 'duplicate (já enviado hoje neste turno)'];
            if ($sent <= 0) return ['ok' => false, 'error' => 'sem telefone do aprovador (' . implode('; ', $failed) . ')'];
            $lemOut = $lastRes + ['phone' => implode(',', $phones), 'phones' => $phones, 'total' => $total, 'sent' => $sent, 'queued' => $sent];
            if (!empty($failed)) $lemOut['partial_fail'] = $failed;
            } finally {
                try { $DB->releaseLock($lemLock); } catch (Throwable $e2) {}
            }
            return $lemOut;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Rede de segurança do lembrete 8h/10h/13h: chamada no polling do kanban
     * (get_board_stamp) para o envio não depender só do cron do GLPI
     * (que só roda com visita na janela ou cron do SO configurado).
     * Só age dentro das janelas 8h, 10h e 13h, uma vez por dia+turno (milestone),
     * e nunca quebra o request (tudo em try/catch).
     */
    static function maybeSendLembreteFallback(): void {
        try {
            $ovToday = self::lembreteDayOverride(date('Y-m-d'));
            if ($ovToday === 'skip') return;
            if ($ovToday !== 'force' && !self::isLembreteBusinessDay()) return;
            $h = (int)date('G');
            $slot = ($h === 8) ? 8 : (($h === 10) ? 10 : (($h === 13) ? 13 : 0));
            if ($slot <= 0) return;
            global $DB;
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_zaplog')) return;
            $milestone = 'lembrete_' . date('Y-m-d') . '_' . str_pad((string)$slot, 2, '0', STR_PAD_LEFT);
            $allSent = true;
            foreach (self::lembreteRecipients() as $rcp) {
                if (!self::alreadySent(0, self::lembreteMilestone($milestone, (string)$rcp))) { $allSent = false; break; }
            }
            if ($allSent) return;
            self::sendLembrete($slot);
        } catch (Throwable $e) {}
    }

    /**
     * Diagnóstico sem enviar: listas encontradas, contagens, telefone (mascarado),
     * Evolution configurada?, template ok?, milestones de hoje. P/ o teste manual.
     * Nunca envia nada, nunca grava zaplog.
     */
    static function diagnoseLembrete(): array {
        global $DB;
        $out = ['now' => date('d/m/Y H:i:s'), 'approver' => self::pendenciaApprover(), 'recipients' => self::lembreteRecipients(), 'business_check' => self::lembreteBusinessCheck(), 'holidays_this_year' => self::lembreteHolidayMap()];
        try {
            $norm = function ($s) {
                $s = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$s), 'UTF-8') : strtolower(trim((string)$s));
                return strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
            };
            $lists = [];
            try {
                foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['is_archived' => 0]]) as $l) {
                    $lt = trim(strtolower((string)($l['list_type'] ?? '')));
                    $raw = $lt;
                    if ($lt === '' || $lt === 'none') {
                        $nm = $norm($l['name'] ?? '');
                        if ($nm === 'pendente') $lt = 'pending';
                        elseif ($nm === 'em andamento') $lt = 'andamento';
                        elseif (strpos($nm, 'pendencia') !== false && strpos($nm, 'chamado') !== false) $lt = 'pend_chamado';
                    }
                    if (in_array($lt, ['pending', 'andamento', 'pend_chamado'], true)) {
                        $lists[] = ['id' => (int)$l['id'], 'board' => (int)($l['plugin_kanpro_boards_id'] ?? 0),
                            'name' => (string)($l['name'] ?? ''), 'type_raw' => $raw, 'type' => $lt];
                    }
                }
            } catch (Throwable $e) { $out['lists_error'] = $e->getMessage(); }
            $out['lists'] = $lists;
            $counts = []; $totP = 0; $totA = 0; $totC = 0;
            try {
                $ids = array_column($lists, 'id');
                if (!empty($ids)) {
                    foreach ($DB->request(['SELECT' => ['plugin_kanpro_lists_id', 'COUNT' => 'id AS total'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['plugin_kanpro_lists_id' => $ids, 'is_archived' => 0], 'GROUPBY' => ['plugin_kanpro_lists_id']]) as $r) {
                        $lid = (int)$r['plugin_kanpro_lists_id'];
                        $n = (int)($r['total'] ?? $r['COUNT_id'] ?? 0);
                        // fallback: GLPI pode devolver a contagem em outra chave
                        if ($n === 0) { foreach ($r as $k => $v) { if (stripos((string)$k, 'total') !== false || $k === 'COUNT_id') { $n = (int)$v; break; } } }
                        $counts[$lid] = $n;
                    }
                }
            } catch (Throwable $e) { $out['count_error'] = $e->getMessage(); }
            foreach ($lists as &$l) {
                $n = $counts[$l['id']] ?? 0;
                $l['cards'] = $n;
                if ($l['type'] === 'pending') $totP += $n;
                elseif ($l['type'] === 'andamento') $totA += $n;
                else $totC += $n;
            }
            unset($l);
            $out['lists'] = $lists;
            $out['totals'] = ['pend_chamado' => $totC, 'pendente' => $totP, 'andamento' => $totA, 'total' => $totC + $totP + $totA];
            // telefones dos destinatários do lembrete (mascarados)
            $out['phones'] = [];
            foreach (self::lembreteRecipients() as $rcp) {
                $phone = self::resolveApproverPhone((string)$rcp);
                $out['phones'][(string)$rcp] = [
                    'found'  => ($phone !== ''),
                    'masked' => ($phone !== '') ? (substr($phone, 0, 4) . '****' . substr($phone, -2)) : '',
                ];
                if ($phone === '') $out['phones'][(string)$rcp]['hint'] = 'Cadastre phone/mobile no usuário ' . $rcp . ' (Administração > Usuários) ou no e-mail correspondente em glpi_useremails';
            }
            // compat: mantém chaves antigas apontando p/ o 1º destinatário
            $phone = self::resolveApproverPhone();
            $out['phone_found'] = ($phone !== '');
            $out['phone_masked'] = ($phone !== '') ? (substr($phone, 0, 4) . '****' . substr($phone, -2)) : '';
            if ($phone === '') $out['phone_hint'] = 'Cadastre phone/mobile no usuário ' . self::pendenciaApprover() . ' (Administração > Usuários) ou no e-mail correspondente em glpi_useremails';
            // evolution
            $cfg = self::evoConfig();
            $out['evo_ok'] = !empty($cfg);
            if (!empty($cfg)) { $out['evo_server'] = (string)($cfg['server_url'] ?? ''); $out['evo_instance'] = (string)($cfg['instance_name'] ?? ''); }
            else $out['evo_hint'] = 'Configure o plugin whatsappsimples (server_url/api_token/instance_name)';
            // template
            $txt = self::renderTxt('lembrete', ['total' => '1', 'pendentes' => '1', 'andamento' => '1', 'pend_chamado' => '1', 'pendencia_chamado' => '1', 'detalhes' => '• Quadro X — Aguardando aprovação: 1 | Pendente: 1 | Andamento: 1', 'data' => date('d/m/Y H:i')]);
            $out['template_ok'] = ($txt !== null && $txt !== '');
            if (!$out['template_ok']) $out['template_hint'] = 'Arquivo templates_whatsapp/lembrete.txt ausente ou vazio';
            // milestones de hoje (por destinatário)
            foreach ([8, 10, 13] as $s) {
                $ms = 'lembrete_' . date('Y-m-d') . '_' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
                $perRcp = [];
                foreach (self::lembreteRecipients() as $rcp) {
                    $m = self::lembreteMilestone($ms, (string)$rcp);
                    $perRcp[(string)$rcp] = ['name' => $m, 'already_sent' => self::alreadySent(0, $m)];
                }
                $out['milestone_' . $s] = ['name' => $ms, 'already_sent' => self::alreadySent(0, $ms), 'per_recipient' => $perRcp];
            }
            // crontasks
            try {
                foreach ($DB->request(['SELECT' => ['name', 'state', 'mode', 'frequency', 'hourmin', 'hourmax', 'lastrun'], 'FROM' => 'glpi_crontasks', 'WHERE' => ['itemtype' => 'PluginKanproMaintenanceZap', 'name' => ['zaplembrete8', 'zaplembrete10', 'zaplembrete13']]]) as $t) {
                    $out['cron_' . $t['name']] = $t;
                }
            } catch (Throwable $e) {}
        } catch (Throwable $e) { $out['error'] = $e->getMessage(); }
        return $out;
    }

    public static function cronZaplembrete8($task = null): int {
        try {
            $r = self::sendLembrete(8);
            if (is_object($task) && method_exists($task, 'log')) {
                $task->log('KanPro lembrete 8h p/ ' . implode(',', self::lembreteRecipients()) . ': ' . (!empty($r['ok']) ? ('enviado (total ' . ($r['total'] ?? '?') . ' p/ ' . ($r['phone'] ?? '?') . ')') : ('não enviado: ' . ($r['error'] ?? ''))));
            }
        } catch (Throwable $e) { return 1; }
        return 1;
    }

    public static function cronZaplembrete10($task = null): int {
        try {
            $r = self::sendLembrete(10);
            if (is_object($task) && method_exists($task, 'log')) {
                $task->log('KanPro lembrete 10h p/ ' . implode(',', self::lembreteRecipients()) . ': ' . (!empty($r['ok']) ? ('enviado (total ' . ($r['total'] ?? '?') . ' p/ ' . ($r['phone'] ?? '?') . ')') : ('não enviado: ' . ($r['error'] ?? ''))));
            }
        } catch (Throwable $e) { return 1; }
        return 1;
    }

    /** @deprecated turno das 9h foi substituído por 8h/10h (mantido p/ não quebrar tarefa antiga residual) */
    public static function cronZaplembrete9($task = null): int {
        try {
            if (is_object($task) && method_exists($task, 'log')) {
                $task->log('KanPro lembrete 9h desativado (substituído por 8h/10h)');
            }
        } catch (Throwable $e) {}
        return 1;
    }

    public static function cronZaplembrete13($task = null): int {
        try {
            $r = self::sendLembrete(13);
            if (is_object($task) && method_exists($task, 'log')) {
                $task->log('KanPro lembrete 13h p/ ' . implode(',', self::lembreteRecipients()) . ': ' . (!empty($r['ok']) ? ('enviado (total ' . ($r['total'] ?? '?') . ' p/ ' . ($r['phone'] ?? '?') . ')') : ('não enviado: ' . ($r['error'] ?? ''))));
            }
        } catch (Throwable $e) { return 1; }
        return 1;
    }

    // ---------- CRON diário: atraso a cada 5 dias em Retirada ----------
    public static function cronInfo(): array {
        return [
            'description' => 'WhatsApp de atraso: cards em Retirada recebem lembrete a cada 5 dias',
            'parameter'   => 'Máximo de cards por execução',
        ];
    }

    /**
     * Estado da transferência do card: null (sem termo), 'retirada' (aguardando
     * assinatura/retirada) ou 'concluido' (assinado = já buscou).
     * Retorna ['state'=>?, 'days'=>dias em retirada, 'date'=>data].
     */
    static function cardTransferState(int $cards_id): array {
        global $DB;
        $none = ['state' => null, 'days' => 0, 'date' => null];
        try {
            if (!$DB->tableExists('glpi_plugin_assetmgrstatus_transfers')) return $none;
            $like = "%[KanPro #{$cards_id}]%";
            $tr = $DB->request(['FROM' => 'glpi_plugin_assetmgrstatus_transfers', 'WHERE' => ['reason' => ['LIKE', $like]], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
            if (!$tr) return $none;
            $signed = !empty($tr['assinatura_image']) && !empty($tr['assinatura_tecnico_image']);
            if ($signed) return ['state' => 'concluido', 'days' => 0, 'date' => null];
            $ref = (string)($tr['date_pronto'] ?? $tr['date_creation'] ?? '');
            if ($ref === '' || $ref === '0000-00-00 00:00:00') return ['state' => 'retirada', 'days' => 0, 'date' => null];
            try {
                $days = (int)floor((time() - (new DateTime($ref))->getTimestamp()) / 86400);
            } catch (Throwable $e) { return $none; }
            return ['state' => 'retirada', 'days' => max(0, $days), 'date' => $ref];
        } catch (Throwable $e) { return $none; }
    }

    /** Data do último atraso enviado com sucesso (null = nunca) */
    static function lastAtrasoDate(int $cards_id): ?string {
        global $DB;
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_zaplog')) return null;
            $row = $DB->request([
                'SELECT' => ['MAX' => 'date_creation AS m'],
                'FROM'   => 'glpi_plugin_kanpro_maintenance_zaplog',
                'WHERE'  => ['plugin_kanpro_cards_id' => $cards_id, 'success' => 1, 'milestone' => ['LIKE', 'atraso%']],
            ])->current();
            $m = (string)($row['m'] ?? '');
            return ($m !== '' && $m !== '0000-00-00 00:00:00') ? $m : null;
        } catch (Throwable $e) { return null; }
    }

    public static function cronZapatraso($task = null): int {
        global $DB;
        $limit = 50;
        try {
            if (is_object($task) && isset($task->fields['param'])) {
                $limit = max(1, (int)$task->fields['param']);
            }
        } catch (Throwable $e) {}
        $sent = 0;
        $fail = 0;
        $queued = 0;
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) return 0;
            $iter = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_plugin_kanpro_cards',
                'WHERE'  => ['is_maintenance' => 1, 'is_archived' => 0],
                'ORDER'  => 'id ASC',
                'LIMIT'  => 500,
            ]);
            foreach ($iter as $c) {
                if ($sent + $fail >= $limit) break;
                $cid = (int)$c['id'];
                // só cobra quem está em Retirada (com termo, sem assinatura)
                $tst = self::cardTransferState($cid);
                if (($tst['state'] ?? null) !== 'retirada') continue;
                if (($tst['days'] ?? 0) < 5) continue; // carência: 5 dias em retirada
                // reenvia a cada 5 dias
                $last = self::lastAtrasoDate($cid);
                if ($last !== null) {
                    try {
                        $gap = (int)floor((time() - (new DateTime($last))->getTimestamp()) / 86400);
                    } catch (Throwable $e) { continue; }
                    if ($gap < 5) continue;
                }
                $r = self::send('atraso', $cid, ['dias' => (string)$tst['days']], 'atraso_' . date('Y-m-d'));
                if (!empty($r['queued'])) $queued++;
                elseif (!empty($r['ok'])) $sent++;
                elseif (!in_array($r['error'] ?? '', ['duplicate', 'sem telefone'], true)) $fail++;
            }
        } catch (Throwable $e) {
            return 1;
        }
        try {
            if (is_object($task) && method_exists($task, 'log')) {
                $task->log("KanPro Zap atraso: {$sent} enviados, {$queued} na fila, {$fail} falhas");
            }
        } catch (Throwable $e) {}
        return 1;
    }

    public static function registerCron(): void {
        global $DB;
        if (!$DB->tableExists('glpi_crontasks')) return;
        try {
            $upsert = function (string $name, array $fields) use ($DB) {
                $found = null;
                foreach ($DB->request(['FROM' => 'glpi_crontasks', 'WHERE' => ['itemtype' => 'PluginKanproMaintenanceZap', 'name' => $name], 'LIMIT' => 1]) as $r) {
                    $found = $r;
                    break;
                }
                if (!$found) {
                    $DB->insert('glpi_crontasks', array_merge([
                        'itemtype' => 'PluginKanproMaintenanceZap',
                        'name'     => $name,
                    ], $fields));
                } else {
                    // atualiza tarefa existente (corrige instalações antigas: modo/horário/frequência)
                    $DB->update('glpi_crontasks', $fields, ['id' => (int)$found['id']]);
                }
            };
            $upsert('zapatraso', [
                'frequency'     => 86400, // 1x ao dia
                'param'         => 50,
                'state'         => 1, // ativada (antes ficava desativada em alguns installs)
                'mode'          => 1, // MODE_INTERNAL: roda no acesso às páginas (externo raramente configurado)
                'allowmode'     => 3, // permite interno + externo
                'logs_lifetime' => 30,
                'hourmin'       => 0,
                'hourmax'       => 24,
                'comment'       => 'KanPro: WhatsApp de atraso (lembrete a cada 5 dias em Retirada)',
            ]);
            // lembretes 8h, 10h e 13h: Aguardando aprovação + Pendente + Em Andamento (só envia se > 0)
            // frequency 1800 (30min): permite nova tentativa ainda na mesma hora.
            // ATENÇÃO: hourmax é EXCLUSIVO no GLPI (roda se hourmin <= H < hourmax).
            // Com hourmin==hourmax a tarefa NUNCA entra na janela e jamais executa. Janela de 1h.
            // mode EXTERNO: dispara via cron do SO (php front/cron.php) — garante o envio
            // mesmo sem ninguém usando o sistema. O fallback do polling continua como rede extra.
            foreach ([['zaplembrete8', 8, 9, 'KanPro: WhatsApp lembrete 8h (Aguard.aprovação + Pendente + Andamento)'], ['zaplembrete10', 10, 11, 'KanPro: WhatsApp lembrete 10h (Aguard.aprovação + Pendente + Andamento)'], ['zaplembrete13', 13, 14, 'KanPro: WhatsApp lembrete 13h (Aguard.aprovação + Pendente + Andamento)']] as [$cname, $chour, $hmax, $cmt]) {
                $upsert($cname, [
                    'frequency'     => 1800,
                    'param'         => $chour,
                    'state'         => 1,
                    'mode'          => 2, // MODE_EXTERNAL: exige cron do SO — ver README do plugin
                    'allowmode'     => 3,
                    'logs_lifetime' => 30,
                    'hourmin'       => $chour,
                    'hourmax'       => $hmax,
                    'comment'       => $cmt,
                ]);
            }
            // limpa tarefa antiga do turno das 9h (substituído por 8h/10h)
            try {
                $DB->delete('glpi_crontasks', ['itemtype' => 'PluginKanproMaintenanceZap', 'name' => 'zaplembrete9']);
            } catch (Throwable $e) {}
            // consumidor da fila de envios: roda no acesso às páginas (modo interno),
            // a cada 5min, em lotes (param). Entrega os enfileirados com retry.
            $upsert('zapqueue', [
                'frequency'     => 300,
                'param'         => 20,
                'state'         => 1,
                'mode'          => 1, // MODE_INTERNAL: roda no acesso às páginas
                'allowmode'     => 3,
                'logs_lifetime' => 30,
                'hourmin'       => 0,
                'hourmax'       => 24,
                'comment'       => 'KanPro: consome a fila de WhatsApp (retry com backoff)',
            ]);
        } catch (Throwable $e) {
            error_log('[KanPro] registerCron zap: ' . $e->getMessage());
        }
    }

    public static function unregisterCron(): void {
        global $DB;
        try {
            if ($DB->tableExists('glpi_crontasks')) {
                $DB->delete('glpi_crontasks', ['itemtype' => 'PluginKanproMaintenanceZap', 'name' => ['zapatraso', 'zaplembrete8', 'zaplembrete9', 'zaplembrete10', 'zaplembrete13', 'zapqueue']]);
            }
        } catch (Throwable $e) {}
    }
}
