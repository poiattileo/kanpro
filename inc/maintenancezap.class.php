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
 *   atraso    cron diário: card em Retirada recebe lembrete a cada 5 dias
 *   cancelado revert_maintenance
 *   pendencia request_chamado / pegar_pending_card — 1 msg por card novo em Pendência Chamado
 *             (destinatário fixo: fone do usuário cristian.sawata@educacao.sp.gov.br)
 *   liberado  confirm_chamado_created + 25s — 1 msg por técnico membro da origem
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
        return ['entrada', 'retirada', 'atraso', 'cancelado', 'pendencia', 'liberado', 'lembrete'];
    }

    /** Login/e-mail do aprovador fixo da Pendência Chamado */
    static function pendenciaApprover(): string {
        return 'cristian.sawata@educacao.sp.gov.br';
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

    /** Telefone do aprovador fixo (aceita login OU e-mail cadastrado). '' = ausente/inválido */
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
            $res = self::evoSend($phone, $txt, 20);
            self::markSent($pendenciaId, 'pendencia', $phone, (bool)$res['ok'], (string)($res['error'] ?? ''));
            self::logCard($pendenciaId, $res['ok']
                ? "WhatsApp pendencia enviado para {$phone} (aprovador)"
                : "WhatsApp pendencia FALHOU para {$phone}: " . ($res['error'] ?? ''));
            return $res + ['phone' => $phone];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Aviso de liberado: 1 msg por técnico membro da origem, 25s após Chamado criado.
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
            // máquinas liberadas (as da solicitação)
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
            $sent = 0; $skipped = 0; $errors = [];
            foreach (array_keys($uids) as $uid) {
                $ms = 'liberado_' . (int)$uid;
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
                    'quantidade'     => (string)count($mids),
                    'maquinas'       => $lines ? implode("\n", $lines) : '(sem máquinas vinculadas)',
                    'solicitado_por' => $solNome !== '' ? $solNome : '—',
                    'data'           => date('d/m/Y H:i'),
                ];
                $txt = self::renderTxt('liberado', $data);
                if ($txt === null || $txt === '') {
                    self::markSent($pendenciaId, $ms, $phone, false, 'template vazio');
                    $errors[] = 'template vazio';
                    continue;
                }
                $res = self::evoSend($phone, $txt, 20);
                self::markSent($pendenciaId, $ms, $phone, (bool)$res['ok'], (string)($res['error'] ?? ''));
                if (!empty($res['ok'])) $sent++;
                else $errors[] = (string)($res['error'] ?? 'falha');
            }
            if ($sent > 0) {
                self::logCard($pendenciaId, "WhatsApp liberado enviado para {$sent} técnico(s)");
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

    /**
     * Lembrete 08:30/13h: quantos cards há nas listas Pendência Chamado, Pendente e
     * Em Andamento (todos os quadros ativos). Só envia se total > 0.
     * Anti-duplicado por dia+turno (milestone lembrete_Y-m-d_08 / _13).
     * Destinatário fixo = aprovador (cristian.sawata@educacao.sp.gov.br).
     * Nunca joga exceção.
     */
    static function sendLembrete(?int $slot = null, array $opts = []): array {
        global $DB;
        try {
            $hour = (int)date('H');
            if ($slot === null || !in_array($slot, [8, 13], true)) $slot = ($hour < 12) ? 8 : 13;
            $forceResend = !empty($opts['forceResend']);
            $milestone = 'lembrete_' . date('Y-m-d') . '_' . str_pad((string)$slot, 2, '0', STR_PAD_LEFT);
            if (!$forceResend && self::alreadySent(0, $milestone)) return ['ok' => false, 'error' => 'duplicate (já enviado hoje neste turno)'];
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
            $phone = self::resolveApproverPhone();
            $phone = self::normalizeBRPhone((string)$phone);
            if ($phone === '') {
                self::markSent(0, $milestone, '', false, 'sem telefone do aprovador');
                return ['ok' => false, 'error' => 'sem telefone do aprovador (' . self::pendenciaApprover() . ' sem phone/mobile válido no GLPI)'];
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
                self::markSent(0, $milestone, $phone, false, 'template vazio');
                return ['ok' => false, 'error' => 'template vazio'];
            }
            $res = self::evoSend($phone, $txt, 20);
            self::markSent(0, $milestone, $phone, (bool)$res['ok'], (string)($res['error'] ?? ''));
            return $res + ['phone' => $phone, 'total' => $total];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Diagnóstico sem enviar: listas encontradas, contagens, telefone (mascarado),
     * Evolution configurada?, template ok?, milestones de hoje. P/ o teste manual.
     * Nunca envia nada, nunca grava zaplog.
     */
    static function diagnoseLembrete(): array {
        global $DB;
        $out = ['now' => date('d/m/Y H:i:s'), 'approver' => self::pendenciaApprover()];
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
            // telefone do aprovador (mascarado)
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
            // milestones de hoje
            foreach ([8, 13] as $s) {
                $ms = 'lembrete_' . date('Y-m-d') . '_' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
                $out['milestone_' . $s] = ['name' => $ms, 'already_sent' => self::alreadySent(0, $ms)];
            }
            // crontasks
            try {
                foreach ($DB->request(['SELECT' => ['name', 'state', 'mode', 'frequency', 'hourmin', 'hourmax', 'lastrun'], 'FROM' => 'glpi_crontasks', 'WHERE' => ['itemtype' => 'PluginKanproMaintenanceZap', 'name' => ['zaplembrete8', 'zaplembrete13']]]) as $t) {
                    $out['cron_' . $t['name']] = $t;
                }
            } catch (Throwable $e) {}
        } catch (Throwable $e) { $out['error'] = $e->getMessage(); }
        return $out;
    }

    public static function cronZaplembrete8($task = null): int {
        try {
            // Slot das 08:30: GLPI só tem janela por hora, então segura o envio até 08:30.
            // (frequency 1800 permite nova tentativa ainda dentro da hora.)
            if (strcmp(date('H:i'), '08:30') < 0) {
                if (is_object($task) && method_exists($task, 'log')) {
                    $task->log('KanPro lembrete 08:30: aguardando janela (agora ' . date('H:i') . ')');
                }
                return 1;
            }
            $r = self::sendLembrete(8);
            if (is_object($task) && method_exists($task, 'log')) {
                $task->log('KanPro lembrete 08:30 p/ ' . self::pendenciaApprover() . ': ' . (!empty($r['ok']) ? ('enviado (total ' . ($r['total'] ?? '?') . ' p/ ' . ($r['phone'] ?? '?') . ')') : ('não enviado: ' . ($r['error'] ?? ''))));
            }
        } catch (Throwable $e) { return 1; }
        return 1;
    }

    public static function cronZaplembrete13($task = null): int {
        try {
            $r = self::sendLembrete(13);
            if (is_object($task) && method_exists($task, 'log')) {
                $task->log('KanPro lembrete 13h p/ ' . self::pendenciaApprover() . ': ' . (!empty($r['ok']) ? ('enviado (total ' . ($r['total'] ?? '?') . ' p/ ' . ($r['phone'] ?? '?') . ')') : ('não enviado: ' . ($r['error'] ?? ''))));
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
                if (!empty($r['ok'])) $sent++;
                elseif (!in_array($r['error'] ?? '', ['duplicate', 'sem telefone'], true)) $fail++;
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
            // lembretes 08:30 e 13h: Pendência Chamado + Pendente + Em Andamento (só envia se > 0)
            // frequency 1800 (30min): permite nova tentativa ainda na mesma hora p/ o slot 08:30
            // (GLPI só tem janela por hora; o PHP segura o envio até 08:30 — sem retry perderia o dia).
            foreach ([['zaplembrete8', 8, 'KanPro: WhatsApp lembrete 08:30 (Pend.Chamado + Pendente + Andamento)'], ['zaplembrete13', 13, 'KanPro: WhatsApp lembrete 13h (Pend.Chamado + Pendente + Andamento)']] as [$cname, $chour, $cmt]) {
                $upsert($cname, [
                    'frequency'     => 1800,
                    'param'         => $chour,
                    'state'         => 1,
                    'mode'          => 1, // MODE_INTERNAL (antes MODE_EXTERNAL nunca rodava sem cron do SO)
                    'allowmode'     => 3,
                    'logs_lifetime' => 30,
                    'hourmin'       => $chour,
                    'hourmax'       => $chour,
                    'comment'       => $cmt,
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
                $DB->delete('glpi_crontasks', ['itemtype' => 'PluginKanproMaintenanceZap', 'name' => ['zapatraso', 'zaplembrete8', 'zaplembrete13']]);
            }
        } catch (Throwable $e) {}
    }
}
