<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

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
 *   atraso    cron diário PluginKanproMaintenanceZap::cronZapatraso — 7/14/30 dias
 *   cancelado revert_maintenance
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
        return ['entrada', 'retirada', 'atraso', 'cancelado'];
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
            $cfg = self::evoConfig();
            if (!$cfg) return ['ok' => false, 'error' => 'EvolutionAPI não configurada (whatsappsimples)'];
            $endpoint = rtrim($cfg['server_url'], '/') . '/message/sendText/' . $cfg['instance_name'];
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $cfg['api_token']],
                CURLOPT_POSTFIELDS     => json_encode(['number' => $phone, 'text' => $text, 'textMessage' => ['text' => $text]], JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT        => $timeout,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($resp === false) return ['ok' => false, 'error' => 'cURL: ' . $err];
            if ($code >= 200 && $code < 300) return ['ok' => true];
            return ['ok' => false, 'error' => "HTTP {$code}: " . mb_substr((string)$resp, 0, 300)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
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
     * Fluxo completo: monta dados, resolve fone, renderiza, envia, registra.
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
            $res = self::evoSend($phone, $txt, $timeout);
            self::markSent($cards_id, $milestone, $phone, (bool)$res['ok'], (string)($res['error'] ?? ''));
            self::logCard($cards_id, $res['ok']
                ? "WhatsApp {$type} enviado para {$phone}"
                : "WhatsApp {$type} FALHOU para {$phone}: " . ($res['error'] ?? ''));
            return $res + ['phone' => $phone];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Atalho com trava de duplicado (entrada/retirada/cancelado) */
    static function sendOnce(string $type, int $cards_id, array $extra = [], ?string $phone = null, int $timeout = 8): array {
        return self::send($type, $cards_id, $extra, $type, $phone, $timeout);
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

    // ---------- CRON diário: atraso 7/14/30 dias ----------
    public static function cronInfo(): array {
        return [
            'description' => 'WhatsApp de atraso da manutenção (7/14/30 dias)',
            'parameter'   => 'Máximo de cards por execução',
        ];
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
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) return 0;
            $iter = $DB->request([
                'SELECT' => ['id', 'name', 'maintenance_date', 'date_creation'],
                'FROM'   => 'glpi_plugin_kanpro_cards',
                'WHERE'  => ['is_maintenance' => 1, 'is_archived' => 0],
                'ORDER'  => 'maintenance_date ASC',
                'LIMIT'  => 500,
            ]);
            foreach ($iter as $c) {
                if ($sent + $fail >= $limit) break;
                $cid = (int)$c['id'];
                $ref = (string)($c['maintenance_date'] ?? $c['date_creation'] ?? '');
                if ($ref === '' || $ref === '0000-00-00 00:00:00') continue;
                try {
                    $days = (int)floor((time() - (new DateTime($ref))->getTimestamp()) / 86400);
                } catch (Throwable $e) { continue; }
                $ms = null;
                $msDays = 0;
                if ($days >= 30) { $ms = 'atraso_30'; $msDays = 30; }
                elseif ($days >= 14) { $ms = 'atraso_14'; $msDays = 14; }
                elseif ($days >= 7) { $ms = 'atraso_7'; $msDays = 7; }
                if ($ms === null || self::alreadySent($cid, $ms)) continue;
                $r = self::send('atraso', $cid, ['dias' => (string)$msDays], $ms);
                if (!empty($r['ok'])) $sent++;
                else {
                    // 'duplicate' e 'sem telefone' não são falha transitória p/ contagem
                    if (!in_array($r['error'] ?? '', ['duplicate', 'sem telefone'], true)) $fail++;
                }
            }
        } catch (Throwable $e) {
            return 1;
        }
        try {
            if (is_object($task) && method_exists($task, 'log')) {
                $task->log("KanPro Zap atraso: {$sent} enviados, {$fail} falhas");
            }
        } catch (Throwable $e) {}
        return 1;
    }

    public static function registerCron(): void {
        global $DB;
        if (!$DB->tableExists('glpi_crontasks')) return;
        try {
            $found = false;
            foreach ($DB->request(['FROM' => 'glpi_crontasks', 'WHERE' => ['itemtype' => 'PluginKanproMaintenanceZap', 'name' => 'zapatraso']]) as $r) {
                $found = true;
                break;
            }
            if (!$found) {
                $DB->insert('glpi_crontasks', [
                    'itemtype'      => 'PluginKanproMaintenanceZap',
                    'name'          => 'zapatraso',
                    'frequency'     => 86400, // 1x ao dia
                    'param'         => 50,
                    'state'         => 1,
                    'mode'          => 2, // MODE_EXTERNAL
                    'allowmode'     => 3,
                    'logs_lifetime' => 30,
                    'hourmin'       => 0,
                    'hourmax'       => 24,
                    'comment'       => 'KanPro: WhatsApp de atraso da manutenção (7/14/30 dias)',
                ]);
            }
        } catch (Throwable $e) {
            error_log('[KanPro] registerCron zap: ' . $e->getMessage());
        }
    }

    public static function unregisterCron(): void {
        global $DB;
        try {
            if ($DB->tableExists('glpi_crontasks')) {
                $DB->delete('glpi_crontasks', ['itemtype' => 'PluginKanproMaintenanceZap', 'name' => 'zapatraso']);
            }
        } catch (Throwable $e) {}
    }
}
