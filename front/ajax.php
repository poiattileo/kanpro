<?php
if (function_exists('opcache_invalidate')) @opcache_invalidate(__FILE__, true);
include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/kanpro/inc/acting.php');
@ob_clean();
header('Content-Type: application/json; charset=UTF-8');
// debug log para 403
$__dbg = sprintf("[%s] UID=%s IP=%s action=%s profile=%s haveREAD=%d haveCREATE=%d haveUPDATE=%d SESSION=%s\n",
    date('Y-m-d H:i:s'),
    Session::getLoginUserID() ?: '0',
    $_SERVER['REMOTE_ADDR'] ?? '-',
    $_REQUEST['action'] ?? '-',
    json_encode($_SESSION['glpiactiveprofile']['id'] ?? null),
    (int)Session::haveRight('plugin_kanpro', READ),
    (int)Session::haveRight('plugin_kanpro', CREATE),
    (int)Session::haveRight('plugin_kanpro', UPDATE),
    json_encode($_SESSION['glpiactiveprofile']['plugin_kanpro'] ?? 'null')
);
if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => 'Não autenticado', 'debug' => $__dbg]);
    exit;
}
if (!Session::haveRight('plugin_kanpro', READ)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Sem permissão (plugin_kanpro READ) - verifique Perfil > KanPro', 'debug' => $__dbg, 'have' => $_SESSION['glpiactiveprofile']['plugin_kanpro'] ?? 0]);
    exit;
}

// CSRF: copia header X-Glpi-Csrf-Token p/ $_POST (GLPI 11 valida via header no listener,
// mas checkCSRF() explícito lê de $_POST). Sem isso o 2º clique falhava.
if (function_exists('kanpro_csrf_bridge')) kanpro_csrf_bridge();

$action = $_REQUEST['action'] ?? '';
global $DB, $CFG_GLPI;

function jexit($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function needEdit() {
    if (!Session::haveRight('plugin_kanpro', UPDATE) && !Session::haveRight('plugin_kanpro', CREATE)) {
        $have = $_SESSION['glpiactiveprofile']['plugin_kanpro'] ?? 0;
        $dbg = json_encode(['profile_id'=>$_SESSION['glpiactiveprofile']['id']??null,'have'=>$have,'haveREAD'=>Session::haveRight('plugin_kanpro',READ),'haveCREATE'=>Session::haveRight('plugin_kanpro',CREATE),'haveUPDATE'=>Session::haveRight('plugin_kanpro',UPDATE)]);
        jexit(['success'=>false,'msg'=>"Sem permissão (precisa CREATE ou UPDATE). Seu nível atual: {$have}. Faça logout/login.", 'debug'=>$dbg]);
    }
}
// Mutação exige POST (bloqueia CSRF via <img GET>) + CSRF explícito com token preservado
// (preserve=true p/ não consumir o token da página — AJAX reusa o mesmo token N vezes).
// Leitura (kanpro_readonly_actions) usa só framework + gate por quadro abaixo.
if (!function_exists('kanpro_ajax_guard')) {
function kanpro_ajax_guard(string $action): void {
    try {
        $readonly = function_exists('kanpro_readonly_actions') ? kanpro_readonly_actions() : [];
        if (in_array($action, $readonly, true) || $action === '') return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            jexit(['success'=>false,'msg'=>'Método não permitido (use POST)']);
        }
        // Se o framework já validou via header, o bridge copiou p/ $_POST. Valida sem consumir.
        try {
            // GLPI 11: checkCSRF($data, $preserve=true). GLPI 10: checkCSRF($data) — extra arg é ignorado.
            Session::checkCSRF($_POST, true);
        } catch (Throwable $e) {
            http_response_code(403);
            jexit(['success'=>false,'msg'=>'Sessão expirada ou token inválido — recarregue a página (F5).','csrf'=>true]);
        }
    } catch (Throwable $e) {
        // jexit acima já saiu; qualquer outro erro aqui não pode vazar HTML
        if (!headers_sent()) http_response_code(403);
        jexit(['success'=>false,'msg'=>'Falha de segurança (CSRF). Recarregue a página.']);
    }
}
}
kanpro_ajax_guard($action);
// Gate por quadro: resolve boards_id de qualquer param (boards_id/lists_id/cards_id) e exige view.
// Edição (needEdit + view) é checada nos cases via kanpro_require_board_edit().
if (!function_exists('kanpro_ajax_board_id')) {
function kanpro_ajax_board_id(): int {
    $bid = (int)($_REQUEST['boards_id'] ?? 0);
    if ($bid > 0) return $bid;
    $lid = (int)($_REQUEST['lists_id'] ?? $_REQUEST['plugin_kanpro_lists_id'] ?? 0);
    if ($lid > 0 && function_exists('kanpro_board_id_for_list')) {
        $b = kanpro_board_id_for_list($lid);
        if ($b > 0) return $b;
    }
    $cid = (int)($_REQUEST['cards_id'] ?? $_REQUEST['plugin_kanpro_cards_id'] ?? $_REQUEST['id'] ?? 0);
    // 'id' só vale como card em actions de card — heurística segura: tenta resolver, se não for card dá 0
    if ($cid > 0 && function_exists('kanpro_board_id_for_card')) {
        $b = kanpro_board_id_for_card($cid);
        if ($b > 0) return $b;
    }
    return 0;
}
}
if (!function_exists('kanpro_require_board_view')) {
function kanpro_require_board_view(int $bid): void {
    if ($bid <= 0) return; // actions globais (busca global, grupos pessoais) não têm quadro
    if (!function_exists('kanpro_can_view_board') || !kanpro_can_view_board($bid)) {
        http_response_code(403);
        jexit(['success'=>false,'msg'=>'Sem acesso a este quadro.']);
    }
}
}
if (!function_exists('kanpro_require_board_edit')) {
function kanpro_require_board_edit(int $bid): void {
    needEdit();
    kanpro_require_board_view($bid);
}
}
// Aplica view-gate precoce p/ leitura sensível (snapshot/stamp/activity/history) — mutação checa no case.
if (in_array($action, ['get_board_stamp','get_board_snapshot','get_board_activity','get_history','get_board_members','presence_heartbeat'], true)) {
    kanpro_require_board_view(kanpro_ajax_board_id());
}
// Normaliza texto para busca sem acentos (case + accent insensitive): "café" == "cafe"
function kanpro_norm_text($s) {
    $s = (string)($s ?? '');
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_D);
        if ($n !== false) $s = $n;
        $s = preg_replace('/\p{Mn}/u', '', $s);
    }
    if (function_exists('mb_strtolower')) $s = mb_strtolower($s, 'UTF-8');
    else $s = strtolower($s);
    // fallback p/ ambiente sem intl: troca manual dos mais comuns
    $s = strtr($s, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ă'=>'a','ą'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','ę'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ć'=>'c','č'=>'c','ñ'=>'n','ń'=>'n','ý'=>'y','ÿ'=>'y',
        'ß'=>'ss','æ'=>'ae','œ'=>'oe',
    ]);
    return $s;
}

// ---------- Helpers Membros do Quadro ----------
// kanpro_my_board_role(), kanpro_is_board_creator() e kanpro_can_manage_members()
// moram em inc/acting.php (lib compartilhada com form/kanban/gear).
// Perfis GLPI vinculados ao usuário (todas as entidades — vale sessão e pessoa)
// NB: helpers de visibilidade (kanpro_my_profile_ids, kanpro_board_profile_role,
// kanpro_can_view_board, kanpro_board_is_restricted, kanpro_groups_owner_id)
// moram em inc/acting.php p/ valer também em board.php, kanban.php e attachment.php.
// Lista todos os perfis GLPI (p/ o seletor "adicionar por perfil")
function kanpro_all_profiles() {
    global $DB;
    $out = [];
    try {
        if (!$DB->tableExists('glpi_profiles')) return [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $r) {
            $out[] = ['id' => (int)$r['id'], 'name' => (string)($r['name'] ?? ('Perfil #' . $r['id']))];
        }
    } catch (Throwable $e) {}
    return $out;
}
function kanpro_need_manage_members($bid) {
    if (!kanpro_can_manage_members($bid)) {
        jexit(['success'=>false,'msg'=>'Somente o criador ou administradores do quadro podem gerenciar o acesso.']);
    }
}
// Conta outros gestores (criador ou admins) além de $excludeUid — evita lockout.
function kanpro_count_other_managers($bid, $excludeUid) {
    global $DB;
    $count = 0;
    $b = new PluginKanproBoard();
    if ($b->getFromDB($bid) && (int)($b->fields['users_id'] ?? 0) !== (int)$excludeUid && (int)($b->fields['users_id'] ?? 0) > 0) {
        $count++;
    }
    $admins = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $bid, 'role' => 'admin']]);
    foreach ($admins as $a) {
        if ((int)$a['users_id'] !== (int)$excludeUid) $count++;
    }
    return $count;
}
function kanpro_user_brief($uid) {
    $u = new User();
    $name = 'Usuário #' . $uid;
    $initials = '?';
    $login = '';
    if ($u->getFromDB($uid)) {
        $name = $u->getFriendlyName();
        $login = $u->fields['name'] ?? '';
        $initials = strtoupper(substr($u->fields['firstname'] ?? $u->fields['name'] ?? '?', 0, 1) . substr($u->fields['realname'] ?? '', 0, 1));
        if (trim($initials) === '') $initials = strtoupper(substr($name, 0, 2));
    }
    return ['users_id' => (int)$uid, 'name' => $name, 'login' => $login, 'initials' => $initials];
}
// Migração runtime das novidades (fixar, aprovação, etiqueta com prazo, lixeira) — sem reinstalar.
// DEPRECATED: schema canônico em hook.php. Mantido como wrapper barato (1x por request) p/ git-update sem reinstall.
function kanpro_ensure_board_extras() {
    static $doneTrash = false;
    kanpro_migrate_schema_once();
    if ($doneTrash) return;
    $doneTrash = true;
    global $DB;
    try {
        if (!$DB->tableExists('glpi_plugin_kanpro_trash')) {
            $charset = DBConnection::getDefaultCharset();
            $collation = DBConnection::getDefaultCollation();
            $sign = DBConnection::getDefaultPrimaryKeySignOption();
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_kanpro_trash` (
                    `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                    `plugin_kanpro_boards_id`     INT {$sign} NOT NULL DEFAULT '0',
                    `plugin_kanpro_lists_id`      INT {$sign} NOT NULL DEFAULT '0',
                    `list_name`                   VARCHAR(255) NOT NULL DEFAULT '',
                    `card_name`                   VARCHAR(255) NOT NULL DEFAULT '',
                    `snapshot`                    LONGTEXT     DEFAULT NULL,
                    `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                    `date_creation`               DATETIME     DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `plugin_kanpro_boards_id` (`plugin_kanpro_boards_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }
    } catch (Throwable $e) {
        error_log('[KanPro] ' . "KanPro ensure_board_extras: " . $e->getMessage());
    }
}

// ---------- Helpers Manutenção ----------
// Etiqueta roxa "Inventário": presente no cartão enquanto houver >=1 máquina que precisa inventariar.
function kanpro_sync_inventory_label($cards_id) {
    global $DB;
    $cards_id = (int)$cards_id;
    if (!$cards_id) return;
    $card = new PluginKanproCard();
    if (!$card->getFromDB($cards_id)) return;
    $bid = (int)$card->fields['plugin_kanpro_boards_id'];
    $needs = countElementsInTable('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cards_id, 'needs_inventory'=>1]);
    // acha ou cria a etiqueta roxa do quadro
    $lab = $DB->request(['FROM'=>'glpi_plugin_kanpro_labels','WHERE'=>['plugin_kanpro_boards_id'=>$bid,'name'=>'Inventário'],'LIMIT'=>1])->current();
    if ($needs > 0) {
        if (!$lab) {
            $nl = new PluginKanproLabel();
            $labId = $nl->add(['plugin_kanpro_boards_id'=>$bid,'name'=>'Inventário','color'=>'#6554c0']);
        } else {
            $labId = (int)$lab['id'];
        }
        if ($labId && !countElementsInTable('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cards_id,'plugin_kanpro_labels_id'=>$labId])) {
            $DB->insert('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cards_id,'plugin_kanpro_labels_id'=>$labId]);
        }
    } elseif ($lab) {
        $DB->delete('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cards_id,'plugin_kanpro_labels_id'=>(int)$lab['id']]);
    }
}
function kanpro_verify_password($input) {
    global $DB;
    $uid = Session::getLoginUserID();
    if (!$uid || $input === '' || $input === null) return false;
    $row = $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $uid]])->current();
    if (!$row) return false;
    $hash = $row['password'] ?? '';
    if (!$hash) return false;
    if (class_exists('Auth') && method_exists('Auth', 'checkPassword')) {
        try {
            if (Auth::checkPassword($input, $hash)) return true;
        } catch (Throwable $e) {}
    }
    if (function_exists('password_verify') && password_verify($input, $hash)) return true;
    if (md5($input) === $hash) return true;
    // legacy GLPI sha1 with salt? try GLPI 9 style: sha1 with maybe prefix
    return false;
}

function kanpro_normalize_confirm($t) {
    $t = trim($t ?? '');
    $t = mb_strtoupper($t, 'UTF-8');
    // remove accents
    $map = ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C'];
    $t = strtr($t, $map);
    return $t;
}

// Palavras-desafio aceitas na confirmação de Manutenção (mesma lista do MAINT_CHALLENGE_WORDS do JS).
function kanpro_maint_challenge_words(): array {
    return ["PAIVA","MASSON","FERRARI","MORANGO","SAWATA","TECNICO","SUPORTE","MANUTENCAO","REPARO","DIAGNOSTICO","HARDWARE","SOFTWARE","NOTEBOOK","DESKTOP","MONITOR","TECLADO","MOUSE","IMPRESSORA","REDE","SERVIDOR","BACKUP","SEGURANCA","ATUALIZACAO","LIMPEZA","FORMATACAO","INSTALACAO","CONFIGURACAO","ATENDIMENTO","CHAMADO","TICKET","PROTOCOLO","SISTEMA","PROCESSADOR","MEMORIA","SSD","HD","PLACA","FONTE","COOLER","GABINETE","BATERIA","CARREGADOR","CABO","CONECTOR","DRIVER","FIRMWARE","BIOS","WINDOWS","LINUX","OFFICE","ANTIVIRUS","FIREWALL","VPN","WIFI","ETHERNET","SWITCH","ROTEADOR","PATCH","CABEAMENTO","ESTRUTURADO","VOIP","TELEFONIA","RAMAL","NOBREAK","ESTABILIZADOR","PROJETOR","WEBCAM","HEADSET","SCANNER","PLOTTER","TABLET","CELULAR","SMARTPHONE","CHIP","BROWSER","NAVEGADOR","EMAIL","SENHA","LOGIN","USUARIO","PERFIL","PERMISSAO","BANCO","DADOS","RELATORIO","INVENTARIO","PATRIMONIO","ATIVO","GARANTIA","CONTRATO","FORNECEDOR","CLIENTE","DEPARTAMENTO","SETOR","ALMOXARIFADO","ESTOQUE","COMPRA","LICENCA","ATIVACAO","VALIDACAO","AUTENTICACAO","CONFIRMACAO"];
}
function kanpro_maint_challenge_ok(string $confirm): bool {
    static $norm_allowed = null;
    if ($norm_allowed === null) {
        $norm_allowed = array_map('kanpro_normalize_confirm', kanpro_maint_challenge_words());
    }
    return in_array(kanpro_normalize_confirm($confirm), $norm_allowed, true);
}

// Nome do cartão de Manutenção = nome da entidade (só o último nível do completename).
// Usado tanto na conversão de um card existente quanto na criação direta na lista Pendente.
function kanpro_maint_name_from_entity(int $entities_id, string $typed = ''): ?string {
    global $DB;
    if ($entities_id > 0) {
        $entRow = $DB->request(['FROM'=>'glpi_entities','WHERE'=>['id'=>$entities_id]])->current();
        if (!$entRow) return null;
        $raw = trim($entRow['completename'] ?? $entRow['name'] ?? '');
        if (strpos($raw, ' > ') !== false) {
            $parts = explode(' > ', $raw);
            $raw = trim(end($parts));
        }
        // fallback: se ainda contiver "Unidade Regional", usa name direto
        if (stripos($raw, 'Unidade Regional de Ensino') !== false) {
            $raw = trim($entRow['name'] ?? $raw);
        }
        if ($raw === '') return null;
        return mb_substr($raw, 0, 255);
    }
    $raw = trim($typed);
    if ($raw === '') return null;
    if (strpos($raw, ' > ') !== false) {
        $parts = explode(' > ', $raw);
        $raw = trim(end($parts));
    }
    if ($raw === '') return null;
    return mb_substr($raw, 0, 255);
}

// Efeitos comuns de "virou Manutenção": activity + chamado GLPI automático (falha não quebra).
// $created=true quando o cartão acabou de nascer (não é conversão).
function kanpro_finish_maintenance(int $cards_id, string $newName, int $entities_id, bool $created = false): array {
    $c = new PluginKanproCard();
    $bid = 0; $lid = 0;
    if ($c->getFromDB($cards_id)) {
        $bid = (int)($c->fields['plugin_kanpro_boards_id'] ?? 0);
        $lid = (int)($c->fields['plugin_kanpro_lists_id'] ?? 0);
    }
    $msg = $created
        ? "Cartão de Manutenção criado na lista Pendente por " . Session::getLoginUserID() . " — Entidade: {$newName} (#{$entities_id})"
        : "Cartão convertido para manutenção por " . Session::getLoginUserID() . " — Entidade: {$newName} (#{$entities_id})";
    PluginKanproBoard::logActivity($bid, $cards_id, $lid, 'card_maintenance_convert', $msg);
    kanpro_touch_member($cards_id);
    $ticketId = 0; $warn = '';
    try {
        $autoRes = kanpro_create_ticket_from_card($cards_id);
        if (!empty($autoRes['ok'])) $ticketId = (int)($autoRes['id'] ?? 0);
        else $warn = (string)($autoRes['error'] ?? 'falha desconhecida');
    } catch (Throwable $e) { $warn = $e->getMessage(); }
    return ['ticket_id' => $ticketId, 'ticket_warning' => $warn];
}

function kanpro_parse_maintenance_raw($raw) {
    $raw = trim($raw ?? '');
    if ($raw === '') return [];
    $defs = [];
    // Normaliza separadores , e ; para quebra de linha
    $normalized = str_replace([',',';'], "\n", $raw);
    // Tenta regex global no texto normalizado (captura mesmo sem quebra de linha, ex: "10x A 10x B")
    if (preg_match_all('/(\d+)\s*[xX]\s*([^\n]+?)(?=\s*\d+\s*[xX]\s*|$)/u', $normalized, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $qty = (int)trim($match[1]);
            $model = trim($match[2]);
            $model = trim($model, " \t\n\r\0\x0B,;.-");
            if ($qty > 0 && $qty <= 500 && $model !== '') {
                $defs[] = ['qty' => $qty, 'model' => $model];
            }
        }
        if (!empty($defs)) {
            $out = [];
            foreach ($defs as $d) {
                $d['qty'] = max(1, min(500, (int)$d['qty']));
                $d['model'] = trim($d['model']);
                if ($d['model'] !== '') $out[] = $d;
            }
            if (!empty($out)) return $out;
        }
        $defs = [];
    }
    // Fallback: split por quebras
    $parts = preg_split('/[\n]+/', $normalized);
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (preg_match('/^(\d+)\s*[xX]\s*(.+)$/u', $part, $mm)) {
            $defs[] = ['qty'=>(int)$mm[1], 'model'=>trim($mm[2], " \t,;.-")];
        } else if (preg_match('/^(\d+)\s+(.+)$/u', $part, $mm)) {
            $defs[] = ['qty'=>(int)$mm[1], 'model'=>trim($mm[2], " \t,;.-")];
        } else {
            $defs[] = ['qty'=>1, 'model'=>$part];
        }
    }
    $out = [];
    foreach ($defs as $d) {
        $d['qty'] = max(1, min(500, (int)$d['qty']));
        $d['model'] = trim($d['model']);
        if ($d['model'] !== '') $out[] = $d;
    }
    return $out;
}

// ---------- Helpers Tablet / Smartphone / Celular ----------
// Regra: tudo que contém tablet, smartphone ou celular (case/acento-insensitive)
// vai para card separado. Ex: Tablet Positivo, Tablets Positivo, Tablet Samsung,
// Tablet Lenovo, Tablet CCE, Smartphone, Celular.
function kanpro_is_tablet_model($model): bool {
    $m = (string)($model ?? '');
    if ($m === '') return false;
    if (function_exists('mb_strtolower')) $m = mb_strtolower($m, 'UTF-8');
    else $m = strtolower($m);
    // remove acentos básicos
    $m = strtr($m, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
    return (strpos($m, 'tablet') !== false
        || strpos($m, 'smartphone') !== false
        || strpos($m, 'smart phone') !== false
        || strpos($m, 'smartfone') !== false
        || strpos($m, 'celular') !== false);
}

// Info de tablet do card: total/tablet/nontab + flags.
// is_tablet_only = 100% tablet (e tem máquina). is_mixed = tem dos dois tipos.
function kanpro_card_tablet_info(int $cards_id): array {
    global $DB;
    $out = ['total'=>0,'tablet'=>0,'nontab'=>0,'is_tablet_only'=>false,'is_mixed'=>false,'is_empty'=>true];
    try {
        if ($cards_id <= 0) return $out;
        if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) return $out;
        $total = 0; $tab = 0;
        foreach ($DB->request(['SELECT'=>['model'],'FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cards_id]]) as $r) {
            $total++;
            if (kanpro_is_tablet_model($r['model'] ?? '')) $tab++;
        }
        $out['total'] = $total;
        $out['tablet'] = $tab;
        $out['nontab'] = $total - $tab;
        $out['is_empty'] = ($total === 0);
        $out['is_tablet_only'] = ($total > 0 && $tab === $total);
        $out['is_mixed'] = ($tab > 0 && $tab < $total);
    } catch (Throwable $e) {}
    return $out;
}

// Status da pendência de um card origem (maintenance): 'liberado' se já teve
// alguma pendência liberada, 'pendente' se tem pendência aberta, 'none' se nunca teve.
// O 'liberado' precisa sobreviver ao auto-delete de 30s da pendência, senão o 2º
// Finalizar do Tablet volta pra 'none' e cria outra pendência (loop infinito).
function kanpro_tablet_pendencia_status(int $src_cards_id): string {
    global $DB;
    try {
        if ($src_cards_id <= 0) return 'none';
        // 1) origem já carimbada como liberada (sobrevive ao auto-delete)
        try {
            $srcChk = new PluginKanproCard();
            if ($srcChk->getFromDB($src_cards_id) && (($srcChk->fields['chamado_status'] ?? '') === 'liberado')) return 'liberado';
        } catch (Throwable $e) {}
        $hasPend = false; $hasLib = false;
        foreach ($DB->request(['SELECT'=>['chamado_status'],'FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['chamado_source_id'=>$src_cards_id]]) as $r) {
            $st = (string)($r['chamado_status'] ?? '');
            if ($st === 'liberado') $hasLib = true;
            elseif ($st === 'pendente') $hasPend = true;
        }
        if ($hasLib) return 'liberado';
        if ($hasPend) return 'pendente';
        // 2) legado: pendência liberada já auto-excluída antes do carimbo na origem.
        // Histórico 'chamado_released' prova que já liberou uma vez — não volta p/ 'none'.
        try {
            if ($DB->tableExists('glpi_plugin_kanpro_activities')) {
                $cntRel = (int)countElementsInTable('glpi_plugin_kanpro_activities', ['plugin_kanpro_cards_id'=>$src_cards_id,'action'=>'chamado_released']);
                if ($cntRel > 0) return 'liberado';
            }
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
    return 'none';
}

// Split automático: se o card tem mistura tablet + não-tablet, move TODOS os
// tablets para um novo card (mesmo quadro/lista/nome/entidade, manutenção).
// Original fica só com não-tablets (re-sequenciado 1..N). Novo fica só tablets.
// Nome mantém igual (decisão do usuário). Retorna ['new_id'=>int,'moved'=>int,'kept'=>int].
function kanpro_split_tablet_machines(int $cards_id): array {
    global $DB;
    $noop = ['new_id'=>0,'moved'=>0,'kept'=>0];
    try {
        if ($cards_id <= 0) return $noop;
        if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) return $noop;
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cards_id)) return $noop;
        if (empty($card->fields['is_maintenance'])) return $noop;
        $all = [];
        foreach ($DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cards_id],'ORDER'=>'seq ASC']) as $r) $all[] = $r;
        if (count($all) < 2) return $noop;
        $tabs = []; $nontabs = [];
        foreach ($all as $m) {
            if (kanpro_is_tablet_model($m['model'] ?? '')) $tabs[] = $m;
            else $nontabs[] = $m;
        }
        if (empty($tabs) || empty($nontabs)) return $noop; // puro: nada a separar
        // cria novo card tablet (mesmo nome da entidade)
        $bid = (int)($card->fields['plugin_kanpro_boards_id'] ?? 0);
        $lid = (int)($card->fields['plugin_kanpro_lists_id'] ?? 0);
        $nm = mb_substr(trim($card->fields['name'] ?? ('Card #' . $cards_id)), 0, 255);
        $now = date('Y-m-d H:i:s');
        $actor = function_exists('kanpro_acting_user_id') ? kanpro_acting_user_id() : (int)Session::getLoginUserID();
        $nc = new PluginKanproCard();
        $newId = (int)$nc->add(['plugin_kanpro_boards_id'=>$bid,'plugin_kanpro_lists_id'=>$lid,
            'name'=>$nm,'description'=>($card->fields['description'] ?? ''),
            'entities_id'=>(int)($card->fields['entities_id'] ?? 0),
            'users_id'=>$actor,'date_creation'=>$now,'date_mod'=>$now]);
        if (!$newId) return $noop;
        $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>1,'maintenance_date'=>$now,'maintenance_by'=>$actor,'date_mod'=>$now], ['id'=>$newId]);
        // copia membros origem -> novo (técnico acompanha os dois)
        try {
            if ($DB->tableExists('glpi_plugin_kanpro_cards_members')) {
                foreach ($DB->request(['SELECT'=>['users_id'],'FROM'=>'glpi_plugin_kanpro_cards_members','WHERE'=>['plugin_kanpro_cards_id'=>$cards_id]]) as $mr) {
                    $uid = (int)($mr['users_id'] ?? 0);
                    if ($uid > 0 && !countElementsInTable('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$newId,'users_id'=>$uid])) {
                        try { $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$newId,'users_id'=>$uid]); } catch (Throwable $e) {}
                    }
                }
            }
        } catch (Throwable $e) {}
        // move tablets p/ novo card (re-seq 1..N) + re-seq origem
        $seq = 1;
        foreach ($tabs as $tm) {
            $newLabel = "Máquina {$seq} - {$tm['model']}";
            $DB->update('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$newId,'seq'=>$seq,'label'=>$newLabel,'date_mod'=>$now], ['id'=>(int)$tm['id']]);
            $seq++;
        }
        $seq = 1;
        foreach ($nontabs as $nm2) {
            $newLabel = "Máquina {$seq} - {$nm2['model']}";
            $DB->update('glpi_plugin_kanpro_maintenance_machines', ['seq'=>$seq,'label'=>$newLabel,'date_mod'=>$now], ['id'=>(int)$nm2['id']]);
            $seq++;
        }
        // ticket próprio p/ o card tablet (não quebra se falhar)
        try {
            if (class_exists('Ticket') && Session::haveRight('ticket', CREATE)) {
                kanpro_create_ticket_from_card($newId);
            }
        } catch (Throwable $e) {}
        // followups informativos (não quebram ticket sem utf8mb4: sem emoji 4-byte)
        try {
            $tidOrig = function_exists('kanpro_card_ticket_id') ? kanpro_card_ticket_id($cards_id) : 0;
            if ($tidOrig) kanpro_ticket_followup($tidOrig, "[KanPro] Separacao automatica Tablet\n\n" . count($tabs) . " maquina(s) Tablet/Smartphone/Celular movida(s) para o cartao #{$newId} (mesmo nome). Este cartao ficou com " . count($nontabs) . " maquina(s) nao-tablet.");
            $tidNew = function_exists('kanpro_card_ticket_id') ? kanpro_card_ticket_id($newId) : 0;
            if ($tidNew) kanpro_ticket_followup($tidNew, "[KanPro] Cartao Tablet criado por separacao automatica\n\nOrigem: cartao #{$cards_id}. " . count($tabs) . " maquina(s) Tablet/Smartphone/Celular.\n\nFluxo Tablet: Pegar vai direto p/ Em Andamento sem pendencia. 1o Finalizar cria Pendencia Chamado; apos liberado, 2o Finalizar vai p/ Assinatura/Retirada.");
        } catch (Throwable $e) {}
        if (function_exists('kanpro_touch_card')) { kanpro_touch_card($cards_id); kanpro_touch_card($newId); }
        PluginKanproBoard::logActivity($bid, $cards_id, $lid, 'maintenance_tablet_split', "Separação Tablet: " . count($tabs) . " tablet(s) movida(s) para #{$newId} — origem ficou com " . count($nontabs) . " não-tablet(s)");
        PluginKanproBoard::logActivity($bid, $newId, $lid, 'card_create', "Card Tablet criado por separação automática de #{$cards_id} (" . count($tabs) . " máquina(s))");
        try { kanpro_sync_inventory_label($cards_id); kanpro_sync_inventory_label($newId); } catch (Throwable $e) {}
        return ['new_id'=>$newId,'moved'=>count($tabs),'kept'=>count($nontabs)];
    } catch (Throwable $e) {
        return $noop;
    }
}

function kanpro_ensure_maintenance_tables() {
    static $done = false;
    if ($done) return;
    $done = true;
    kanpro_migrate_schema_once();
    global $DB;
    $charset = method_exists('DBConnection','getDefaultCharset') ? DBConnection::getDefaultCharset() : 'utf8mb4';
    $collation = method_exists('DBConnection','getDefaultCollation') ? DBConnection::getDefaultCollation() : 'utf8mb4_unicode_ci';
    $sign = method_exists('DBConnection','getDefaultPrimaryKeySignOption') ? DBConnection::getDefaultPrimaryKeySignOption() : 'unsigned';
    if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_maintenance_machines` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_cards_id`      INT {$sign} NOT NULL DEFAULT '0',
                `seq`                         INT          NOT NULL DEFAULT '0',
                `model`                       VARCHAR(255) NOT NULL DEFAULT '',
                `label`                       VARCHAR(255) NOT NULL DEFAULT '',
                `diary`                       TEXT         DEFAULT NULL,
                `is_done`                     TINYINT(1)   NOT NULL DEFAULT '0',
                `is_ok`                       TINYINT(1)   NOT NULL DEFAULT '0',
                `status`                      VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'garantia,ok,inservivel,pendente',
                `is_inventoried`              TINYINT(1)   NOT NULL DEFAULT '0' COMMENT '0=nao,1=inventariado',
                `needs_inventory`             TINYINT(1)   NOT NULL DEFAULT '0' COMMENT '0=nao precisa,1=precisa inventariar',
                `is_urgent`                   TINYINT(1)   NOT NULL DEFAULT '0' COMMENT '0=normal,1=urgencia',
                `is_locked`                   TINYINT(1)   NOT NULL DEFAULT '0' COMMENT '1=travada aguardando chamado',
                `locked_chamado_card_id`      INT {$sign} NOT NULL DEFAULT '0',
                `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                `date_creation`               DATETIME     DEFAULT NULL,
                `date_mod`                    DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_kanpro_cards_id` (`plugin_kanpro_cards_id`),
                KEY `seq` (`seq`),
                KEY `is_done` (`is_done`),
                KEY `is_inventoried` (`is_inventoried`),
                KEY `is_urgent` (`is_urgent`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ");
    } else {
        // garante status default '' (obrigatório) e migra legados pending/defect
        try {
            if ($DB->fieldExists('glpi_plugin_kanpro_maintenance_machines', 'status')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_maintenance_machines` MODIFY `status` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'garantia,ok,inservivel,pendente'");
                $DB->doQuery("UPDATE `glpi_plugin_kanpro_maintenance_machines` SET `status`='pendente' WHERE `status`='pending'");
                $DB->doQuery("UPDATE `glpi_plugin_kanpro_maintenance_machines` SET `status`='inservivel' WHERE `status`='defect' OR `status`='nok'");
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_maintenance_machines', 'is_inventoried')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_maintenance_machines` ADD `is_inventoried` TINYINT(1) NOT NULL DEFAULT '0' AFTER `status`");
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_maintenance_machines', 'needs_inventory')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_maintenance_machines` ADD `needs_inventory` TINYINT(1) NOT NULL DEFAULT '0' AFTER `is_inventoried`");
                // legado: quem já estava inventariado, precisava inventariar
                $DB->doQuery("UPDATE `glpi_plugin_kanpro_maintenance_machines` SET `needs_inventory`=1 WHERE `is_inventoried`=1");
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_maintenance_machines', 'is_urgent')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_maintenance_machines` ADD `is_urgent` TINYINT(1) NOT NULL DEFAULT '0' AFTER `is_inventoried`");
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_maintenance_machines', 'is_locked')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_maintenance_machines` ADD `is_locked` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1=travada aguardando chamado' AFTER `is_urgent`");
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_maintenance_machines', 'locked_chamado_card_id')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_maintenance_machines` ADD `locked_chamado_card_id` INT NOT NULL DEFAULT '0' AFTER `is_locked`");
            }
            // limpeza: Nome do Recebedor deve ficar vazio por padrão — remove preenchimento automático antigo em transferências pendentes do KanPro
            try {
                if ($DB->tableExists('glpi_plugin_assetmgrstatus_transfers') && $DB->fieldExists('glpi_plugin_assetmgrstatus_transfers', 'assinatura_nome')) {
                    // limpa nome pré-preenchido em termos ainda não assinados pelo recebedor (imagem vazia)
                    $DB->doQuery("UPDATE `glpi_plugin_assetmgrstatus_transfers` SET `assinatura_nome` = NULL WHERE (`assinatura_image` IS NULL OR `assinatura_image` = '') AND `assinatura_nome` IS NOT NULL AND `reason` LIKE '%KanPro%'");
                }
            } catch (Throwable $e) {}
        } catch (Throwable $e) {}
    }
    // Anotações por máquina — cria se não existir (dispensa reinstalar o plugin)
    if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_notes')) {
        try {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_kanpro_maintenance_notes` (
                    `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                    `machine_id`                  INT {$sign} NOT NULL DEFAULT '0',
                    `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                    `note`                        TEXT         DEFAULT NULL,
                    `date_creation`               DATETIME     DEFAULT NULL,
                    `date_mod`                    DATETIME     DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `machine_id` (`machine_id`),
                    KEY `date_creation` (`date_creation`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        } catch (Throwable $e) {}
    }
    // garante colunas de card
    if ($DB->tableExists('glpi_plugin_kanpro_cards')) {
        if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'is_maintenance')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `is_maintenance` TINYINT(1) NOT NULL DEFAULT '0' AFTER `is_completed`");
        }
        if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'maintenance_date')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `maintenance_date` DATETIME DEFAULT NULL AFTER `is_maintenance`");
        }
        if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'maintenance_by')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `maintenance_by` INT NOT NULL DEFAULT '0' AFTER `maintenance_date`");
        }
    }
}

function kanpro_ticket_info(int $tid): ?array {
    if (!class_exists('Ticket')) return null;
    $tk = new Ticket();
    if (!$tk->getFromDB($tid)) return null;
    $can = false;
    try { $can = $tk->can($tid, READ); } catch (Throwable $e) { $can = false; }
    $status = (int)($tk->fields['status'] ?? 0);
    $label = '';
    if (method_exists('Ticket', 'getStatus')) {
        try { $label = Ticket::getStatus($status); } catch (Throwable $e) { $label = 'Status '.$status; }
    } else {
        $label = 'Status '.$status;
    }
    return [
        'id'           => $tid,
        'name'         => $can ? ($tk->fields['name'] ?? '') : '',
        'restricted'   => !$can,
        'status'       => $status,
        'status_label' => $label,
        'date_mod'     => $tk->fields['date_mod'] ?? null,
    ];
}

// Migração de schema: roda 1x por request (guard static) em vez de DDL espalhado no caminho quente.
// Schema canônico está em hook.php (install). Aqui é só fallback p/ quem atualizou via git sem reinstalar.
function kanpro_migrate_schema_once() {
    static $done = false;
    if ($done) return;
    $done = true;
    global $DB;
    try {
        if ($DB->tableExists('glpi_plugin_kanpro_boards')) {
            if ($DB->fieldExists('glpi_plugin_kanpro_boards', 'color')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` MODIFY `color` VARCHAR(255) NOT NULL DEFAULT '#0079bf'"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'background')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` ADD `background` VARCHAR(255) DEFAULT NULL AFTER `color`"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'whatsapp_notify')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` ADD `whatsapp_notify` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1=notificacao whatsapp do quadro ligada' AFTER `visibility`"); } catch (Throwable $e) {}
            }
        }
        if ($DB->tableExists('glpi_plugin_kanpro_cards')) {
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'is_pinned')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `is_pinned` TINYINT(1) NOT NULL DEFAULT '0'"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'is_urgent')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `is_urgent` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1=urgente (destaque vermelho)'"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'approval_from')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `approval_from` INT NOT NULL DEFAULT '0'"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'tickets_id')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `tickets_id` INT NOT NULL DEFAULT '0' AFTER `cover_attachment_id`"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'is_maintenance')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `is_maintenance` TINYINT(1) NOT NULL DEFAULT '0' AFTER `is_completed`"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'entities_id')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `entities_id` INT NOT NULL DEFAULT '0' AFTER `tickets_id`"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'is_notified')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `is_notified` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1=notificado sobre o chamado'"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'notified_by')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `notified_by` INT NOT NULL DEFAULT '0' AFTER `is_notified`"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'notified_date')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `notified_date` DATETIME DEFAULT NULL AFTER `notified_by`"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'chamado_source_id')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `chamado_source_id` INT NOT NULL DEFAULT '0' AFTER `notified_date`"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'chamado_machines')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `chamado_machines` TEXT DEFAULT NULL AFTER `chamado_source_id`"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'chamado_status')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `chamado_status` VARCHAR(20) NOT NULL DEFAULT '' AFTER `chamado_machines`"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'chamado_by')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `chamado_by` INT NOT NULL DEFAULT '0' AFTER `chamado_status`"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'whatsapp_notify')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `whatsapp_notify` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1=notificacao whatsapp habilitada' AFTER `chamado_by`"); } catch (Throwable $e) {}
            }
        }
        if ($DB->tableExists('glpi_plugin_kanpro_lists')) {
            if (!$DB->fieldExists('glpi_plugin_kanpro_lists', 'require_approval')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_lists` ADD `require_approval` TINYINT(1) NOT NULL DEFAULT '0'"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_lists', 'list_type')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_lists` ADD `list_type` VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'categoria: backlog,todo,doing,done,awaiting,pending,andamento,retirada,none (vazio/none=normal)'"); } catch (Throwable $e) {}
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_lists', 'users_id')) {
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_lists` ADD `users_id` INT NOT NULL DEFAULT '0' COMMENT 'quem criou a lista' AFTER `list_type`"); } catch (Throwable $e) {}
            }
        }
        if (!$DB->tableExists('glpi_plugin_kanpro_lists_viewers')) {
            try {
                $charset = DBConnection::getDefaultCharset();
                $collation = DBConnection::getDefaultCollation();
                $sign = DBConnection::getDefaultPrimaryKeySignOption();
                $DB->doQuery("CREATE TABLE `glpi_plugin_kanpro_lists_viewers` (`id` INT {$sign} NOT NULL AUTO_INCREMENT, `plugin_kanpro_lists_id` INT {$sign} NOT NULL DEFAULT '0', `users_id` INT {$sign} NOT NULL DEFAULT '0', PRIMARY KEY (`id`), UNIQUE KEY `uniq_list_user` (`plugin_kanpro_lists_id`, `users_id`), KEY `plugin_kanpro_lists_id` (`plugin_kanpro_lists_id`)) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
            } catch (Throwable $e) {}
        }
        if ($DB->tableExists('glpi_plugin_kanpro_labels') && !$DB->fieldExists('glpi_plugin_kanpro_labels', 'due_date')) {
            try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_labels` ADD `due_date` DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
        }
        if ($DB->tableExists('glpi_plugin_kanpro_comments') && !$DB->fieldExists('glpi_plugin_kanpro_comments', 'is_pinned')) {
            try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_comments` ADD `is_pinned` TINYINT(1) NOT NULL DEFAULT '0'"); } catch (Throwable $e) {}
        }
        // acesso por perfil GLPI + grupos pessoais (schema canônico em hook.php)
        if (!$DB->tableExists('glpi_plugin_kanpro_boards_profiles')) {
            try {
                $charset = DBConnection::getDefaultCharset();
                $collation = DBConnection::getDefaultCollation();
                $sign = DBConnection::getDefaultPrimaryKeySignOption();
                $DB->doQuery("CREATE TABLE `glpi_plugin_kanpro_boards_profiles` (`id` INT {$sign} NOT NULL AUTO_INCREMENT, `plugin_kanpro_boards_id` INT {$sign} NOT NULL DEFAULT '0', `profiles_id` INT {$sign} NOT NULL DEFAULT '0', `role` VARCHAR(20) NOT NULL DEFAULT 'member', `date_creation` DATETIME DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uniq_board_profile` (`plugin_kanpro_boards_id`, `profiles_id`), KEY `plugin_kanpro_boards_id` (`plugin_kanpro_boards_id`)) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
            } catch (Throwable $e) {}
        }
        if (!$DB->tableExists('glpi_plugin_kanpro_board_groups')) {
            try {
                $charset = DBConnection::getDefaultCharset();
                $collation = DBConnection::getDefaultCollation();
                $sign = DBConnection::getDefaultPrimaryKeySignOption();
                $DB->doQuery("CREATE TABLE `glpi_plugin_kanpro_board_groups` (`id` INT {$sign} NOT NULL AUTO_INCREMENT, `users_id` INT {$sign} NOT NULL DEFAULT '0', `name` VARCHAR(255) NOT NULL DEFAULT '', `rank` DOUBLE NOT NULL DEFAULT '0', `date_creation` DATETIME DEFAULT NULL, `date_mod` DATETIME DEFAULT NULL, PRIMARY KEY (`id`), KEY `users_id` (`users_id`)) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
            } catch (Throwable $e) {}
        }
        if (!$DB->tableExists('glpi_plugin_kanpro_board_groups_items')) {
            try {
                $charset = DBConnection::getDefaultCharset();
                $collation = DBConnection::getDefaultCollation();
                $sign = DBConnection::getDefaultPrimaryKeySignOption();
                $DB->doQuery("CREATE TABLE `glpi_plugin_kanpro_board_groups_items` (`id` INT {$sign} NOT NULL AUTO_INCREMENT, `groups_id` INT {$sign} NOT NULL DEFAULT '0', `users_id` INT {$sign} NOT NULL DEFAULT '0', `plugin_kanpro_boards_id` INT {$sign} NOT NULL DEFAULT '0', `rank` DOUBLE NOT NULL DEFAULT '0', PRIMARY KEY (`id`), UNIQUE KEY `uniq_user_board` (`users_id`, `plugin_kanpro_boards_id`), KEY `groups_id` (`groups_id`)) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
            } catch (Throwable $e) {}
        } elseif (!$DB->fieldExists('glpi_plugin_kanpro_board_groups_items', 'rank')) {
            try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_board_groups_items` ADD `rank` DOUBLE NOT NULL DEFAULT '0' COMMENT 'ordem do quadro na lista (0=não ordenado)'"); } catch (Throwable $e) {}
        }
        // log anti-duplicado do WhatsApp da manutenção
        if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_zaplog')) {
            try {
                $charset = DBConnection::getDefaultCharset();
                $collation = DBConnection::getDefaultCollation();
                $sign = DBConnection::getDefaultPrimaryKeySignOption();
                $DB->doQuery("CREATE TABLE `glpi_plugin_kanpro_maintenance_zaplog` (`id` INT {$sign} NOT NULL AUTO_INCREMENT, `plugin_kanpro_cards_id` INT {$sign} NOT NULL DEFAULT '0', `milestone` VARCHAR(30) NOT NULL DEFAULT '', `phone` VARCHAR(30) DEFAULT NULL, `success` TINYINT(1) NOT NULL DEFAULT '0', `detail` VARCHAR(255) DEFAULT NULL, `date_creation` DATETIME DEFAULT NULL, PRIMARY KEY (`id`), KEY `plugin_kanpro_cards_id` (`plugin_kanpro_cards_id`), KEY `milestone` (`milestone`)) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
            } catch (Throwable $e) {}
        }
        // exceções do calendário do lembrete (tirar do envio / forçar)
        if (!$DB->tableExists('glpi_plugin_kanpro_lembrete_days')) {
            try {
                $charset = DBConnection::getDefaultCharset();
                $collation = DBConnection::getDefaultCollation();
                $sign = DBConnection::getDefaultPrimaryKeySignOption();
                $DB->doQuery("CREATE TABLE `glpi_plugin_kanpro_lembrete_days` (`id` INT {$sign} NOT NULL AUTO_INCREMENT, `date` DATE NOT NULL, `mode` VARCHAR(10) NOT NULL DEFAULT 'skip', `reason` VARCHAR(255) DEFAULT NULL, `users_id` INT {$sign} NOT NULL DEFAULT '0', `date_creation` DATETIME DEFAULT NULL, `date_mod` DATETIME DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uniq_date` (`date`)) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
            } catch (Throwable $e) {}
        }
        // atualizações do fluxo Chamado (Em Andamento): autocura sem reinstalar (reinstalar NÃO apaga nada,
        // só desinstalar apaga — mas aqui nem precisa: cria sozinha no próximo request)
        if (!$DB->tableExists('glpi_plugin_kanpro_chamado_updates')) {
            try {
                $charset = DBConnection::getDefaultCharset();
                $collation = DBConnection::getDefaultCollation();
                $sign = DBConnection::getDefaultPrimaryKeySignOption();
                $DB->doQuery("CREATE TABLE `glpi_plugin_kanpro_chamado_updates` (`id` INT {$sign} NOT NULL AUTO_INCREMENT, `plugin_kanpro_cards_id` INT {$sign} NOT NULL DEFAULT '0', `users_id` INT {$sign} NOT NULL DEFAULT '0', `note` TEXT DEFAULT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'pendente', `date_creation` DATETIME DEFAULT NULL, PRIMARY KEY (`id`), KEY `plugin_kanpro_cards_id` (`plugin_kanpro_cards_id`), KEY `date_creation` (`date_creation`)) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
            } catch (Throwable $e) {}
        }
        // modelos de máquinas (gerenciável pela equipe): cria + seed sem reinstalar
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_models')) {
                $charset = DBConnection::getDefaultCharset();
                $collation = DBConnection::getDefaultCollation();
                $sign = DBConnection::getDefaultPrimaryKeySignOption();
                $DB->doQuery("CREATE TABLE `glpi_plugin_kanpro_maintenance_models` (`id` INT {$sign} NOT NULL AUTO_INCREMENT, `name` VARCHAR(80) NOT NULL DEFAULT '', `rank` DOUBLE NOT NULL DEFAULT '0', `users_id` INT {$sign} NOT NULL DEFAULT '0', `date_creation` DATETIME DEFAULT NULL, `date_mod` DATETIME DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uniq_name` (`name`), KEY `rank` (`rank`)) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
            }
            if ($DB->tableExists('glpi_plugin_kanpro_maintenance_models') && countElementsInTable('glpi_plugin_kanpro_maintenance_models') == 0) {
                $rk = 1024; $nowSeed = date('Y-m-d H:i:s');
                foreach (kanpro_maintenance_model_defaults() as $sm) {
                    try { $DB->insert('glpi_plugin_kanpro_maintenance_models', ['name'=>$sm,'rank'=>$rk,'users_id'=>0,'date_creation'=>$nowSeed,'date_mod'=>$nowSeed]); } catch (Throwable $e) {}
                    $rk += 1024;
                }
            }
        } catch (Throwable $e) {}
        // cron diário do zap de atraso (só cria a linha se não existir)
        if (class_exists('PluginKanproMaintenanceZap')) {
            try { PluginKanproMaintenanceZap::registerCron(); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
}
// Padrões de modelos (seed + fallback se tabela vazia/offline)
function kanpro_maintenance_model_defaults(): array {
    return ['Notebook Positivo','Notebook Multilaser','Notebook Ultra','Notebook Lenovo','Desktop Legado','Desktop','Tablet Positivo','Tablets Positivo','Tablet Samsung','Tablet Lenovo','Tablet CCE','Smartphone','Celular'];
}
// Lista ordenada (só nomes). Fallback p/ padrões se tabela indisponível/vazia.
function kanpro_list_maintenance_models(): array {
    global $DB;
    try {
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_models')) {
            $out = [];
            foreach ($DB->request(['SELECT'=>['id','name'],'FROM'=>'glpi_plugin_kanpro_maintenance_models','ORDER'=>['rank ASC','name ASC']]) as $r) {
                $nm = trim((string)($r['name'] ?? ''));
                if ($nm !== '') $out[] = ['id'=>(int)$r['id'],'name'=>$nm];
            }
            if (!empty($out)) return $out;
        }
    } catch (Throwable $e) {}
    $out = [];
    foreach (kanpro_maintenance_model_defaults() as $nm) $out[] = ['id'=>0,'name'=>$nm];
    return $out;
}
// Migration em runtime: garante coluna do chamado vinculado sem depender do update do plugin
function kanpro_ensure_v11() {
    kanpro_migrate_schema_once();
}
kanpro_migrate_schema_once();
function kanpro_card_id_of_checklist(int $checklists_id): int {
    global $DB;
    try {
        $cl = $DB->request(['SELECT' => ['plugin_kanpro_cards_id'], 'FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['id' => $checklists_id]])->current();
        return (int)($cl['plugin_kanpro_cards_id'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

// Auto-membro: quem edita o card vira membro (passa a ver no Minhas Tarefas e no contexto)
function kanpro_touch_member(int $cards_id, ?int $users_id = null) {
    global $DB;
    try {
        $uid = $users_id ?: kanpro_acting_user_id();
        if ($cards_id <= 0 || $uid <= 0) return;
        if (!$DB->tableExists('glpi_plugin_kanpro_cards_members')) return;
        $exists = countElementsInTable('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => $cards_id, 'users_id' => $uid]);
        if (!$exists) {
            $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => $cards_id, 'users_id' => $uid]);
        }
    } catch (Throwable $e) {}
}

// Categorias de lista (listas de ajuste): backlog=Pautas futuras, todo=A Fazer,
// doing=Em Progresso, done=Concluído, awaiting=Aguardando Chegada, pending=Pendente,
// andamento=Em Andamento, retirada=Retirada, pend_chamado=Pendência Chamado. '' = lista normal (vale dedução pelo nome p/ legado),
// 'none' = normal explícito (usuário tirou a categoria: nome NÃO reaplica).
function kanpro_valid_list_type(string $t): string {
    $t = trim(strtolower($t));
    return in_array($t, ['backlog', 'todo', 'doing', 'done', 'awaiting', 'pending', 'andamento', 'retirada', 'pend_chamado', 'abrir_chamado', 'andamento_chamado', 'chamado_finalizado', 'none'], true) ? $t : '';
}

// Acha a lista do quadro pela categoria (vale dedução pelo nome p/ legado).
function kanpro_find_list_by_type(int $boards_id, string $type): ?array {
    global $DB;
    $type = trim(strtolower($type));
    if ($boards_id <= 0 || $type === '') return null;
    try {
        foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'is_archived' => 0], 'ORDER' => 'rank ASC']) as $l) {
            $lt = trim(strtolower($l['list_type'] ?? ''));
            if ($lt === $type) return $l;
            if ($lt === '' || $lt === 'none') {
                $nm = function_exists('mb_strtolower') ? mb_strtolower(trim($l['name'] ?? ''), 'UTF-8') : strtolower(trim($l['name'] ?? ''));
                $nm = strtr($nm, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
                if ($type === 'pending' && $nm === 'pendente') return $l;
                if ($type === 'andamento' && $nm === 'em andamento') return $l;
                if ($type === 'pend_chamado' && (strpos($nm, 'pendencia') !== false && strpos($nm, 'chamado') !== false)) return $l;
                if ($type === 'abrir_chamado' && $nm === 'abrir chamado') return $l;
                if ($type === 'andamento_chamado' && $nm === 'em andamento chamado') return $l;
                if ($type === 'chamado_finalizado' && ($nm === 'chamado finalizado' || $nm === 'chamados finalizados')) return $l;
            }
        }
    } catch (Throwable $e) {}
    return null;
}

// Cria a Pendência Chamado para um card de manutenção: card na lista Pendência
// Chamado + trava as máquinas + zap p/ o aprovador (mesmo fluxo do Pegar).
// Nunca joga exceção. Retorna ['pendencia_id'=>int,'locked'=>int,'zap_ok'=>bool,
// 'zap_error'=>string] ou ['pendencia_id'=>0,...,'warning'=>string] sem falhar.
function kanpro_create_pendencia_chamado(int $boards_id, int $src_cards_id, array $machine_ids, int $who, string $origin_label): array {
    global $DB;
    $fail = function (string $warning) { return ['pendencia_id'=>0,'locked'=>0,'zap_ok'=>false,'zap_error'=>'','warning'=>$warning]; };
    try {
        $mids = array_values(array_unique(array_filter(array_map('intval', $machine_ids), function ($v) { return $v > 0; })));
        if ($boards_id <= 0 || $src_cards_id <= 0 || empty($mids)) return $fail('Sem máquinas para pendência');
        $src = new PluginKanproCard();
        if (!$src->getFromDB($src_cards_id)) return $fail('Card origem não encontrado');
        $target = kanpro_find_list_by_type($boards_id, 'pend_chamado');
        if (!$target) return $fail('Crie uma lista com categoria "Pendência chamados" neste quadro');
        $machines = [];
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            foreach ($DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mids,'plugin_kanpro_cards_id'=>$src_cards_id]]) as $m) $machines[(int)$m['id']] = $m;
        }
        if (empty($machines)) return $fail('Nenhuma máquina válida para pendência');
        $mids = array_keys($machines);
        $targetLid = (int)$target['id'];
        $nc = new PluginKanproCard();
        $nm = mb_substr(trim($src->fields['name'] ?? ('Card #' . $src_cards_id)), 0, 255);
        $pendId = (int)$nc->add(['plugin_kanpro_boards_id'=>$boards_id,'plugin_kanpro_lists_id'=>$targetLid,'name'=>$nm,
            'description'=>"{$origin_label}: card #{$src_cards_id} ('" . ($src->fields['name'] ?? '') . "'). Todas as máquinas travadas até 'Chamado criado'."]);
        if (!$pendId) return $fail('Falha ao criar card na Pendência Chamado');
        $DB->update('glpi_plugin_kanpro_cards', ['chamado_source_id'=>$src_cards_id,'chamado_machines'=>json_encode(array_values($mids), JSON_UNESCAPED_UNICODE),
            'chamado_status'=>'pendente','chamado_by'=>$who,'entities_id'=>(int)($src->fields['entities_id'] ?? 0),'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$pendId]);
        $cl = new PluginKanproChecklist();
        $clId = (int)$cl->add(['plugin_kanpro_cards_id'=>$pendId,'name'=>'Máquinas para chamado']);
        if ($clId) {
            $rk = 1024;
            foreach (array_values($machines) as $m) {
                $it = new PluginKanproChecklistItem();
                $it->add(['plugin_kanpro_checklists_id'=>$clId,'name'=>'#' . (int)$m['seq'] . ' ' . ($m['label'] ?: $m['model']) . ' [mid:' . (int)$m['id'] . ']','rank'=>$rk]);
                $rk += 1024;
            }
        }
        $DB->update('glpi_plugin_kanpro_maintenance_machines', ['is_locked'=>1,'locked_chamado_card_id'=>$pendId,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$mids]);
        kanpro_touch_card($pendId);
        PluginKanproBoard::logActivity($boards_id, $pendId, $targetLid, 'chamado_created', "Pendência Chamado criada via {$origin_label} de #{$src_cards_id} (" . count($mids) . " máquina(s))");
        $zapOk = false; $zapErr = '';
        try { if (class_exists('PluginKanproMaintenanceZap')) { $zr = PluginKanproMaintenanceZap::sendPendencia($pendId); $zapOk = !empty($zr['ok']); $zapErr = (string)($zr['error'] ?? ''); } } catch (Throwable $e) { $zapErr = $e->getMessage(); }
        return ['pendencia_id'=>$pendId,'locked'=>count($mids),'zap_ok'=>$zapOk,'zap_error'=>$zapErr];
    } catch (Throwable $e) {
        return $fail('Erro: ' . $e->getMessage());
    }
}

// Categorias de lista que NÃO aceitam cartão novo pelo botão genérico: são de ajuste
// (entram sozinhas pelo fluxo). Pendência Chamado tem fluxo próprio (add_chamado_card:
// título + descrição -> clona p/ Abrir chamado e move o original p/ Em andamento).
function kanpro_list_blocked_for_create(string $cat): ?string {
    $map = [
        'andamento'          => 'Em Andamento',
        'retirada'           => 'Retirada',
        'done'               => 'Concluído',
        'abrir_chamado'      => 'Abrir chamado',
        'andamento_chamado'  => 'Em Andamento Chamado',
        'chamado_finalizado' => 'Chamado finalizado',
    ];
    return $map[$cat] ?? null;
}

// Categoria real da lista (list_type; p/ listas legadas sem tipo, deduz pelo nome).
function kanpro_list_category(int $lists_id): string {
    if ($lists_id <= 0) return '';
    $l = new PluginKanproList();
    if (!$l->getFromDB($lists_id)) return '';
    $t = trim(strtolower((string)($l->fields['list_type'] ?? '')));
    if ($t !== '' && $t !== 'none') return $t;
    $nm = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($l->fields['name'] ?? '')), 'UTF-8') : strtolower(trim((string)($l->fields['name'] ?? '')));
    $nm = trim(strtr($nm, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']));
    if ($nm === 'pendente') return 'pending';
    if ($nm === 'em andamento') return 'andamento';
    if ($nm === 'retirada') return 'retirada';
    if ($nm === 'concluido' || $nm === 'concluida') return 'done';
    if (strpos($nm, 'pendencia') !== false && strpos($nm, 'chamado') !== false) return 'pend_chamado';
    if ($nm === 'abrir chamado') return 'abrir_chamado';
    if ($nm === 'em andamento chamado') return 'andamento_chamado';
    if ($nm === 'chamado finalizado' || $nm === 'chamados finalizados') return 'chamado_finalizado';
    return '';
}

// Trava de criação na lista. Devolve a categoria. 'Pendente' NÃO entra na lista de
// bloqueio: lá o cartão nasce como Manutenção (add_pending_maintenance).
function kanpro_need_list_allows_card(int $lists_id): string {
    $cat = kanpro_list_category($lists_id);
    $label = kanpro_list_blocked_for_create($cat);
    if ($label !== null) {
        jexit(['success'=>false,'msg'=>"A lista \"{$label}\" não aceita cartão novo."]);
    }
    return $cat;
}

// Cartão da lista "Pendente" já nasce como Manutenção: o nome é o da entidade e o
// conteúdo é o checklist de máquinas — nada pode ser alterado dentro dele.
function kanpro_card_is_locked(int $cards_id): bool {
    if ($cards_id <= 0) return false;
    $c = new PluginKanproCard();
    if (!$c->getFromDB($cards_id)) return false;
    return kanpro_list_category((int)($c->fields['plugin_kanpro_lists_id'] ?? 0)) === 'pending';
}
// $allowBoardAdmin = true libera a ação travada para o criador/admin do quadro (ex.: excluir).
function kanpro_need_card_editable(int $cards_id, bool $allowBoardAdmin = false) {
    // trava do fluxo Chamado vale em todos os pontos que já checam editabilidade
    kanpro_need_chamado_released($cards_id);
    if (!kanpro_card_is_locked($cards_id)) return;
    if ($allowBoardAdmin) {
        $c = new PluginKanproCard();
        if ($c->getFromDB($cards_id) && kanpro_can_manage_members((int)($c->fields['plugin_kanpro_boards_id'] ?? 0))) return;
    }
    jexit(['success'=>false,'msg'=>'Cartão da lista Pendente é travado: foi criado como Manutenção e o nome vem da entidade. Nada pode ser alterado dentro dele.']);
}
// Fluxo Chamado: original em "Em Andamento Chamado" fica BLOQUEADO (sem alterações)
// enquanto o clone em "Abrir chamado" não for liberado ("Chamado aberto").
// Após o auto-delete de 30s o clone some: o original carrega chamado_status='liberado'
// (gravado no mark_open) e permanece liberado — sem voltar a bloquear.
function kanpro_chamado_original_blocked(int $cards_id): bool {
    global $DB;
    if ($cards_id <= 0) return false;
    try {
        $c = new PluginKanproCard();
        if (!$c->getFromDB($cards_id)) return false;
        if (kanpro_list_category((int)($c->fields['plugin_kanpro_lists_id'] ?? 0)) !== 'andamento_chamado') return false;
        // já liberado no próprio original (clone pode ter sido auto-excluído): nunca re-bloqueia
        if (($c->fields['chamado_status'] ?? '') === 'liberado') return false;
        $sib = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['chamado_source_id' => $cards_id], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        if (!$sib) return false; // sem clone: fail-open (legado / já auto-excluído)
        return (($sib['chamado_status'] ?? '') !== 'liberado');
    } catch (Throwable $e) { return false; }
}
function kanpro_need_chamado_released(int $cards_id) {
    if (kanpro_chamado_original_blocked($cards_id)) {
        jexit(['success'=>false,'msg'=>'Card bloqueado — aguarde o "Chamado aberto" em Abrir chamado.','chamado_blocked'=>true]);
    }
}
// Card finalizado = já foi para Assinatura (existe transferência KanPro).
// Depois do Finalizar não pode mais editar/adicionar/remover máquinas — só visualizar.
function kanpro_card_is_finalized(int $cards_id): bool {
    global $DB;
    if ($cards_id <= 0) return false;
    try {
        if (!$DB->tableExists('glpi_plugin_assetmgrstatus_transfers')) return false;
        $like = "%[KanPro #{$cards_id}%";
        $iter = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_assetmgrstatus_transfers', 'WHERE' => ['reason' => ['LIKE', $like]], 'LIMIT' => 1]);
        return $iter->count() > 0;
    } catch (Throwable $e) { return false; }
}
function kanpro_need_not_finalized(int $cards_id) {
    if (!kanpro_card_is_finalized($cards_id)) return;
    jexit(['success'=>false,'msg'=>'Manutenção finalizada — já foi enviada para Assinatura. Não é mais possível editar, adicionar ou remover máquinas. Somente visualização.','finalized'=>true]);
}
// Origem com Pendência Chamado aberta = máquinas travadas aguardando "Chamado criado".
// Enquanto travar, NADA pode ser editado/adicionado/removido nesse chamado (nem mesmo as livres,
// para não quebrar o snapshot da pendência). Libera sozinho no "Chamado criado".
function kanpro_card_chamado_locked_info(int $cards_id): array {
    global $DB;
    $out = ['locked' => 0, 'pendencia_id' => 0];
    if ($cards_id <= 0) return $out;
    try {
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            $out['locked'] = (int)countElementsInTable('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id' => $cards_id, 'is_locked' => 1]);
        }
        if ($DB->tableExists('glpi_plugin_kanpro_cards')) {
            $r = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['chamado_source_id' => $cards_id, 'chamado_status' => 'pendente'], 'ORDER' => 'id ASC', 'LIMIT' => 1])->current();
            if ($r && !empty($r['id'])) $out['pendencia_id'] = (int)$r['id'];
        }
    } catch (Throwable $e) {}
    return $out;
}
function kanpro_need_not_chamado_locked(int $cards_id) {
    $info = kanpro_card_chamado_locked_info($cards_id);
    if ($info['locked'] > 0 || $info['pendencia_id'] > 0) {
        $pend = $info['pendencia_id'] > 0 ? (' (pendência #' . $info['pendencia_id'] . ')') : '';
        jexit(['success'=>false,'msg'=>'Máquina travada — aguardando Chamado criado' . $pend . '. Nada pode ser editado/adicionado até liberar.','locked'=>true,'pendencia_id'=>$info['pendencia_id']]);
    }
}

// Toca date_mod do cartão (e do quadro) p/ o selo do polling perceber a mudança.
// Comentários/checks/anexos não geravam activity nem tocavam datas — o selo ficava cego.
function kanpro_touch_card(int $cards_id) {
    global $DB;
    try {
        if ($cards_id <= 0) return;
        $now = date('Y-m-d H:i:s');
        $DB->update('glpi_plugin_kanpro_cards', ['date_mod' => $now], ['id' => $cards_id]);
        $row = $DB->request(['SELECT' => ['plugin_kanpro_boards_id'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['id' => $cards_id]])->current();
        if ($row && !empty($row['plugin_kanpro_boards_id'])) {
            $DB->update('glpi_plugin_kanpro_boards', ['date_mod' => $now], ['id' => (int)$row['plugin_kanpro_boards_id']]);
        }
    } catch (Throwable $e) {}
}

// Move o card para a lista de categoria "Retirada" do quadro (pós-Finalizar).
// Sem lista Retirada ou já estando nela: não faz nada. Nunca joga exceção.
// Retorna ['moved'=>bool,'lists_id'=>int,'list_name'=>string].
function kanpro_move_card_to_retirada(int $cards_id): array {
    global $DB;
    $noop = ['moved'=>false,'lists_id'=>0,'list_name'=>''];
    try {
        if ($cards_id <= 0) return $noop;
        $c = new PluginKanproCard();
        if (!$c->getFromDB($cards_id)) return $noop;
        $bid = (int)($c->fields['plugin_kanpro_boards_id'] ?? 0);
        $curLid = (int)($c->fields['plugin_kanpro_lists_id'] ?? 0);
        if ($bid <= 0) return $noop;
        $dest = kanpro_find_list_by_type($bid, 'retirada');
        if (!$dest) return $noop;
        $destLid = (int)($dest['id'] ?? 0);
        if ($destLid <= 0 || $destLid === $curLid) return $noop;
        $last = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$destLid],'ORDER'=>'rank DESC','LIMIT'=>1])->current();
        $rank = $last ? ((float)$last['rank'] + 1024) : 1024;
        $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_lists_id'=>$destLid,'rank'=>$rank,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$cards_id]);
        kanpro_touch_card($cards_id);
        PluginKanproBoard::logActivity($bid, $cards_id, $destLid, 'card_move', "Finalizado e movido para '{$dest['name']}'");
        return ['moved'=>true,'lists_id'=>$destLid,'list_name'=>(string)($dest['name'] ?? 'Retirada')];
    } catch (Throwable $e) { return $noop; }
}

// Cria um chamado GLPI a partir do cartão e vincula (tickets_id).
// Usado na conversão para manutenção (automático) e no botão Chamado.
// Se o cartão já tem chamado válido, só retorna o vínculo existente.
function kanpro_create_ticket_from_card(int $cards_id, int $force_entities_id = 0, string $origin_label = ''): array {
    global $DB;
    if (!class_exists('Ticket')) return ['ok' => false, 'error' => 'Classe Ticket indisponível'];
    $card = new PluginKanproCard();
    if (!$card->getFromDB($cards_id)) return ['ok' => false, 'error' => 'Cartão não encontrado'];
    if (!Session::haveRight('ticket', CREATE)) return ['ok' => false, 'error' => 'Sem permissão para criar chamados (perfil sem ticket CREATE)'];
    $old = (int)($card->fields['tickets_id'] ?? 0);
    if ($old > 0) {
        $tkOld = new Ticket();
        if ($tkOld->getFromDB($old)) return ['ok' => true, 'id' => $old, 'existed' => true, 'ticket' => kanpro_ticket_info($old)];
    }
    $board = new PluginKanproBoard();
    $board->getFromDB((int)$card->fields['plugin_kanpro_boards_id']);
    $list = new PluginKanproList();
    $list->getFromDB((int)$card->fields['plugin_kanpro_lists_id']);
    // Escola selecionada tem prioridade; senão entidade do quadro/ativa (URE).
    $entities_id = $force_entities_id > 0 ? $force_entities_id : (int)($card->fields['entities_id'] ?? 0);
    if ($entities_id <= 0) $entities_id = (int)($board->fields['entities_id'] ?? 0);
    if ($entities_id <= 0) $entities_id = (int)($_SESSION['glpiactive_entity'] ?? 0);
    $content = "Chamado aberto automaticamente pelo KanPro.\n\n"
        . 'Quadro: ' . ($board->fields['name'] ?? '-') . "\n"
        . 'Lista: ' . ($list->fields['name'] ?? '-') . "\n"
        . ($origin_label !== '' ? 'Origem: ' . $origin_label . "\n" : '')
        . 'Cartão: #' . $cards_id . ' ' . (trim($card->fields['name'] ?? '') ?: ('Cartão #' . $cards_id)) . "\n";
    if (!empty($card->fields['description'])) $content .= "\nDescrição do cartão:\n" . $card->fields['description'] . "\n";
    $content .= "\nAs atualizações da manutenção serão registradas como acompanhamentos neste chamado.";
    $tk = new Ticket();
    $tid = $tk->add([
        'name' => mb_substr(trim($card->fields['name'] ?? ('Cartão #' . $cards_id)), 0, 255),
        'content' => $content,
        'entities_id' => $entities_id,
        'status' => 1,
        '_users_id_requester' => kanpro_acting_user_id(),
    ]);
    if (!$tid) return ['ok' => false, 'error' => 'Falha ao criar chamado (verifique entidade/perfil)'];
    $tid = (int)$tid;
    $DB->update('glpi_plugin_kanpro_cards', ['tickets_id' => $tid], ['id' => $cards_id]);
    PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cards_id, $card->fields['plugin_kanpro_lists_id'], 'card_create_ticket', "Chamado #{$tid} criado a partir do cartão");
    return ['ok' => true, 'id' => $tid, 'ticket' => kanpro_ticket_info($tid)];
}

// Usuário para atribuição no chamado: ver inc/acting.php (kanpro_acting_user_id).

// ID do chamado vinculado ao cartão (0 se nenhum ou inválido)
function kanpro_card_ticket_id(int $cards_id): int {
    global $DB;
    try {
        if (!$DB->tableExists('glpi_plugin_kanpro_cards')) return 0;
        $row = $DB->request(['SELECT' => ['tickets_id'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['id' => $cards_id]])->current();
        $tid = (int)($row['tickets_id'] ?? 0);
        if ($tid <= 0 || !class_exists('Ticket')) return 0;
        $tk = new Ticket();
        if (!$tk->getFromDB($tid)) return 0;
        return $tid;
    } catch (Throwable $e) { return 0; }
}

// Acompanhamento no chamado vinculado (nunca quebra o fluxo principal).
// Por padrão também atribui quem agiu (type=2), sem duplicar — usa "Agindo como".
function kanpro_ticket_followup(int $tickets_id, string $content, bool $assignActingUser = true): bool {
    if ($tickets_id <= 0 || trim($content) === '' || !class_exists('ITILFollowup')) return false;
    global $DB;
    try {
        $auid = kanpro_acting_user_id();
        $tf = new ITILFollowup();
        $fid = $tf->add([
            'itemtype' => 'Ticket',
            'items_id' => $tickets_id,
            'content' => $content,
            'users_id' => $auid,
            'is_private' => 0,
        ]);
        if ($fid) {
            // garante o autor (o core pode forçar o usuário da sessão)
            foreach (['glpi_itilfollowups', 'glpi_ticketfollowups'] as $t) {
                if ($DB->tableExists($t)) { $DB->update($t, ['users_id' => $auid], ['id' => $fid]); break; }
            }
            if ($assignActingUser) kanpro_ticket_assign($tickets_id, $auid);
        }
        return (bool)$fid;
    } catch (Throwable $e) { return false; }
}

// Soluciona o chamado (forma oficial via ITILSolution; fallback update direto)
function kanpro_ticket_solve(int $tickets_id, string $solution): bool {
    if ($tickets_id <= 0 || !class_exists('Ticket')) return false;
    global $DB;
    try {
        if (class_exists('ITILSolution')) {
            $sol = new ITILSolution();
            $auid = kanpro_acting_user_id();
            $sid = $sol->add(['itemtype' => 'Ticket', 'items_id' => $tickets_id, 'content' => $solution, 'users_id' => $auid]);
            if ($sid) {
                foreach (['glpi_itilsolutions', 'glpi_solution'] as $t) {
                    if ($DB->tableExists($t)) { $DB->update($t, ['users_id' => $auid], ['id' => $sid]); break; }
                }
                return true;
            }
        }
        $tk = new Ticket();
        return (bool)$tk->update(['id' => $tickets_id, 'status' => (defined('Ticket::SOLVED') ? Ticket::SOLVED : 5)]);
    } catch (Throwable $e) { return false; }
}

// Move o chamado para Em atendimento (só se ainda estiver aberto — nunca reabre Solucionado/Fechado)
function kanpro_ticket_set_attending(int $tickets_id): bool {
    if ($tickets_id <= 0 || !class_exists('Ticket')) return false;
    try {
        $tk = new Ticket();
        if (!$tk->getFromDB($tickets_id)) return false;
        $st = (int)($tk->fields['status'] ?? 0);
        $attending = (defined('Ticket::ASSIGNED') ? Ticket::ASSIGNED : 2);
        $solved = (defined('Ticket::SOLVED') ? Ticket::SOLVED : 5);
        $closed = (defined('Ticket::CLOSED') ? Ticket::CLOSED : 6);
        if (in_array($st, [$attending, $solved, $closed], true)) return true;
        return (bool)$tk->update(['id' => $tickets_id, 'status' => $attending]);
    } catch (Throwable $e) { return false; }
}

// Vincula usuário como atribuído (type=2) no chamado, sem duplicar
function kanpro_ticket_assign(int $tickets_id, int $users_id): bool {
    if ($tickets_id <= 0 || $users_id <= 0 || !class_exists('Ticket_User')) return false;
    try {
        $exists = countElementsInTable('glpi_tickets_users', ['tickets_id' => $tickets_id, 'users_id' => $users_id, 'type' => 2]);
        if ($exists) return true;
        $tu = new Ticket_User();
        return (bool)$tu->add(['tickets_id' => $tickets_id, 'users_id' => $users_id, 'type' => 2]);
    } catch (Throwable $e) { return false; }
}

function kanpro_machine_status_label(string $st): string {
    $map = ['' => 'sem status', 'pendente' => 'Pendente', 'garantia' => 'Garantia', 'ok' => 'OK', 'inservivel' => 'Inservível'];
    $k = mb_strtolower(trim($st), 'UTF-8');
    return $map[$k] ?? ($st === '' ? 'sem status' : $st);
}

// Relatório completo das máquinas do card (vai no corpo do acompanhamento do chamado).
// Usa só caracteres de até 3 bytes (nada de emoji 4-byte — quebra no ticket).
function kanpro_card_machines_report(int $cards_id): string {
    global $DB;
    try {
        if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) return '';
        $iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['plugin_kanpro_cards_id' => $cards_id], 'ORDER' => 'seq ASC']);
        $lines = [];
        foreach ($iter as $m) {
            $bits = [];
            $bits[] = 'Status: ' . kanpro_machine_status_label($m['status'] ?? '');
            $bits[] = !empty($m['is_done']) ? 'Feita' : 'Pendente';
            if (!empty($m['is_urgent'])) $bits[] = 'URGENTE';
            if (!empty($m['is_inventoried'])) $bits[] = 'Inventariada';
            $diary = trim($m['diary'] ?? '');
            if ($diary !== '') $bits[] = 'Relatório: ' . mb_substr($diary, 0, 150) . (mb_strlen($diary) > 150 ? '…' : '');
            $lines[] = '• #' . $m['seq'] . ' ' . ($m['label'] ?: $m['model']) . ' — ' . implode(' | ', $bits);
        }
        if (empty($lines)) return '';
        // separador entre "o que mudou" e "como está tudo agora" (só 3-byte: ticket não é utf8mb4)
        return str_repeat('─', 40) . "\nSituação atual das máquinas do cartão (" . count($lines) . "):\n\n" . implode("\n", $lines);
    } catch (Throwable $e) { return ''; }
}

// ---------- Folha Informativa (CIE + A4 + impressão) ----------
// Tabela CIE -> escola (código => nome como aparece nos cartões).
function kanpro_cie_table(): array {
    return [
        '28435' => 'Coripheu de Azevedo Marques', '27259' => 'José dos Santos',
        '30636' => 'Maria Pereira de B. Benetoli', '30624' => 'João Rodrigues Fernandes',
        '27170' => 'Osvaldo Ramos', '27108' => 'Baptista Dolci',
        '30752' => 'Vanir Ferrero Moraes', '27224' => 'Dom Artur Horsthuis',
        '985715' => 'Cel de Jales EE Dom Artur Horsthuis', '27145' => 'Dr. Euphly Jalles',
        '27261' => 'Carlos de Arnaldo Silva', '49700' => 'Sueli da Silveira Marin Batista',
        '27285' => 'Juvenal Giraldelli', '906104' => 'Onélia Faggioni Moreira',
        '28332' => 'Antonio Marin Cruz', '27054' => 'Adelino Bertani',
        '28320' => 'Maria Pilar Ortega Garcia', '28344' => 'Orestes Ferreira de Toledo',
        '27194' => 'Prefeito José Ribeiro', '27182' => 'Zélia de Lourdes Zaccarelli Lopes',
        '28381' => 'Rubens de Oliveira Camargo', '27112' => 'Carlos Celso Lenarduzzi',
        '28393' => 'Prefeito Antonio Bezerra de Araújo', '28400' => 'Professor Itael de Mattos',
        '985806' => 'CEL de Santa Fé do Sul', '28289' => 'Maria das Dores Ferreira Rocha',
        '27133' => 'Francisco Molina Molina', '28290' => 'Domingos Donato Rivelli',
        '27066' => 'Oscar Antônio da Costa', '30582' => 'Coronel Ernesto Schmidt',
        '28319' => 'José Joaquim dos Santos', '27248' => 'Professor Akio Satoru',
        '909993' => 'Elide Apparecida Carlos', '27212' => 'José Teixeira do Amaral',
        '27169' => 'José Nogueira de Souza',
    ];
}
function kanpro_norm_name(string $t): string {
    $t = mb_strtoupper($t, 'UTF-8');
    $map = ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C','ª'=>'A','º'=>'O','–'=>' ','—'=>' ','-'=>' '];
    $t = strtr($t, $map);
    $t = preg_replace('/[^A-Z0-9]+/', ' ', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}
// Retorna [cie, escola] pelo nome do cartão (maior match vence) ou ['', ''].
function kanpro_cie_lookup(string $cardName): array {
    $cn = kanpro_norm_name($cardName);
    if ($cn === '') return ['', ''];
    $best = ['', '', 0];
    foreach (kanpro_cie_table() as $cie => $school) {
        $sn = kanpro_norm_name($school);
        if ($sn !== '' && strpos($cn, $sn) !== false && strlen($sn) > $best[2]) {
            $best = [$cie, $school, strlen($sn)];
        }
    }
    return [$best[0], $best[1]];
}
function kanpro_machine_status_label_pt(string $st): string {
    $m = ['' => '—', 'garantia' => 'Garantia', 'ok' => 'OK', 'inservivel' => 'Inservível', 'pendente' => 'Pendente'];
    return $m[$st] ?? ($st === '' ? '—' : $st);
}
// Dados + HTML A4 da folha informativa das máquinas do cartão.
function kanpro_info_sheet_data(int $cid): ?array {
    global $DB;
    $card = new PluginKanproCard();
    if (!$card->getFromDB($cid)) return null;
    $board = new PluginKanproBoard();
    $board->getFromDB((int)$card->fields['plugin_kanpro_boards_id']);
    $list = new PluginKanproList();
    $list->getFromDB((int)$card->fields['plugin_kanpro_lists_id']);
    $machines = [];
    $miter = $DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['plugin_kanpro_cards_id' => $cid], 'ORDER' => 'seq ASC']);
    foreach ($miter as $m) $machines[] = $m;
    if (!count($machines)) return null;
    [$cie, $school] = kanpro_cie_lookup($card->fields['name'] ?? '');
    $hasStatus = false;
    $hasDiary = false;
    foreach ($machines as $m) {
        if (trim($m['status'] ?? '') !== '') {
            $hasStatus = true;
        }
        if (trim($m['diary'] ?? '') !== '') {
            $hasDiary = true;
        }
        if ($hasStatus && $hasDiary) break;
    }
    $byModel = [];
    foreach ($machines as $m) {
        $k = trim($m['model'] ?? '') ?: '—';
        $byModel[$k] = ($byModel[$k] ?? 0) + 1;
    }
    // pendentes que viraram novo card
    $pendingCard = null;
    $piter = $DB->request(['SELECT' => ['details'], 'FROM' => 'glpi_plugin_kanpro_activities',
        'WHERE' => ['plugin_kanpro_cards_id' => $cid, 'action' => 'maintenance_pending_split'], 'ORDER' => 'date_creation DESC', 'LIMIT' => 5]);
    foreach ($piter as $pa) {
        if (preg_match('/#(\d+)/', $pa['details'] ?? '', $mm)) {
            $nid = (int)$mm[1];
            if ($nid !== $cid) {
                $nc = new PluginKanproCard();
                if ($nc->getFromDB($nid)) {
                    $pendingCard = ['id' => $nid, 'name' => $nc->fields['name']];
                    break;
                }
            }
        }
    }
    $counts = ['garantia' => 0, 'ok' => 0, 'inservivel' => 0, 'pendente' => 0, 'sem' => 0];
    foreach ($machines as $m) {
        $s = trim($m['status'] ?? '');
        if (isset($counts[$s])) $counts[$s]++;
        else $counts['sem']++;
    }
    $me = kanpro_acting_user_id();
    $meName = '';
    try {
        $mu = new User();
        if ($mu->getFromDB($me)) $meName = $mu->getFriendlyName();
    } catch (Throwable $e) {}
    return ['card' => $card->fields, 'board' => $board->fields, 'list' => $list->fields, 'machines' => $machines,
        'cie' => $cie, 'school' => $school, 'hasStatus' => $hasStatus, 'hasDiary' => $hasDiary, 'byModel' => $byModel,
        'pendingCard' => $pendingCard, 'counts' => $counts, 'meName' => $meName];
}
function kanpro_info_sheet_html(array $d): string {
    $esc = function ($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); };
    $card = $d['card'];
    $total = count($d['machines']);
    $now = date('d/m/Y H:i');
    $rows = '';
    foreach ($d['machines'] as $m) {
        $st = kanpro_machine_status_label_pt(trim($m['status'] ?? ''));
        $stBg = ['Garantia' => '#e6f7ff', 'OK' => '#e3fcef', 'Inservível' => '#ffebe6', 'Pendente' => '#fff8e6', '—' => '#f4f5f7'];
        $rows .= '<tr><td style="width:44px;text-align:center"><strong>#' . (int)$m['seq'] . '</strong></td>'
            . '<td>' . $esc($m['model']) . '</td><td>' . $esc($m['label']) . '</td>'
            . ($d['hasStatus'] ? '<td style="text-align:center;background:' . ($stBg[$st] ?? '#fff') . '"><strong>' . $esc($st) . '</strong></td>' : '')
            . '</tr>';
    }
    $models = '';
    foreach ($d['byModel'] as $model => $qtd) {
        $models .= '<span style="display:inline-block;background:#e6fcff;border:1px solid #91d5ff;border-radius:12px;padding:4px 12px;margin:0 6px 6px 0;font-weight:700">' . (int)$qtd . 'x ' . $esc($model) . '</span>';
    }
    $pendBox = '';
    if (!empty($d['pendingCard'])) {
        $pendBox = '<div style="margin-top:10px;background:#fff8e6;border:1px solid #ffab00;border-radius:8px;padding:10px 14px;font-size:13px">'
            . '⏳ <strong>Máquinas pendentes foram para um novo card:</strong> #' . (int)$d['pendingCard']['id'] . ' — ' . $esc($d['pendingCard']['name']) . '</div>';
    }
    $statusRow = '';
    if ($d['hasStatus']) {
        $c = $d['counts'];
        $statusRow = '<div style="display:flex;gap:10px;margin-top:10px">'
            . '<div style="flex:1;background:#e6f7ff;border-radius:8px;padding:8px;text-align:center"><strong style="font-size:16px">' . (int)$c['garantia'] . '</strong><br><small>Garantia</small></div>'
            . '<div style="flex:1;background:#e3fcef;border-radius:8px;padding:8px;text-align:center"><strong style="font-size:16px">' . (int)$c['ok'] . '</strong><br><small>OK</small></div>'
            . '<div style="flex:1;background:#ffebe6;border-radius:8px;padding:8px;text-align:center"><strong style="font-size:16px">' . (int)$c['inservivel'] . '</strong><br><small>Inservível</small></div>'
            . '<div style="flex:1;background:#fff8e6;border-radius:8px;padding:8px;text-align:center"><strong style="font-size:16px">' . (int)$c['pendente'] . '</strong><br><small>Pendente</small></div>'
            . '</div>';
    }
    $diaryBox = '';
    if (!empty($d['hasDiary'])) {
        $diaryBox = '<div style="margin-top:12px"><div style="font-size:12px;font-weight:800;margin-bottom:6px">📝 RELATÓRIO POR MÁQUINA — o que foi feito</div>';
        foreach ($d['machines'] as $m) {
            $txt = trim($m['diary'] ?? '');
            if ($txt === '') continue;
            $diaryBox .= '<div style="border:1px solid #dfe1e6;border-radius:8px;padding:8px 12px;margin-bottom:8px;page-break-inside:avoid">'
                . '<div style="font-size:12px;font-weight:800">#'. (int)$m['seq'] . ' — ' . $esc($m['model']) . ' <span style="font-weight:400;color:#5e6c84">[' . $esc($m['label']) . ']</span></div>'
                . '<div style="font-size:12px;margin-top:4px;white-space:pre-wrap;word-break:break-word">' . nl2br($esc($txt)) . '</div>'
                . '</div>';
        }
        $diaryBox .= '</div>';
    }
    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<title>Folha Informativa — Cartão #' . (int)$card['id'] . '</title>'
        . '<style>@page{size:A4;margin:11mm}*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#172b4d;margin:0}'
        . '.folha{min-height:255mm;display:flex;flex-direction:column}'
        . '.topo{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;border-bottom:3px solid #0052cc;padding-bottom:10px}'
        . '.cie{background:#0052cc;color:#fff;border-radius:10px;padding:8px 18px;text-align:center}'
        . '.cie strong{font-size:26px;display:block}.cie small{font-size:11px}'
        . '.grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;background:#f4f5f7;border-radius:8px;padding:10px 14px;margin-top:10px;font-size:13px}'
        . 'table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px}'
        . 'th{background:#0052cc;color:#fff;padding:7px 8px;text-align:left}td{border:1px solid #dfe1e6;padding:6px 8px}'
        . '.grow{flex:1}.obs{border:1px solid #dfe1e6;border-radius:8px;margin-top:10px;padding:8px 12px;min-height:90px;font-size:12px;color:#5e6c84}'
        . '.sig{display:flex;gap:40px;margin-top:22px}.sig div{flex:1;text-align:center;border-top:1px solid #172b4d;padding-top:6px;font-size:12px}'
        . '.foot{margin-top:12px;text-align:center;color:#97a0af;font-size:10px}'
        . '.no-print{margin:16px 0;text-align:center}@media print{.no-print{display:none}}</style></head><body>'
        . '<div class="folha">'
        . '<div class="topo"><div><div style="font-size:20px;font-weight:800">🔧 FOLHA INFORMATIVA — MANUTENÇÃO</div>'
        . '<div style="font-size:12px;color:#5e6c84">Gerada em ' . $now . ($d['meName'] !== '' ? ' por ' . $esc($d['meName']) : '') . '</div></div>'
        . ($d['cie'] !== '' ? '<div class="cie"><small>CIE</small><strong>' . $esc($d['cie']) . '</strong></div>' : '')
        . '</div>'
        . '<div class="grid">'
        . '<div><strong>Cartão:</strong> #' . (int)$card['id'] . ' — ' . $esc($card['name']) . '</div>'
        . '<div><strong>Escola:</strong> ' . ($d['school'] !== '' ? $esc($d['school']) : '—') . '</div>'
        . '<div><strong>Quadro:</strong> ' . $esc($d['board']['name'] ?? '—') . ' &nbsp;|&nbsp; <strong>Lista:</strong> ' . $esc($d['list']['name'] ?? '—') . '</div>'
        . '<div><strong>Total de máquinas:</strong> ' . $total . '</div>'
        . '</div>'
        . '<div style="margin-top:10px"><div style="font-size:12px;font-weight:700;margin-bottom:4px">MODELOS</div>' . $models . '</div>'
        . '<table><thead><tr><th>#</th><th>Modelo</th><th>Etiqueta</th>' . ($d['hasStatus'] ? '<th style="text-align:center">Status Final</th>' : '') . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . $pendBox . $statusRow . $diaryBox
        . '<div class="grow"></div>'
        . '<div class="obs"><strong>Observações:</strong><br><br><br></div>'
        . '<div class="foot">Documento gerado pelo KanPro • Cartão #' . (int)$card['id'] . ' • ' . $now . '</div>'
        . '</div>'
        . '<div class="no-print"><button onclick="window.print()" style="background:#0052cc;color:#fff;border:none;padding:10px 18px;border-radius:6px;cursor:pointer;font-weight:700">🖨️ Imprimir / Salvar PDF</button> '
        . '<button id="btn-hp" onclick="kpPrintHP(' . (int)$card['id'] . ')" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;border:none;padding:10px 18px;border-radius:6px;cursor:pointer;font-weight:700">🖨️ Imprimir na HP</button></div>'
        . '<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>'
        . '<script>async function kpPrintHP(cid){var btn=document.getElementById("btn-hp");var old=btn?btn.innerHTML:"";if(!confirm("Enviar Folha do cartão #"+String(cid).padStart(4,"0")+" para impressão na HP?\\n\\nSerá impresso exatamente o que você vê nesta prévia (A4)."))return;if(btn){btn.disabled=true;btn.innerHTML="⏳ Gerando PDF...";}var b64=null;try{var el=document.querySelector(".folha");if(el&&window.html2pdf){var opt={margin:[10,10,10,10],filename:"Folha-"+String(cid).padStart(4,"0")+".pdf",image:{type:"jpeg",quality:0.98},html2canvas:{scale:2,useCORS:true,scrollY:0,logging:false},jsPDF:{unit:"mm",format:"a4",orientation:"portrait"}};var uri=await html2pdf().set(opt).from(el).outputPdf("datauristring");b64=(uri.split(",")[1]||null);}}catch(e){b64=null;}if(btn)btn.innerHTML="⏳ Enviando...";try{var ajaxUrl="/plugins/kanpro/front/ajax.php";try{if(window.opener&&window.opener.location&&window.opener.location.pathname){var p=window.opener.location.pathname;if(p.indexOf("/plugins/kanpro/")>=0){ajaxUrl=p.substring(0,p.indexOf("/plugins/kanpro/"))+"/plugins/kanpro/front/ajax.php";}}}catch(e){}var fd=new FormData();fd.append("action","print_info_sheet");fd.append("cards_id",String(cid));if(b64)fd.append("pdf_base64",b64);var r=await fetch(ajaxUrl,{method:"POST",body:fd,credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}});var t=await r.text();var j;try{j=JSON.parse(t);}catch(e){alert("❌ Erro servidor (HTTP "+r.status+")");if(btn){btn.disabled=false;btn.innerHTML=old;}return;}if(j.success){alert("✅ Impressão enviada!\\n"+(j.audit||("Impressora: "+(j.printer||"-")+(j.request_id?" | Job:"+j.request_id:""))));}else{alert("❌ Falha ao imprimir\\n"+(j.msg||"Erro desconhecido"));} }catch(e){alert("Erro de rede: "+e.message);}finally{if(btn){btn.disabled=false;btn.innerHTML=old;}}}</script>'
        . '</body></html>';
}
// HTML -> PDF (mesma cadeia do termo: mPDF, Dompdf, wkhtmltopdf, chromium).
function kanpro_html_to_pdf(string $html): ?string {
    $tag = 'kanpro_folha_' . uniqid();
    $html = kanpro_strip_no_print($html);
    try {
        $mpdf = null;
        if (class_exists('Mpdf\Mpdf')) {
            try {
                $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 11, 'margin_right' => 11, 'margin_top' => 11, 'margin_bottom' => 11, 'tempDir' => sys_get_temp_dir()]);
            } catch (Throwable $e) {
                $mpdf = null;
            }
        }
        if (!$mpdf) {
            $tryPaths = [
                GLPI_ROOT . '/vendor/mpdf/mpdf/src/Mpdf.php',
                GLPI_ROOT . '/vendor/mpdf/mpdf/autoload.php',
                GLPI_ROOT . '/vendor/autoload.php',
                GLPI_ROOT . '/lib/mpdf/autoload.php',
                GLPI_ROOT . '/lib/mpdf/src/Mpdf.php',
            ];
            foreach ($tryPaths as $p) {
                if (!is_string($p) || !file_exists($p)) continue;
                try {
                    @require_once $p;
                    if (class_exists('Mpdf\Mpdf')) {
                        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 11, 'margin_right' => 11, 'margin_top' => 11, 'margin_bottom' => 11, 'tempDir' => sys_get_temp_dir()]);
                        break;
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }
        }
        if ($mpdf) {
            $path = sys_get_temp_dir() . '/' . $tag . '.pdf';
            try {
                $mpdf->WriteHTML($html);
                $mpdf->Output($path, \Mpdf\Output\Destination::FILE);
                if (file_exists($path) && filesize($path) > 500) return $path;
            } catch (Throwable $e) {}
            @unlink($path);
        }
    } catch (Throwable $e) {}
    try {
        if (class_exists('Dompdf\Dompdf') || class_exists('Dompdf\Options')) {
            if (!class_exists('Dompdf\Dompdf') && file_exists(GLPI_ROOT . '/vendor/dompdf/dompdf/src/Dompdf.php')) {
                @require_once GLPI_ROOT . '/vendor/autoload.php';
            }
            if (class_exists('Dompdf\Dompdf')) {
                $opts = class_exists('Dompdf\Options') ? new \Dompdf\Options() : null;
                if ($opts) {
                    $opts->set('isRemoteEnabled', true);
                    $opts->set('isHtml5ParserEnabled', true);
                    $dompdf = new \Dompdf\Dompdf($opts);
                } else {
                    $dompdf = new \Dompdf\Dompdf();
                }
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4');
                $dompdf->render();
                $out = $dompdf->output();
                $path = sys_get_temp_dir() . '/' . $tag . '.pdf';
                file_put_contents($path, $out);
                if (file_exists($path) && filesize($path) > 500) return $path;
                @unlink($path);
            }
        }
    } catch (Throwable $e) {}
    try {
        if (function_exists('exec') && is_callable('exec')) {
            $wk = trim((string)@shell_exec('which wkhtmltopdf 2>&1'));
            if ($wk && !str_contains($wk, 'not found') && file_exists(trim($wk))) {
                $wkBin = trim(explode("\n", $wk)[0]);
                $htmlPath = sys_get_temp_dir() . '/' . $tag . '.html';
                $pdfPath = sys_get_temp_dir() . '/' . $tag . '.pdf';
                file_put_contents($htmlPath, $html);
                $cmd = escapeshellarg($wkBin) . ' --enable-local-file-access --encoding utf-8 --page-size A4 --margin-top 10mm --margin-bottom 10mm --margin-left 10mm --margin-right 10mm ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
                $out = [];
                $ret = -1;
                @exec($cmd, $out, $ret);
                @unlink($htmlPath);
                if ($ret === 0 && file_exists($pdfPath) && filesize($pdfPath) > 500) return $pdfPath;
                @unlink($pdfPath);
            }
            $chrome = trim((string)@shell_exec('which chromium-browser 2>&1'));
            if (!$chrome || str_contains($chrome, 'not found')) $chrome = trim((string)@shell_exec('which google-chrome 2>&1'));
            if (!$chrome || str_contains($chrome, 'not found')) $chrome = trim((string)@shell_exec('which chromium 2>&1'));
            if ($chrome && !str_contains($chrome, 'not found') && file_exists(trim(explode("\n", $chrome)[0]))) {
                $chromeBin = trim(explode("\n", $chrome)[0]);
                $htmlPath = sys_get_temp_dir() . '/' . $tag . '.html';
                $pdfPath = sys_get_temp_dir() . '/' . $tag . '.pdf';
                file_put_contents($htmlPath, $html);
                $cmd = escapeshellarg($chromeBin) . ' --headless --disable-gpu --no-sandbox --print-to-pdf=' . escapeshellarg($pdfPath) . ' ' . escapeshellarg('file://' . $htmlPath) . ' 2>&1';
                $out = [];
                $ret = -1;
                @exec($cmd, $out, $ret);
                @unlink($htmlPath);
                if (file_exists($pdfPath) && filesize($pdfPath) > 500) return $pdfPath;
                @unlink($pdfPath);
            }
        }
    } catch (Throwable $e) {}
    // Fallback final: PDF puro em PHP (sem dependencias) extraindo texto do HTML.
    // Garante que a folha sempre gera algo imprimivel, igual ao termo no assetmgrstatus.
    try {
        $txt = html_entity_decode(strip_tags(preg_replace('/<(br|p|div|tr|h[1-6])[^>]*>/i', "\n\$0", $html)), ENT_QUOTES, 'UTF-8');
        $txt = preg_replace("/[ \t]+/", ' ', $txt);
        $txt = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $txt);
        $lines = explode("\n", trim($txt));
        if (count($lines) > 3) {
            $pdfPath = sys_get_temp_dir() . '/' . $tag . '.pdf';
            if (kanpro_build_simple_pdf_from_lines($lines, $pdfPath)) {
                if (file_exists($pdfPath) && filesize($pdfPath) > 500) return $pdfPath;
                @unlink($pdfPath);
            }
        }
    } catch (Throwable $e) {}
    return null;
}
// Construtor de PDF puro (Helvetica core, sem libs) — copia do assetmgrstatus.
function kanpro_build_simple_pdf_from_lines(array $lines, string $outPath): bool {
    $clean = [];
    foreach ($lines as $l) {
        $l = (string)$l;
        $l = str_replace("\r", '', $l);
        foreach (explode("\n", $l) as $part) {
            $part = trim($part);
            if ($part === '') { $clean[] = ''; continue; }
            $partIso = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $part);
            if ($partIso === false) $partIso = $part;
            $words = explode(' ', $partIso);
            $cur = '';
            foreach ($words as $w) {
                if (strlen($cur . ' ' . $w) > 85) { $clean[] = trim($cur); $cur = $w; }
                else { $cur = $cur === '' ? $w : $cur . ' ' . $w; }
            }
            if ($cur !== '') $clean[] = trim($cur);
        }
    }
    $pages = array_chunk($clean, 45);
    if (empty($pages)) $pages = [[]];
    $fontObjNum = 3; $catalogNum = 1; $pagesNum = 2;
    $pageObjNums = []; $contentObjNums = []; $nextNum = 4;
    foreach ($pages as $i => $pg) { $pageObjNums[$i] = $nextNum++; $contentObjNums[$i] = $nextNum++; }
    $totalObjs = $nextNum - 1;
    $esc = function($s) { return str_replace(['\\','(',')',"\r"], ['\\\\','\\(','\\)','\\r'], $s); };
    $objs = [];
    $objs[$catalogNum] = "<< /Type /Catalog /Pages $pagesNum 0 R >>";
    $kids = implode(' ', array_map(fn($n) => "$n 0 R", $pageObjNums));
    $objs[$pagesNum] = "<< /Type /Pages /Kids [$kids] /Count " . count($pages) . " >>";
    $objs[$fontObjNum] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    foreach ($pages as $idx => $pgLines) {
        $pNum = $pageObjNums[$idx]; $cNum = $contentObjNums[$idx];
        $content = "BT\n/F1 9 Tf\n";
        $y = 800;
        foreach ($pgLines as $line) {
            $content .= sprintf("1 0 0 1 40 %.2F Tm (%s) Tj\n", $y, $esc($line));
            $y -= 13;
            if ($y < 40) break;
        }
        $content .= sprintf("1 0 0 1 500 20 Tm (%d/%d) Tj\n", $idx+1, count($pages));
        $content .= "ET\n";
        $objs[$cNum] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
        $objs[$pNum] = "<< /Type /Page /Parent $pagesNum 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 $fontObjNum 0 R >> >> /Contents $cNum 0 R >>";
    }
    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    for ($i=1; $i<=$totalObjs; $i++) { $offsets[$i] = strlen($pdf); $pdf .= $i . " 0 obj\n" . $objs[$i] . "\nendobj\n"; }
    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . ($totalObjs+1) . "\n0000000000 65535 f \n";
    for ($i=1; $i<=$totalObjs; $i++) { $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]); }
    $pdf .= "trailer\n<< /Size " . ($totalObjs+1) . " /Root $catalogNum 0 R >>\nstartxref\n$xrefPos\n%%EOF\n";
    return @file_put_contents($outPath, $pdf) !== false;
}
// PDF simples estruturado da folha (usado quando mPDF/Dompdf/wkhtml falham).
function kanpro_info_sheet_simple_pdf(array $d): ?string {
    try {
        $card = $d['card']; $total = count($d['machines']);
        $lines = [];
        $lines[] = 'FOLHA INFORMATIVA - MANUTENCAO  -  Cartao #' . str_pad((int)$card['id'], 4, '0', STR_PAD_LEFT) . '  -  ' . date('d/m/Y H:i');
        $lines[] = str_repeat('=', 85);
        $lines[] = 'Cartao: #' . (int)$card['id'] . ' - ' . ($card['name'] ?? '');
        $lines[] = 'Escola: ' . ($d['school'] !== '' ? $d['school'] . '  (CIE ' . $d['cie'] . ')' : '-');
        $lines[] = 'Quadro: ' . ($d['board']['name'] ?? '-') . '  |  Lista: ' . ($d['list']['name'] ?? '-');
        $lines[] = 'Total de maquinas: ' . $total;
        $lines[] = '';
        $lines[] = 'Modelos: ' . implode('  |  ', array_map(fn($q,$m) => $q . 'x ' . $m, array_values($d['byModel']), array_keys($d['byModel'])));
        $lines[] = str_repeat('-', 85);
        $lines[] = 'MAQUINAS:';
        foreach ($d['machines'] as $m) {
            $st = kanpro_machine_status_label_pt(trim($m['status'] ?? ''));
            $lines[] = '#' . (int)$m['seq'] . '. ' . ($m['model'] ?? '-') . '  [' . ($m['label'] ?? '-') . ']' . ($d['hasStatus'] ? '  Status: ' . $st : '');
        }
        if (!empty($d['hasDiary'])) {
            $lines[] = '';
            $lines[] = 'RELATORIO POR MAQUINA:';
            $lines[] = str_repeat('-', 85);
            foreach ($d['machines'] as $m) {
                $txt = trim($m['diary'] ?? '');
                if ($txt === '') continue;
                $lines[] = '#' . (int)$m['seq'] . ' ' . ($m['model'] ?? '-') . ': ' . mb_substr(preg_replace('/\s+/', ' ', $txt), 0, 300);
            }
        }
        if (!empty($d['pendingCard'])) $lines[] = 'Pendentes foram para o card #' . (int)$d['pendingCard']['id'] . ' - ' . $d['pendingCard']['name'];
        $lines[] = '';
        $lines[] = 'Observacoes:';
        $lines[] = '';
        $lines[] = str_repeat('-', 85);
        $lines[] = 'Documento gerado pelo KanPro - Cartao #' . (int)$card['id'] . ' - ' . date('d/m/Y H:i');
        $path = sys_get_temp_dir() . '/kanpro_folha_' . uniqid() . '.pdf';
        if (kanpro_build_simple_pdf_from_lines($lines, $path)) {
            if (file_exists($path) && filesize($path) > 500) return $path;
            @unlink($path);
        }
    } catch (Throwable $e) {}
    return null;
}
// Remove blocos .no-print e scripts antes de gerar PDF no servidor (mPDF/Dompdf ignoram @media print).
function kanpro_strip_no_print(string $html): string {
    $html = preg_replace('/<div class="no-print".*?<\/div>/is', '', $html);
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    return $html;
}
// Envia PDF ao CUPS: 1 cópia, A4, duplex automático (2+ págs) — folha só precisa de 1.
function kanpro_print_pdf_cups(string $pdf_path, string $title, ?string $preferred_printer = null): array {
    try {
        if (!file_exists($pdf_path)) return ['ok' => false, 'error' => 'PDF não encontrado'];
        $head = @file_get_contents($pdf_path, false, null, 0, 5);
        if ($head !== '%PDF-') {
            @unlink($pdf_path);
            return ['ok' => false, 'error' => 'Arquivo não é PDF válido'];
        }
        $size = filesize($pdf_path);
        if ($size !== false && $size > 15 * 1024 * 1024) {
            @unlink($pdf_path);
            return ['ok' => false, 'error' => 'PDF muito grande'];
        }
        $pages = 0;
        try {
            $raw = @file_get_contents($pdf_path);
            if ($raw !== false) {
                $pages = substr_count($raw, '/Type /Page') - substr_count($raw, '/Type /Pages');
                if ($pages <= 0) $pages = (int)preg_match_all('/\/Type\s*\/Page[^s]/', $raw);
            }
        } catch (Throwable $e) {}
        $duplex = ((int)$pages > 1);
        $sidesOpt = $duplex ? 'two-sided-long-edge' : 'one-sided';
        $printer = ($preferred_printer !== null && trim($preferred_printer) !== '') ? trim($preferred_printer) : null;
        if ($printer === null && class_exists('\\GlpiPlugin\\Assetmgrstatus\\Transfer')) {
            try {
                $printer = \GlpiPlugin\Assetmgrstatus\Transfer::findHpPrinter();
            } catch (Throwable $e) {}
        }
        if (!function_exists('exec') || !is_callable('exec')) {
            @unlink($pdf_path);
            return ['ok' => false, 'error' => 'exec desabilitado no PHP'];
        }
        $hasLp = trim((string)@shell_exec('which lp 2>&1')) !== '' && !str_contains(trim((string)@shell_exec('which lp 2>&1')), 'not found');
        $hasLpr = trim((string)@shell_exec('which lpr 2>&1')) !== '' && !str_contains(trim((string)@shell_exec('which lpr 2>&1')), 'not found');
        if ($printer === null || $printer === '') {
            @unlink($pdf_path);
            return ['ok' => false, 'error' => 'Nenhuma impressora no CUPS'];
        }
        @chmod($pdf_path, 0644);
        $stdOpts = '-n 1 -o media=A4 -o fit-to-page -o sides=' . $sidesOpt . ' -o Resolution=600dpi -o print-quality=5';
        $output = [];
        $ret = -1;
        $printed = false;
        $lastOut = '';
        if ($hasLp) {
            $cmd = 'lp -d ' . escapeshellarg($printer) . ' -t ' . escapeshellarg($title) . ' ' . $stdOpts . ' -o ColorModel=Color ' . escapeshellarg($pdf_path) . ' 2>&1';
            @exec($cmd, $output, $ret);
            $lastOut = implode("\n", $output);
            if ($ret === 0) $printed = true;
        }
        if (!$printed && $hasLpr) {
            $output = [];
            $cmd = 'lpr -P ' . escapeshellarg($printer) . ' -# 1 -o media=A4 -o fit-to-page -o sides=' . $sidesOpt . ' -o Resolution=600dpi -o print-quality=5 ' . escapeshellarg($pdf_path) . ' 2>&1';
            @exec($cmd, $output, $ret);
            $lastOut = implode("\n", $output);
            if ($ret === 0) $printed = true;
        }
        $request_id = '';
        if ($printed && preg_match('/request id is\s+(\S+)/i', $lastOut, $m)) $request_id = $m[1];
        elseif ($printed && preg_match('/(\S+-\d+)/', $lastOut, $m)) $request_id = $m[1];
        @unlink($pdf_path);
        if (!$printed) return ['ok' => false, 'error' => 'Falha ao enviar para ' . $printer, 'output' => $lastOut];
        return ['ok' => true, 'printer' => $printer, 'request_id' => $request_id, 'output' => $lastOut,
            'audit' => 'Folha informativa | ' . date('d/m/Y H:i') . ' | Impressora: ' . $printer . ($request_id ? ' | Job: ' . $request_id : '') . ($duplex ? ' | frente e verso' : '')];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

switch ($action) {

    // --- BOARD ---
    case 'rename_board':
        needEdit();
        $id = (int)($_POST['boards_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$name) jexit(['success'=>false,'msg'=>'Nome obrigatório']);
        $b = new PluginKanproBoard();
        if (!$b->getFromDB($id)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        $b->update(['id'=>$id,'name'=>$name]);
        PluginKanproBoard::logActivity($id, null, null, 'board_rename', "Renomeado para {$name}");
        jexit(['success'=>true]);

    case 'star_board':
        needEdit();
        $id = (int)($_POST['boards_id'] ?? 0);
        $b = new PluginKanproBoard();
        $b->getFromDB($id);
        $new = $b->fields['is_starred'] ? 0 : 1;
        $b->update(['id'=>$id,'is_starred'=>$new]);
        jexit(['success'=>true,'is_starred'=>$new]);

    case 'archive_board':
        needEdit();
        $id = (int)($_POST['boards_id'] ?? 0);
        $b = new PluginKanproBoard();
        $b->getFromDB($id);
        $b->update(['id'=>$id,'is_archived'=> $b->fields['is_archived'] ? 0 : 1]);
        jexit(['success'=>true]);

    case 'delete_board':
        if (!Session::haveRight('plugin_kanpro', PURGE)) jexit(['success'=>false,'msg'=>'Sem permissão PURGE']);
        $id = (int)($_POST['boards_id'] ?? 0);
        $b = new PluginKanproBoard();
        $b->delete(['id'=>$id], true);
        jexit(['success'=>true]);

    case 'update_board_color':
        needEdit();
        $id = (int)($_POST['boards_id'] ?? 0);
        $color = trim($_POST['color'] ?? '#0079bf');
        $isHex = (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $color);
        $isGrad = (strpos($color, 'linear-gradient') === 0);
        if (!$isHex && !$isGrad) {
            jexit(['success'=>false,'msg'=>'Cor inválida — use hex #rrggbb ou degradê']);
        }
        if (strlen($color) > 255) $color = substr($color, 0, 255);
        // schema em hook.php (+ migrate_once no topo); aqui só retry se a coluna antiga estourar
        try {
            $DB->update('glpi_plugin_kanpro_boards', ['color'=>$color], ['id'=>$id]);
            if ($DB->error() && stripos($DB->error(), 'Data too long') !== false) {
                // coluna antiga VARCHAR(20): tenta ampliar de novo e só salva se couber — nunca salva degradê truncado (quebrava o CSS da capa)
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` MODIFY `color` VARCHAR(255) NOT NULL DEFAULT '#0079bf'"); } catch (Throwable $e2) {}
                $DB->update('glpi_plugin_kanpro_boards', ['color'=>$color], ['id'=>$id]);
                if ($DB->error()) jexit(['success'=>false,'msg'=>'A coluna de cor do banco é antiga e não pôde ser ampliada automaticamente. Peça ao admin para rodar: ALTER TABLE glpi_plugin_kanpro_boards MODIFY color VARCHAR(255).']);
            } elseif ($DB->error()) {
                jexit(['success'=>false,'msg'=>'Erro ao salvar: '.$DB->error()]);
            }
        } catch (Throwable $e) {
            jexit(['success'=>false,'msg'=>'Erro ao salvar: '.$e->getMessage()]);
        }
        jexit(['success'=>true]);

    case 'upload_board_background':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $board = new PluginKanproBoard();
        if (!$board->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) jexit(['success'=>false,'msg'=>'Nenhum arquivo enviado']);
        // schema em hook.php — sem ALTER aqui (migrate_once já rodou no topo)
        $rel = PluginKanproBoard::handleBackgroundUpload($bid, $_FILES['file']);
        if (!$rel) jexit(['success'=>false,'msg'=>'Falha ao salvar imagem — verifique formato (JPG/PNG/WebP/GIF) e tamanho máximo 5MB. Resolução recomendada 1920×1080 (16:9)']);
        $board->getFromDB($bid);
        jexit(['success'=>true,'background'=>$rel,'url'=>PluginKanproBoard::getBackgroundImageUrl($bid, $rel)]);

    case 'remove_board_background':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $board = new PluginKanproBoard();
        if (!$board->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        PluginKanproBoard::deleteBackgroundFile($bid);
        jexit(['success'=>true]);

    case 'set_board_wallpaper':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $key = trim($_POST['wallpaper'] ?? '');
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $board = new PluginKanproBoard();
        if (!$board->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        $rel = PluginKanproBoard::setBoardWallpaper($bid, $key);
        if (!$rel) jexit(['success'=>false,'msg'=>'Papel de parede inválido']);
        $board->getFromDB($bid);
        jexit(['success'=>true,'background'=>$rel,'url'=>PluginKanproBoard::getBackgroundImageUrl($bid, $rel)]);

    case 'set_board_whatsapp':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $boardW = new PluginKanproBoard();
        if (!$boardW->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        // só admin do quadro pode ligar/desligar
        if (!kanpro_can_manage_members($bid)) {
            jexit(['success'=>false,'msg'=>'Somente admin do quadro pode alterar a Notificação WhatsApp.']);
        }
        $enabledW = !empty($_POST['enabled']) ? 1 : 0;
        if (isset($_POST['whatsapp_notify'])) $enabledW = ((int)$_POST['whatsapp_notify'] ? 1 : 0);
        try {
            if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'whatsapp_notify')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` ADD `whatsapp_notify` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1=notificacao whatsapp do quadro ligada' AFTER `visibility`");
            }
            $DB->update('glpi_plugin_kanpro_boards', ['whatsapp_notify'=>$enabledW,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$bid]);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Falha ao salvar']); }
        PluginKanproBoard::logActivity($bid, null, null, 'board_whatsapp', ($enabledW ? 'Notificação WhatsApp ATIVADA' : 'Notificação WhatsApp desativada'));
        jexit(['success'=>true,'whatsapp_notify'=>$enabledW]);

    case 'send_board_whatsapp':
        $bid = (int)($_POST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $boardS = new PluginKanproBoard();
        if (!$boardS->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        if (!kanpro_can_view_board($bid)) jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
        if (empty($boardS->fields['whatsapp_notify'])) jexit(['success'=>false,'msg'=>'Notificação WhatsApp desligada neste quadro.']);
        // só Membro ou Admin do quadro pode apertar o botão
        $boardRoleS = kanpro_my_board_role($bid);
        if ($boardRoleS === null) {
            $meIdsS = array_unique([kanpro_acting_user_id(), (int)Session::getLoginUserID()]);
            if (!in_array((int)($boardS->fields['users_id'] ?? 0), $meIdsS, true)) {
                jexit(['success'=>false,'msg'=>'Somente Membro ou Admin do quadro pode notificar.']);
            }
        }
        if (!class_exists('PluginKanproMaintenanceZap')) jexit(['success'=>false,'msg'=>'Remetente WhatsApp indisponível']);
        try {
            $resS = PluginKanproMaintenanceZap::sendBoardAlerta($bid);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Erro ao enviar: '.$e->getMessage()]); }
        if (!empty($resS['ok'])) jexit(['success'=>true,'phone'=>($resS['phone'] ?? '')]);
        $errS = (string)($resS['error'] ?? 'falha');
        if ($errS === 'sem telefone') {
            $apprS = PluginKanproMaintenanceZap::pendenciaApprover();
            $errS = $apprS !== '' ? "Aprovador sem telefone cadastrado ({$apprS})" : 'Aprovador não configurado — ajuste nas Configurações do KanPro.';
        }
        jexit(['success'=>false,'msg'=>'WhatsApp não enviado: '.$errS]);

    case 'invite_member':
        $bid = (int)($_POST['boards_id'] ?? 0);
        $uid = (int)($_POST['users_id'] ?? 0);
        $role = $_POST['role'] ?? 'member';
        if (!in_array($role, ['admin','member','observer'], true)) $role = 'member';
        if (!$bid || !$uid) jexit(['success'=>false,'msg'=>'Quadro ou usuário inválido']);
        kanpro_need_manage_members($bid);
        $DB->insert('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>$uid,'role'=>$role,'date_creation'=>date('Y-m-d H:i:s')]);
        // ignora duplicado
        if ($DB->error() && strpos($DB->error(), 'Duplicate')!==false) jexit(['success'=>false,'msg'=>'Usuário já é membro']);
        PluginKanproBoard::logActivity($bid, null, null, 'member_add', "Membro {$uid} adicionado ({$role})");
        jexit(['success'=>true]);

    case 'remove_member':
        $bid = (int)($_POST['boards_id'] ?? 0);
        $uid = (int)($_POST['users_id'] ?? 0);
        if (!$bid || !$uid) jexit(['success'=>false,'msg'=>'Quadro ou usuário inválido']);
        kanpro_need_manage_members($bid);
        // não permite remover o criador nem se auto-remover sendo o último gestor
        $bchk = new PluginKanproBoard();
        if ($bchk->getFromDB($bid) && (int)($bchk->fields['users_id'] ?? 0) === $uid) {
            jexit(['success'=>false,'msg'=>'O criador do quadro não pode ser removido.']);
        }
        if (in_array($uid, [(int)Session::getLoginUserID(), kanpro_acting_user_id()], true) && kanpro_count_other_managers($bid, $uid) === 0) {
            jexit(['success'=>false,'msg'=>'Você é o último gestor. Promova outra pessoa a admin antes de sair.']);
        }
        $DB->delete('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>$uid]);
        PluginKanproBoard::logActivity($bid, null, null, 'member_remove', "Membro {$uid} removido");
        jexit(['success'=>true]);

    case 'set_member_role':
        $bid = (int)($_POST['boards_id'] ?? 0);
        $uid = (int)($_POST['users_id'] ?? 0);
        $role = $_POST['role'] ?? 'member';
        if (!in_array($role, ['admin','member','observer'], true)) jexit(['success'=>false,'msg'=>'Papel inválido (use admin, member ou observer)']);
        if (!$bid || !$uid) jexit(['success'=>false,'msg'=>'Quadro ou usuário inválido']);
        kanpro_need_manage_members($bid);
        $bchk = new PluginKanproBoard();
        if ($bchk->getFromDB($bid) && (int)($bchk->fields['users_id'] ?? 0) === $uid) {
            jexit(['success'=>false,'msg'=>'O criador do quadro já tem acesso total.']);
        }
        $exists = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>$uid]);
        if (!$exists) jexit(['success'=>false,'msg'=>'Usuário não é membro do quadro']);
        // não permite se rebaixar sendo o último gestor
        if ($role !== 'admin' && in_array($uid, [(int)Session::getLoginUserID(), kanpro_acting_user_id()], true) && kanpro_count_other_managers($bid, $uid) === 0) {
            jexit(['success'=>false,'msg'=>'Você é o último gestor. Promova outra pessoa a admin antes.']);
        }
        $DB->update('glpi_plugin_kanpro_boards_members', ['role'=>$role], ['plugin_kanpro_boards_id'=>$bid,'users_id'=>$uid]);
        PluginKanproBoard::logActivity($bid, null, null, 'member_role', "Membro {$uid} agora é {$role}");
        jexit(['success'=>true]);

    case 'invite_profile':
        $bid = (int)($_POST['boards_id'] ?? 0);
        $pid = (int)($_POST['profiles_id'] ?? 0);
        $role = $_POST['role'] ?? 'member';
        if (!in_array($role, ['admin','member'], true)) $role = 'member';
        if (!$bid || !$pid) jexit(['success'=>false,'msg'=>'Quadro ou perfil inválido']);
        kanpro_need_manage_members($bid);
        if (!$DB->tableExists('glpi_profiles')) jexit(['success'=>false,'msg'=>'Tabela de perfis indisponível']);
        $pex = $DB->request(['FROM' => 'glpi_profiles', 'WHERE' => ['id' => $pid]])->current();
        if (!$pex) jexit(['success'=>false,'msg'=>'Perfil não encontrado']);
        $DB->insert('glpi_plugin_kanpro_boards_profiles', ['plugin_kanpro_boards_id'=>$bid,'profiles_id'=>$pid,'role'=>$role,'date_creation'=>date('Y-m-d H:i:s')]);
        if ($DB->error() && strpos($DB->error(), 'Duplicate')!==false) jexit(['success'=>false,'msg'=>'Perfil já tem acesso']);
        PluginKanproBoard::logActivity($bid, null, null, 'member_add', "Perfil {$pid} (" . ($pex['name'] ?? '') . ") adicionado ({$role})");
        jexit(['success'=>true]);

    case 'remove_profile':
        $bid = (int)($_POST['boards_id'] ?? 0);
        $pid = (int)($_POST['profiles_id'] ?? 0);
        if (!$bid || !$pid) jexit(['success'=>false,'msg'=>'Quadro ou perfil inválido']);
        kanpro_need_manage_members($bid);
        // não permite se trancar para fora: garante outro caminho de gestão (criador, admin direto, UPDATE ou outro perfil admin)
        $bchk2 = new PluginKanproBoard();
        $isCreator = $bchk2->getFromDB($bid) && (int)($bchk2->fields['users_id'] ?? 0) === (int)Session::getLoginUserID();
        $hasDirect = false;
        foreach (array_unique([kanpro_acting_user_id(), (int)Session::getLoginUserID()]) as $auid) {
            if ($auid <= 0) continue;
            $ar = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $bid, 'users_id' => $auid, 'role' => 'admin']])->current();
            if ($ar) { $hasDirect = true; break; }
        }
        $hasOtherProf = false;
        $myPids = kanpro_my_profile_ids();
        foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_boards_profiles', 'WHERE' => ['plugin_kanpro_boards_id' => $bid, 'role' => 'admin']]) as $pr) {
            if ((int)$pr['profiles_id'] !== $pid && in_array((int)$pr['profiles_id'], $myPids, true)) { $hasOtherProf = true; break; }
        }
        if (!$isCreator && !$hasDirect && !Session::haveRight('plugin_kanpro', UPDATE) && !$hasOtherProf) {
            jexit(['success'=>false,'msg'=>'Você perderia a gestão deste quadro. Promova outro acesso a admin antes.']);
        }
        $DB->delete('glpi_plugin_kanpro_boards_profiles', ['plugin_kanpro_boards_id'=>$bid,'profiles_id'=>$pid]);
        PluginKanproBoard::logActivity($bid, null, null, 'member_remove', "Perfil {$pid} removido");
        jexit(['success'=>true]);

    case 'set_profile_role':
        $bid = (int)($_POST['boards_id'] ?? 0);
        $pid = (int)($_POST['profiles_id'] ?? 0);
        $role = $_POST['role'] ?? 'member';
        if (!in_array($role, ['admin','member'], true)) jexit(['success'=>false,'msg'=>'Papel inválido (use admin ou member)']);
        if (!$bid || !$pid) jexit(['success'=>false,'msg'=>'Quadro ou perfil inválido']);
        kanpro_need_manage_members($bid);
        $exists = countElementsInTable('glpi_plugin_kanpro_boards_profiles', ['plugin_kanpro_boards_id'=>$bid,'profiles_id'=>$pid]);
        if (!$exists) jexit(['success'=>false,'msg'=>'Perfil não tem acesso ao quadro']);
        $DB->update('glpi_plugin_kanpro_boards_profiles', ['role'=>$role], ['plugin_kanpro_boards_id'=>$bid,'profiles_id'=>$pid]);
        PluginKanproBoard::logActivity($bid, null, null, 'member_role', "Perfil {$pid} agora é {$role}");
        jexit(['success'=>true]);

    // --- FAMÍLIA DE QUADROS (pai/filhos p/ navegação rápida no header) ---
    case 'set_board_parent':
        $bid = (int)($_POST['boards_id'] ?? 0);
        $parentId = max(0, (int)($_POST['parent_boards_id'] ?? 0));
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        kanpro_require_board_view($bid);
        // mover B exige gerenciar B (não o pai) — vale p/ vincular e desvincular
        if (!function_exists('kanpro_can_manage_members') || !kanpro_can_manage_members($bid)) {
            jexit(['success'=>false,'msg'=>'Somente o criador ou admin do quadro pode trocar a família.']);
        }
        if (!function_exists('kanpro_set_board_parent')) jexit(['success'=>false,'msg'=>'Recurso indisponível (atualize o plugin)']);
        [$ok, $msg] = kanpro_set_board_parent($bid, $parentId);
        if (!$ok) jexit(['success'=>false,'msg'=>$msg]);
        PluginKanproBoard::logActivity($bid, null, null, 'board_family', $parentId > 0 ? "Família: agora filho do quadro #{$parentId}" : "Família: removido do quadro pai (virou raiz)");
        jexit(['success'=>true]);

    // --- BUTLER-LIKE (automações: ao entrar na lista -> ação) ---
    case 'rule_list':
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        kanpro_require_board_view($bid);
        $rules = [];
        try {
            if ($DB->tableExists('glpi_plugin_kanpro_rules')) {
                foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_rules', 'WHERE' => ['plugin_kanpro_boards_id' => $bid], 'ORDER' => 'id ASC']) as $r) {
                    $rules[] = ['id' => (int)$r['id'], 'lists_id' => (int)$r['plugin_kanpro_lists_id'],
                        'action' => (string)$r['action'], 'params' => (string)($r['params'] ?? ''),
                        'is_active' => (int)$r['is_active']];
                }
            }
        } catch (Throwable $e) {}
        jexit(['success'=>true,'rules'=>$rules,'can_manage'=>function_exists('kanpro_can_manage_members') ? kanpro_can_manage_members($bid) : false]);

    case 'rule_add':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $lists_id = (int)($_POST['lists_id'] ?? 0);
        $action = trim($_POST['rule_action'] ?? '');
        $params = trim($_POST['params'] ?? '');
        if (!$bid || !$lists_id) jexit(['success'=>false,'msg'=>'Quadro ou lista inválidos']);
        if (!in_array($action, ['add_label','assign_member','set_due_days'], true)) jexit(['success'=>false,'msg'=>'Ação inválida']);
        kanpro_require_board_edit($bid);
        if (function_exists('kanpro_board_id_for_list') && kanpro_board_id_for_list($lists_id) !== $bid) {
            jexit(['success'=>false,'msg'=>'Lista de outro quadro']);
        }
        // valida params por ação
        if ($action === 'add_label') {
            $lok = $DB->request(['FROM' => 'glpi_plugin_kanpro_labels', 'WHERE' => ['id' => (int)$params, 'plugin_kanpro_boards_id' => $bid]])->current();
            if (!$lok) jexit(['success'=>false,'msg'=>'Etiqueta inválida']);
            $params = (string)(int)$params;
        } elseif ($action === 'assign_member') {
            $uid = (int)$params;
            if ($uid <= 0) jexit(['success'=>false,'msg'=>'Membro inválido']);
            $params = (string)$uid;
        } elseif ($action === 'set_due_days') {
            if (!is_numeric($params) || (int)$params < 0 || (int)$params > 365) jexit(['success'=>false,'msg'=>'Dias inválidos (0-365)']);
            $params = (string)(int)$params;
        }
        if (!$DB->tableExists('glpi_plugin_kanpro_rules')) jexit(['success'=>false,'msg'=>'Tabela de regras ausente (reinstale o plugin)']);
        $rid = $DB->insert('glpi_plugin_kanpro_rules', ['plugin_kanpro_boards_id' => $bid, 'trigger' => 'enter_list',
            'plugin_kanpro_lists_id' => $lists_id, 'action' => $action, 'params' => $params, 'is_active' => 1,
            'users_id' => (int)Session::getLoginUserID(), 'date_creation' => date('Y-m-d H:i:s'), 'date_mod' => date('Y-m-d H:i:s')]);
        if (!$rid) jexit(['success'=>false,'msg'=>'Não foi possível salvar a regra']);
        jexit(['success'=>true,'id'=>(int)$rid]);

    case 'rule_delete':
        needEdit();
        $rid = (int)($_POST['id'] ?? 0);
        if (!$rid) jexit(['success'=>false,'msg'=>'Regra inválida']);
        $rr = $DB->request(['FROM' => 'glpi_plugin_kanpro_rules', 'WHERE' => ['id' => $rid]])->current();
        if (!$rr) jexit(['success'=>false,'msg'=>'Regra não encontrada']);
        kanpro_require_board_edit((int)$rr['plugin_kanpro_boards_id']);
        $DB->delete('glpi_plugin_kanpro_rules', ['id' => $rid]);
        jexit(['success'=>true]);

    // --- GRUPOS PESSOAIS DE QUADROS (cada usuário organiza os seus do seu jeito) ---
    case 'my_board_groups':
        try {
            $owner = kanpro_groups_owner_id();
            if ($owner <= 0) jexit(['success'=>false,'msg'=>'Não autenticado']);
            $groups = [];
            foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_board_groups', 'WHERE' => ['users_id' => $owner], 'ORDER' => 'rank ASC, id ASC']) as $g) {
                $bids = [];
                foreach ($DB->request(['SELECT' => ['plugin_kanpro_boards_id'], 'FROM' => 'glpi_plugin_kanpro_board_groups_items', 'WHERE' => ['groups_id' => (int)$g['id'], 'users_id' => $owner]]) as $it) {
                    $bids[] = (int)$it['plugin_kanpro_boards_id'];
                }
                $groups[] = ['id' => (int)$g['id'], 'name' => $g['name'], 'boards' => $bids];
            }
            jexit(['success'=>true, 'groups'=>$groups]);
        } catch (Throwable $e) {
            jexit(['success'=>false,'msg'=>'Erro ao listar grupos']);
        }

    case 'add_board_group':
        try {
            $owner = kanpro_groups_owner_id();
            if ($owner <= 0) jexit(['success'=>false,'msg'=>'Não autenticado']);
            $name = trim($_POST['name'] ?? '');
            if ($name === '') jexit(['success'=>false,'msg'=>'Dê um nome ao grupo']);
            $name = mb_substr($name, 0, 100);
            $maxRank = 0;
            $rmax = $DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => 'glpi_plugin_kanpro_board_groups', 'WHERE' => ['users_id' => $owner]])->current();
            if ($rmax) $maxRank = (float)($rmax['m'] ?? 0);
            $ok = $DB->insert('glpi_plugin_kanpro_board_groups', ['users_id' => $owner, 'name' => $name, 'rank' => $maxRank + 1024, 'date_creation' => date('Y-m-d H:i:s')]);
            $id = $ok ? (int)$DB->insertId() : 0;
            if (!$id) jexit(['success'=>false,'msg'=>'Não foi possível criar o grupo']);
            jexit(['success'=>true, 'id'=>$id]);
        } catch (Throwable $e) {
            jexit(['success'=>false,'msg'=>'Erro ao criar grupo']);
        }

    case 'rename_board_group':
        try {
            $owner = kanpro_groups_owner_id();
            $gid = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($gid <= 0 || $name === '') jexit(['success'=>false,'msg'=>'Grupo ou nome inválido']);
            $grow = $DB->request(['FROM' => 'glpi_plugin_kanpro_board_groups', 'WHERE' => ['id' => $gid, 'users_id' => $owner]])->current();
            if (!$grow) jexit(['success'=>false,'msg'=>'Grupo não encontrado']);
            $DB->update('glpi_plugin_kanpro_board_groups', ['name' => mb_substr($name, 0, 100), 'date_mod' => date('Y-m-d H:i:s')], ['id' => $gid]);
            jexit(['success'=>true]);
        } catch (Throwable $e) {
            jexit(['success'=>false,'msg'=>'Erro ao renomear grupo']);
        }

    case 'delete_board_group':
        try {
            $owner = kanpro_groups_owner_id();
            $gid = (int)($_POST['id'] ?? 0);
            if ($gid <= 0) jexit(['success'=>false,'msg'=>'Grupo inválido']);
            $grow = $DB->request(['FROM' => 'glpi_plugin_kanpro_board_groups', 'WHERE' => ['id' => $gid, 'users_id' => $owner]])->current();
            if (!$grow) jexit(['success'=>false,'msg'=>'Grupo não encontrado']);
            $DB->delete('glpi_plugin_kanpro_board_groups_items', ['groups_id' => $gid, 'users_id' => $owner]);
            $DB->delete('glpi_plugin_kanpro_board_groups', ['id' => $gid, 'users_id' => $owner]);
            jexit(['success'=>true]);
        } catch (Throwable $e) {
            jexit(['success'=>false,'msg'=>'Erro ao excluir grupo']);
        }

    case 'assign_board_group':
        try {
            $owner = kanpro_groups_owner_id();
            $bid = (int)($_POST['boards_id'] ?? 0);
            $gid = (int)($_POST['groups_id'] ?? 0); // 0 = sem grupo
            if ($bid <= 0) jexit(['success'=>false,'msg'=>'Quadro inválido']);
            if (!kanpro_can_view_board($bid)) jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
            if ($gid > 0) {
                $grow = $DB->request(['FROM' => 'glpi_plugin_kanpro_board_groups', 'WHERE' => ['id' => $gid, 'users_id' => $owner]])->current();
                if (!$grow) jexit(['success'=>false,'msg'=>'Grupo não encontrado']);
            }
            $cur = $DB->request(['FROM' => 'glpi_plugin_kanpro_board_groups_items', 'WHERE' => ['users_id' => $owner, 'plugin_kanpro_boards_id' => $bid]])->current();
            if ($cur && (int)($cur['groups_id'] ?? -1) === $gid) {
                jexit(['success'=>true]); // já está lá: mantém posição
            }
            // vai p/ o fim da lista destino
            $maxR = 0;
            $rmax = $DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => 'glpi_plugin_kanpro_board_groups_items', 'WHERE' => ['users_id' => $owner, 'groups_id' => $gid]])->current();
            if ($rmax) $maxR = (float)($rmax['m'] ?? 0);
            $newRank = $maxR > 0 ? $maxR + 1024 : 1024;
            if ($cur) {
                $DB->update('glpi_plugin_kanpro_board_groups_items', ['groups_id' => $gid, 'rank' => $newRank], ['id' => (int)$cur['id']]);
            } else {
                $DB->insert('glpi_plugin_kanpro_board_groups_items', ['groups_id' => $gid, 'users_id' => $owner, 'plugin_kanpro_boards_id' => $bid, 'rank' => $newRank]);
            }
            jexit(['success'=>true]);
        } catch (Throwable $e) {
            jexit(['success'=>false,'msg'=>'Erro ao mover quadro']);
        }

    case 'reorder_board_group':
        // ordem manual dos quadros na lista (drag entre/na lista) — salva rank de todos da lista
        try {
            $owner = kanpro_groups_owner_id();
            $gid = (int)($_POST['groups_id'] ?? 0);
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (!is_array($order)) jexit(['success'=>false,'msg'=>'Ordem inválida']);
            if ($gid > 0) {
                $grow = $DB->request(['FROM' => 'glpi_plugin_kanpro_board_groups', 'WHERE' => ['id' => $gid, 'users_id' => $owner]])->current();
                if (!$grow) jexit(['success'=>false,'msg'=>'Grupo não encontrado']);
            }
            $rank = 1024;
            foreach ($order as $obid) {
                $obid = (int)$obid;
                if ($obid <= 0 || !kanpro_can_view_board($obid)) continue;
                $cur = $DB->request(['FROM' => 'glpi_plugin_kanpro_board_groups_items', 'WHERE' => ['users_id' => $owner, 'plugin_kanpro_boards_id' => $obid]])->current();
                if ($cur) {
                    $DB->update('glpi_plugin_kanpro_board_groups_items', ['groups_id' => $gid, 'rank' => $rank], ['id' => (int)$cur['id']]);
                } else {
                    $DB->insert('glpi_plugin_kanpro_board_groups_items', ['groups_id' => $gid, 'users_id' => $owner, 'plugin_kanpro_boards_id' => $obid, 'rank' => $rank]);
                }
                $rank += 1024;
            }
            jexit(['success'=>true]);
        } catch (Throwable $e) {
            error_log('[KanPro] reorder_board_group: ' . $e->getMessage());
            jexit(['success'=>false,'msg'=>'Erro ao salvar ordem']);
        }

    case 'reorder_board_groups':
        // ordem manual das listas de grupos (arrastar a lista pelo cabeçalho) — salva rank
        try {
            $owner = kanpro_groups_owner_id();
            if ($owner <= 0) jexit(['success'=>false,'msg'=>'Não autenticado']);
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (!is_array($order)) jexit(['success'=>false,'msg'=>'Ordem inválida']);
            $rank = 1024;
            foreach ($order as $gid) {
                $gid = (int)$gid;
                if ($gid <= 0) continue;
                $grow = $DB->request(['FROM' => 'glpi_plugin_kanpro_board_groups', 'WHERE' => ['id' => $gid, 'users_id' => $owner]])->current();
                if (!$grow) continue;
                $DB->update('glpi_plugin_kanpro_board_groups', ['rank' => $rank, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $gid]);
                $rank += 1024;
            }
            jexit(['success'=>true]);
        } catch (Throwable $e) {
            error_log('[KanPro] reorder_board_groups: ' . $e->getMessage());
            jexit(['success'=>false,'msg'=>'Erro ao salvar ordem das listas']);
        }

    case 'send_test_zap':
        // TESTE: envia os 4 modelos de WhatsApp p/ um fone (padrão: fone do usuário 'glpi').
        // Não grava zaplog (pode repetir). Exige UPDATE no KanPro.
        try {
            if (!Session::haveRight('plugin_kanpro', UPDATE)) jexit(['success'=>false,'msg'=>'Sem permissão (precisa UPDATE no KanPro)']);
            if (!class_exists('PluginKanproMaintenanceZap')) jexit(['success'=>false,'msg'=>'Sender indisponível']);
            $type  = trim($_POST['type'] ?? 'all'); // all|entrada|retirada|atraso|cancelado
            $cid   = (int)($_POST['cards_id'] ?? 0);
            $phone = trim($_POST['phone'] ?? '');
            $phone = $phone !== ''
                ? PluginKanproMaintenanceZap::normalizeBRPhone($phone)
                : PluginKanproMaintenanceZap::resolveUserPhone('glpi');
            if ($phone === '') jexit(['success'=>false,'msg'=>'Telefone inválido/ausente (usuário glpi sem fone cadastrado?)']);
            $types = ($type === 'all' || $type === '')
                ? PluginKanproMaintenanceZap::allowedTypes()
                : [$type];
            if ($cid > 0) {
                $data = PluginKanproMaintenanceZap::baseData($cid);
                $data['dias'] = '7';
                $data['motivo'] = 'Teste de envio';
                if (($data['observacao'] ?? '') === '') $data['observacao'] = '(mensagem de teste)';
            } else {
                $now = date('d/m/Y H:i');
                $data = [
                    'escola' => 'EE Teste de Demonstração', 'card_id' => '999', 'card_nome' => 'EE Teste de Demonstração',
                    'data_recebimento' => $now, 'data_pronto' => $now, 'data_cancelamento' => $now,
                    'quantidade' => '2',
                    'maquinas'   => "#1 — Notebook Positivo (OK)\n#2 — Notebook Ultra (Garantia)",
                    'observacao' => '(mensagem de teste)', 'motivo' => 'Teste de envio', 'dias' => '7',
                ];
            }
            $results = [];
            $allOk = true;
            foreach ($types as $t) {
                if (!in_array($t, PluginKanproMaintenanceZap::allowedTypes(), true)) {
                    $results[$t] = ['ok' => false, 'error' => 'Tipo inválido'];
                    $allOk = false;
                    continue;
                }
                $txt = PluginKanproMaintenanceZap::renderTxt($t, $data);
                if ($txt === null || $txt === '') {
                    $results[$t] = ['ok' => false, 'error' => 'template vazio'];
                    $allOk = false;
                    continue;
                }
                $r = PluginKanproMaintenanceZap::evoSend($phone, $txt);
                $results[$t] = ['ok' => !empty($r['ok']), 'error' => $r['error'] ?? null];
                if (empty($r['ok'])) $allOk = false;
                usleep(400000); // respira entre envios (evita flood na Evolution)
            }
            jexit(['success' => $allOk, 'phone' => $phone, 'results' => $results]);
        } catch (Throwable $e) {
            jexit(['success'=>false,'msg'=>'Erro no teste: ' . $e->getMessage()]);
        }

    case 'get_board_members':
        $bid = (int)($_POST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $bchk = new PluginKanproBoard();
        if (!$bchk->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        // trava de visibilidade (criador, membro, perfil GLPI ou legado sem membros — vale sessão e pessoa)
        if (!kanpro_can_view_board($bid)) jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
        $creatorId = (int)($bchk->fields['users_id'] ?? 0);
        $me = (int)Session::getLoginUserID();
        // só quem pode ver o quadro pode listar membros (criador, membro ou quadro legado sem membros)
        // vale sessão e pessoa (login compartilhado)
        $__myRole = kanpro_my_board_role($bid);
        $__hasAny = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => $bid]) > 0;
        $__meIds = array_unique([$me, kanpro_acting_user_id()]);
        if (!in_array($creatorId, $__meIds, true) && $__myRole === null && $__hasAny) {
            jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
        }
        $members = [];
        $memberIds = [];
        $miter = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $bid], 'ORDER' => 'date_creation ASC']);
        foreach ($miter as $m) {
            $brief = kanpro_user_brief((int)$m['users_id']);
            $brief['role'] = $m['role'];
            $brief['is_creator'] = ((int)$m['users_id'] === $creatorId);
            $members[] = $brief;
            $memberIds[(int)$m['users_id']] = true;
        }
        // garante que o criador apareça na lista mesmo sem linha em boards_members (quadros legados)
        if ($creatorId > 0 && !isset($memberIds[$creatorId])) {
            $brief = kanpro_user_brief($creatorId);
            $brief['role'] = 'admin';
            $brief['is_creator'] = true;
            array_unshift($members, $brief);
            $memberIds[$creatorId] = true;
        }
        // usuários disponíveis para adicionar
        $available = [];
        $uiter = $DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_deleted' => 0, 'is_active' => 1], 'ORDER' => 'realname ASC, firstname ASC', 'LIMIT' => 300]);
        foreach ($uiter as $u) {
            if (isset($memberIds[(int)$u['id']])) continue;
            $display = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? ''));
            if ($display === '') $display = $u['name'];
            $initials = strtoupper(substr($u['firstname'] ?? $u['name'] ?? '?', 0, 1) . substr($u['realname'] ?? '', 0, 1));
            if (trim($initials) === '') $initials = strtoupper(substr($display, 0, 2));
            $available[] = ['id' => (int)$u['id'], 'name' => $display . ' (' . $u['name'] . ')', 'login' => $u['name'], 'initials' => $initials];
        }
        // perfis GLPI com acesso ao quadro + perfis disponíveis para adicionar
        $profiles = [];
        $profileIds = [];
        if ($DB->tableExists('glpi_plugin_kanpro_boards_profiles')) {
            $pname = [];
            foreach (kanpro_all_profiles() as $ap) $pname[$ap['id']] = $ap['name'];
            foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_boards_profiles', 'WHERE' => ['plugin_kanpro_boards_id' => $bid], 'ORDER' => 'date_creation ASC']) as $pr) {
                $profiles[] = ['profiles_id' => (int)$pr['profiles_id'], 'name' => $pname[(int)$pr['profiles_id']] ?? ('Perfil #' . (int)$pr['profiles_id']), 'role' => $pr['role'] ?? 'member'];
                $profileIds[(int)$pr['profiles_id']] = true;
            }
        }
        $available_profiles = [];
        foreach (kanpro_all_profiles() as $ap) {
            if (isset($profileIds[$ap['id']])) continue;
            $available_profiles[] = $ap;
        }
        // família de quadros (engrenagem também gerencia o pai — quadros já criados entram aqui)
        $__fam = ['parent_id' => 0, 'parent_name' => '', 'children' => [], 'candidates' => []];
        try {
            if (function_exists('kanpro_ensure_family_column')) kanpro_ensure_family_column();
            if (function_exists('kanpro_board_parent_id')) {
                $__pid = kanpro_board_parent_id($bid);
                $__fam['parent_id'] = $__pid;
                if ($__pid > 0) {
                    $pb = new PluginKanproBoard();
                    if ($pb->getFromDB($__pid)) $__fam['parent_name'] = (string)($pb->fields['name'] ?? '');
                }
                if (function_exists('kanpro_family_candidates')) $__fam['candidates'] = kanpro_family_candidates($bid);
                global $DB;
                foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_plugin_kanpro_boards', 'WHERE' => ['parent_boards_id' => $bid], 'ORDER' => 'name ASC']) as $__kr) {
                    $__kid = (int)$__kr['id'];
                    $__fam['children'][] = ['id' => $__kid, 'name' => (string)$__kr['name'],
                        'can_manage' => function_exists('kanpro_can_manage_members') ? kanpro_can_manage_members($__kid) : false];
                }
            }
        } catch (Throwable $e) {}
        jexit(['success'=>true, 'board_name'=>$bchk->fields['name'] ?? '', 'members'=>$members,
            'profiles'=>$profiles, 'available_profiles'=>$available_profiles,
            'my_role'=>kanpro_my_board_role($bid), 'is_creator'=>($me === $creatorId),
            'can_manage'=>kanpro_can_manage_members($bid), 'available'=>$available, 'family'=>$__fam]);

    case 'search_board_users':
        // Busca server-side (a lista inicial traz só 300; com milhares de usuários a pessoa some).
        $bid = (int)($_POST['boards_id'] ?? 0);
        $q = trim($_POST['q'] ?? '');
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $bchk = new PluginKanproBoard();
        if (!$bchk->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        if (!kanpro_can_view_board($bid)) jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
        if (mb_strlen($q) < 2) jexit(['success'=>true,'results'=>[]]);
        $memberIds = [];
        try {
            foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $bid]]) as $mr) {
                $memberIds[(int)$mr['users_id']] = true;
            }
            $creatorId = (int)($bchk->fields['users_id'] ?? 0);
            if ($creatorId > 0) $memberIds[$creatorId] = true;
        } catch (Throwable $e) {}
        $results = [];
        try {
            $like = "%{$q}%";
            $uiter = $DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => [
                    'is_deleted' => 0, 'is_active' => 1,
                    'OR' => [
                        'name'     => ['LIKE', $like],
                        'realname' => ['LIKE', $like],
                        'firstname'=> ['LIKE', $like],
                    ],
                ],
                'ORDER'  => 'realname ASC, firstname ASC',
                'LIMIT'  => 60,
            ]);
            foreach ($uiter as $u) {
                if (isset($memberIds[(int)$u['id']])) continue;
                $display = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? ''));
                if ($display === '') $display = $u['name'];
                $initials = strtoupper(substr($u['firstname'] ?? $u['name'] ?? '?', 0, 1) . substr($u['realname'] ?? '', 0, 1));
                if (trim($initials) === '') $initials = strtoupper(substr($display, 0, 2));
                $results[] = ['id' => (int)$u['id'], 'name' => $display . ' (' . $u['name'] . ')', 'login' => $u['name'], 'initials' => $initials];
                if (count($results) >= 60) break;
            }
        } catch (Throwable $e) {}
        // fallback sem acento em PHP (LIKE depende do collation)
        if (count($results) < 60) {
            try {
                $nq = kanpro_norm_text($q);
                if ($nq !== '') {
                    $seen = [];
                    foreach ($results as $r) $seen[$r['id']] = true;
                    $uiter2 = $DB->request([
                        'SELECT' => ['id', 'name', 'realname', 'firstname'],
                        'FROM'   => 'glpi_users',
                        'WHERE'  => ['is_deleted' => 0, 'is_active' => 1],
                        'ORDER'  => 'realname ASC, firstname ASC',
                        'LIMIT'  => 800,
                    ]);
                    foreach ($uiter2 as $u) {
                        if (count($results) >= 60) break;
                        if (isset($memberIds[(int)$u['id']]) || isset($seen[(int)$u['id']])) continue;
                        $hay = kanpro_norm_text(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? '') . ' ' . ($u['name'] ?? ''));
                        if (mb_strpos($hay, $nq) === false) continue;
                        $display = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? ''));
                        if ($display === '') $display = $u['name'];
                        $initials = strtoupper(substr($u['firstname'] ?? $u['name'] ?? '?', 0, 1) . substr($u['realname'] ?? '', 0, 1));
                        if (trim($initials) === '') $initials = strtoupper(substr($display, 0, 2));
                        $results[] = ['id' => (int)$u['id'], 'name' => $display . ' (' . $u['name'] . ')', 'login' => $u['name'], 'initials' => $initials];
                    }
                }
            } catch (Throwable $e) {}
        }
        jexit(['success'=>true,'results'=>$results]);

    // --- LABELS ---
    case 'add_label':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#61bd4f';
        $l = new PluginKanproLabel();
        $id = $l->add(['plugin_kanpro_boards_id'=>$bid,'name'=>$name,'color'=>$color]);
        jexit(['success'=>true,'id'=>$id]);

    case 'update_label':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#61bd4f';
        $DB->update('glpi_plugin_kanpro_labels', ['name'=>$name,'color'=>$color], ['id'=>$id]);
        jexit(['success'=>true]);

    case 'delete_label':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $DB->delete('glpi_plugin_kanpro_labels', ['id'=>$id]);
        $DB->delete('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_labels_id'=>$id]);
        jexit(['success'=>true]);

    case 'set_label_due':
        needEdit();
        kanpro_ensure_board_extras();
        $id = (int)($_POST['id'] ?? 0);
        $due = trim($_POST['due_date'] ?? '');
        $DB->update('glpi_plugin_kanpro_labels', ['due_date'=>($due !== '' ? $due : null)], ['id'=>$id]);
        jexit(['success'=>true]);

    case 'toggle_card_label':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        kanpro_need_card_editable($cid);
        $lid = (int)($_POST['labels_id'] ?? 0);
        $exists = countElementsInTable('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cid,'plugin_kanpro_labels_id'=>$lid]);
        if ($exists) {
            $DB->delete('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cid,'plugin_kanpro_labels_id'=>$lid]);
            jexit(['success'=>true,'added'=>false]);
        } else {
            $DB->insert('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cid,'plugin_kanpro_labels_id'=>$lid]);
            jexit(['success'=>true,'added'=>true]);
        }

    // --- LISTS ---
    case 'add_list':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $name = trim($_POST['name'] ?? 'Nova Lista');
        if (!$name) $name = 'Nova Lista';
        $list = new PluginKanproList();
        $id = $list->add(['plugin_kanpro_boards_id'=>$bid,'name'=>$name,'list_type'=>kanpro_valid_list_type((string)($_POST['list_type'] ?? '')),'users_id'=>kanpro_acting_user_id()]);
        PluginKanproBoard::logActivity($bid, null, $id, 'list_create', "Lista '{$name}' criada");
        jexit(['success'=>true,'id'=>$id]);

    case 'rename_list':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$name) jexit(['success'=>false,'msg'=>'Nome obrigatório']);
        $DB->update('glpi_plugin_kanpro_lists', ['name'=>$name], ['id'=>$id]);
        jexit(['success'=>true]);

    case 'set_list_type':
        // troca a categoria da lista (listas de ajuste) — '' = normal
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jexit(['success'=>false,'msg'=>'Lista inválida']);
        $type = kanpro_valid_list_type((string)($_POST['list_type'] ?? ''));
        $lchk = new PluginKanproList();
        if (!$lchk->getFromDB($id)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        $DB->update('glpi_plugin_kanpro_lists', ['list_type'=>$type], ['id'=>$id]);
        jexit(['success'=>true,'list_type'=>$type]);

    case 'get_list_viewers':
        // Quem pode ver a lista — criador da lista / admin do quadro gerencia; todos podem ler p/ exibir cadeado.
        $lid = (int)($_REQUEST['lists_id'] ?? $_REQUEST['id'] ?? 0);
        if (!$lid) jexit(['success'=>false,'msg'=>'Lista inválida']);
        $lr = new PluginKanproList();
        if (!$lr->getFromDB($lid)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        $bid = (int)($lr->fields['plugin_kanpro_boards_id'] ?? 0);
        $viewerIds = function_exists('kanpro_list_viewer_ids') ? kanpro_list_viewer_ids($lid) : [];
        $canManage = function_exists('kanpro_can_manage_list') ? kanpro_can_manage_list($bid, $lr->fields) : false;
        // Quem não tem acesso à lista restrita não pode nem ver a lista de quem tem
        // (ela aparece como fantasma, sem cards). Só entra quem já vê ou quem gerencia.
        $hasAccess = function_exists('kanpro_can_view_list') ? kanpro_can_view_list($lr->fields, $bid) : true;
        if (!$canManage && !$hasAccess) {
            jexit(['success'=>false,'msg'=>'Você não tem acesso a esta lista.']);
        }
        $viewers = [];
        if (!empty($viewerIds)) {
            $urows = [];
            try {
                foreach ($DB->request(['SELECT' => ['id','name','realname','firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => array_values($viewerIds)]]) as $ur) $urows[(int)$ur['id']] = $ur;
            } catch (Throwable $e) {}
            foreach ($viewerIds as $uid) {
                $ur = $urows[$uid] ?? null;
                if ($ur) {
                    $tmpU = new User();
                    $tmpU->fields = $ur + ($tmpU->fields ?? []);
                    $uname = $tmpU->getFriendlyName();
                } else $uname = 'Usuário #' . $uid;
                $viewers[] = ['users_id' => (int)$uid, 'name' => $uname];
            }
        }
        // criador da lista p/ exibir "criada por"
        $creatorName = '';
        $creatorId = (int)($lr->fields['users_id'] ?? 0);
        if ($creatorId > 0) {
            $cu = new User();
            if ($cu->getFromDB($creatorId)) $creatorName = $cu->getFriendlyName();
            else $creatorName = 'Usuário #' . $creatorId;
        }
        jexit(['success'=>true,'lists_id'=>$lid,'boards_id'=>$bid,'list_name'=>$lr->fields['name'] ?? '','users_id'=>$creatorId,'creator_name'=>$creatorName,'viewer_ids'=>array_values($viewerIds),'viewers'=>$viewers,'is_restricted'=>!empty($viewerIds),'can_manage'=>$canManage]);

    case 'set_list_viewers':
        needEdit();
        $lid = (int)($_POST['lists_id'] ?? $_POST['id'] ?? 0);
        if (!$lid) jexit(['success'=>false,'msg'=>'Lista inválida']);
        $lr = new PluginKanproList();
        if (!$lr->getFromDB($lid)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        $bid = (int)($lr->fields['plugin_kanpro_boards_id'] ?? 0);
        if (function_exists('kanpro_can_manage_list') && !kanpro_can_manage_list($bid, $lr->fields)) {
            jexit(['success'=>false,'msg'=>'Somente quem criou a lista ou admin do quadro pode escolher quem vê.']);
        }
        $raw = $_POST['users_id'] ?? $_POST['viewers'] ?? '[]';
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $uids = is_array($decoded) ? $decoded : [];
        } elseif (is_array($raw)) {
            $uids = $raw;
        } else $uids = [];
        $uids = array_values(array_unique(array_map('intval', $uids)));
        $uids = array_values(array_filter($uids, function ($v) { return $v > 0; }));
        $uids = array_slice($uids, 0, 200);
        try {
            if ($DB->tableExists('glpi_plugin_kanpro_lists_viewers')) {
                $DB->delete('glpi_plugin_kanpro_lists_viewers', ['plugin_kanpro_lists_id' => $lid]);
                foreach ($uids as $uid) {
                    try { $DB->insert('glpi_plugin_kanpro_lists_viewers', ['plugin_kanpro_lists_id' => $lid, 'users_id' => $uid]); } catch (Throwable $e) {}
                }
                $DB->update('glpi_plugin_kanpro_lists', ['date_mod' => date('Y-m-d H:i:s')], ['id' => $lid]);
            }
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Falha ao salvar']); }
        PluginKanproBoard::logActivity($bid, null, $lid, 'list_visibility', empty($uids) ? "Lista '{$lr->fields['name']}' liberada para todos" : "Visibilidade da lista '{$lr->fields['name']}' ajustada (" . count($uids) . " pessoa(s))");
        jexit(['success'=>true,'viewer_ids'=>$uids,'is_restricted'=>!empty($uids)]);

    case 'archive_list':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $l = new PluginKanproList();
        $l->getFromDB($id);
        $new = $l->fields['is_archived'] ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_lists', ['is_archived'=>$new], ['id'=>$id]);
        jexit(['success'=>true,'is_archived'=>$new]);

    case 'delete_list':
        if (!Session::haveRight('plugin_kanpro', DELETE)) jexit(['success'=>false,'msg'=>'Sem permissão']);
        $id = (int)($_POST['id'] ?? 0);
        $l = new PluginKanproList();
        $l->delete(['id'=>$id], true);
        jexit(['success'=>true]);

    case 'presence_heartbeat':
        $boards_id = (int) ($_POST['boards_id'] ?? 0);
        if (!$boards_id) jexit(['success' => false]);
        $uid = Session::getLoginUserID();
        $existing = $DB->request(['FROM' => 'glpi_plugin_kanpro_presence', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'users_id' => $uid]])->current();
        if ($existing) {
            $DB->update('glpi_plugin_kanpro_presence', ['last_seen' => date('Y-m-d H:i:s')], ['id' => $existing['id']]);
        } else {
            $DB->insert('glpi_plugin_kanpro_presence', ['plugin_kanpro_boards_id' => $boards_id, 'users_id' => $uid, 'last_seen' => date('Y-m-d H:i:s')]);
        }
        // poda linhas antigas (evita crescimento infinito — antes nunca deletava)
        try {
            $DB->delete('glpi_plugin_kanpro_presence', ['last_seen' => ['<', date('Y-m-d H:i:s', time() - 300)]]);
        } catch (Throwable $e) {}
        jexit(['success' => true]);

    case 'get_board_stamp':
        // Selo leve p/ polling inteligente: JS só baixa o snapshot pesado se o selo mudou
        $boards_id = (int) ($_POST['boards_id'] ?? 0);
        if (!$boards_id) jexit(['success' => false]);
        $board_chk = new PluginKanproBoard();
        if (!$board_chk->getFromDB($boards_id)) jexit(['success' => false]);
        // rede do lembrete 8h/10h/13h: garante o envio mesmo se o cron do GLPI não rodar na janela
        // (fora da janela custa só um date(); dentro, 1 lookup indexado até enviar)
        try { if (class_exists('PluginKanproMaintenanceZap')) PluginKanproMaintenanceZap::maybeSendLembreteFallback(); } catch (Throwable $e) {}
        try {
            // aproveita o selo p/ heartbeat (1 request leve faz os dois — antes eram 2 a cada 2s)
            $uid0 = (int)Session::getLoginUserID();
            if ($uid0 > 0) {
                $ex0 = $DB->request(['FROM' => 'glpi_plugin_kanpro_presence', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'users_id' => $uid0]])->current();
                if ($ex0) {
                    $DB->update('glpi_plugin_kanpro_presence', ['last_seen' => date('Y-m-d H:i:s')], ['id' => $ex0['id']]);
                } else {
                    $DB->insert('glpi_plugin_kanpro_presence', ['plugin_kanpro_boards_id' => $boards_id, 'users_id' => $uid0, 'last_seen' => date('Y-m-d H:i:s')]);
                }
            }
            $bmod = (string)($board_chk->fields['date_mod'] ?? '');
            $lmax = '';
            $lcnt = 0;
            foreach ($DB->request(['SELECT' => ['MAX' => 'date_mod AS m', 'COUNT' => 'id AS c'], 'FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id]]) as $r) {
                $lmax = (string)($r['m'] ?? '');
                $lcnt = (int)($r['c'] ?? 0);
            }
            $cmax = '';
            $ccnt = 0;
            foreach ($DB->request(['SELECT' => ['MAX' => 'date_mod AS m', 'COUNT' => 'id AS c'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'is_archived' => 0]]) as $r) {
                $cmax = (string)($r['m'] ?? '');
                $ccnt = (int)($r['c'] ?? 0);
            }
            $amax = '';
            foreach ($DB->request(['SELECT' => ['MAX' => 'date_creation AS m'], 'FROM' => 'glpi_plugin_kanpro_activities', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id]]) as $r) {
                $amax = (string)($r['m'] ?? '');
            }
            // comentários/anexos/checks nem sempre tocam card.date_mod nem geram activity — inclui no selo
            $cardIds = [];
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id]]) as $r) {
                $cardIds[] = (int)$r['id'];
            }
            $cardIds = $cardIds ?: [0];
            $comax = '';
            foreach ($DB->request(['SELECT' => ['MAX' => 'date_creation AS m'], 'FROM' => 'glpi_plugin_kanpro_comments', 'WHERE' => ['plugin_kanpro_cards_id' => $cardIds]]) as $r) {
                $comax = (string)($r['m'] ?? '');
            }
            $atmax = '';
            foreach ($DB->request(['SELECT' => ['MAX' => 'date_creation AS m'], 'FROM' => 'glpi_plugin_kanpro_attachments', 'WHERE' => ['plugin_kanpro_cards_id' => $cardIds]]) as $r) {
                $atmax = (string)($r['m'] ?? '');
            }
            // Cobertura total do selo: várias ações fazem UPDATE/INSERT direto sem tocar date_mod
            // (renomear/arquivar/reordenar listas, mover na mesma lista, etiquetas, membros,
            //  checklist, manutenção, acesso ao quadro) — sem estes bits o outro PC nunca
            // percebia a mudança e só atualizava ao focar a aba ou recarregar.
            $bidInt = (int)$boards_id;
            $cardIdsIn = implode(',', array_map('intval', $cardIds));
            $ag = function (string $select, string $from, string $where) use ($DB): string {
                try {
                    $res = $DB->doQuery("SELECT {$select} FROM {$from} WHERE {$where}");
                    if ($res && ($r = $res->fetch_assoc())) {
                        return implode(':', array_map(function ($v) { return (string)($v ?? ''); }, array_values($r)));
                    }
                } catch (Throwable $e) {}
                return '';
            };
            // listas: nome/arquivada/ordem/categoria (rename, arquivar, reorder, mover e set_list_type não tocam date_mod)
            $listsBit = $ag("COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT(id, '|', name, '|', is_archived, '|', rank, '|', IFNULL(list_type, '')))), 0) AS s", "`glpi_plugin_kanpro_lists`", "`plugin_kanpro_boards_id` = {$bidInt}");
            // cartões: lista+rank+arquivada+aprovação+urgência+notificado+chamado+whatsapp (mover/reordenar na mesma lista é rank-only sem date_mod)
            $cardsBit = $ag("COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT(id, '|', plugin_kanpro_lists_id, '|', rank, '|', is_archived, '|', approval_from, '|', IFNULL(is_urgent, 0), '|', IFNULL(is_notified, 0), '|', IFNULL(chamado_source_id, 0), '|', IFNULL(chamado_status, ''), '|', IFNULL(whatsapp_notify, 0)))), 0) AS s", "`glpi_plugin_kanpro_cards`", "`plugin_kanpro_boards_id` = {$bidInt}");
            // visibilidade das listas: trocar quem vê não toca date_mod — sem isso o outro PC nunca percebe
            $listVisBit = '';
            if ($DB->tableExists('glpi_plugin_kanpro_lists_viewers')) {
                $listVisBit = $ag("COUNT(*) AS c, COALESCE(MAX(v.id), 0) AS m, COALESCE(SUM(CRC32(CONCAT(v.plugin_kanpro_lists_id, '|', v.users_id))), 0) AS s", "`glpi_plugin_kanpro_lists_viewers` AS v INNER JOIN `glpi_plugin_kanpro_lists` AS l ON l.id = v.plugin_kanpro_lists_id", "l.plugin_kanpro_boards_id = {$bidInt}");
            }
            // etiquetas do quadro: criar/renomear/recolorir/prazo/excluir
            $labelsBit = $ag("COUNT(*) AS c, COALESCE(MAX(id), 0) AS m, COALESCE(SUM(CRC32(CONCAT(id, '|', name, '|', color, '|', IFNULL(due_date, '')))), 0) AS s", "`glpi_plugin_kanpro_labels`", "`plugin_kanpro_boards_id` = {$bidInt}");
            // etiquetas/membros no cartão: toggle é insert/delete sem data
            $clBit = $ag("COUNT(*) AS c, COALESCE(MAX(cl.id), 0) AS m", "`glpi_plugin_kanpro_cards_labels` AS cl INNER JOIN `glpi_plugin_kanpro_cards` AS c ON c.id = cl.plugin_kanpro_cards_id", "c.plugin_kanpro_boards_id = {$bidInt}");
            $cmBit = $ag("COUNT(*) AS c, COALESCE(MAX(cm.id), 0) AS m", "`glpi_plugin_kanpro_cards_members` AS cm INNER JOIN `glpi_plugin_kanpro_cards` AS c ON c.id = cm.plugin_kanpro_cards_id", "c.plugin_kanpro_boards_id = {$bidInt}");
            // checklists: marcar/desmarcar item (is_checked) não tem data — a soma cobre
            $chkBit = $ag("COUNT(*) AS c, COALESCE(MAX(cl.id), 0) AS m", "`glpi_plugin_kanpro_checklists` AS cl INNER JOIN `glpi_plugin_kanpro_cards` AS c ON c.id = cl.plugin_kanpro_cards_id", "c.plugin_kanpro_boards_id = {$bidInt}");
            $chitBit = $ag("COUNT(*) AS c, COALESCE(MAX(ci.id), 0) AS m, COALESCE(SUM(ci.is_checked), 0) AS k", "`glpi_plugin_kanpro_checklist_items` AS ci INNER JOIN `glpi_plugin_kanpro_checklists` AS cl ON cl.id = ci.plugin_kanpro_checklists_id INNER JOIN `glpi_plugin_kanpro_cards` AS c ON c.id = cl.plugin_kanpro_cards_id", "c.plugin_kanpro_boards_id = {$bidInt}");
            // manutenção: edições tocam machines.date_mod (fora do selo até agora) — inclui trava de chamado
            $machBit = $ag("COUNT(*) AS c, COALESCE(MAX(mm.id), 0) AS m, COALESCE(MAX(mm.date_mod), '') AS d, COALESCE(SUM(mm.is_done), 0) AS k, COALESCE(SUM(mm.is_locked), 0) AS l", "`glpi_plugin_kanpro_maintenance_machines` AS mm INNER JOIN `glpi_plugin_kanpro_cards` AS c ON c.id = mm.plugin_kanpro_cards_id", "c.plugin_kanpro_boards_id = {$bidInt}");
            // acesso ao quadro: adicionar/remover/trocar papel (sem data)
            $bmBit = $ag("COUNT(*) AS c, COALESCE(MAX(id), 0) AS m, COALESCE(SUM(CRC32(CONCAT(users_id, '|', role))), 0) AS s", "`glpi_plugin_kanpro_boards_members`", "`plugin_kanpro_boards_id` = {$bidInt}");
            $bpBit = '';
            if ($DB->tableExists('glpi_plugin_kanpro_boards_profiles')) {
                $bpBit = $ag("COUNT(*) AS c, COALESCE(MAX(id), 0) AS m, COALESCE(SUM(CRC32(CONCAT(profiles_id, '|', role))), 0) AS s", "`glpi_plugin_kanpro_boards_profiles`", "`plugin_kanpro_boards_id` = {$bidInt}");
            }
            // comentários editados (date_mod) e anexos removidos (count/max)
            $coBit = $ag("COUNT(*) AS c, COALESCE(MAX(id), 0) AS m, COALESCE(MAX(date_mod), '') AS d", "`glpi_plugin_kanpro_comments`", "`plugin_kanpro_cards_id` IN ({$cardIdsIn})");
            $attBit = $ag("COUNT(*) AS c, COALESCE(MAX(id), 0) AS m", "`glpi_plugin_kanpro_attachments`", "`plugin_kanpro_cards_id` IN ({$cardIdsIn})");
            // anotações das máquinas (adicionar/excluir não toca em nenhuma data do selo)
            $notesBit = '';
            if ($DB->tableExists('glpi_plugin_kanpro_maintenance_notes') && $DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
                $notesBit = $ag("COUNT(*) AS c, COALESCE(MAX(n.id), 0) AS m", "`glpi_plugin_kanpro_maintenance_notes` AS n INNER JOIN `glpi_plugin_kanpro_maintenance_machines` AS mm ON mm.id = n.machine_id INNER JOIN `glpi_plugin_kanpro_cards` AS c ON c.id = mm.plugin_kanpro_cards_id", "c.plugin_kanpro_boards_id = {$bidInt}");
            }
            // assinatura acontece noutra tabela/plugin (assetmgrstatus) sem tocar nas datas do kanpro —
            // sem isso o selo nunca muda ao assinar e o badge não vira "Concluído" sozinho
            $tstat = '';
            if ($DB->tableExists('glpi_plugin_assetmgrstatus_transfers')) {
                $tbits = [];
                foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'is_maintenance' => 1, 'is_archived' => 0]]) as $mr) {
                    $mcid = (int)$mr['id'];
                    $mlike = "%[KanPro #{$mcid}]%";
                    $mtr = $DB->request(['FROM' => 'glpi_plugin_assetmgrstatus_transfers', 'WHERE' => ['reason' => ['LIKE', $mlike]], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
                    if ($mtr) $tbits[] = $mcid . ':' . (!empty($mtr['assinatura_image']) ? '1' : '0') . (!empty($mtr['assinatura_tecnico_image']) ? '1' : '0');
                }
                $tstat = implode(',', $tbits);
            }
            $stamp = sha1(implode('|', [$bmod, $lmax, $lcnt, $cmax, $ccnt, $amax, $comax, $atmax, $tstat, $listsBit, $cardsBit, $listVisBit, $labelsBit, $clBit, $cmBit, $chkBit, $chitBit, $machBit, $bmBit, $bpBit, $coBit, $attBit, $notesBit]));
            // viewers junto (barato) p/ avatares continuarem vivos sem snapshot pesado
            $viewers = [];
            $cutoff = date('Y-m-d H:i:s', time() - 15);
            $__vrows = [];
            $__vuids = [];
            foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_presence', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'last_seen' => ['>', $cutoff]]]) as $v) {
                $__vrows[] = $v;
                $__vuids[] = (int)$v['users_id'];
            }
            $__vusers = [];
            if (!empty($__vuids)) {
                foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => array_values(array_unique($__vuids))]]) as $ur) {
                    $__vusers[(int)$ur['id']] = $ur;
                }
            }
            foreach ($__vrows as $v) {
                $uid2 = (int)$v['users_id'];
                $ur = $__vusers[$uid2] ?? null;
                if ($ur) {
                    $tmpU = new User();
                    $tmpU->fields = $ur + ($tmpU->fields ?? []);
                    $uname = $tmpU->getFriendlyName();
                    $initials = strtoupper(substr($ur['firstname'] ?? $ur['name'] ?? '?', 0, 1));
                } else {
                    $uname = '#' . $uid2;
                    $initials = '?';
                }
                $viewers[] = ['users_id' => $uid2, 'name' => $uname, 'initials' => $initials];
            }
            // carimbo do build (mesma fonte do ?v= e do window.KANPRO.build):
            // o polling compara e o JS avisa quem está com aba aberta
            $__build = 0;
            try {
                $__plugDir = dirname(__DIR__);
                foreach (['public/js/kanpro.js','public/js/kanpro.modal.js','public/js/kanpro.dnd.js','public/js/kanpro.maintenance.js','public/js/kanpro.butler.js','public/js/kanpro.chamado.js','public/js/history.js','public/css/kanpro.css'] as $__bf) {
                    $__bm = @filemtime($__plugDir . '/' . $__bf);
                    if ($__bm && $__bm > $__build) $__build = (int)$__bm;
                }
            } catch (Throwable $e) {}
            jexit(['success' => true, 'stamp' => $stamp, 'viewers' => $viewers, 'build' => $__build]);
        } catch (Throwable $e) {
            jexit(['success' => false]);
        }

    case 'get_board_snapshot':
        $boards_id = (int) ($_POST['boards_id'] ?? 0);
        if (!$boards_id) jexit(['success' => false]);
        $board_chk = new PluginKanproBoard();
        if (!$board_chk->getFromDB($boards_id)) jexit(['success' => false]);

        $lists = PluginKanproList::getListsForBoard($boards_id);
        $hiddenLists = [];
        // visibilidade por lista: some de verdade p/ quem não vê; fantasma via "Exibir invisíveis" (sem cards)
        try {
            $hasRestrSnap = function_exists('kanpro_board_has_list_restrictions') ? kanpro_board_has_list_restrictions($boards_id) : true;
            if ($hasRestrSnap) {
                if (function_exists('kanpro_enrich_lists_with_viewers')) $lists = kanpro_enrich_lists_with_viewers($lists);
                if (function_exists('kanpro_split_visible_hidden_lists')) {
                    list($lists, $hiddenLists) = kanpro_split_visible_hidden_lists($lists);
                } elseif (function_exists('kanpro_filter_visible_lists')) {
                    $lists = kanpro_filter_visible_lists($lists);
                }
            }
        } catch (Throwable $e) {
            $lists = PluginKanproList::getListsForBoard($boards_id);
            $hiddenLists = [];
        }
        // Fantasma não deve carregar os ids de quem tem acesso: quem não vê a lista só
        // precisa da contagem. Quem a gerencia precisa dos ids (edita a visibilidade).
        foreach ($hiddenLists as $hi => $hl) {
            if (!empty($hl['can_manage_viewers'])) continue;
            $hiddenLists[$hi]['viewer_count'] = count((array)($hl['viewer_ids'] ?? []));
            $hiddenLists[$hi]['viewer_ids'] = [];
        }
        $visibleListIds = array_map(function ($l) { return (int)($l['id'] ?? 0); }, $lists);
        $labels = PluginKanproLabel::getForBoard($boards_id);

        $members_raw = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id]]);
        $__mrows = [];
        $__muids = [];
        foreach ($members_raw as $m) { $__mrows[] = $m; $__muids[] = (int)$m['users_id']; }
        $__musers = [];
        if (!empty($__muids)) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname', 'picture'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => array_values(array_unique($__muids))]]) as $ur) {
                $__musers[(int)$ur['id']] = $ur;
            }
        }
        $members_list = [];
        foreach ($__mrows as $m) {
            $uid = (int)$m['users_id'];
            $ur = $__musers[$uid] ?? null;
            if ($ur) {
                $tmpU = new User();
                $tmpU->fields = $ur + ($tmpU->fields ?? []);
                $uname = $tmpU->getFriendlyName();
                $initials = strtoupper(substr($ur['firstname'] ?? $ur['name'] ?? '?', 0, 1) . substr($ur['realname'] ?? '', 0, 1));
                if (trim($initials) === '') $initials = strtoupper(substr($uname, 0, 2));
                $pic = $ur['picture'] ?? '';
            } else {
                $uname = 'Usuário #' . $uid;
                $initials = '?';
                $pic = '';
            }
            $members_list[] = ['users_id' => $uid, 'role' => $m['role'], 'name' => $uname, 'initials' => $initials,
                'picture_url' => ($pic !== '' ? ($CFG_GLPI['root_doc'] ?? '') . '/front/document.send.php?file=_pictures/' . $pic : '')];
        }

        $all_cards = [];
        $cards_where = ['plugin_kanpro_boards_id' => $boards_id, 'is_archived' => 0];
        if (isset($visibleListIds)) {
            if (empty($visibleListIds)) $cards_where['plugin_kanpro_lists_id'] = [0];
            else $cards_where['plugin_kanpro_lists_id'] = array_values($visibleListIds);
        }
        $cards_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => $cards_where, 'ORDER' => 'rank ASC']);
        foreach ($cards_iter as $c) $all_cards[] = $c;
        try {
            // clones buscados direto no banco (vale lista oculta p/ o usuário): senão a tag
            // saía "Liberado" p/ quem não vê Abrir chamado e "Bloqueado" p/ o admin
            $__cloneStBySrc2 = [];
            $__visIds2 = [];
            foreach ($all_cards as $__cc2) $__visIds2[] = (int)$__cc2['id'];
            if (!empty($__visIds2)) {
                foreach ($DB->request(['SELECT' => ['chamado_source_id','chamado_status'], 'FROM' => 'glpi_plugin_kanpro_cards',
                    'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'chamado_source_id' => $__visIds2], 'ORDER' => 'id ASC']) as $__cl2) {
                    $__cloneStBySrc2[(int)$__cl2['chamado_source_id']] = (string)($__cl2['chamado_status'] ?? '');
                }
            }
            foreach ($all_cards as $__k2 => $__cc2) {
                $__srcSelf2 = (int)($__cc2['chamado_source_id'] ?? 0);
                if (($__cc2['chamado_status'] ?? '') === 'liberado') {
                    $all_cards[$__k2]['chamado_blocked'] = 0;
                } elseif ($__srcSelf2 === 0 && isset($__cloneStBySrc2[(int)$__cc2['id']])) {
                    $all_cards[$__k2]['chamado_blocked'] = ($__cloneStBySrc2[(int)$__cc2['id']] !== 'liberado') ? 1 : 0;
                } else {
                    $all_cards[$__k2]['chamado_blocked'] = 0;
                }
            }
        } catch (Throwable $__e2) {}

        $card_labels_map = [];
        $cl_iter = $DB->request([
            'SELECT' => ['cl.plugin_kanpro_cards_id', 'l.id', 'l.name', 'l.color', 'l.due_date'],
            'FROM'   => 'glpi_plugin_kanpro_cards_labels AS cl',
            'LEFT JOIN' => ['glpi_plugin_kanpro_labels AS l' => ['ON' => ['l' => 'id', 'cl' => 'plugin_kanpro_labels_id']]],
            'WHERE'  => ['l.plugin_kanpro_boards_id' => $boards_id],
        ]);
        foreach ($cl_iter as $r) {
            $card_labels_map[$r['plugin_kanpro_cards_id']][] = ['id' => $r['id'], 'name' => $r['name'], 'color' => $r['color'], 'due_date' => ($r['due_date'] ?? null)];
        }

        $card_members_map = [];
        $__cmrows = [];
        $__cmuids = [];
        $cm_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards_members', 'WHERE' => ['plugin_kanpro_cards_id' => array_column($all_cards, 'id') ?: [0]]]);
        foreach ($cm_iter as $r) { $__cmrows[] = $r; $__cmuids[] = (int)$r['users_id']; }
        $__cmusers = [];
        if (!empty($__cmuids)) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname', 'picture'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => array_values(array_unique($__cmuids))]]) as $ur) {
                $__cmusers[(int)$ur['id']] = $ur;
            }
        }
        foreach ($__cmrows as $r) {
            $uid = (int)$r['users_id'];
            $ur = $__cmusers[$uid] ?? null;
            if ($ur) {
                $tmpU = new User();
                $tmpU->fields = $ur + ($tmpU->fields ?? []);
                $uname = $tmpU->getFriendlyName();
                $initials = strtoupper(substr($ur['firstname'] ?? $ur['name'] ?? '?', 0, 1));
                $pic = $ur['picture'] ?? '';
            } else {
                $uname = '#' . $uid;
                $initials = '?';
                $pic = '';
            }
            $card_members_map[$r['plugin_kanpro_cards_id']][] = ['users_id' => $uid, 'name' => $uname, 'initials' => $initials,
                'picture_url' => ($pic !== '' ? ($CFG_GLPI['root_doc'] ?? '') . '/front/document.send.php?file=_pictures/' . $pic : '')];
        }

        $check_progress = [];
        $__cp_ids = array_column($all_cards, 'id') ?: [0];
        try {
            $check_ids_by_card = [];
            $cl2card = [];
            $check_iter = $DB->request(['SELECT' => ['id', 'plugin_kanpro_cards_id'], 'FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['plugin_kanpro_cards_id' => $__cp_ids]]);
            foreach ($check_iter as $cl) { $check_ids_by_card[$cl['plugin_kanpro_cards_id']][] = (int)$cl['id']; $cl2card[(int)$cl['id']] = (int)$cl['plugin_kanpro_cards_id']; }
            if (!empty($cl2card)) {
                $totals = []; $dones = [];
                foreach ($DB->request(['SELECT' => ['plugin_kanpro_checklists_id', 'COUNT' => 'id AS total'], 'FROM' => 'glpi_plugin_kanpro_checklist_items', 'WHERE' => ['plugin_kanpro_checklists_id' => array_keys($cl2card)], 'GROUPBY' => ['plugin_kanpro_checklists_id']]) as $r) {
                    $totals[(int)$r['plugin_kanpro_checklists_id']] = (int)$r['total'];
                }
                foreach ($DB->request(['SELECT' => ['plugin_kanpro_checklists_id', 'COUNT' => 'id AS total'], 'FROM' => 'glpi_plugin_kanpro_checklist_items', 'WHERE' => ['plugin_kanpro_checklists_id' => array_keys($cl2card), 'is_checked' => 1], 'GROUPBY' => ['plugin_kanpro_checklists_id']]) as $r) {
                    $dones[(int)$r['plugin_kanpro_checklists_id']] = (int)$r['total'];
                }
                foreach ($check_ids_by_card as $cid => $cids) {
                    $t = 0; $d = 0;
                    foreach ($cids as $clid) { $t += $totals[$clid] ?? 0; $d += $dones[$clid] ?? 0; }
                    $check_progress[$cid] = ['total' => $t, 'done' => $d];
                }
            }
        } catch (Throwable $e) {}

        $maintenance_progress = [];
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            $maint_ids = array_column($all_cards, 'id') ?: [0];
            $maint_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['plugin_kanpro_cards_id' => $maint_ids]]);
            $maint_by_card = [];
            foreach ($maint_iter as $mm) $maint_by_card[$mm['plugin_kanpro_cards_id']][] = $mm;
            // anotações por card (selo no card minimizado) — 1 query
            $notes_by_card = [];
            if ($DB->tableExists('glpi_plugin_kanpro_maintenance_notes') && !empty($maint_by_card)) {
                $mid2cid = [];
                $all_mids = [];
                foreach ($maint_by_card as $cid => $machines) {
                    foreach ($machines as $mm) { $mid2cid[(int)$mm['id']] = (int)$cid; $all_mids[] = (int)$mm['id']; }
                }
                if (!empty($all_mids)) {
                    try {
                        foreach ($DB->request(['SELECT' => ['machine_id', 'COUNT' => 'id AS total'], 'FROM' => 'glpi_plugin_kanpro_maintenance_notes', 'WHERE' => ['machine_id' => $all_mids], 'GROUPBY' => ['machine_id']]) as $nr) {
                            $cc = $mid2cid[(int)$nr['machine_id']] ?? 0;
                            if ($cc) $notes_by_card[$cc] = ($notes_by_card[$cc] ?? 0) + (int)$nr['total'];
                        }
                    } catch (Throwable $e) {}
                }
            }
            // fingerprint do conteúdo (diary/status/etc): diary muda sem mudar contadores,
            // então o snapshot precisa mudar — senão o modal aberto do outro usuário nunca atualiza sem F5
            $maint_hash = '';
            try {
                $fpParts = [];
                foreach ($maint_by_card as $cid => $machines) {
                    foreach ($machines as $mm) {
                        $fpParts[] = (int)($mm['id'] ?? 0) . '|' . (int)($mm['seq'] ?? 0) . '|' . ($mm['model'] ?? '') . '|' . ($mm['status'] ?? '') . '|' . (int)(!empty($mm['is_done'])) . '|' . (int)(!empty($mm['is_ok'])) . '|' . (string)($mm['diary'] ?? '') . '|' . (int)(!empty($mm['is_urgent'])) . '|' . (int)(!empty($mm['is_locked'])) . '|' . (string)($mm['date_mod'] ?? '');
                    }
                }
                sort($fpParts);
                $maint_hash = sha1(implode("\n", $fpParts));
            } catch (Throwable $e) { $maint_hash = ''; }
            foreach ($maint_by_card as $cid => $machines) {
                $total = count($machines);
                $done = 0;
                $urgent = 0;
                $locked = 0;
                foreach ($machines as $mm) {
                    if (!empty($mm['is_done'])) $done++;
                    if (!empty($mm['is_urgent'])) $urgent++;
                    if (!empty($mm['is_locked'])) $locked++;
                }
                $maintenance_progress[$cid] = ['total'=>$total,'done'=>$done,'percent'=>$total?round($done/$total*100):0,'urgent'=>$urgent,'notes'=>($notes_by_card[$cid] ?? 0),'locked'=>$locked];
            }
            foreach ($all_cards as $c) {
                if (!empty($c['is_maintenance']) && !isset($maintenance_progress[$c['id']])) $maintenance_progress[$c['id']] = ['total'=>0,'done'=>0,'percent'=>0,'urgent'=>0,'notes'=>0,'locked'=>0];
            }
        }

        $comment_counts = [];
        $att_counts = [];
        try {
            $__card_ids = array_column($all_cards, 'id') ?: [0];
            foreach ($DB->request(['SELECT' => ['plugin_kanpro_cards_id', 'COUNT' => 'id AS total'], 'FROM' => 'glpi_plugin_kanpro_comments', 'WHERE' => ['plugin_kanpro_cards_id' => $__card_ids], 'GROUPBY' => ['plugin_kanpro_cards_id']]) as $r) {
                $comment_counts[(int)$r['plugin_kanpro_cards_id']] = (int)$r['total'];
            }
            foreach ($DB->request(['SELECT' => ['plugin_kanpro_cards_id', 'COUNT' => 'id AS total'], 'FROM' => 'glpi_plugin_kanpro_attachments', 'WHERE' => ['plugin_kanpro_cards_id' => $__card_ids], 'GROUPBY' => ['plugin_kanpro_cards_id']]) as $r) {
                $att_counts[(int)$r['plugin_kanpro_cards_id']] = (int)$r['total'];
            }
            foreach ($all_cards as $c) {
                $cid = (int)$c['id'];
                if (!isset($comment_counts[$cid])) $comment_counts[$cid] = 0;
                if (!isset($att_counts[$cid])) $att_counts[$cid] = 0;
            }
        } catch (Throwable $e) {}

        $viewers = [];
        $cutoff = date('Y-m-d H:i:s', time() - 15);
        $viewers_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_presence', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'last_seen' => ['>', $cutoff]]]);
        $__vrows = [];
        $__vuids = [];
        foreach ($viewers_iter as $v) { $__vrows[] = $v; $__vuids[] = (int)$v['users_id']; }
        $__vusers = [];
        if (!empty($__vuids)) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => array_values(array_unique($__vuids))]]) as $ur) {
                $__vusers[(int)$ur['id']] = $ur;
            }
        }
        foreach ($__vrows as $v) {
            $uid = (int)$v['users_id'];
            $ur = $__vusers[$uid] ?? null;
            if ($ur) {
                $tmpU = new User();
                $tmpU->fields = $ur + ($tmpU->fields ?? []);
                $uname = $tmpU->getFriendlyName();
                $initials = strtoupper(substr($ur['firstname'] ?? $ur['name'] ?? '?', 0, 1));
            } else {
                $uname = '#' . $uid;
                $initials = '?';
            }
            $viewers[] = ['users_id' => $uid, 'name' => $uname, 'initials' => $initials];
        }

        // últimas atividades p/ toast "quem alterou" (5 últimas, com nome Nome+Sobrenome)
        $recent_activity = [];
        try {
            $aiter = $DB->request([
                'SELECT' => ['a.id', 'a.action', 'a.details', 'a.users_id', 'a.plugin_kanpro_cards_id', 'a.date_creation', 'u.firstname', 'u.realname', 'c.name AS card_name'],
                'FROM'   => 'glpi_plugin_kanpro_activities AS a',
                'LEFT JOIN' => [
                    'glpi_users AS u' => ['ON' => ['u' => 'id', 'a' => 'users_id']],
                    'glpi_plugin_kanpro_cards AS c' => ['ON' => ['c' => 'id', 'a' => 'plugin_kanpro_cards_id']],
                ],
                'WHERE'  => ['a.plugin_kanpro_boards_id' => $boards_id],
                'ORDER'  => 'a.id DESC',
                'LIMIT'  => 5,
            ]);
            foreach ($aiter as $ra) {
                $rn = trim(($ra['firstname'] ?? '') . ' ' . ($ra['realname'] ?? ''));
                if ($rn === '') $rn = 'Sistema';
                $det = preg_replace('/^\[from:\d+\]\s*/', '', (string)($ra['details'] ?? ''));
                if (function_exists('mb_substr') ? mb_strlen($det, 'UTF-8') > 120 : strlen($det) > 120) {
                    $det = (function_exists('mb_substr') ? mb_substr($det, 0, 120, 'UTF-8') : substr($det, 0, 120)) . '…';
                }
                $recent_activity[] = [
                    'id' => (int)($ra['id'] ?? 0),
                    'action' => (string)($ra['action'] ?? ''),
                    'details' => $det,
                    'users_id' => (int)($ra['users_id'] ?? 0),
                    'user' => $rn,
                    'card_id' => (int)($ra['plugin_kanpro_cards_id'] ?? 0),
                    'card_name' => (string)($ra['card_name'] ?? ''),
                    'date' => (string)($ra['date_creation'] ?? ''),
                ];
            }
        } catch (Throwable $e) {}

        $transfer_status = [];
        if ($DB->tableExists('glpi_plugin_assetmgrstatus_transfers')) {
            foreach ($all_cards as $c) {
                if (empty($c['is_maintenance'])) continue;
                $like = "%[KanPro #{$c['id']}]%";
                $trIter = $DB->request(['FROM'=>'glpi_plugin_assetmgrstatus_transfers','WHERE'=>['reason'=>['LIKE',$like]],'ORDER'=>'id DESC','LIMIT'=>1]);
                if ($trIter->count()===0) continue;
                $tr = $trIter->current();
                if (!$tr) continue;
                $hasRec = !empty($tr['assinatura_image']);
                $hasTec = !empty($tr['assinatura_tecnico_image']);
                $isAssinado = $hasRec && $hasTec;
                if ($isAssinado) $transfer_status[$c['id']] = ['label'=>'Concluído','status'=>'concluido'];
                else $transfer_status[$c['id']] = ['label'=>'Retirada','status'=>'retirada'];
            }
        }

        jexit([
            'success' => true,
            'lists' => $lists,
            'hiddenLists' => $hiddenLists ?? [],
            'labels' => $labels,
            'cards' => $all_cards,
            'cardLabels' => $card_labels_map,
            'cardMembers' => $card_members_map,
            'checkProgress' => $check_progress,
            'maintenanceProgress' => $maintenance_progress,
            'maintHash' => ($maint_hash ?? ''),
            'commentCounts' => $comment_counts,
            'attCounts' => $att_counts,
            'members' => $members_list,
            'viewers' => $viewers,
            'transferStatus' => $transfer_status,
            'recentActivity' => ($recent_activity ?? []),
        ]);

    case 'global_search_cards':
        $q = trim($_POST['q'] ?? '');
        if (mb_strlen($q) < 2) jexit(['success' => true, 'results' => []]);
        $nq = kanpro_norm_text($q);
        $entities = $_SESSION['glpiactiveentities'] ?? [0];
        $boards_iter = $DB->request([
            'FROM'  => 'glpi_plugin_kanpro_boards',
            'WHERE' => ['entities_id' => $entities, 'is_archived' => 0],
        ]);
        $boards_by_id = [];
        foreach ($boards_iter as $b) { $boards_by_id[$b['id']] = $b; }
        if (empty($boards_by_id)) jexit(['success' => true, 'results' => []]);

        $lists_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['plugin_kanpro_boards_id' => array_keys($boards_by_id)]]);
        $lists_by_id = [];
        foreach ($lists_iter as $l) { $lists_by_id[$l['id']] = $l; }

        $push_card = function($c) use (&$results, &$seen_ids, $boards_by_id, $lists_by_id) {
            $cid = (int)$c['id'];
            if (isset($seen_ids[$cid])) return false;
            $board = $boards_by_id[$c['plugin_kanpro_boards_id']] ?? null;
            if (!$board) return false;
            $list = $lists_by_id[$c['plugin_kanpro_lists_id']] ?? null;
            // respeita visibilidade da lista
            if ($list && function_exists('kanpro_can_view_list') && !kanpro_can_view_list($list, (int)$c['plugin_kanpro_boards_id'])) return false;
            $seen_ids[$cid] = true;
            $results[] = [
                'card_id'    => $cid,
                'card_name'  => $c['name'],
                'board_id'   => (int) $board['id'],
                'board_name' => $board['name'],
                'list_name'  => $list['name'] ?? '',
            ];
            return true;
        };
        $results = [];
        $seen_ids = [];
        // 1) via SQL LIKE (rápido; depende do collation p/ acentos)
        $cards_iter = $DB->request([
            'FROM'  => 'glpi_plugin_kanpro_cards',
            'WHERE' => [
                'plugin_kanpro_boards_id' => array_keys($boards_by_id),
                'is_archived' => 0,
                'OR' => [
                    'name'        => ['LIKE', "%{$q}%"],
                    'description' => ['LIKE', "%{$q}%"],
                ],
            ],
            'ORDER' => 'date_mod DESC',
            'LIMIT' => 100,
        ]);
        foreach ($cards_iter as $c) {
            if (count($results) >= 40) break;
            $push_card($c);
        }
        // 2) fallback sem acento em PHP (garante "cafe" achar "café" e vice-versa,
        // independente do collation do banco)
        if (count($results) < 40 && $nq !== '') {
            $all_iter = $DB->request([
                'FROM'  => 'glpi_plugin_kanpro_cards',
                'WHERE' => [
                    'plugin_kanpro_boards_id' => array_keys($boards_by_id),
                    'is_archived' => 0,
                ],
                'ORDER' => 'date_mod DESC',
                'LIMIT' => 800,
            ]);
            foreach ($all_iter as $c) {
                if (count($results) >= 40) break;
                $cid = (int)$c['id'];
                if (isset($seen_ids[$cid])) continue;
                $hay = kanpro_norm_text(($c['name'] ?? '') . ' ' . ($c['description'] ?? ''));
                if (mb_strpos($hay, $nq) !== false) $push_card($c);
            }
        }
        jexit(['success' => true, 'results' => $results]);

    case 'reorder_lists':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (!is_array($order)) jexit(['success'=>false]);
        PluginKanproList::reorder($bid, $order);
        jexit(['success'=>true]);

    case 'copy_list':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $l = new PluginKanproList();
        if (!$l->getFromDB($id)) jexit(['success'=>false]);
        $new_id = $l->add(['plugin_kanpro_boards_id'=>$l->fields['plugin_kanpro_boards_id'],'name'=>$l->fields['name'].' (cópia)','list_type'=>kanpro_valid_list_type((string)($l->fields['list_type'] ?? ''))]);
        // copia visibilidade (quem via a original vê a cópia)
        try {
            if ($new_id && $DB->tableExists('glpi_plugin_kanpro_lists_viewers') && function_exists('kanpro_list_viewer_ids')) {
                foreach (kanpro_list_viewer_ids($id) as $uid) {
                    try { $DB->insert('glpi_plugin_kanpro_lists_viewers', ['plugin_kanpro_lists_id' => $new_id, 'users_id' => (int)$uid]); } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {}
        // copia cartões
        $cards = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$id,'is_archived'=>0]]);
        foreach ($cards as $c) {
            PluginKanproCard::duplicate($c['id'], $new_id);
        }
        jexit(['success'=>true,'id'=>$new_id]);

    case 'move_list':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $target_board = (int)($_POST['target_boards_id'] ?? 0);
        if (!$target_board) jexit(['success'=>false]);
        $DB->update('glpi_plugin_kanpro_lists', ['plugin_kanpro_boards_id'=>$target_board], ['id'=>$id]);
        // move também cartões?
        $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_boards_id'=>$target_board], ['plugin_kanpro_lists_id'=>$id]);
        jexit(['success'=>true]);

    // --- CARDS ---
    case 'add_card':
        needEdit();
        $lists_id = (int)($_POST['lists_id'] ?? 0);
        kanpro_require_board_edit(kanpro_board_id_for_list($lists_id));
        $name = function_exists('kanpro_clean_text') ? kanpro_clean_text($_POST['name'] ?? '', 255) : trim(strip_tags($_POST['name'] ?? ''));
        if (!$name) jexit(['success'=>false,'msg'=>'Título obrigatório']);
        $list = new PluginKanproList();
        if (!$list->getFromDB($lists_id)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        // listas de ajuste não aceitam cartão novo (entram sozinhas pelo fluxo)
        $cat = kanpro_need_list_allows_card($lists_id);
        // Pendência Chamado tem criação própria (título + descrição -> clona p/ Abrir e move p/ Em andamento)
        if ($cat === 'pend_chamado') {
            jexit(['success'=>false,'msg'=>'Na Pendência Chamado use "Novo chamado" (título + descrição).','need_chamado'=>true]);
        }
        if ($cat === 'pending') {
            jexit(['success'=>false,'msg'=>'Na lista Pendente o cartão é criado direto como Manutenção.','need_maintenance'=>true]);
        }
        $card = new PluginKanproCard();
        $newFields = ['plugin_kanpro_boards_id'=>$list->fields['plugin_kanpro_boards_id'],'plugin_kanpro_lists_id'=>$lists_id,'name'=>$name];
        if (!empty($_POST['whatsapp_notify']) && $DB->fieldExists('glpi_plugin_kanpro_cards', 'whatsapp_notify')) {
            $newFields['whatsapp_notify'] = 1;
        }
        $id = $card->add($newFields);
        if (!$id) jexit(['success'=>false,'msg'=>'Não foi possível criar o cartão (tente de novo)']);
        // garante flag mesmo se add() filtrou (schema antigo)
        if (!empty($newFields['whatsapp_notify'])) {
            try { $DB->update('glpi_plugin_kanpro_cards', ['whatsapp_notify'=>1], ['id'=>$id]); $card->getFromDB($id); } catch (Throwable $e) {}
        }
        jexit(['success'=>true,'id'=>$id, 'card'=>$card->fields]);

    case 'add_pending_maintenance':
        // Criação na lista "Pendente": o cartão JÁ nasce como Manutenção (checklist por
        // máquina), com o nome vindo da entidade. Mesmo desafio de confirmação da conversão.
        needEdit();
        kanpro_ensure_maintenance_tables();
        $lists_id = (int)($_POST['lists_id'] ?? 0);
        $list = new PluginKanproList();
        if (!$lists_id || !$list->getFromDB($lists_id)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        if (kanpro_need_list_allows_card($lists_id) !== 'pending') {
            jexit(['success'=>false,'msg'=>'Só a lista com categoria "Pendente" cria cartão de Manutenção.']);
        }
        $confirm = $_POST['confirm_text'] ?? $_POST['confirm'] ?? '';
        if (!kanpro_maint_challenge_ok((string)$confirm)) {
            jexit(['success'=>false,'msg'=>'Palavra de confirmação inválida. Digite exatamente a palavra desafio exibida (sem acento).','need_confirm'=>true]);
        }
        $entities_id = isset($_POST['entities_id']) ? (int)$_POST['entities_id'] : 0;
        $newName = kanpro_maint_name_from_entity($entities_id, (string)($_POST['entity_name'] ?? $_POST['entities_name'] ?? ''));
        if ($newName === null) {
            jexit(['success'=>false,'msg'=>'Selecione a entidade. O nome do card virará o nome da entidade.','need_entity'=>true]);
        }
        $bid = (int)($list->fields['plugin_kanpro_boards_id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $actor = kanpro_acting_user_id();
        $cardFields = [
            'plugin_kanpro_boards_id' => $bid,
            'plugin_kanpro_lists_id'  => $lists_id,
            'name'                    => $newName,
        ];
        // add() só recebe o que existe no schema (install antigo pode não ter as colunas)
        $after = [];
        if ($DB->fieldExists('glpi_plugin_kanpro_cards', 'is_maintenance'))   { $cardFields['is_maintenance'] = 1; }
        if ($DB->fieldExists('glpi_plugin_kanpro_cards', 'maintenance_date')) { $cardFields['maintenance_date'] = $now; $after['maintenance_date'] = $now; }
        if ($DB->fieldExists('glpi_plugin_kanpro_cards', 'maintenance_by'))   { $cardFields['maintenance_by'] = $actor; $after['maintenance_by'] = $actor; }
        if ($DB->fieldExists('glpi_plugin_kanpro_cards', 'entities_id'))      { $cardFields['entities_id'] = (int)$entities_id; }
        $card = new PluginKanproCard();
        $id = $card->add($cardFields);
        if (!$id) jexit(['success'=>false,'msg'=>'Não foi possível criar o cartão (tente de novo)']);
        // is_maintenance/maintenance_* podem não ter entrado no add() (schema sem coluna) — garante
        if ($after) {
            $post = $after;
            if ($DB->fieldExists('glpi_plugin_kanpro_cards', 'is_maintenance')) $post['is_maintenance'] = 1;
            $DB->update('glpi_plugin_kanpro_cards', $post, ['id'=>$id]);
        }
        // 1 activity só: kanpro_finish_maintenance já registra a criação + gera o chamado
        $extra = kanpro_finish_maintenance($id, $newName, $entities_id, true);
        kanpro_touch_card($id);
        $fresh = new PluginKanproCard();
        $fresh->getFromDB($id);
        jexit(['success'=>true,'id'=>(int)$id,'card'=>$fresh->fields,'is_maintenance'=>1,'new_name'=>$newName,
            'entities_id'=>$entities_id] + $extra);

    case 'add_task_card':
        // criação guiada (listas A Fazer / Pautas futuras): título + checklist + prazo + urgência
        needEdit();
        $lists_id = (int)($_POST['lists_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$name) jexit(['success'=>false,'msg'=>'Título obrigatório']);
        $list = new PluginKanproList();
        if (!$list->getFromDB($lists_id)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        kanpro_need_list_allows_card($lists_id);
        $due = trim($_POST['due_date'] ?? '');
        $urgent = !empty($_POST['is_urgent']) ? 1 : 0;
        $zapNotify = !empty($_POST['whatsapp_notify']) ? 1 : 0;
        $card = new PluginKanproCard();
        $taskFields = [
            'plugin_kanpro_boards_id' => $list->fields['plugin_kanpro_boards_id'],
            'plugin_kanpro_lists_id'  => $lists_id,
            'name'                    => mb_substr($name, 0, 255),
            'due_date'                => ($due !== '' ? $due : null),
            'is_urgent'               => $urgent,
        ];
        if ($zapNotify && $DB->fieldExists('glpi_plugin_kanpro_cards', 'whatsapp_notify')) {
            $taskFields['whatsapp_notify'] = 1;
        }
        $id = $card->add($taskFields);
        if (!$id) jexit(['success'=>false,'msg'=>'Não foi possível criar o cartão']);
        if ($zapNotify) {
            try { $DB->update('glpi_plugin_kanpro_cards', ['whatsapp_notify'=>1], ['id'=>$id]); } catch (Throwable $e) {}
        }
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items)) $items = [];
        $items = array_values(array_filter(array_map(function ($v) { return mb_substr(trim((string)$v), 0, 255); }, $items), function ($v) { return $v !== ''; }));
        $items = array_slice($items, 0, 100);
        $checklists_id = 0;
        $added = 0;
        if (!empty($items)) {
            $cl = new PluginKanproChecklist();
            $checklists_id = (int)$cl->add(['plugin_kanpro_cards_id'=>$id,'name'=>'O que fazer']);
            if ($checklists_id) {
                foreach ($items as $n) {
                    $it = new PluginKanproChecklistItem();
                    if ($it->add(['plugin_kanpro_checklists_id'=>$checklists_id,'name'=>$n])) $added++;
                }
            }
        }
        kanpro_touch_member($id);
        kanpro_touch_card($id);
        PluginKanproBoard::logActivity($list->fields['plugin_kanpro_boards_id'], $id, $lists_id, 'card_create', "Cartão '{$name}' criado");
        $card->getFromDB($id);
        jexit(['success'=>true,'id'=>$id,'card'=>$card->fields,'checklists_id'=>$checklists_id,'items_added'=>$added]);

    case 'get_card':
        $cid = (int)($_REQUEST['cards_id'] ?? 0);
        kanpro_ensure_board_extras();
        $data = PluginKanproCard::getFullData($cid);
        if (!$data) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        // IDOR: exige acesso ao quadro + visibilidade da lista
        kanpro_require_board_view((int)($data['plugin_kanpro_boards_id'] ?? 0));
        // trava de visibilidade da lista: quem não pode ver a lista não abre o cartão
        if (function_exists('kanpro_can_view_list')) {
            $lrChk = new PluginKanproList();
            if ($lrChk->getFromDB((int)($data['plugin_kanpro_lists_id'] ?? 0))) {
                if (!kanpro_can_view_list($lrChk->fields, (int)($data['plugin_kanpro_boards_id'] ?? 0))) {
                    jexit(['success'=>false,'msg'=>'Você não tem acesso a esta lista.']);
                }
            }
        }
        jexit(['success'=>true,'data'=>$data]);

    case 'update_card':
        needEdit();
        $cid = (int)($_POST['id'] ?? 0);
        kanpro_require_board_edit(kanpro_board_id_for_card($cid));
        kanpro_need_card_editable($cid);
        $fields = [];
        if (isset($_POST['name'])) $fields['name'] = function_exists('kanpro_clean_text') ? kanpro_clean_text($_POST['name'], 255) : trim(strip_tags($_POST['name']));
        if (array_key_exists('description', $_POST)) $fields['description'] = function_exists('kanpro_clean_rich') ? kanpro_clean_rich($_POST['description']) : $_POST['description'];
        if (array_key_exists('due_date', $_POST)) $fields['due_date'] = empty($_POST['due_date']) ? null : $_POST['due_date'];
        if (array_key_exists('start_date', $_POST)) $fields['start_date'] = empty($_POST['start_date']) ? null : $_POST['start_date'];
        if (array_key_exists('cover_color', $_POST)) {
            $__cc = trim((string)$_POST['cover_color']);
            $fields['cover_color'] = ($__cc !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $__cc)) ? $__cc : null;
        }
        if (array_key_exists('is_urgent', $_POST)) $fields['is_urgent'] = (int)$_POST['is_urgent'] ? 1 : 0;
        if (array_key_exists('is_completed', $_POST)) $fields['is_completed'] = (int)$_POST['is_completed'];
        if (empty($fields)) jexit(['success'=>false]);
        $fields['id'] = $cid;
        $c = new PluginKanproCard();
        // update() retorna false em falha (ex: coluna nova sem migração, deadlock) — antes fingia sucesso
        // e a edição "voltava" sozinha no próximo polling
        if (!$c->update($fields)) jexit(['success'=>false,'msg'=>'Não foi possível salvar (tente de novo)']);
        kanpro_touch_member($cid);
        jexit(['success'=>true]);

    case 'set_card_whatsapp':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? $_POST['id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        kanpro_need_card_editable($cid);
        $cardW = new PluginKanproCard();
        if (!$cardW->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $bidW = (int)($cardW->fields['plugin_kanpro_boards_id'] ?? 0);
        // só admin do quadro (ou criador do card) pode ligar/desligar — "admin do card"
        $isCreator = ((int)($cardW->fields['users_id'] ?? 0) === kanpro_acting_user_id()) || ((int)($cardW->fields['users_id'] ?? 0) === (int)Session::getLoginUserID());
        if (!kanpro_can_manage_members($bidW) && !$isCreator) {
            jexit(['success'=>false,'msg'=>'Somente admin do quadro pode alterar a Notificação WhatsApp.']);
        }
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        if (isset($_POST['whatsapp_notify'])) $enabled = ((int)$_POST['whatsapp_notify'] ? 1 : 0);
        try {
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'whatsapp_notify')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `whatsapp_notify` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1=notificacao whatsapp habilitada' AFTER `chamado_by`");
            }
            $DB->update('glpi_plugin_kanpro_cards', ['whatsapp_notify'=>$enabled,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$cid]);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Falha ao salvar']); }
        kanpro_touch_card($cid);
        PluginKanproBoard::logActivity($bidW, $cid, (int)($cardW->fields['plugin_kanpro_lists_id'] ?? 0), 'card_whatsapp', ($enabled ? 'Notificação WhatsApp ATIVADA' : 'Notificação WhatsApp desativada'));
        jexit(['success'=>true,'whatsapp_notify'=>$enabled]);

    case 'send_card_whatsapp':
        $cid = (int)($_POST['cards_id'] ?? $_POST['id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $cardS = new PluginKanproCard();
        if (!$cardS->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $bidS = (int)($cardS->fields['plugin_kanpro_boards_id'] ?? 0);
        if (!kanpro_can_view_board($bidS)) jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
        if (empty($cardS->fields['whatsapp_notify'])) jexit(['success'=>false,'msg'=>'Notificação WhatsApp desligada neste cartão.']);
        // só membro do card ou membro/admin do quadro pode apertar o botão
        $meIds = array_unique([kanpro_acting_user_id(), (int)Session::getLoginUserID()]);
        $isCardMember = countElementsInTable('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$cid,'users_id'=>$meIds]) > 0;
        $boardRole = kanpro_my_board_role($bidS);
        if (!$isCardMember && $boardRole === null) {
            // criador do card também pode
            $isCreatorS = in_array((int)($cardS->fields['users_id'] ?? 0), $meIds, true);
            if (!$isCreatorS) jexit(['success'=>false,'msg'=>'Somente Membro ou Admin do cartão pode notificar.']);
        }
        if (!class_exists('PluginKanproMaintenanceZap')) jexit(['success'=>false,'msg'=>'Remetente WhatsApp indisponível']);
        try {
            $res = PluginKanproMaintenanceZap::sendCardAlerta($cid);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Erro ao enviar: '.$e->getMessage()]); }
        if (!empty($res['ok'])) jexit(['success'=>true,'phone'=>($res['phone'] ?? '')]);
        $err = (string)($res['error'] ?? 'falha');
        if ($err === 'sem telefone') {
            $appr = PluginKanproMaintenanceZap::pendenciaApprover();
            $err = $appr !== '' ? "Aprovador sem telefone cadastrado ({$appr})" : 'Aprovador não configurado — ajuste nas Configurações do KanPro.';
        }
        jexit(['success'=>false,'msg'=>'WhatsApp não enviado: '.$err]);

    case 'move_card':
        needEdit();
        kanpro_ensure_board_extras();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $target_list = (int)($_POST['target_lists_id'] ?? 0);
        // IDOR: origem e destino têm que ser do mesmo quadro acessível
        $__srcBid = kanpro_board_id_for_card($cid);
        $__dstBid = kanpro_board_id_for_list($target_list);
        if ($__srcBid > 0) kanpro_require_board_edit($__srcBid);
        if ($__dstBid > 0 && $__dstBid !== $__srcBid) kanpro_require_board_edit($__dstBid);
        if ($__srcBid > 0 && $__dstBid > 0 && $__srcBid !== $__dstBid) jexit(['success'=>false,'msg'=>'Listas de quadros diferentes']);
        // Pendente só recebe cartão de Manutenção (lá tudo é travado): card normal não entra
        if (kanpro_list_category($target_list) === 'pending') {
            $mchk = new PluginKanproCard();
            if ($mchk->getFromDB($cid) && empty($mchk->fields['is_maintenance'])) {
                jexit(['success'=>false,'msg'=>'A lista Pendente só recebe cartões de Manutenção. Crie o cartão por ela (ele já nasce como Manutenção).']);
            }
        }
        // origem p/ histórico de movimentação
        $c0 = new PluginKanproCard();
        $from_list = 0; $from_name = ''; $bid0 = 0;
        if ($c0->getFromDB($cid)) {
            $from_list = (int)$c0->fields['plugin_kanpro_lists_id'];
            $bid0 = (int)$c0->fields['plugin_kanpro_boards_id'];
            $fl0 = new PluginKanproList();
            if ($fl0->getFromDB($from_list)) $from_name = $fl0->fields['name'];
        }
        // Pendente é travado: ninguém arrasta — o caminho é o botão Pegar (membro ou admin).
        if ($from_list && kanpro_list_category($from_list) === 'pending') {
            jexit(['success'=>false,'msg'=>'Card da lista Pendente é travado — ninguém pode arrastar. Use o botão Pegar (membro ou admin do quadro) para mover para Em Andamento.']);
        }
        // Fluxo Chamado é travado: Abrir / Em andamento / Finalizado só mudam pelos botões
        // (Chamado aberto / Atualizar card) — arrastar quebraria o vínculo com o ticket do GLPI.
        if ($from_list && in_array(kanpro_list_category($from_list), ['abrir_chamado','andamento_chamado','chamado_finalizado'], true)) {
            jexit(['success'=>false,'msg'=>'Card do fluxo Chamado é travado — use os botões do cartão (Chamado aberto / Atualizar card).']);
        }
        if ($target_list && in_array(kanpro_list_category($target_list), ['abrir_chamado','andamento_chamado','chamado_finalizado'], true)) {
            jexit(['success'=>false,'msg'=>'Não é possível arrastar para esta lista — os cartões chegam aqui pelo fluxo do Chamado.']);
        }
        $pos = (isset($_POST['position']) && $_POST['position'] !== '') ? (int)$_POST['position'] : null;
        // Se position dado, calcula rank; senão joga pro fim
        $moveOk = true;
        if ($pos !== null) {
            // pega cartões da lista destino ordenados
            $cards = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$target_list,'is_archived'=>0],'ORDER'=>'rank ASC']);
            $ids = array_column(iterator_to_array($cards), 'id');
            // remove se já está
            $ids = array_values(array_filter($ids, fn($x)=>$x!=$cid));
            array_splice($ids, max(0, $pos), 0, [$cid]);
            // reordena
            $rank = 1024;
            foreach ($ids as $id) {
                if ($id == $cid) {
                    $moveOk = $DB->update('glpi_plugin_kanpro_cards', ['rank'=>$rank,'plugin_kanpro_lists_id'=>$target_list], ['id'=>$cid]) && $moveOk;
                } else {
                    $DB->update('glpi_plugin_kanpro_cards', ['rank'=>$rank], ['id'=>$id]);
                }
                $rank+=1024;
            }
            // atualiza boards_id se mudou de quadro
            $list = new PluginKanproList();
            if ($list->getFromDB($target_list)) {
                $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_boards_id'=>$list->fields['plugin_kanpro_boards_id']], ['id'=>$cid]);
            }
        } else {
            $moveOk = PluginKanproCard::moveCard($cid, $target_list);
        }
        if (!$moveOk) jexit(['success'=>false,'msg'=>'Não foi possível mover (tente de novo)']);
        // histórico de movimentação do cartão
        $pending = false;
        if ($from_list && $target_list && $from_list !== $target_list) {
            $tl0 = new PluginKanproList();
            $to_name = $tl0->getFromDB($target_list) ? $tl0->fields['name'] : ('#' . $target_list);
            PluginKanproBoard::logActivity($bid0, $cid, $target_list, 'card_move', "[from:{$from_list}] Saiu de '{$from_name}' → '{$to_name}'");
            // lista com aprovação: membro move, só admin/criador aprova
            $targetBid = $tl0->fields['plugin_kanpro_boards_id'] ?? $bid0;
            if (!empty($tl0->fields['require_approval']) && !kanpro_can_manage_members((int)$targetBid)) {
                $DB->update('glpi_plugin_kanpro_cards', ['approval_from'=>$from_list], ['id'=>$cid]);
                PluginKanproBoard::logActivity((int)$targetBid, $cid, $target_list, 'card_approval_request', "Aguardando aprovação de admin para entrar em '{$to_name}'");
                $pending = true;
            } else {
                $DB->update('glpi_plugin_kanpro_cards', ['approval_from'=>0], ['id'=>$cid]);
            }
        }
        // Butler-like: entrou na lista de verdade (sem pendência) -> roda automações do quadro
        if ($from_list && $target_list && $from_list !== $target_list && !$pending && function_exists('kanpro_run_rules')) {
            kanpro_run_rules((int)$bid0, (int)$cid, (int)$target_list);
        }
        // devolve a linha fresca p/ o JS reconciliar (lista+rank reais do banco)
        $fresh = new PluginKanproCard();
        $fresh->getFromDB($cid);
        jexit(['success'=>true,'pending_approval'=>$pending,'card'=>$fresh->fields]);

    case 'move_all_cards':

    case 'move_all_cards':
        needEdit();
        kanpro_ensure_board_extras();
        $from = (int)($_POST['lists_id'] ?? 0);
        $to = (int)($_POST['target_lists_id'] ?? 0);
        if (!$from || !$to || $from === $to) jexit(['success'=>false,'msg'=>'Listas inválidas']);
        $fl = new PluginKanproList(); $tl = new PluginKanproList();
        if (!$fl->getFromDB($from) || !$tl->getFromDB($to)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        if ((int)$fl->fields['plugin_kanpro_boards_id'] !== (int)$tl->fields['plugin_kanpro_boards_id']) jexit(['success'=>false,'msg'=>'Listas de quadros diferentes']);
        // Pendente só recebe cartão de Manutenção (lá tudo é travado)
        if (kanpro_list_category($to) === 'pending') {
            foreach ($DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$from,'is_archived'=>0]]) as $chk) {
                if (empty($chk['is_maintenance'])) {
                    jexit(['success'=>false,'msg'=>'A lista Pendente só recebe cartões de Manutenção — este lote tem card comum (#'.(int)$chk['id'].').']);
                }
            }
        }
        $bid = (int)$fl->fields['plugin_kanpro_boards_id'];
        $cards = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$from,'is_archived'=>0],'ORDER'=>'rank ASC']);
        $last = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$to],'ORDER'=>'rank DESC','LIMIT'=>1])->current();
        $rank = $last ? ((float)$last['rank'] + 1024) : 1024;
        $count = 0;
        $needsAppr = !empty($tl->fields['require_approval']) && !kanpro_can_manage_members($bid);
        foreach ($cards as $c) {
            $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_lists_id'=>$to,'rank'=>$rank,'approval_from'=>($needsAppr ? $from : 0)], ['id'=>$c['id']]);
            PluginKanproBoard::logActivity($bid, (int)$c['id'], $to, 'card_move', "[from:{$from}] Saiu de '{$fl->fields['name']}' → '{$tl->fields['name']}' (mover todos)");
            if ($needsAppr) PluginKanproBoard::logActivity($bid, (int)$c['id'], $to, 'card_approval_request', "Aguardando aprovação de admin para entrar em '{$tl->fields['name']}'");
            $rank += 1024; $count++;
        }
        if ($count) PluginKanproBoard::logActivity($bid, null, $to, 'list_move_all', "{$count} cartão(ões) movidos de '{$fl->fields['name']}' → '{$tl->fields['name']}'");
        jexit(['success'=>true,'moved'=>$count,'pending_approval'=>$needsAppr]);

    case 'archive_all_cards':
        needEdit();
        $lid = (int)($_POST['lists_id'] ?? 0);
        $fl = new PluginKanproList();
        if (!$fl->getFromDB($lid)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        $bid = (int)$fl->fields['plugin_kanpro_boards_id'];
        $cards = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$lid,'is_archived'=>0]]);
        $count = 0;
        foreach ($cards as $c) {
            $DB->update('glpi_plugin_kanpro_cards', ['is_archived'=>1], ['id'=>$c['id']]);
            PluginKanproBoard::logActivity($bid, (int)$c['id'], $lid, 'card_archive', "Arquivado junto com a lista '{$fl->fields['name']}' (arquivar todos)");
            $count++;
        }
        jexit(['success'=>true,'archived'=>$count]);

    case 'toggle_pin':
        needEdit();
        kanpro_ensure_board_extras();
        $cid = (int)($_POST['cards_id'] ?? 0);
        kanpro_need_chamado_released($cid);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['id'=>$cid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $new = !empty($row['is_pinned']) ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_cards', ['is_pinned'=>$new], ['id'=>$cid]);
        jexit(['success'=>true,'is_pinned'=>$new]);

    case 'set_list_approval':
        $lid = (int)($_POST['lists_id'] ?? 0);
        $val = !empty($_POST['require']) ? 1 : 0;
        $fl = new PluginKanproList();
        if (!$fl->getFromDB($lid)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        kanpro_ensure_board_extras();
        kanpro_need_manage_members((int)$fl->fields['plugin_kanpro_boards_id']);
        $DB->update('glpi_plugin_kanpro_lists', ['require_approval'=>$val], ['id'=>$lid]);
        jexit(['success'=>true,'require_approval'=>$val]);

    case 'approve_card':
        $cid = (int)($_POST['cards_id'] ?? 0);
        $c = new PluginKanproCard();
        if (!$c->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        kanpro_ensure_board_extras();
        kanpro_need_manage_members((int)$c->fields['plugin_kanpro_boards_id']);
        $DB->update('glpi_plugin_kanpro_cards', ['approval_from'=>0], ['id'=>$cid]);
        PluginKanproBoard::logActivity((int)$c->fields['plugin_kanpro_boards_id'], $cid, (int)$c->fields['plugin_kanpro_lists_id'], 'card_approval_ok', "Movimentação aprovada por admin");
        // Butler-like: aprovação confirma a entrada na lista -> roda automações
        if (function_exists('kanpro_run_rules')) {
            kanpro_run_rules((int)$c->fields['plugin_kanpro_boards_id'], (int)$cid, (int)$c->fields['plugin_kanpro_lists_id']);
        }
        jexit(['success'=>true]);

    case 'devolve_card':
        $cid = (int)($_POST['cards_id'] ?? 0);
        $c = new PluginKanproCard();
        if (!$c->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        kanpro_ensure_board_extras();
        kanpro_need_manage_members((int)$c->fields['plugin_kanpro_boards_id']);
        $back = (int)($c->fields['approval_from'] ?? 0);
        if (!$back) jexit(['success'=>false,'msg'=>'Sem pendência']);
        $bl = new PluginKanproList();
        if (!$bl->getFromDB($back)) jexit(['success'=>false,'msg'=>'Lista de origem não existe mais']);
        $last = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$back],'ORDER'=>'rank DESC','LIMIT'=>1])->current();
        $rank = $last ? ((float)$last['rank'] + 1024) : 1024;
        $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_lists_id'=>$back,'rank'=>$rank,'approval_from'=>0], ['id'=>$cid]);
        PluginKanproBoard::logActivity((int)$c->fields['plugin_kanpro_boards_id'], $cid, $back, 'card_move', "Devolvido para '{$bl->fields['name']}' (aprovação negada)");
        jexit(['success'=>true]);

    // --- FLUXO CHAMADO (Pendência -> Abrir -> Em andamento -> Finalizado + ticket GLPI) ---
    case 'add_chamado_card':
        // Cria na Pendência Chamado (título + descrição), abre o ticket no GLPI, clona o
        // card p/ Abrir chamado e move o original p/ Em andamento Chamado.
        needEdit();
        $lists_id = (int)($_POST['lists_id'] ?? 0);
        kanpro_require_board_edit(kanpro_board_id_for_list($lists_id));
        if (kanpro_list_category($lists_id) !== 'pend_chamado') {
            jexit(['success'=>false,'msg'=>'Chamados só nascem na lista Pendência Chamado.']);
        }
        $name = function_exists('kanpro_clean_text') ? kanpro_clean_text($_POST['name'] ?? '', 255) : trim(strip_tags($_POST['name'] ?? ''));
        $desc = function_exists('kanpro_clean_rich') ? kanpro_clean_rich($_POST['description'] ?? '') : trim(strip_tags($_POST['description'] ?? ''));
        if ($name === '') jexit(['success'=>false,'msg'=>'Título obrigatório']);
        $plist = new PluginKanproList();
        if (!$plist->getFromDB($lists_id)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        $bid = (int)$plist->fields['plugin_kanpro_boards_id'];
        $abrir = function_exists('kanpro_find_list_by_type') ? kanpro_find_list_by_type($bid, 'abrir_chamado') : null;
        $andam = function_exists('kanpro_find_list_by_type') ? kanpro_find_list_by_type($bid, 'andamento_chamado') : null;
        if (!$abrir) jexit(['success'=>false,'msg'=>'Crie uma lista com categoria "Abrir chamado" neste quadro.']);
        if (!$andam) jexit(['success'=>false,'msg'=>'Crie uma lista com categoria "Em Andamento Chamado" neste quadro.']);
        if (!class_exists('Ticket') || !Session::haveRight('ticket', CREATE)) {
            jexit(['success'=>false,'msg'=>'Sem permissão para criar chamados no GLPI (perfil sem ticket CREATE).']);
        }
        $actor = function_exists('kanpro_acting_user_id') ? kanpro_acting_user_id() : (int)Session::getLoginUserID();
        // Origem do chamado: URE (padrão, entidade do quadro/ativa) ou Escola (entidade selecionada).
        $origin = strtolower(trim($_POST['origin'] ?? 'ure'));
        if (!in_array($origin, ['ure','escola'], true)) $origin = 'ure';
        $originEntitiesId = 0;
        $originLabel = 'URE';
        if ($origin === 'escola') {
            $originEntitiesId = (int)($_POST['entities_id'] ?? 0);
            if ($originEntitiesId <= 0) jexit(['success'=>false,'msg'=>'Selecione a escola (entidade).']);
            $entRow = $DB->request(['FROM'=>'glpi_entities','WHERE'=>['id'=>$originEntitiesId]])->current();
            if (!$entRow || !empty($entRow['is_deleted'])) jexit(['success'=>false,'msg'=>'Escola (entidade) não encontrada.']);
            $originLabel = 'Escola: ' . trim(($entRow['completename'] ?? $entRow['name'] ?? ('#' . $originEntitiesId)));
        }
        $now = date('Y-m-d H:i:s');
        $card = new PluginKanproCard();
        $origId = (int)$card->add(['plugin_kanpro_boards_id'=>$bid,'plugin_kanpro_lists_id'=>$lists_id,
            'name'=>$name,'description'=>$desc,'entities_id'=>$originEntitiesId,
            'users_id'=>$actor,'date_creation'=>$now,'date_mod'=>$now]);
        if (!$origId) jexit(['success'=>false,'msg'=>'Não foi possível criar o cartão (tente de novo)']);
        // solicitante fica vinculado ao card (membro) — vale p/ ver o finalizado depois
        try { $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$origId,'users_id'=>$actor]); } catch (Throwable $e) {}
        // ticket GLPI acompanha tudo (obrigatório: sem ticket não há fluxo)
        $tk = function_exists('kanpro_create_ticket_from_card') ? kanpro_create_ticket_from_card($origId, $originEntitiesId, $originLabel) : ['ok'=>false,'error'=>'integração indisponível'];
        if (empty($tk['ok'])) {
            $card->delete(['id'=>$origId], true);
            jexit(['success'=>false,'msg'=>'Chamado GLPI não criado: ' . ($tk['error'] ?? 'erro')]);
        }
        $tickets_id = (int)($tk['id'] ?? 0);
        $card->getFromDB($origId);
        // clone p/ Abrir chamado (aguarda alguém abrir o chamado)
        $clone = new PluginKanproCard();
        $cloneId = (int)$clone->add(['plugin_kanpro_boards_id'=>$bid,'plugin_kanpro_lists_id'=>(int)$abrir['id'],
            'name'=>$name,'description'=>$desc,'tickets_id'=>$tickets_id,
            'entities_id'=>(int)($card->fields['entities_id'] ?? 0),
            'chamado_source_id'=>$origId,'chamado_status'=>'pendente','chamado_by'=>$actor,
            'users_id'=>$actor,'date_creation'=>$now,'date_mod'=>$now]);
        if (!$cloneId) {
            $card->delete(['id'=>$origId], true);
            jexit(['success'=>false,'msg'=>'Falha ao clonar para Abrir chamado']);
        }
        try { $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$cloneId,'users_id'=>$actor]); } catch (Throwable $e) {}
        // original -> Em andamento Chamado (fim da fila)
        try {
            $last = $DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['plugin_kanpro_lists_id' => (int)$andam['id']]])->current();
            $rank = (float)($last['m'] ?? 0) + 1024;
            if ($rank <= 0) $rank = 1024;
        } catch (Throwable $e) { $rank = 1024; }
        $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_lists_id'=>(int)$andam['id'],'rank'=>$rank,'date_mod'=>$now], ['id'=>$origId]);
        if (function_exists('kanpro_touch_card')) { kanpro_touch_card($origId); kanpro_touch_card($cloneId); }
        // Butler: entrada por fluxo também dispara automações da lista
        if (function_exists('kanpro_run_rules')) {
            kanpro_run_rules($bid, $origId, (int)$andam['id']);
            kanpro_run_rules($bid, $cloneId, (int)$abrir['id']);
        }
        PluginKanproBoard::logActivity($bid, $origId, (int)$andam['id'], 'chamado_created', "Chamado #{$tickets_id} criado ({$originLabel}): clone #{$cloneId} em Abrir chamado, original em Em Andamento");
        // zap p/ os responsáveis (não trava o fluxo se falhar)
        $zapRes = ['ok' => false];
        try {
            if (class_exists('PluginKanproMaintenanceZap')) $zapRes = PluginKanproMaintenanceZap::sendChamadoAbrir($cloneId);
        } catch (Throwable $e) { $zapRes = ['ok' => false, 'error' => $e->getMessage()]; }
        jexit(['success'=>true,'id'=>$origId,'clone_id'=>$cloneId,'tickets_id'=>$tickets_id,'zap_ok'=>!empty($zapRes['ok'])]);

    case 'chamado_mark_open':
        // Botão "Chamado aberto" (card clone em Abrir chamado): libera p/ ser realizado.
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $cc = new PluginKanproCard();
        if (!$cc->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        kanpro_require_board_edit((int)$cc->fields['plugin_kanpro_boards_id']);
        if (kanpro_list_category((int)$cc->fields['plugin_kanpro_lists_id']) !== 'abrir_chamado') {
            jexit(['success'=>false,'msg'=>'Este botão só existe em Abrir chamado.']);
        }
        if (($cc->fields['chamado_status'] ?? '') === 'liberado') jexit(['success'=>true,'already'=>true]);
        // autenticação por palavra-desafio (igual Manutenção): sem a palavra não libera
        $confirm = (string)($_POST['confirm_text'] ?? '');
        if (!function_exists('kanpro_maint_challenge_ok') || !kanpro_maint_challenge_ok($confirm)) {
            jexit(['success'=>false,'msg'=>'Palavra de confirmação inválida. Digite exatamente a palavra desafio exibida.','need_confirm'=>true]);
        }
        $DB->update('glpi_plugin_kanpro_cards', ['chamado_status'=>'liberado','date_mod'=>date('Y-m-d H:i:s')], ['id'=>$cid]);
        // marca o original também: o clone se auto-exclui em 30s e sem isso o cadeado voltava
        try {
            $srcId = (int)($cc->fields['chamado_source_id'] ?? 0);
            if ($srcId > 0) $DB->update('glpi_plugin_kanpro_cards', ['chamado_status'=>'liberado','date_mod'=>date('Y-m-d H:i:s')], ['id'=>$srcId]);
        } catch (Throwable $e) {}
        $tid = (int)($cc->fields['tickets_id'] ?? 0);
        if ($tid > 0) {
            if (function_exists('kanpro_ticket_followup')) kanpro_ticket_followup($tid, "Chamado aberto pelo responsável no KanPro (card #{$cid}). Liberado para execução.");
            if (function_exists('kanpro_ticket_set_attending')) kanpro_ticket_set_attending($tid);
        }
        if (function_exists('kanpro_touch_card')) kanpro_touch_card($cid);
        PluginKanproBoard::logActivity((int)$cc->fields['plugin_kanpro_boards_id'], $cid, (int)$cc->fields['plugin_kanpro_lists_id'], 'chamado_opened', "Chamado aberto (ticket #{$tid}) — liberado para execução");
        jexit(['success'=>true,'tickets_id'=>$tid]);

    case 'chamado_update':
        // Botão "Atualizar card" (original em Em andamento): anota o realizado + status.
        // Pendente = só anota; Finalizado = encerra ticket e move p/ Chamado finalizado.
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $note = function_exists('kanpro_clean_rich') ? trim(kanpro_clean_rich($_POST['note'] ?? '')) : trim(strip_tags($_POST['note'] ?? ''));
        $st = strtolower(trim($_POST['status'] ?? 'pendente'));
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        if ($note === '') jexit(['success'=>false,'msg'=>'Escreva o que foi realizado.']);
        if (!in_array($st, ['pendente','finalizado'], true)) jexit(['success'=>false,'msg'=>'Status inválido (use Pendente ou Finalizado).']);
        $cu = new PluginKanproCard();
        if (!$cu->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $bidU = (int)$cu->fields['plugin_kanpro_boards_id'];
        kanpro_require_board_edit($bidU);
        // bloqueado até o "Chamado aberto" (vale p/ anotar e p/ finalizar)
        kanpro_need_chamado_released($cid);
        if (kanpro_list_category((int)$cu->fields['plugin_kanpro_lists_id']) !== 'andamento_chamado') {
            jexit(['success'=>false,'msg'=>'Este botão só existe em Em Andamento Chamado.']);
        }
        if (!$DB->tableExists('glpi_plugin_kanpro_chamado_updates')) {
            // autocura: tenta criar na hora (servidor atualizado via git sem passar no Instalar).
            // Não apaga nada — CREATE IF NOT EXISTS lógico via tableExists.
            try {
                $charset = DBConnection::getDefaultCharset();
                $collation = DBConnection::getDefaultCollation();
                $sign = DBConnection::getDefaultPrimaryKeySignOption();
                $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_kanpro_chamado_updates` (`id` INT {$sign} NOT NULL AUTO_INCREMENT, `plugin_kanpro_cards_id` INT {$sign} NOT NULL DEFAULT '0', `users_id` INT {$sign} NOT NULL DEFAULT '0', `note` TEXT DEFAULT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'pendente', `date_creation` DATETIME DEFAULT NULL, PRIMARY KEY (`id`), KEY `plugin_kanpro_cards_id` (`plugin_kanpro_cards_id`), KEY `date_creation` (`date_creation`)) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
            } catch (Throwable $e) {}
        }
        if (!$DB->tableExists('glpi_plugin_kanpro_chamado_updates')) {
            jexit(['success'=>false,'msg'=>'Tabela de atualizações ausente (rode Instalar/Atualizar do plugin — não desinstale, não apaga nada).']);
        }
        $actorU = function_exists('kanpro_acting_user_id') ? kanpro_acting_user_id() : (int)Session::getLoginUserID();
        $DB->insert('glpi_plugin_kanpro_chamado_updates', ['plugin_kanpro_cards_id'=>$cid,'users_id'=>$actorU,
            'note'=>$note,'status'=>$st,'date_creation'=>date('Y-m-d H:i:s')]);
        $tidU = (int)($cu->fields['tickets_id'] ?? 0);
        if ($st === 'finalizado') {
            // só finaliza com o chamado realmente aberto: clone liberado, OU original já
            // carimbado como liberado, OU clone já auto-excluído (fail-open igual ao cadeado).
            // Sem isso, quem clicou "Chamado aberto" e esperou 30s nunca conseguia finalizar.
            $cloneRow = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards',
                'WHERE' => ['chamado_source_id' => $cid], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
            $__origLib = (($cu->fields['chamado_status'] ?? '') === 'liberado');
            $__cloneLib = ($cloneRow && (($cloneRow['chamado_status'] ?? '') === 'liberado'));
            if (!$__origLib && !$__cloneLib && $cloneRow) {
                jexit(['success'=>false,'msg'=>'Só dá para finalizar após o "Chamado aberto" em Abrir chamado.','need_open'=>true]);
            }
            if ($tidU > 0) {
                if (function_exists('kanpro_ticket_followup')) kanpro_ticket_followup($tidU, "Atualização final: {$note}");
                if (function_exists('kanpro_ticket_solve')) kanpro_ticket_solve($tidU, "Chamado finalizado pelo KanPro (card #{$cid}). {$note}");
            }
            $fin = function_exists('kanpro_find_list_by_type') ? kanpro_find_list_by_type($bidU, 'chamado_finalizado') : null;
            if (!$fin) jexit(['success'=>false,'msg'=>'Crie uma lista com categoria "Chamado finalizado" neste quadro.']);
            $nowF = date('Y-m-d H:i:s');
            // move o original sempre; o clone só se ainda existir (pode ter auto-excluído em 30s)
            $__toMove = [$cid];
            if ($cloneRow && (int)($cloneRow['id'] ?? 0) > 0) $__toMove[] = (int)$cloneRow['id'];
            foreach ($__toMove as $mid) {
                try {
                    $lastF = $DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['plugin_kanpro_lists_id' => (int)$fin['id']]])->current();
                    $rkF = (float)($lastF['m'] ?? 0) + 1024;
                    if ($rkF <= 0) $rkF = 1024;
                } catch (Throwable $e) { $rkF = 1024; }
                $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_lists_id'=>(int)$fin['id'],'rank'=>$rkF,'date_mod'=>$nowF], ['id'=>$mid]);
                if (function_exists('kanpro_touch_card')) kanpro_touch_card($mid);
            }
            PluginKanproBoard::logActivity($bidU, $cid, (int)$fin['id'], 'chamado_finished', "Chamado #{$tidU} finalizado e movido p/ Chamado finalizado");
            jexit(['success'=>true,'finished'=>true,'tickets_id'=>$tidU]);
        }
        if ($tidU > 0 && function_exists('kanpro_ticket_followup')) {
            kanpro_ticket_followup($tidU, "Atualização (pendente): {$note}");
        }
        if (function_exists('kanpro_touch_card')) kanpro_touch_card($cid);
        PluginKanproBoard::logActivity($bidU, $cid, (int)$cu->fields['plugin_kanpro_lists_id'], 'chamado_updated', "Atualização registrada (pendente)");
        jexit(['success'=>true,'finished'=>false,'tickets_id'=>$tidU]);

    case 'chamado_detail':
        // Dados p/ a visão simplificada (Abrir: título+desc+botão; Andamento: +atualizações).
        $cid = (int)($_REQUEST['cards_id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $cd = new PluginKanproCard();
        if (!$cd->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $bidD = (int)$cd->fields['plugin_kanpro_boards_id'];
        kanpro_require_board_view($bidD);
        $catD = kanpro_list_category((int)$cd->fields['plugin_kanpro_lists_id']);
        if (!in_array($catD, ['abrir_chamado','andamento_chamado','chamado_finalizado'], true)) {
            jexit(['success'=>false,'msg'=>'Fora do fluxo Chamado.']);
        }
        // Finalizado: admin vê tudo; membro só abre card ao qual está vinculado
        // (membro do card, criador ou autor de atualização). Espelha o filtro da lista.
        if ($catD === 'chamado_finalizado' && function_exists('kanpro_can_manage_members') && !kanpro_can_manage_members($bidD)) {
            $__linked = false;
            try {
                $__viewerIds = function_exists('kanpro_viewer_ids') ? kanpro_viewer_ids() : [(int)Session::getLoginUserID()];
                $__viewerIds = array_values(array_unique(array_map('intval', $__viewerIds)));
                if (in_array((int)($cd->fields['users_id'] ?? 0), $__viewerIds, true)) $__linked = true;
                if (!$__linked) {
                    foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_plugin_kanpro_cards_members', 'WHERE' => ['plugin_kanpro_cards_id' => $cid]]) as $__m) {
                        if (in_array((int)($__m['users_id'] ?? 0), $__viewerIds, true)) { $__linked = true; break; }
                    }
                }
                if (!$__linked && $DB->tableExists('glpi_plugin_kanpro_chamado_updates')) {
                    foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_plugin_kanpro_chamado_updates', 'WHERE' => ['plugin_kanpro_cards_id' => $cid]]) as $__u) {
                        if (in_array((int)($__u['users_id'] ?? 0), $__viewerIds, true)) { $__linked = true; break; }
                    }
                }
            } catch (Throwable $e) {}
            if (!$__linked) jexit(['success'=>false,'msg'=>'Você não está vinculado a este chamado finalizado.','need_link'=>true]);
        }
        if (function_exists('kanpro_can_view_list')) {
            $lrD = new PluginKanproList();
            if ($lrD->getFromDB((int)$cd->fields['plugin_kanpro_lists_id']) && !kanpro_can_view_list($lrD->fields, $bidD)) {
                jexit(['success'=>false,'msg'=>'Você não tem acesso a esta lista.']);
            }
        }
        $updates = [];
        try {
            if ($DB->tableExists('glpi_plugin_kanpro_chamado_updates')) {
                foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_chamado_updates', 'WHERE' => ['plugin_kanpro_cards_id' => $cid], 'ORDER' => 'date_creation ASC']) as $u) {
                    $updates[] = ['id'=>(int)$u['id'],'users_id'=>(int)$u['users_id'],'note'=>(string)($u['note'] ?? ''),
                        'status'=>(string)$u['status'],'date'=>(string)($u['date_creation'] ?? '')];
                }
            }
        } catch (Throwable $e) {}
        // irmão do fluxo: clone aponta p/ original via chamado_source_id
        $sibling = null;
        try {
            if ($catD === 'andamento_chamado') {
                $s = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['chamado_source_id' => $cid], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
                if ($s) $sibling = ['id'=>(int)$s['id'],'status'=>(string)($s['chamado_status'] ?? ''),'lists_id'=>(int)$s['plugin_kanpro_lists_id']];
            } elseif ($catD === 'abrir_chamado') {
                $srcId = (int)($cd->fields['chamado_source_id'] ?? 0);
                if ($srcId > 0) {
                    $s = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['id' => $srcId]])->current();
                    if ($s) $sibling = ['id'=>(int)$s['id'],'status'=>'','lists_id'=>(int)$s['plugin_kanpro_lists_id']];
                }
            }
        } catch (Throwable $e) {}
        jexit(['success'=>true,'card'=>['id'=>$cid,'name'=>(string)($cd->fields['name'] ?? ''),
            'description'=>(string)($cd->fields['description'] ?? ''),'tickets_id'=>(int)($cd->fields['tickets_id'] ?? 0),
            'chamado_status'=>(string)($cd->fields['chamado_status'] ?? ''),'category'=>$catD,
            'members'=> (function() use ($cid, $cd) {
                global $DB; $out = []; $seen = [];
                try {
                    $uids = [];
                    foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_plugin_kanpro_cards_members', 'WHERE' => ['plugin_kanpro_cards_id' => $cid]]) as $__m) $uids[] = (int)($__m['users_id'] ?? 0);
                    $creator = (int)($cd->fields['users_id'] ?? 0);
                    if ($creator > 0) $uids[] = $creator;
                    $uids = array_values(array_unique(array_filter($uids)));
                    if (!empty($uids)) {
                        $users = [];
                        foreach ($DB->request(['SELECT' => ['id','name','realname','firstname','picture'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $uids]]) as $ur) $users[(int)$ur['id']] = $ur;
                        $root = $GLOBALS['CFG_GLPI']['root_doc'] ?? '';
                        foreach ($uids as $uid) {
                            if (isset($seen[$uid])) continue; $seen[$uid] = true;
                            $ur = $users[$uid] ?? null;
                            if ($ur) {
                                $tmpU = new User(); $tmpU->fields = $ur + ($tmpU->fields ?? []);
                                $uname = $tmpU->getFriendlyName();
                                if (trim((string)$uname) === '') $uname = (string)($ur['name'] ?? ('#' . $uid));
                                $initials = strtoupper(substr($ur['firstname'] ?? $ur['name'] ?? '?', 0, 1));
                                $pic = $ur['picture'] ?? '';
                            } else { $uname = '#' . $uid; $initials = '?'; $pic = ''; }
                            $out[] = ['users_id'=>$uid,'name'=>$uname,'initials'=>$initials,
                                'picture_url'=>($pic !== '' ? $root . '/front/document.send.php?file=_pictures/' . $pic : '')];
                        }
                    }
                } catch (Throwable $e) {}
                return $out;
            })(),
            'board_name'=> (function() use ($bidD) { try { $b = new PluginKanproBoard(); if ($b->getFromDB($bidD)) return (string)($b->fields['name'] ?? ''); } catch (Throwable $e) {} return ''; })(),
            'entity_name'=> (function() use ($cd) { try { global $DB; $eid = (int)($cd->fields['entities_id'] ?? 0); if ($eid <= 0) return ''; $r = $DB->request(['FROM'=>'glpi_entities','WHERE'=>['id'=>$eid]])->current(); if (!$r) return ''; return trim(($r['completename'] ?? $r['name'] ?? '')); } catch (Throwable $e) { return ''; } })(),
            'creator_id'=>(int)($cd->fields['users_id'] ?? 0),
            'creator_name'=> (function() use ($cd) { try { $u = new User(); if ($u->getFromDB((int)($cd->fields['users_id'] ?? 0))) { $n = $u->getFriendlyName(); if (trim((string)$n) === '') $n = (string)($u->fields['name'] ?? ''); return (string)$n; } } catch (Throwable $e) {} return ''; })(),
            'date_creation'=>(string)($cd->fields['date_creation'] ?? '')],
            'updates'=>$updates,'sibling'=>$sibling,
            'can_edit'=> (Session::haveRight('plugin_kanpro', UPDATE) || Session::haveRight('plugin_kanpro', CREATE))]);

    case 'duplicate_board':
        if (!Session::haveRight('plugin_kanpro', CREATE)) jexit(['success'=>false,'msg'=>'Sem permissão']);
        kanpro_ensure_board_extras();
        $bid = (int)($_POST['boards_id'] ?? 0);
        // IDOR: só clona o que pode ver — antes clonava cards/membros de quadro privado
        kanpro_require_board_view($bid);
        $name = trim($_POST['name'] ?? '');
        $src = new PluginKanproBoard();
        if (!$src->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        if ($name === '') $name = $src->fields['name'] . ' (cópia)';
        $me = (int)Session::getLoginUserID();
        $nb = new PluginKanproBoard();
        $newBid = $nb->add([
            'name'=>$name, 'entities_id'=>($src->fields['entities_id'] ?? 0), 'is_recursive'=>($src->fields['is_recursive'] ?? 0),
            'comment'=>($src->fields['comment'] ?? ''), 'color'=>($src->fields['color'] ?? '#0079bf'),
            'is_archived'=>0, 'is_starred'=>0, 'generate_term'=>($src->fields['generate_term'] ?? 0),
            'visibility'=>($src->fields['visibility'] ?? 'private'), 'users_id'=>$me,
        ]);
        if (!$newBid) jexit(['success'=>false,'msg'=>'Falha ao criar quadro']);
        // imagem de fundo: copia o arquivo
        if (!empty($src->fields['background'])) {
            $srcPath = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $src->fields['background'];
            if (is_file($srcPath)) {
                $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) $ext = 'jpg';
                $dir = GLPI_PLUGIN_DOC_DIR . '/kanpro/boards/' . $newBid . '/';
                @mkdir($dir, 0755, true);
                $rel = 'boards/' . $newBid . '/bg_copy_' . time() . '.' . $ext;
                if (@copy($srcPath, GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $rel)) {
                    $DB->update('glpi_plugin_kanpro_boards', ['background'=>$rel], ['id'=>$newBid]);
                }
            }
        }
        // membros
        $miter = $DB->request(['FROM'=>'glpi_plugin_kanpro_boards_members','WHERE'=>['plugin_kanpro_boards_id'=>$bid]]);
        foreach ($miter as $m) {
            $DB->insert('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$newBid,'users_id'=>$m['users_id'],'role'=>$m['role'],'date_creation'=>date('Y-m-d H:i:s')]);
        }
        // garante o criador como admin
        if (!countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$newBid,'users_id'=>$me])) {
            $DB->insert('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$newBid,'users_id'=>$me,'role'=>'admin','date_creation'=>date('Y-m-d H:i:s')]);
        }
        // listas (mapa id antigo -> novo)
        $listMap = [];
        $liter = $DB->request(['FROM'=>'glpi_plugin_kanpro_lists','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'rank ASC']);
        foreach ($liter as $l) {
            $nl = new PluginKanproList();
            $newLid = $nl->add(['plugin_kanpro_boards_id'=>$newBid,'name'=>$l['name'],'rank'=>$l['rank'],'is_archived'=>$l['is_archived'],'color'=>($l['color'] ?? null)]);
            if ($newLid) {
                $listMap[(int)$l['id']] = (int)$newLid;
                try {
                    if ($DB->tableExists('glpi_plugin_kanpro_lists_viewers') && function_exists('kanpro_list_viewer_ids')) {
                        foreach (kanpro_list_viewer_ids((int)$l['id']) as $uid) {
                            try { $DB->insert('glpi_plugin_kanpro_lists_viewers', ['plugin_kanpro_lists_id' => $newLid, 'users_id' => (int)$uid]); } catch (Throwable $e) {}
                        }
                    }
                } catch (Throwable $e) {}
            }
        }
        // etiquetas (mapa)
        $labelMap = [];
        $labiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_labels','WHERE'=>['plugin_kanpro_boards_id'=>$bid]]);
        foreach ($labiter as $l) {
            $nlab = new PluginKanproLabel();
            $newLab = $nlab->add(['plugin_kanpro_boards_id'=>$newBid,'name'=>$l['name'],'color'=>$l['color']]);
            if ($newLab) $labelMap[(int)$l['id']] = (int)$newLab;
        }
        // cartões
        $citer = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'id ASC']);
        foreach ($citer as $c) {
            $newLid = $listMap[(int)$c['plugin_kanpro_lists_id']] ?? 0;
            if (!$newLid) continue;
            $nc = new PluginKanproCard();
            $newCid = $nc->add([
                'plugin_kanpro_boards_id'=>$newBid, 'plugin_kanpro_lists_id'=>$newLid,
                'name'=>$c['name'], 'description'=>($c['description'] ?? ''), 'rank'=>$c['rank'],
                'is_archived'=>$c['is_archived'], 'is_completed'=>$c['is_completed'],
                'due_date'=>($c['due_date'] ?? null), 'start_date'=>($c['start_date'] ?? null),
                'cover_color'=>($c['cover_color'] ?? null), 'is_maintenance'=>0, 'tickets_id'=>0,
            ]);
            if (!$newCid) continue;
            // etiquetas do cartão
            $cliter = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards_labels','WHERE'=>['plugin_kanpro_cards_id'=>$c['id']]]);
            foreach ($cliter as $cl) {
                $nlid = $labelMap[(int)$cl['plugin_kanpro_labels_id']] ?? 0;
                if ($nlid) $DB->insert('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$newCid,'plugin_kanpro_labels_id'=>$nlid]);
            }
            // membros do cartão
            $cmiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards_members','WHERE'=>['plugin_kanpro_cards_id'=>$c['id']]]);
            foreach ($cmiter as $cm) {
                $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$newCid,'users_id'=>$cm['users_id']]);
            }
            // checklists + itens
            $chkiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_checklists','WHERE'=>['plugin_kanpro_cards_id'=>$c['id']],'ORDER'=>'rank ASC']);
            foreach ($chkiter as $chk) {
                $nch = new PluginKanproChecklist();
                $newCh = $nch->add(['plugin_kanpro_cards_id'=>$newCid,'name'=>$chk['name'],'rank'=>$chk['rank']]);
                if ($newCh) {
                    $ititer = $DB->request(['FROM'=>'glpi_plugin_kanpro_checklist_items','WHERE'=>['plugin_kanpro_checklists_id'=>$chk['id']],'ORDER'=>'rank ASC']);
                    foreach ($ititer as $it) {
                        $ni = new PluginKanproChecklistItem();
                        $ni->add(['plugin_kanpro_checklists_id'=>$newCh,'name'=>$it['name'],'is_checked'=>$it['is_checked'],
                            'users_id'=>($it['users_id'] ?? 0),'rank'=>$it['rank'],'due_date'=>($it['due_date'] ?? null)]);
                    }
                }
            }
            // anexos: copia arquivo + linha
            $atiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_attachments','WHERE'=>['plugin_kanpro_cards_id'=>$c['id']]]);
            foreach ($atiter as $at) {
                $newRel = null;
                if (!empty($at['filepath'])) {
                    $srcFile = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $at['filepath'];
                    if (is_file($srcFile)) {
                        $newRel = dirname($at['filepath']) . '/dup_' . uniqid() . '_' . basename($at['filepath']);
                        if (!@copy($srcFile, GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $newRel)) $newRel = null;
                    }
                }
                if ($newRel !== null || empty($at['filepath'])) {
                    $DB->insert('glpi_plugin_kanpro_attachments', ['plugin_kanpro_cards_id'=>$newCid,
                        'name'=>$at['name'],'filename'=>$at['filename'],'filepath'=>($newRel ?? $at['filepath']),
                        'filesize'=>($newRel !== null && isset($at['filesize'])) ? (int)$at['filesize'] : 0,
                        'mime'=>($at['mime'] ?? null),'users_id'=>$me,'date_creation'=>date('Y-m-d H:i:s')]);
                }
            }
            // máquinas de manutenção (sem diário de execução? mantém modelo/seq p/ reaproveitar estrutura)
            $mmiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$c['id']],'ORDER'=>'seq ASC']);
            foreach ($mmiter as $mm) {
                $DB->insert('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$newCid,
                    'seq'=>$mm['seq'],'model'=>$mm['model'],'label'=>$mm['label'],'diary'=>null,
                    'is_done'=>0,'is_ok'=>0,'status'=>'','is_inventoried'=>0,'is_urgent'=>0,
                    'users_id'=>$me,'date_creation'=>date('Y-m-d H:i:s'),'date_mod'=>date('Y-m-d H:i:s')]);
            }
        }
        PluginKanproBoard::logActivity((int)$newBid, null, null, 'board_duplicate', "Quadro duplicado a partir de #{$bid} '{$src->fields['name']}'");
        jexit(['success'=>true,'id'=>(int)$newBid]);

    case 'reorder_cards':
        needEdit();
        $list_id = (int)($_POST['lists_id'] ?? 0);
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (!is_array($order)) jexit(['success'=>false]);
        PluginKanproCard::reorderInList($list_id, $order);
        jexit(['success'=>true]);

    case 'copy_card':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        kanpro_need_chamado_released($cid);
        $target_list = isset($_POST['target_lists_id']) ? (int)$_POST['target_lists_id'] : null;
        $new_id = PluginKanproCard::duplicate($cid, $target_list);
        jexit(['success'=>true,'id'=>$new_id]);

    case 'archive_card':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        kanpro_need_card_editable($cid);
        $c = new PluginKanproCard();
        $c->getFromDB($cid);
        $new = $c->fields['is_archived'] ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_cards', ['is_archived'=>$new], ['id'=>$cid]);
        jexit(['success'=>true,'is_archived'=>$new]);

    case 'delete_card':
        // Apagar card: SÓ admin do quadro (criador/admin). Vale sem DELETE global —
        // quem é admin no quadro pode limpar; membro/comum não apaga nada.
        needEdit();
        kanpro_ensure_board_extras();
        $cid = (int)($_POST['cards_id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $cDelBid = function_exists('kanpro_board_id_for_card') ? (int)kanpro_board_id_for_card($cid) : 0;
        if ($cDelBid > 0) kanpro_require_board_edit($cDelBid);
        if ($cDelBid > 0 && function_exists('kanpro_can_manage_members') && !kanpro_can_manage_members($cDelBid)) {
            jexit(['success'=>false,'msg'=>'Somente admin do quadro pode apagar cards.','need_admin'=>true]);
        }
        // cartão travado (Pendente) só sai com o criador/admin do quadro
        kanpro_need_card_editable($cid, true);
        $c = new PluginKanproCard();
        if (!$c->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        // snapshot p/ lixeira antes do purge (anexos físicos não são restaurados)
        $full = PluginKanproCard::getFullData($cid);
        $ll = new PluginKanproList();
        $lname = $ll->getFromDB((int)$c->fields['plugin_kanpro_lists_id']) ? $ll->fields['name'] : '';
        $DB->insert('glpi_plugin_kanpro_trash', [
            'plugin_kanpro_boards_id'=>(int)$c->fields['plugin_kanpro_boards_id'],
            'plugin_kanpro_lists_id'=>(int)$c->fields['plugin_kanpro_lists_id'],
            'list_name'=>$lname, 'card_name'=>$c->fields['name'],
            'snapshot'=>json_encode($full, JSON_UNESCAPED_UNICODE),
            'users_id'=>kanpro_acting_user_id(), 'date_creation'=>date('Y-m-d H:i:s'),
        ]);
        $c->delete(['id'=>$cid], true);
        jexit(['success'=>true]);

    case 'delete_liberado_pendencia':
        // Auto-exclusão 30s após Chamado criado: só pendência liberada + só admin do quadro (vale sem DELETE global).
        needEdit();
        kanpro_ensure_board_extras();
        $pid = (int)($_POST['pendencia_cards_id'] ?? $_POST['cards_id'] ?? $_POST['id'] ?? 0);
        if (!$pid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $pc = new PluginKanproCard();
        if (!$pc->getFromDB($pid)) jexit(['success'=>true,'already_deleted'=>true]);
        if ((int)($pc->fields['chamado_source_id'] ?? 0) <= 0) jexit(['success'=>false,'msg'=>'Só Pendência Chamado se auto-exclui']);
        if (($pc->fields['chamado_status'] ?? '') !== 'liberado') jexit(['success'=>false,'msg'=>'Ainda não liberado (sem Chamado criado)']);
        $bidD = (int)$pc->fields['plugin_kanpro_boards_id'];
        // admin do quadro (criador/admin; UPDATE só em legado aberto) — mesma regra central
        if (!kanpro_can_manage_members($bidD)) jexit(['success'=>false,'msg'=>'Somente admin do quadro']);
        $full = PluginKanproCard::getFullData($pid);
        $ll = new PluginKanproList();
        $lname = $ll->getFromDB((int)$pc->fields['plugin_kanpro_lists_id']) ? $ll->fields['name'] : '';
        try {
            $DB->insert('glpi_plugin_kanpro_trash', [
                'plugin_kanpro_boards_id'=>(int)$pc->fields['plugin_kanpro_boards_id'],
                'plugin_kanpro_lists_id'=>(int)$pc->fields['plugin_kanpro_lists_id'],
                'list_name'=>$lname, 'card_name'=>$pc->fields['name'],
                'snapshot'=>json_encode($full, JSON_UNESCAPED_UNICODE),
                'users_id'=>kanpro_acting_user_id(), 'date_creation'=>date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {}
        PluginKanproBoard::logActivity($bidD, $pid, (int)$pc->fields['plugin_kanpro_lists_id'], 'chamado_autodelete', "Pendência #{$pid} auto-excluída 30s após Chamado criado");
        $pc->delete(['id'=>$pid], true);
        jexit(['success'=>true,'deleted'=>true]);

    case 'chamado_prune_open':
        // Auto-exclusão 30s após "Chamado aberto": clone liberado em Abrir chamado.
        // Mesmo nível de quem abriu (edit no quadro) — snapshot vai p/ lixeira.
        needEdit();
        $pid = (int)($_POST['cards_id'] ?? 0);
        if (!$pid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $pc = new PluginKanproCard();
        if (!$pc->getFromDB($pid)) jexit(['success'=>true,'already_deleted'=>true]);
        if (kanpro_list_category((int)$pc->fields['plugin_kanpro_lists_id']) !== 'abrir_chamado') {
            jexit(['success'=>false,'msg'=>'Só Abrir chamado se auto-exclui']);
        }
        if ((int)($pc->fields['chamado_source_id'] ?? 0) <= 0) jexit(['success'=>false,'msg'=>'Só clone do fluxo se auto-exclui']);
        if (($pc->fields['chamado_status'] ?? '') !== 'liberado') jexit(['success'=>false,'msg'=>'Ainda não liberado (sem Chamado aberto)']);
        $bidP = (int)$pc->fields['plugin_kanpro_boards_id'];
        kanpro_require_board_edit($bidP);
        $full = PluginKanproCard::getFullData($pid);
        $ll = new PluginKanproList();
        $lname = $ll->getFromDB((int)$pc->fields['plugin_kanpro_lists_id']) ? $ll->fields['name'] : '';
        try {
            $DB->insert('glpi_plugin_kanpro_trash', [
                'plugin_kanpro_boards_id'=>$bidP,
                'plugin_kanpro_lists_id'=>(int)$pc->fields['plugin_kanpro_lists_id'],
                'list_name'=>$lname, 'card_name'=>$pc->fields['name'],
                'snapshot'=>json_encode($full, JSON_UNESCAPED_UNICODE),
                'users_id'=>kanpro_acting_user_id(), 'date_creation'=>date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {}
        PluginKanproBoard::logActivity($bidP, $pid, (int)$pc->fields['plugin_kanpro_lists_id'], 'chamado_autodelete', "Abrir chamado #{$pid} auto-excluído 30s após Chamado aberto");
        $pc->delete(['id'=>$pid], true);
        jexit(['success'=>true,'deleted'=>true]);

    case 'send_liberado_zap':
        // Disparo 5s após Chamado criado (agendado no kanban). Anti-duplicado por técnico. Nunca quebra.
        needEdit();
        kanpro_ensure_maintenance_tables();
        $pid = (int)($_POST['pendencia_cards_id'] ?? $_POST['cards_id'] ?? $_POST['id'] ?? 0);
        if (!$pid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        try {
            if (!class_exists('PluginKanproMaintenanceZap')) jexit(['success'=>false,'msg'=>'Zap indisponível']);
            $r = PluginKanproMaintenanceZap::sendLiberado($pid);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Erro: '.$e->getMessage()]); }
        if (!empty($r['ok'])) jexit(['success'=>true,'sent'=>($r['sent'] ?? 1)]);
        if (($r['error'] ?? '') === 'duplicate') jexit(['success'=>true,'already'=>true]);
        jexit(['success'=>false,'msg'=>($r['error'] ?? 'Falha ao enviar')]);

    case 'get_card_term':
        // Termo do assetmgrstatus gerado para o card (transferência [KanPro #id]).
        // Devolve pdf_url (termo assinado/pronto) + assinatura_url + se já está assinado.
        try {
            $cid = (int)($_POST['cards_id'] ?? $_POST['id'] ?? 0);
            if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
            $card = new PluginKanproCard();
            if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
            $tr = null;
            if ($DB->tableExists('glpi_plugin_assetmgrstatus_transfers')) {
                $like = "%[KanPro #{$cid}]%";
                $trIter = $DB->request(['FROM'=>'glpi_plugin_assetmgrstatus_transfers','WHERE'=>['reason'=>['LIKE',$like]],'ORDER'=>'id DESC','LIMIT'=>1]);
                if ($trIter->count() > 0) $tr = $trIter->current();
            }
            if (!$tr) jexit(['success'=>false,'msg'=>'Nenhum termo gerado para este card ainda.','need_term'=>true]);
            $tid = (int)$tr['id'];
            try { $base = Plugin::getWebDir('assetmgrstatus'); } catch (Throwable $e) { $base = ''; }
            if (!$base) $base = '/plugins/assetmgrstatus';
            $signed = !empty($tr['assinatura_image']) && !empty($tr['assinatura_tecnico_image']);
            jexit(['success'=>true,'transfer_id'=>$tid,'signed'=>$signed,
                'pdf_url'=>$base.'/front/transfer_pdf.php?id='.$tid.'&stage=pronto',
                'assinatura_url'=>$base.'/front/assinatura.php?f=pendente&highlight='.$tid]);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Erro: '.$e->getMessage()]); }

    case 'zap_lembrete_diagnose':
        // Diagnóstico do lembrete 8h/10h/13h sem enviar (listas, contagens, fone mascarado, evo, cron).
        try {
            if (!Session::haveRight('plugin_kanpro', UPDATE)) jexit(['success'=>false,'msg'=>'Sem permissão (precisa UPDATE no KanPro)']);
            if (!class_exists('PluginKanproMaintenanceZap')) jexit(['success'=>false,'msg'=>'Zap indisponível']);
            $d = PluginKanproMaintenanceZap::diagnoseLembrete();
            jexit(['success'=>true,'diagnose'=>$d]);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Erro: '.$e->getMessage()]); }

    case 'zap_lembrete_calendar':
        // Calendário do lembrete p/ o modal (board.php): dias úteis + já enviados. Leitura (READ basta).
        try {
            if (!class_exists('PluginKanproMaintenanceZap')) jexit(['success'=>false,'msg'=>'Zap indisponível']);
            $month = trim((string)($_REQUEST['month'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
            $cal = PluginKanproMaintenanceZap::lembreteCalendar($month);
            jexit(['success'=>true,'calendar'=>$cal]);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Erro: '.$e->getMessage()]); }

    case 'zap_lembrete_send':
        // Envio manual do lembrete (ignora a trava de horário, útil p/ testar às 14h o slot das 13h).
        // params: slot=8|10|13 (padrão: pelo horário), force=1 reenvia mesmo se já enviado hoje.
        try {
            if (!Session::haveRight('plugin_kanpro', UPDATE)) jexit(['success'=>false,'msg'=>'Sem permissão (precisa UPDATE no KanPro)']);
            if (!class_exists('PluginKanproMaintenanceZap')) jexit(['success'=>false,'msg'=>'Zap indisponível']);
            $slot = (int)($_POST['slot'] ?? $_GET['slot'] ?? 0);
            if (!in_array($slot, [8, 9, 10, 13], true)) $slot = ((int)date('H') < 9) ? 8 : (((int)date('H') < 12) ? 10 : 13);
            $force = !empty($_POST['force']) || !empty($_GET['force']);
            $r = PluginKanproMaintenanceZap::sendLembrete($slot, $force ? ['forceResend' => true] : []);
            if (!empty($r['ok'])) jexit(['success'=>true,'slot'=>$slot,'total'=>($r['total'] ?? 0),'phone'=>($r['phone'] ?? ''),'queued'=>($r['queued'] ?? 0)]);
            jexit(['success'=>false,'slot'=>$slot,'msg'=>($r['error'] ?? 'Falha ao enviar')]);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Erro: '.$e->getMessage()]); }

    case 'zap_lembrete_day_set':
        // Exceção do calendário: tirar do envio / forçar / voltar ao automático (1 ou N dias).
        // params POST: date=YYYY-MM-DD | dates=[...]/"a,b" , mode=skip|force|auto, reason=texto.
        try {
            if (!Session::haveRight('plugin_kanpro', UPDATE)) jexit(['success'=>false,'msg'=>'Sem permissão (precisa UPDATE no KanPro)']);
            if (!class_exists('PluginKanproMaintenanceZap')) jexit(['success'=>false,'msg'=>'Zap indisponível']);
            $mode = trim(strtolower((string)($_POST['mode'] ?? $_GET['mode'] ?? '')));
            $reason = trim((string)($_POST['reason'] ?? ''));
            $dates = [];
            if (isset($_POST['dates']) || isset($_GET['dates'])) {
                $raw = $_POST['dates'] ?? $_GET['dates'];
                if (is_array($raw)) $dates = $raw;
                else {
                    $dec = json_decode((string)$raw, true);
                    $dates = is_array($dec) ? $dec : preg_split('/[\s,;]+/', (string)$raw);
                }
            }
            if (isset($_POST['date']) || isset($_GET['date'])) $dates[] = $_POST['date'] ?? $_GET['date'];
            $dates = array_values(array_unique(array_filter(array_map(function($d){ $d = substr(trim((string)$d), 0, 10); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : ''; }, $dates))));
            if (empty($dates)) jexit(['success'=>false,'msg'=>'Nenhuma data válida (use YYYY-MM-DD)']);
            if (count($dates) > 62) jexit(['success'=>false,'msg'=>'Máximo 62 dias por vez']);
            $r = PluginKanproMaintenanceZap::setLembreteDayOverrides($dates, $mode, $reason);
            if (!empty($r['ok'])) jexit(['success'=>true,'applied'=>($r['applied'] ?? 0),'mode'=>$mode,'errors'=>($r['errors'] ?? [])]);
            jexit(['success'=>false,'msg'=>implode('; ', ($r['errors'] ?? ['Nada aplicado']))]);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Erro: '.$e->getMessage()]); }

    case 'get_history':
        try {
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $bchk = new PluginKanproBoard();
        if (!$bchk->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        // Histórico: só admin do quadro (criador ou papel admin)
        $__me = (int)Session::getLoginUserID();
        $__creator = (int)($bchk->fields['users_id'] ?? 0);
        if ($__me !== $__creator && kanpro_my_board_role($bid) !== 'admin') {
            jexit(['success'=>false,'msg'=>'Histórico restrito a administradores do quadro']);
        }
        // pessoas com acesso (criador + membros)
        $people = []; $seen = [];
        $addP = function ($uid, $extra = '') use (&$people, &$seen) {
            $uid = (int)$uid;
            if ($uid <= 0 || isset($seen[$uid])) return;
            $seen[$uid] = true;
            $u = new User();
            $name = 'Usuário #' . $uid;
            if ($u->getFromDB($uid)) {
                $full = trim(($u->fields['firstname'] ?? '') . ' ' . ($u->fields['realname'] ?? ''));
                $name = $full !== '' ? $full : $u->getFriendlyName();
            }
            $people[] = ['id'=>$uid, 'name'=>$name, 'extra'=>$extra];
        };
        $addP($__creator, 'criador');
        $pmiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_boards_members','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'date_creation ASC']);
        foreach ($pmiter as $pm) $addP($pm['users_id'], $pm['role'] ?? '');
        // filtros (faction: "action" é o parâmetro de rota — não usar)
        $where = ['a.plugin_kanpro_boards_id' => $bid];
        $fuser = (int)($_REQUEST['users_id'] ?? 0);
        if ($fuser > 0) $where['a.users_id'] = $fuser;
        $faction = trim($_REQUEST['faction'] ?? '');
        if ($faction !== '') $where['a.action'] = $faction;
        $fcard = (int)($_REQUEST['card_id'] ?? 0);
        if ($fcard > 0) $where['a.plugin_kanpro_cards_id'] = $fcard;
        $fmach = (int)($_REQUEST['machine'] ?? $_REQUEST['machine_seq'] ?? 0);
        $fmodel = trim((string)($_REQUEST['model'] ?? ''));
        if (function_exists('mb_substr') ? mb_strlen($fmodel, 'UTF-8') > 80 : strlen($fmodel) > 80) {
            $fmodel = function_exists('mb_substr') ? mb_substr($fmodel, 0, 80, 'UTF-8') : substr($fmodel, 0, 80);
        }
        $fmodelNorm = ($fmodel !== '' && function_exists('kanpro_norm_text')) ? kanpro_norm_text($fmodel) : mb_strtolower($fmodel, 'UTF-8');
        $kanpro_norm_date = function ($v) {
            $v = trim((string)($v ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $v, $m)) {
                $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
                if (strlen($m[3]) === 2) $y += ($y <= 30 ? 2000 : 1900);
                if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
            // só dígitos (ex: 24092026 vindo de máscara sem barra)
            $dig = preg_replace('/\D+/', '', $v);
            if (strlen($dig) === 8 && preg_match('/^(\d{2})(\d{2})(\d{4})$/', $dig, $m)) {
                if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return $m[3] . '-' . $m[2] . '-' . $m[1];
            }
            return '';
        };
        $ffrom = $kanpro_norm_date($_REQUEST['date_from'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ffrom)) $ffrom = '';
        $fto = $kanpro_norm_date($_REQUEST['date_to'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fto)) $fto = '';
        $rows = [];
        $aiter = $DB->request([
            'SELECT' => ['a.*', 'u.name AS user_name', 'u.realname', 'u.firstname', 'c.name AS card_name'],
            'FROM'   => 'glpi_plugin_kanpro_activities AS a',
            'LEFT JOIN' => [
                'glpi_users AS u' => ['ON' => ['u' => 'id', 'a' => 'users_id']],
                'glpi_plugin_kanpro_cards AS c' => ['ON' => ['c' => 'id', 'a' => 'plugin_kanpro_cards_id']],
            ],
            'WHERE'  => $where,
            'ORDER'  => 'a.date_creation DESC',
            'LIMIT'  => 500,
        ]);
        foreach ($aiter as $a) {
            $d = (string)($a['date_creation'] ?? '');
            if ($ffrom !== '' && substr($d, 0, 10) < $ffrom) continue;
            if ($fto !== '' && substr($d, 0, 10) > $fto) continue;
            if ($fmach > 0) {
                $det = (string)($a['details'] ?? '');
                if (!preg_match('/m[aá]quina\s*#\s*' . $fmach . '\b/iu', $det)) continue;
            }
            if ($fmodelNorm !== '') {
                $detN = function_exists('kanpro_norm_text') ? kanpro_norm_text((string)($a['details'] ?? '')) : mb_strtolower((string)($a['details'] ?? ''), 'UTF-8');
                if (strpos($detN, $fmodelNorm) === false) continue;
            }
            $uname = trim(($a['firstname'] ?? '') . ' ' . ($a['realname'] ?? ''));
            if ($uname === '') $uname = $a['user_name'] ?? 'Sistema';
            $rows[] = ['id'=>(int)$a['id'], 'date'=>$d, 'user'=>$uname,
                'user_id'=>(int)($a['users_id'] ?? 0), 'action'=>($a['action'] ?? ''),
                'details'=>preg_replace('/^\[from:\d+\]\s*/', '', (string)($a['details'] ?? '')),
                'card_id'=>(int)($a['plugin_kanpro_cards_id'] ?? 0), 'card_name'=>($a['card_name'] ?? '')];
        }
        jexit(['success'=>true, 'board_id'=>$bid, 'board_name'=>($bchk->fields['name'] ?? ''),
            'people'=>$people, 'rows'=>$rows, 'filters'=>['users_id'=>$fuser,'faction'=>$faction,'card_id'=>$fcard,'machine'=>$fmach,'model'=>$fmodel,'date_from'=>$ffrom,'date_to'=>$fto]]);
        } catch (Throwable $e) {
            error_log('[KanPro] ' . 'KanPro get_history: ' . $e->getMessage());
            jexit(['success'=>false,'msg'=>'Falha ao carregar histórico']);
        }


    case 'get_trash':
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        kanpro_ensure_board_extras();
        $archived = [];
        $aiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_boards_id'=>$bid,'is_archived'=>1],'ORDER'=>'date_mod DESC','LIMIT'=>200]);
        $listNames = [];
        foreach ($aiter as $a) {
            $lid = (int)$a['plugin_kanpro_lists_id'];
            if (!isset($listNames[$lid])) {
                $ll = new PluginKanproList();
                $listNames[$lid] = $ll->getFromDB($lid) ? $ll->fields['name'] : ('#' . $lid);
            }
            $archived[] = ['id'=>(int)$a['id'],'name'=>$a['name'],'list_name'=>$listNames[$lid],'date_mod'=>$a['date_mod']];
        }
        $deleted = [];
        $titer = $DB->request(['FROM'=>'glpi_plugin_kanpro_trash','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'date_creation DESC','LIMIT'=>200]);
        foreach ($titer as $t) {
            $deleted[] = ['id'=>(int)$t['id'],'card_name'=>$t['card_name'],'list_name'=>$t['list_name'],'date'=>$t['date_creation']];
        }
        jexit(['success'=>true,'archived'=>$archived,'deleted'=>$deleted]);

    case 'restore_archived':
        $cid = (int)($_POST['cards_id'] ?? 0);
        $c = new PluginKanproCard();
        if (!$c->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        kanpro_need_manage_members((int)$c->fields['plugin_kanpro_boards_id']);
        $DB->update('glpi_plugin_kanpro_cards', ['is_archived'=>0], ['id'=>$cid]);
        PluginKanproBoard::logActivity((int)$c->fields['plugin_kanpro_boards_id'], $cid, (int)$c->fields['plugin_kanpro_lists_id'], 'card_restore', "Cartão restaurado da lixeira");
        jexit(['success'=>true]);

    case 'purge_trash':
        // Apagar pra sempre: SÓ admin do quadro (mesma regra do Excluir).
        needEdit();
        $tid = (int)($_POST['trash_id'] ?? 0);
        if (!$tid) jexit(['success'=>false,'msg'=>'Item inválido']);
        $tRow = $DB->request(['FROM'=>'glpi_plugin_kanpro_trash','WHERE'=>['id'=>$tid]])->current();
        if (!$tRow) jexit(['success'=>false,'msg'=>'Item não encontrado']);
        $tBid = (int)($tRow['plugin_kanpro_boards_id'] ?? 0);
        if ($tBid > 0) kanpro_require_board_edit($tBid);
        if ($tBid > 0 && function_exists('kanpro_can_manage_members') && !kanpro_can_manage_members($tBid)) {
            jexit(['success'=>false,'msg'=>'Somente admin do quadro pode apagar cards.','need_admin'=>true]);
        }
        $DB->delete('glpi_plugin_kanpro_trash', ['id'=>$tid]);
        jexit(['success'=>true]);

    case 'restore_trash':
        kanpro_ensure_board_extras();
        $tid = (int)($_POST['trash_id'] ?? 0);
        $t = $DB->request(['FROM'=>'glpi_plugin_kanpro_trash','WHERE'=>['id'=>$tid]])->current();
        if (!$t) jexit(['success'=>false,'msg'=>'Item não encontrado']);
        kanpro_need_manage_members((int)$t['plugin_kanpro_boards_id']);
        $snap = json_decode($t['snapshot'] ?? '', true);
        if (!is_array($snap) || empty($snap['name'])) jexit(['success'=>false,'msg'=>'Snapshot inválido']);
        $bid = (int)$t['plugin_kanpro_boards_id'];
        $lid = (int)$t['plugin_kanpro_lists_id'];
        $ll = new PluginKanproList();
        if (!$ll->getFromDB($lid) || (int)$ll->fields['plugin_kanpro_boards_id'] !== $bid) {
            $first = $DB->request(['FROM'=>'glpi_plugin_kanpro_lists','WHERE'=>['plugin_kanpro_boards_id'=>$bid,'is_archived'=>0],'ORDER'=>'rank ASC','LIMIT'=>1])->current();
            if (!$first) jexit(['success'=>false,'msg'=>'Quadro sem listas ativas']);
            $lid = (int)$first['id'];
        }
        $nc = new PluginKanproCard();
        $newId = $nc->add([
            'plugin_kanpro_boards_id'=>$bid, 'plugin_kanpro_lists_id'=>$lid,
            'name'=>($snap['name'] ?? $t['card_name']), 'description'=>($snap['description'] ?? ''),
            'due_date'=>($snap['due_date'] ?? null), 'start_date'=>($snap['start_date'] ?? null),
            'cover_color'=>($snap['cover_color'] ?? null), 'is_completed'=>!empty($snap['is_completed']) ? 1 : 0,
            'is_maintenance'=>0, 'tickets_id'=>0,
        ]);
        if (!$newId) jexit(['success'=>false,'msg'=>'Falha ao recriar cartão']);
        // etiquetas (reaproveita por nome+cor ou cria)
        foreach (($snap['labels'] ?? []) as $sl) {
            $lname = trim($sl['name'] ?? ''); $lcolor = $sl['color'] ?? '#61bd4f';
            $ex = $DB->request(['FROM'=>'glpi_plugin_kanpro_labels','WHERE'=>['plugin_kanpro_boards_id'=>$bid,'name'=>$lname,'color'=>$lcolor],'LIMIT'=>1])->current();
            $labelId = $ex ? (int)$ex['id'] : (new PluginKanproLabel())->add(['plugin_kanpro_boards_id'=>$bid,'name'=>$lname,'color'=>$lcolor]);
            if ($labelId) $DB->insert('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$newId,'plugin_kanpro_labels_id'=>$labelId]);
        }
        // membros
        foreach (($snap['members'] ?? []) as $sm) {
            $muid = (int)($sm['id'] ?? $sm['users_id'] ?? 0);
            if ($muid) $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$newId,'users_id'=>$muid]);
        }
        // checklists + itens
        foreach (($snap['checklists'] ?? []) as $scl) {
            $ncl = new PluginKanproChecklist();
            $clid = $ncl->add(['plugin_kanpro_cards_id'=>$newId,'name'=>($scl['name'] ?? 'Checklist'),'rank'=>($scl['rank'] ?? 0)]);
            if ($clid) {
                foreach (($scl['items'] ?? []) as $it) {
                    $ni = new PluginKanproChecklistItem();
                    $ni->add(['plugin_kanpro_checklists_id'=>$clid,'name'=>($it['name'] ?? ''),'is_checked'=>!empty($it['is_checked']) ? 1 : 0,
                        'users_id'=>(int)($it['users_id'] ?? 0),'rank'=>($it['rank'] ?? 0),'due_date'=>($it['due_date'] ?? null)]);
                }
            }
        }
        // comentários
        foreach (($snap['comments'] ?? []) as $sc) {
            if (trim($sc['content'] ?? '') === '') continue;
            $DB->insert('glpi_plugin_kanpro_comments', ['plugin_kanpro_cards_id'=>$newId,'users_id'=>(int)($sc['users_id'] ?? 0),
                'content'=>$sc['content'],'date_creation'=>($sc['date_creation'] ?? date('Y-m-d H:i:s')),'date_mod'=>date('Y-m-d H:i:s')]);
        }
        $DB->delete('glpi_plugin_kanpro_trash', ['id'=>$tid]);
        PluginKanproBoard::logActivity($bid, (int)$newId, $lid, 'card_restore', "Cartão '{$t['card_name']}' restaurado da lixeira");
        jexit(['success'=>true,'id'=>(int)$newId]);

    case 'toggle_card_member':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        kanpro_need_card_editable($cid);
        $uid = (int)($_POST['users_id'] ?? 0);
        $exists = countElementsInTable('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$cid,'users_id'=>$uid]);
        if ($exists) {
            $DB->delete('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$cid,'users_id'=>$uid]);
            jexit(['success'=>true,'added'=>false]);
        } else {
            $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$cid,'users_id'=>$uid]);
            jexit(['success'=>true,'added'=>true]);
        }

    // --- CHECKLIST ---
    case 'add_checklist':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        kanpro_need_card_editable($cid);
        $name = trim($_POST['name'] ?? 'Checklist');
        $cl = new PluginKanproChecklist();
        $id = $cl->add(['plugin_kanpro_cards_id'=>$cid,'name'=>$name]);
        kanpro_touch_card($cid);
        jexit(['success'=>true,'id'=>$id]);

    case 'rename_checklist':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        kanpro_need_card_editable(kanpro_card_id_of_checklist($id));
        $name = trim($_POST['name'] ?? '');
        $DB->update('glpi_plugin_kanpro_checklists', ['name'=>$name], ['id'=>$id]);
        kanpro_touch_card(kanpro_card_id_of_checklist($id));
        jexit(['success'=>true]);

    case 'delete_checklist':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $delCid = kanpro_card_id_of_checklist($id);
        kanpro_need_card_editable($delCid);
        $DB->delete('glpi_plugin_kanpro_checklist_items', ['plugin_kanpro_checklists_id'=>$id]);
        $DB->delete('glpi_plugin_kanpro_checklists', ['id'=>$id]);
        kanpro_touch_card($delCid);
        jexit(['success'=>true]);

    case 'add_checkitem':
        needEdit();
        $clid = (int)($_POST['checklists_id'] ?? 0);
        kanpro_need_card_editable(kanpro_card_id_of_checklist($clid));
        $name = trim($_POST['name'] ?? '');
        if (!$name) jexit(['success'=>false]);
        $it = new PluginKanproChecklistItem();
        $id = $it->add(['plugin_kanpro_checklists_id'=>$clid,'name'=>$name]);
        $addCid = kanpro_card_id_of_checklist($clid);
        kanpro_touch_member($addCid);
        kanpro_touch_card($addCid);
        jexit(['success'=>true,'id'=>$id]);

    case 'toggle_checkitem':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_checklist_items','WHERE'=>['id'=>$id]])->current();
        if (!$row) jexit(['success'=>false]);
        $togCid = kanpro_card_id_of_checklist((int)$row['plugin_kanpro_checklists_id']);
        kanpro_need_card_editable($togCid);
        $new = $row['is_checked'] ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_checklist_items', ['is_checked'=>$new], ['id'=>$id]);
        kanpro_touch_member($togCid);
        kanpro_touch_card($togCid);
        jexit(['success'=>true,'is_checked'=>$new]);

    case 'rename_checkitem':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $rnRow = $DB->request(['SELECT' => ['plugin_kanpro_checklists_id'], 'FROM' => 'glpi_plugin_kanpro_checklist_items', 'WHERE' => ['id' => $id]])->current();
        if ($rnRow) kanpro_need_card_editable(kanpro_card_id_of_checklist((int)$rnRow['plugin_kanpro_checklists_id']));
        $name = trim($_POST['name'] ?? '');
        $DB->update('glpi_plugin_kanpro_checklist_items', ['name'=>$name], ['id'=>$id]);
        if ($rnRow) kanpro_touch_card(kanpro_card_id_of_checklist((int)$rnRow['plugin_kanpro_checklists_id']));
        jexit(['success'=>true]);

    case 'delete_checkitem':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $delRow = $DB->request(['SELECT' => ['plugin_kanpro_checklists_id'], 'FROM' => 'glpi_plugin_kanpro_checklist_items', 'WHERE' => ['id' => $id]])->current();
        if ($delRow) kanpro_need_card_editable(kanpro_card_id_of_checklist((int)$delRow['plugin_kanpro_checklists_id']));
        $DB->delete('glpi_plugin_kanpro_checklist_items', ['id'=>$id]);
        if ($delRow) kanpro_touch_card(kanpro_card_id_of_checklist((int)$delRow['plugin_kanpro_checklists_id']));
        jexit(['success'=>true]);

    case 'reorder_checkitems':
        needEdit();
        $clid = (int)($_POST['checklists_id'] ?? 0);
        kanpro_need_card_editable(kanpro_card_id_of_checklist($clid));
        $order = json_decode($_POST['order'] ?? '[]', true);
        $rank=1024;
        foreach ($order as $iid) {
            $DB->update('glpi_plugin_kanpro_checklist_items', ['rank'=>$rank], ['id'=>$iid,'plugin_kanpro_checklists_id'=>$clid]);
            $rank+=1024;
        }
        kanpro_touch_card(kanpro_card_id_of_checklist($clid));
        jexit(['success'=>true]);

    // --- COMMENTS ---
    case 'add_comment':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        kanpro_require_board_edit(kanpro_board_id_for_card($cid));
        kanpro_need_chamado_released($cid);
        $content = function_exists('kanpro_clean_rich') ? kanpro_clean_rich($_POST['content'] ?? '') : trim(strip_tags($_POST['content'] ?? ''));
        $content = trim($content);
        if (!$content) jexit(['success'=>false]);
        $co = new PluginKanproComment();
        $id = $co->add(['plugin_kanpro_cards_id'=>$cid,'content'=>$content]);
        kanpro_touch_member($cid);
        kanpro_touch_card($cid);
        jexit(['success'=>true,'id'=>$id]);

    case 'update_comment':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $content = function_exists('kanpro_clean_rich') ? kanpro_clean_rich($_POST['content'] ?? '') : trim(strip_tags($_POST['content'] ?? ''));
        $content = trim($content);
        $upRow = $DB->request(['SELECT' => ['plugin_kanpro_cards_id'], 'FROM' => 'glpi_plugin_kanpro_comments', 'WHERE' => ['id' => $id]])->current();
        if ($upRow) kanpro_need_chamado_released((int)$upRow['plugin_kanpro_cards_id']);
        $DB->update('glpi_plugin_kanpro_comments', ['content'=>$content,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$id,'users_id'=>[Session::getLoginUserID(), kanpro_acting_user_id()]]);
        if ($upRow) kanpro_touch_card((int)$upRow['plugin_kanpro_cards_id']);
        jexit(['success'=>true]);

    case 'delete_comment':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $delCrow = $DB->request(['SELECT' => ['plugin_kanpro_cards_id', 'users_id'], 'FROM' => 'glpi_plugin_kanpro_comments', 'WHERE' => ['id' => $id]])->current();
        if (!$delCrow) jexit(['success'=>false,'msg'=>'Comentário não encontrado']);
        kanpro_need_chamado_released((int)($delCrow['plugin_kanpro_cards_id'] ?? 0));
        $__delBid = kanpro_board_id_for_card((int)($delCrow['plugin_kanpro_cards_id'] ?? 0));
        kanpro_require_board_view($__delBid);
        // Só dono do comentário ou gestor do quadro (criador/admin) pode excluir — antes qualquer membro excluía qualquer um
        $__meIds = array_unique([(int)Session::getLoginUserID(), function_exists('kanpro_acting_user_id') ? (int)kanpro_acting_user_id() : 0]);
        $__isOwner = in_array((int)($delCrow['users_id'] ?? 0), $__meIds, true);
        if (!$__isOwner && !kanpro_can_manage_members($__delBid)) jexit(['success'=>false,'msg'=>'Somente o autor ou admin do quadro pode excluir.']);
        $DB->delete('glpi_plugin_kanpro_comments', ['id'=>$id]);
        if ($delCrow) kanpro_touch_card((int)$delCrow['plugin_kanpro_cards_id']);
        jexit(['success'=>true]);

    case 'toggle_comment_pin':
        needEdit();
        kanpro_ensure_board_extras();
        $id = (int)($_POST['id'] ?? 0);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_comments','WHERE'=>['id'=>$id]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Comentário não encontrado']);
        kanpro_need_chamado_released((int)($row['plugin_kanpro_cards_id'] ?? 0));
        $new = !empty($row['is_pinned']) ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_comments', ['is_pinned'=>$new], ['id'=>$id]);
        kanpro_touch_card((int)($row['plugin_kanpro_cards_id'] ?? 0));
        jexit(['success'=>true,'is_pinned'=>$new]);

    // --- ATTACHMENTS ---
    case 'upload_attachment':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        kanpro_need_chamado_released($cid);
        if (!isset($_FILES['file'])) jexit(['success'=>false,'msg'=>'Nenhum arquivo']);
        $id = PluginKanproAttachment::handleUpload($cid, $_FILES['file']);
        kanpro_touch_member($cid);
        kanpro_touch_card($cid);
        jexit(['success'=> (bool)$id,'id'=>$id]);

    case 'delete_attachment':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_attachments','WHERE'=>['id'=>$id]])->current();
        $attCid = (int)($row['plugin_kanpro_cards_id'] ?? 0);
        kanpro_need_chamado_released($attCid);
        if ($row && !empty($row['filepath'])) {
            $path = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $row['filepath'];
            if (file_exists($path)) @unlink($path);
        }
        $DB->delete('glpi_plugin_kanpro_attachments', ['id'=>$id]);
        if ($attCid) kanpro_touch_card($attCid);
        jexit(['success'=>true]);

    case 'set_cover':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        kanpro_need_card_editable($cid);
        $color = $_POST['cover_color'] ?? null;
        $att_id = $_POST['attachment_id'] ?? null;
        // se att_id vier, usa cor nula
        $DB->update('glpi_plugin_kanpro_cards', ['cover_color'=>$color ?: null,'cover_attachment_id'=>$att_id ?: null], ['id'=>$cid]);
        jexit(['success'=>true]);

    // --- DATES ---
    case 'set_dates':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        kanpro_need_card_editable($cid);
        $start = empty($_POST['start_date']) ? null : $_POST['start_date'];
        $due = empty($_POST['due_date']) ? null : $_POST['due_date'];
        $DB->update('glpi_plugin_kanpro_cards', ['start_date'=>$start,'due_date'=>$due], ['id'=>$cid]);
        jexit(['success'=>true]);

    case 'toggle_complete':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        kanpro_need_card_editable($cid);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['id'=>$cid]])->current();
        $new = $row['is_completed'] ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_cards', ['is_completed'=>$new], ['id'=>$cid]);
        PluginKanproBoard::logActivity((int)$row['plugin_kanpro_boards_id'], $cid, (int)$row['plugin_kanpro_lists_id'], $new ? 'card_complete' : 'card_reopen', $new ? 'Cartão concluído' : 'Cartão reaberto');
        jexit(['success'=>true,'is_completed'=>$new]);

    case 'toggle_notified':
        // Marca/desmarca que foi notificado sobre o chamado (botão Notificado no mini + modal).
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? $_POST['id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        kanpro_need_chamado_released($cid);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['id'=>$cid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $new = !empty($row['is_notified']) ? 0 : 1;
        $auid = function_exists('kanpro_acting_user_id') ? kanpro_acting_user_id() : (int)Session::getLoginUserID();
        $DB->update('glpi_plugin_kanpro_cards', [
            'is_notified' => $new,
            'notified_by' => $new ? $auid : 0,
            'notified_date' => $new ? date('Y-m-d H:i:s') : null,
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id'=>$cid]);
        kanpro_touch_member($cid, $auid);
        PluginKanproBoard::logActivity((int)$row['plugin_kanpro_boards_id'], $cid, (int)$row['plugin_kanpro_lists_id'], $new ? 'card_notified' : 'card_unnotified', $new ? 'Marcado como notificado sobre o chamado' : 'Desmarcado como notificado');
        jexit(['success'=>true,'is_notified'=>$new]);

    case 'retirada_notify_info':
        // Leitura p/ o modal Notificar (destinatários + estado). Precisa edição (revela telefones).
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? $_POST['id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        kanpro_need_chamado_released($cid);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['id'=>$cid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        kanpro_require_board_edit((int)($row['plugin_kanpro_boards_id'] ?? 0));
        if (kanpro_list_category((int)($row['plugin_kanpro_lists_id'] ?? 0)) !== 'retirada') jexit(['success'=>false,'msg'=>'Só cards da Retirada.']);
        if (!class_exists('PluginKanproMaintenanceZap')) jexit(['success'=>false,'msg'=>'Zap indisponível']);
        $t = PluginKanproMaintenanceZap::retiradaNotifyTargets($cid);
        if (empty($t['ok'])) jexit(['success'=>false,'msg'=>($t['error'] ?? 'Falha')]);
        jexit(['success'=>true,'info'=>$t]);

    case 'retirada_notify_send':
        // Enfileira 1 job por destino (contatos da entidade + fone da escola) e marca notificado.
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? $_POST['id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        kanpro_need_chamado_released($cid);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['id'=>$cid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        kanpro_require_board_edit((int)($row['plugin_kanpro_boards_id'] ?? 0));
        if (kanpro_list_category((int)($row['plugin_kanpro_lists_id'] ?? 0)) !== 'retirada') jexit(['success'=>false,'msg'=>'Só cards da Retirada.']);
        if (!class_exists('PluginKanproMaintenanceZap')) jexit(['success'=>false,'msg'=>'Zap indisponível']);
        $auid = function_exists('kanpro_acting_user_id') ? kanpro_acting_user_id() : (int)Session::getLoginUserID();
        $r = PluginKanproMaintenanceZap::sendRetiradaNotify($cid, $auid);
        if (empty($r['ok'])) jexit(['success'=>false,'msg'=>($r['error'] ?? 'Falha ao enfileirar')]);
        jexit(['success'=>true,'queued'=>($r['queued'] ?? 0),'phones'=>($r['phones'] ?? [])]);

    // --- BOARD ACTIVITY ---
    case 'get_board_activity':
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        kanpro_require_board_view($bid);
        $acts = PluginKanproActivity::getForBoard($bid, 50);
        jexit(['success'=>true,'data'=>$acts]);

    case 'get_board_report':
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $bchk = new PluginKanproBoard();
        if (!$bchk->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        // mesma trava de visibilidade do kanban: criador, membro ou quadro legado sem membros
        $__me = (int)Session::getLoginUserID();
        $__creator = (int)($bchk->fields['users_id'] ?? 0);
        if ($__me !== $__creator) {
            $__isM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>kanpro_viewer_ids()]) > 0;
            $__hasM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid]) > 0;
            if (!$__isM && $__hasM) jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
        }
        $lists = [];
        $liter = $DB->request(['FROM'=>'glpi_plugin_kanpro_lists','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'rank ASC']);
        foreach ($liter as $l) $lists[] = ['id'=>(int)$l['id'],'name'=>$l['name'],'is_archived'=>(int)$l['is_archived']];
        $cards = [];
        $citer = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'date_creation ASC']);
        foreach ($citer as $c) {
            $cards[] = ['id'=>(int)$c['id'],'name'=>$c['name'],'list_id'=>(int)$c['plugin_kanpro_lists_id'],
                'date_creation'=>$c['date_creation'],'date_mod'=>$c['date_mod'],
                'is_completed'=>(int)$c['is_completed'],'is_archived'=>(int)$c['is_archived']];
        }
        $moves = [];
        $miter = $DB->request([
            'SELECT' => ['a.plugin_kanpro_cards_id', 'a.plugin_kanpro_lists_id', 'a.action', 'a.details', 'a.date_creation', 'u.name AS user_name', 'u.realname', 'u.firstname'],
            'FROM'   => 'glpi_plugin_kanpro_activities AS a',
            'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['u' => 'id', 'a' => 'users_id']]],
            'WHERE'  => ['a.plugin_kanpro_boards_id'=>$bid, 'a.action'=>['card_move','card_complete','card_reopen','card_create','card_archive']],
            'ORDER'  => 'a.date_creation ASC',
            'LIMIT'  => 5000,
        ]);
        foreach ($miter as $m) {
            $uname = trim(($m['realname'] ?? '') . ' ' . ($m['firstname'] ?? ''));
            if ($uname === '') $uname = $m['user_name'] ?? 'Sistema';
            $moves[] = ['card_id'=>(int)$m['plugin_kanpro_cards_id'],'list_id'=>(int)$m['plugin_kanpro_lists_id'],
                'action'=>$m['action'],'details'=>$m['details'],'date'=>$m['date_creation'],'user'=>$uname];
        }
        jexit(['success'=>true,'lists'=>$lists,'cards'=>$cards,'moves'=>$moves]);

    case 'get_retirada_schools':
        // Escolas com cards NAO notificados na coluna Retirada (o botão "Notificado" do card
        // tira de cima). Deduplicadas por escola (a mesma escola pode se repetir na coluna).
        // Coluna Retirada = lista com categoria 'retirada' ou nome "Retirada" (legado).
        kanpro_ensure_board_extras();
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $bchk = new PluginKanproBoard();
        if (!$bchk->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        $__me = (int)Session::getLoginUserID();
        $__creator = (int)($bchk->fields['users_id'] ?? 0);
        if ($__me !== $__creator) {
            $__isM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>kanpro_viewer_ids()]) > 0;
            $__hasM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid]) > 0;
            if (!$__isM && $__hasM) jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
        }
        $retLists = []; // id => name
        foreach ($DB->request(['FROM'=>'glpi_plugin_kanpro_lists','WHERE'=>['plugin_kanpro_boards_id'=>$bid,'is_archived'=>0]]) as $l) {
            $lt = strtolower(trim($l['list_type'] ?? ''));
            $nm = function_exists('mb_strtolower') ? mb_strtolower(trim($l['name'] ?? ''), 'UTF-8') : strtolower(trim($l['name'] ?? ''));
            if ($lt === 'retirada' || $nm === 'retirada') $retLists[(int)$l['id']] = (string)$l['name'];
        }
        $schools = [];
        $notifiedCards = 0;
        if (!empty($retLists)) {
            // lista COMPLETA da Retirada (notificados ou não): o status viaja por card
            $cardWhere = ['plugin_kanpro_boards_id'=>$bid,'plugin_kanpro_lists_id'=>array_keys($retLists),'is_archived'=>0];
            $rcards = [];
            $eids = [];
            foreach ($DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>$cardWhere,'ORDER'=>'id ASC']) as $c) {
                $rcards[] = $c;
                if (!empty($c['is_notified'])) $notifiedCards++;
                if ((int)($c['entities_id'] ?? 0) > 0) $eids[] = (int)$c['entities_id'];
            }
            $enames = [];
            if (!empty($eids)) {
                foreach ($DB->request(['SELECT'=>['id','name','completename'],'FROM'=>'glpi_entities','WHERE'=>['id'=>array_values(array_unique($eids))]]) as $e) {
                    $nm = trim($e['name'] ?? '');
                    if ($nm === '') $nm = trim($e['completename'] ?? '');
                    $enames[(int)$e['id']] = $nm !== '' ? $nm : ('Entidade #' . (int)$e['id']);
                }
            }
            $groups = []; // chave => ['name'=>, 'cards'=>[]]
            foreach ($rcards as $c) {
                $eid = (int)($c['entities_id'] ?? 0);
                if ($eid > 0 && isset($enames[$eid])) {
                    $key = 'e' . $eid;
                    $sname = $enames[$eid];
                } else {
                    // sem entidade: agrupa pelo nome normalizado (iguais juntam, diferentes separam)
                    $cn = function_exists('mb_strtolower') ? mb_strtolower(trim($c['name'] ?? ''), 'UTF-8') : strtolower(trim($c['name'] ?? ''));
                    $key = 'n:' . $cn;
                    $sname = trim($c['name'] ?? '') !== '' ? trim($c['name']) : ('Cartão #' . (int)$c['id']);
                }
                if (!isset($groups[$key])) $groups[$key] = ['name'=>$sname, 'cards'=>[]];
                $groups[$key]['cards'][] = ['id'=>(int)$c['id'],'name'=>(string)($c['name'] ?? ''),'list'=>(string)($retLists[(int)$c['plugin_kanpro_lists_id']] ?? ''),'is_notified'=>!empty($c['is_notified']) ? 1 : 0];
            }
            foreach ($groups as $g) {
                usort($g['cards'], function ($a, $b) { return $a['id'] <=> $b['id']; });
                $schools[] = ['name'=>$g['name'],'count'=>count($g['cards']),'cards'=>$g['cards']];
            }
            usort($schools, function ($a, $b) {
                $na = function_exists('mb_strtolower') ? mb_strtolower($a['name'], 'UTF-8') : strtolower($a['name']);
                $nb = function_exists('mb_strtolower') ? mb_strtolower($b['name'], 'UTF-8') : strtolower($b['name']);
                return $na <=> $nb;
            });
        }
        $totalCards = 0;
        foreach ($schools as $s) $totalCards += $s['count'];
        jexit(['success'=>true,'schools'=>$schools,'total_schools'=>count($schools),'total_cards'=>$totalCards,'notified_cards'=>$notifiedCards]);

    // --- SEARCH FILTER ---
    case 'search_cards':
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        $q = trim($_REQUEST['q'] ?? '');
        if (strlen($q) < 1) jexit(['success'=>true,'ids'=>[]]);
        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_kanpro_cards',
            'WHERE'  => [
                'plugin_kanpro_boards_id' => $bid,
                'is_archived' => 0,
                'OR' => [
                    ['name' => ['LIKE', "%{$q}%"]],
                    ['description' => ['LIKE', "%{$q}%"]],
                ]
            ]
        ]);
        $ids = [];
        foreach ($iter as $r) $ids[] = $r['id'];
        jexit(['success'=>true,'ids'=>$ids]);

    case 'list_entities':
        // Lista entidades GLPI para seleção ao converter manutenção — nome do Card vira nome da Entidade
        // include_root=1 (botão Escola): inclui a mãe "Unidade Regional de Ensino de Jales" na lista
        $includeRoot = !empty($_REQUEST['include_root']);
        $entities = [];
        try {
            $iter = $DB->request(['FROM' => 'glpi_entities', 'ORDER' => 'completename ASC']);
            foreach ($iter as $row) {
                // ignora lixeira se houver
                if (isset($row['is_deleted']) && $row['is_deleted']) continue;
                $cname = trim($row['completename'] ?? $row['name'] ?? '');
                $rname = trim($row['name'] ?? '');
                // remove a própria "Unidade Regional de Ensino de Jales" da lista (exceto botão Escola)
                if (!$includeRoot) {
                    if ($cname === 'Unidade Regional de Ensino de Jales' || $rname === 'Unidade Regional de Ensino de Jales') continue;
                    if (strcasecmp($cname, 'Unidade Regional de Ensino de Jales') === 0) continue;
                }
                $entities[] = [
                    'id'           => (int)$row['id'],
                    'name'         => $row['name'] ?? '',
                    'completename' => $row['completename'] ?? $row['name'] ?? '',
                ];
            }
        } catch (Throwable $e) {
            jexit(['success'=>false,'msg'=>'Erro ao listar entidades: '.$e->getMessage()]);
        }
        // limpeza retroativa: remove prefixo "Unidade Regional de Ensino de Jales > " de cards já convertidos
        try {
            $DB->doQuery("UPDATE `glpi_plugin_kanpro_cards` SET `name` = TRIM(SUBSTRING_INDEX(`name`, ' > ', -1)) WHERE `is_maintenance` = 1 AND `name` LIKE 'Unidade Regional de Ensino%' AND `name` LIKE '% > %'");
        } catch (Throwable $e) {}
        jexit(['success'=>true,'entities'=>$entities]);

    // ==================== MANUTENÇÃO (2FA + checklist por máquina) ====================
    case 'convert_to_maintenance':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? $_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_text'] ?? $_POST['confirm'] ?? '';
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (!empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Este cartão já é de manutenção']);
        // 2 etapas: confirmação textual (palavra aleatória sem acento/ç) + senha
        if (!kanpro_maint_challenge_ok((string)$confirm)) {
            jexit(['success'=>false,'msg'=>'Palavra de confirmação inválida. Digite exatamente a palavra desafio exibida (sem acento).','need_confirm'=>true]);
        }
        // senha opcional - fluxo atual só pede palavra (sem senha)
        if ($password !== '' && $password !== null && !kanpro_verify_password($password)) {
            jexit(['success'=>false,'msg'=>'Senha incorreta. Verifique sua senha do GLPI.','need_password'=>true]);
        }
        // Entidade selecionada — nome do Card vira nome da Entidade
        $entities_id = isset($_POST['entities_id']) ? (int)$_POST['entities_id'] : 0;
        $entity_name_input = trim($_POST['entity_name'] ?? $_POST['entities_name'] ?? '');
        $newName = kanpro_maint_name_from_entity($entities_id, $entity_name_input);
        if ($newName === null) {
            if ($entities_id > 0) jexit(['success'=>false,'msg'=>'Entidade não encontrada']);
            jexit(['success'=>false,'msg'=>'Selecione a entidade. O nome do card virará o nome da entidade.','need_entity'=>true]);
        }
        $updateData = [
            'is_maintenance'   => 1,
            'maintenance_date' => date('Y-m-d H:i:s'),
            'maintenance_by'   => kanpro_acting_user_id(),
            'date_mod'         => date('Y-m-d H:i:s'),
            'name'             => $newName
        ];
        // guarda a entidade da escola no card (fone do WhatsApp) — 0 se nome digitado
        if ($DB->fieldExists('glpi_plugin_kanpro_cards', 'entities_id')) {
            $updateData['entities_id'] = (int)$entities_id;
        }
        $DB->update('glpi_plugin_kanpro_cards', $updateData, ['id' => $cid]);
        $extra = kanpro_finish_maintenance($cid, $newName, $entities_id);
        jexit(['success'=>true,'msg'=>'Card convertido para manutenção','is_maintenance'=>1,'new_name'=>$newName,'entities_id'=>$entities_id] + $extra);

    case 'verify_maintenance_password':
        // endpoint auxiliar só para validar senha antes de converter (usado em fluxo 2 etapas separado)
        $pwd = $_POST['password'] ?? '';
        if (!kanpro_verify_password($pwd)) jexit(['success'=>false,'msg'=>'Senha incorreta']);
        jexit(['success'=>true]);

    case 'setup_maintenance_machines':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $machines_raw = $_POST['machines_raw'] ?? $_POST['raw'] ?? '';
        $definitions_json = $_POST['definitions'] ?? '';
        $replace = !empty($_POST['replace']) ? (int)$_POST['replace'] : 0;
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Cartão não é de manutenção. Converta primeiro.']);
        // Trava da Pendente vale DEPOIS da configuração inicial: primeiro setup
        // (card sem máquinas) passa; com máquinas, segue bloqueado.
        $existing = countElementsInTable('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cid]);
        if ($existing > 0 || !kanpro_card_is_locked($cid)) kanpro_need_card_editable($cid);
        kanpro_need_not_finalized($cid);
        kanpro_need_not_chamado_locked($cid);
        // Parse definições
        $defs = [];
        if (!empty($definitions_json)) {
            $decoded = json_decode($definitions_json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $d) {
                    $qty = (int)($d['qty'] ?? $d['quantity'] ?? 1);
                    $model = trim($d['model'] ?? $d['name'] ?? '');
                    if ($model !== '' && $qty>0) $defs[] = ['qty'=>$qty,'model'=>$model];
                }
            }
        }
        if (empty($defs) && $machines_raw !== '') {
            $defs = kanpro_parse_maintenance_raw($machines_raw);
        }
        // fallback: tenta definitions como raw json string
        if (empty($defs) && !empty($_POST['machines'])) {
            $tmp = json_decode($_POST['machines'], true);
            if (is_array($tmp) && isset($tmp[0]['model'])) {
                foreach ($tmp as $d) {
                    $qty = (int)($d['qty'] ?? 1);
                    $model = trim($d['model'] ?? '');
                    if ($model !== '' && $qty>0) $defs[] = ['qty'=>$qty,'model'=>$model];
                }
            }
        }
        if (empty($defs)) jexit(['success'=>false,'msg'=>'Informe pelo menos um modelo. Ex: 10x Notebook Positivo']);
        // Valida total
        $total = 0;
        foreach ($defs as $d) $total += $d['qty'];
        if ($total <=0 || $total > 500) jexit(['success'=>false,'msg'=>'Total de máquinas inválido (1-500). Informado: '.$total]);
        // Se já existem máquinas e não é replace, bloqueia
        if ($existing >0 && !$replace) {
            jexit(['success'=>false,'msg'=>'Este cartão já possui máquinas cadastradas. Use replace=1 para substituir.','existing'=>$existing,'need_replace'=>true]);
        }
        if ($replace) {
            $DB->delete('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cid]);
        }
        // Busca max seq atual (se não replace e existir, continua)
        $maxSeq = 0;
        if (!$replace && $existing>0) {
            $row = $DB->request(['SELECT'=>['MAX'=>'seq AS m'],'FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid]])->current();
            $maxSeq = (int)($row['m'] ?? 0);
        }
        $seq = $maxSeq;
        $now = date('Y-m-d H:i:s');
        $uid = kanpro_acting_user_id();
        $created = [];
        foreach ($defs as $def) {
            $qty = (int)$def['qty'];
            $model = trim($def['model']);
            // Sanitiza modelo
            $model = mb_substr($model, 0, 250);
            for ($i=1; $i <= $qty; $i++) {
                $seq++;
                $label = "Máquina {$seq} - {$model}";
                $mid = $DB->insert('glpi_plugin_kanpro_maintenance_machines', [
                    'plugin_kanpro_cards_id' => $cid,
                    'seq'                    => $seq,
                    'model'                  => $model,
                    'label'                  => $label,
                    'diary'                  => '',
                    'is_done'                => 0,
                    'is_ok'                  => 0,
                    'status'                 => '',
                    'is_inventoried'         => 0,
                    'is_urgent'              => 0,
                    'users_id'               => $uid,
                    'date_creation'          => $now,
                    'date_mod'               => $now,
                ]);
                // Fallback se insert retorna false (algumas versões não retornam id, mas cria)
                // Busca último id
                if (!$mid) {
                    $last = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid,'seq'=>$seq],'ORDER'=>'id DESC','LIMIT'=>1])->current();
                    $mid = $last['id'] ?? 0;
                }
                $created[] = ['id'=>$mid,'seq'=>$seq,'model'=>$model,'label'=>$label];
            }
        }
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_setup', "Máquinas configuradas: {$total} ({$existing} existiam)");
        kanpro_touch_member($cid);
        // lista as máquinas no chamado vinculado
        $tid = kanpro_card_ticket_id($cid);
        if ($tid && !empty($created)) {
            $lst = [];
            foreach ($created as $mc) $lst[] = '• #' . $mc['seq'] . ' ' . $mc['model'];
            kanpro_ticket_followup($tid, "⚙ [KanPro] Máquinas configuradas\n\n" . count($created) . " nova(s) máquina(s) adicionada(s) a este atendimento:\n\n" . implode("\n", array_slice($lst, 0, 20)) . (count($lst) > 20 ? "\n... (+" . (count($lst) - 20) . " máquinas)" : ''));
        }
        // Retorna lista completa atualizada
        $all = [];
        $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach ($iter as $r) $all[] = $r;
        $done = count(array_filter($all, fn($x)=> $x['is_done']==1));
        $urgent = count(array_filter($all, fn($x)=> !empty($x['is_urgent'])));
        // WhatsApp ENTRADA (1ª configuração do card) — nunca quebra o fluxo
        if ($existing == 0 && class_exists('PluginKanproMaintenanceZap')) {
            try { PluginKanproMaintenanceZap::sendOnce('entrada', $cid); } catch (Throwable $e) {}
        }
        // Split automático Tablet/Smartphone/Celular: mistura vira 2 cards
        $tabletSplit = ['new_id'=>0,'moved'=>0,'kept'=>0];
        try {
            if (function_exists('kanpro_split_tablet_machines')) {
                $tabletSplit = kanpro_split_tablet_machines($cid);
            }
        } catch (Throwable $e) {}
        // recarrega lista do card origem após split (tablets saíram)
        if (!empty($tabletSplit['new_id'])) {
            $all = [];
            $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
            foreach ($iter as $r) $all[] = $r;
            $done = count(array_filter($all, fn($x)=> $x['is_done']==1));
            $urgent = count(array_filter($all, fn($x)=> !empty($x['is_urgent'])));
        }
        jexit(['success'=>true,'total'=>$total,'created'=>count($created),'machines'=>$all,'progress'=>['total'=>count($all),'done'=>$done,'percent'=> count($all)? round($done/count($all)*100):0,'urgent'=>$urgent],'tablet_split'=>$tabletSplit]);

    case 'get_maintenance':
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_REQUEST['cards_id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $isMaint = !empty($card->fields['is_maintenance']) ? 1 : 0;
        $machines = [];
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
            foreach ($iter as $r) $machines[] = $r;
        }
        $total = count($machines);
        $done = 0; $ok = 0;
        foreach ($machines as $m) { if ($m['is_done']) $done++; if ($m['is_ok'] || $m['status']==='ok') $ok++; }
        jexit(['success'=>true,'is_maintenance'=>$isMaint,'card'=>$card->fields,'machines'=>$machines,'progress'=>['total'=>$total,'done'=>$done,'percent'=>$total?round($done/$total*100):0,'ok'=>$ok]]);

    case 'list_maintenance_models':
        kanpro_ensure_maintenance_tables();
        $models = function_exists('kanpro_list_maintenance_models') ? kanpro_list_maintenance_models() : [];
        $canManage = false;
        try {
            $bidM = (int)($_REQUEST['boards_id'] ?? 0);
            if ($bidM > 0 && function_exists('kanpro_can_manage_models')) $canManage = kanpro_can_manage_models($bidM);
            elseif ($bidM > 0 && function_exists('kanpro_can_manage_members')) $canManage = kanpro_can_manage_members($bidM);
            elseif (Session::haveRight('plugin_kanpro', UPDATE)) $canManage = true;
        } catch (Throwable $e) {}
        jexit(['success'=>true,'models'=>$models,'can_manage'=>$canManage ? 1 : 0]);

    case 'add_maintenance_model':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $bidM = (int)($_POST['boards_id'] ?? 0);
        if ($bidM > 0) {
            if (!function_exists('kanpro_can_manage_models') || !kanpro_can_manage_models($bidM)) jexit(['success'=>false,'msg'=>'Somente Membro ou Admin do quadro pode gerenciar modelos.']);
        } elseif (!Session::haveRight('plugin_kanpro', UPDATE)) {
            jexit(['success'=>false,'msg'=>'Sem permissão (precisa UPDATE no KanPro).']);
        }
        $nm = function_exists('kanpro_clean_text') ? kanpro_clean_text($_POST['name'] ?? '', 80) : trim(strip_tags($_POST['name'] ?? ''));
        if ($nm === '') jexit(['success'=>false,'msg'=>'Nome obrigatório']);
        if (function_exists('mb_strlen') ? mb_strlen($nm, 'UTF-8') < 2 : strlen($nm) < 2) jexit(['success'=>false,'msg'=>'Nome muito curto']);
        try {
            if (countElementsInTable('glpi_plugin_kanpro_maintenance_models', ['name'=>$nm]) > 0) jexit(['success'=>false,'msg'=>'Modelo já existe']);
            $last = $DB->request(['SELECT'=>['MAX'=>'rank AS m'],'FROM'=>'glpi_plugin_kanpro_maintenance_models'])->current();
            $rk = (float)($last['m'] ?? 0) + 1024;
            if ($rk <= 0) $rk = 1024;
            $now = date('Y-m-d H:i:s');
            $nid = $DB->insert('glpi_plugin_kanpro_maintenance_models', ['name'=>$nm,'rank'=>$rk,'users_id'=>kanpro_acting_user_id(),'date_creation'=>$now,'date_mod'=>$now]);
            if (!$nid) {
                $rw = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_models','WHERE'=>['name'=>$nm]])->current();
                $nid = (int)($rw['id'] ?? 0);
            }
            if (!$nid) jexit(['success'=>false,'msg'=>'Não foi possível salvar']);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Modelo já existe ou falha ao salvar']); }
        jexit(['success'=>true,'id'=>(int)$nid,'models'=>kanpro_list_maintenance_models()]);

    case 'rename_maintenance_model':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $bidM = (int)($_POST['boards_id'] ?? 0);
        if ($bidM > 0) {
            if (!function_exists('kanpro_can_manage_models') || !kanpro_can_manage_models($bidM)) jexit(['success'=>false,'msg'=>'Somente Membro ou Admin do quadro pode gerenciar modelos.']);
        } elseif (!Session::haveRight('plugin_kanpro', UPDATE)) {
            jexit(['success'=>false,'msg'=>'Sem permissão (precisa UPDATE no KanPro).']);
        }
        $mid = (int)($_POST['id'] ?? 0);
        $nm = function_exists('kanpro_clean_text') ? kanpro_clean_text($_POST['name'] ?? '', 80) : trim(strip_tags($_POST['name'] ?? ''));
        if (!$mid || $nm === '') jexit(['success'=>false,'msg'=>'Dados inválidos']);
        $rowM = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_models','WHERE'=>['id'=>$mid]])->current();
        if (!$rowM) jexit(['success'=>false,'msg'=>'Modelo não encontrado']);
        $oldNm = (string)($rowM['name'] ?? '');
        try {
            if ($oldNm !== $nm && countElementsInTable('glpi_plugin_kanpro_maintenance_models', ['name'=>$nm]) > 0) jexit(['success'=>false,'msg'=>'Já existe um modelo com esse nome']);
            $DB->update('glpi_plugin_kanpro_maintenance_models', ['name'=>$nm,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$mid]);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Falha ao renomear']); }
        jexit(['success'=>true,'models'=>kanpro_list_maintenance_models()]);

    case 'delete_maintenance_model':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $bidM = (int)($_POST['boards_id'] ?? 0);
        if ($bidM > 0) {
            if (!function_exists('kanpro_can_manage_models') || !kanpro_can_manage_models($bidM)) jexit(['success'=>false,'msg'=>'Somente Membro ou Admin do quadro pode gerenciar modelos.']);
        } elseif (!Session::haveRight('plugin_kanpro', UPDATE)) {
            jexit(['success'=>false,'msg'=>'Sem permissão (precisa UPDATE no KanPro).']);
        }
        $mid = (int)($_POST['id'] ?? 0);
        if (!$mid) jexit(['success'=>false,'msg'=>'Modelo inválido']);
        $DB->delete('glpi_plugin_kanpro_maintenance_models', ['id'=>$mid]);
        jexit(['success'=>true,'models'=>kanpro_list_maintenance_models()]);

    case 'update_maintenance_machine':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_POST['id'] ?? 0);
        if (!$mid) jexit(['success'=>false,'msg'=>'Máquina inválida']);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Máquina não encontrada']);
        // trava total: aguardando chamado não edita nada
        if (!empty($row['is_locked'])) {
            jexit(['success'=>false,'msg'=>'Máquina travada — aguardando Chamado criado (#' . (int)($row['locked_chamado_card_id'] ?? 0) . ')','locked'=>true]);
        }
        // card ainda na lista Pendente = ninguém pegou: nada de Feito/Status/Diário.
        // O caminho é clicar em Pegar (admin), que move p/ Em Andamento e cria a Pendência Chamado.
        kanpro_need_card_editable((int)$row['plugin_kanpro_cards_id']);
        kanpro_need_not_finalized((int)$row['plugin_kanpro_cards_id']);
        kanpro_need_not_chamado_locked((int)$row['plugin_kanpro_cards_id']);
        $updates = [];
        if (array_key_exists('diary', $_POST)) $updates['diary'] = $_POST['diary'];
        if (array_key_exists('is_done', $_POST)) $updates['is_done'] = (int)$_POST['is_done'] ? 1:0;
        if (array_key_exists('is_ok', $_POST)) $updates['is_ok'] = (int)$_POST['is_ok'] ? 1:0;
        if (array_key_exists('status', $_POST)) {
            $st = trim($_POST['status']);
            $stLower = mb_strtolower($st, 'UTF-8');
            // normaliza aliases legados e aceita vazio (obrigatório — validação no finalize)
            $map = [
                'pending'   => 'pendente',
                'pendente'  => 'pendente',
                'garantia'  => 'garantia',
                'ok'        => 'ok',
                'inservivel'=> 'inservivel',
                'inservível'=> 'inservivel',
                'defect'    => 'inservivel',
                'defeito'   => 'inservivel',
                'nok'       => 'inservivel',
                ''          => '',
            ];
            if (array_key_exists($stLower, $map)) {
                $st = $map[$stLower];
            } else {
                // valor desconhecido -> vazio (força seleção)
                $st = '';
            }
            $updates['status'] = $st;
            // sincroniza is_ok para compat: apenas ok = 1
            $updates['is_ok'] = ($st==='ok'?1:0);
        }
        if (array_key_exists('model', $_POST)) {
            $model = trim($_POST['model']);
            if ($model !== '') {
                $updates['model'] = mb_substr($model,0,250);
                // atualiza label para manter seq
                $updates['label'] = "Máquina {$row['seq']} - {$updates['model']}";
            }
        }
        if (array_key_exists('is_inventoried', $_POST)) $updates['is_inventoried'] = (int)$_POST['is_inventoried'] ? 1:0;
        if (array_key_exists('inventoried', $_POST)) $updates['is_inventoried'] = (int)$_POST['inventoried'] ? 1:0;
        if (array_key_exists('needs_inventory', $_POST)) {
            $updates['needs_inventory'] = (int)$_POST['needs_inventory'] ? 1:0;
            // se não precisa inventariar, limpa confirmação
            if (!$updates['needs_inventory']) $updates['is_inventoried'] = 0;
        }
        if (array_key_exists('is_urgent', $_POST)) $updates['is_urgent'] = (int)$_POST['is_urgent'] ? 1:0;
        if (array_key_exists('urgent', $_POST)) $updates['is_urgent'] = (int)$_POST['urgent'] ? 1:0;
        if (array_key_exists('urgencia', $_POST)) $updates['is_urgent'] = (int)$_POST['urgencia'] ? 1:0;
        // Invariante: Pendente nunca é Feito (rede de segurança do servidor)
        $effStatus = $updates['status'] ?? ($row['status'] ?? '');
        if ($effStatus === 'pending') $effStatus = 'pendente';
        if ($effStatus === 'pendente') $updates['is_done'] = 0;
        if (empty($updates)) jexit(['success'=>false,'msg'=>'Nada para atualizar']);
        $updates['date_mod'] = date('Y-m-d H:i:s');
        $updates['users_id'] = kanpro_acting_user_id();
        $DB->update('glpi_plugin_kanpro_maintenance_machines', $updates, ['id'=>$mid]);
        kanpro_touch_member((int)$row['plugin_kanpro_cards_id']);
        // espelha mudanças relevantes no chamado (diário NÃO vai — salva a cada tecla)
        $chg = [];
        if (array_key_exists('status', $updates) && ($updates['status'] ?? '') !== ($row['status'] ?? '')) {
            $chg[] = 'status: ' . kanpro_machine_status_label($row['status'] ?? '') . ' → ' . kanpro_machine_status_label($updates['status']);
        }
        if (array_key_exists('is_done', $updates) && (int)$updates['is_done'] !== (int)($row['is_done'] ?? 0)) {
            $chg[] = !empty($updates['is_done']) ? 'marcada como FEITA' : 'desmarcada (não feita)';
        }
        if (array_key_exists('is_urgent', $updates) && (int)$updates['is_urgent'] !== (int)($row['is_urgent'] ?? 0)) {
            $chg[] = !empty($updates['is_urgent']) ? 'marcada como URGÊNCIA' : 'urgência removida';
        }
        if (array_key_exists('model', $updates) && $updates['model'] !== ($row['model'] ?? '')) {
            $chg[] = 'modelo: "' . ($row['model'] ?? '') . '" → "' . $updates['model'] . '"';
        }
        if (array_key_exists('is_inventoried', $updates) && (int)$updates['is_inventoried'] !== (int)($row['is_inventoried'] ?? 0)) {
            $chg[] = !empty($updates['is_inventoried']) ? 'marcada como INVENTARIADA' : 'desmarcada de inventariada';
        }
        if (array_key_exists('needs_inventory', $updates) && (int)$updates['needs_inventory'] !== (int)($row['needs_inventory'] ?? 0)) {
            $chg[] = !empty($updates['needs_inventory']) ? 'marcada como PRECISA INVENTARIAR' : 'marcada como NÃO precisa inventariar';
        }
        if (!empty($chg)) {
            $cidM = (int)$row['plugin_kanpro_cards_id'];
            $tid = kanpro_card_ticket_id($cidM);
            if ($tid) {
                $msg = "⚙ [KanPro] Atualização de máquina\n\nMáquina #{$row['seq']} '" . ($row['model'] ?? '') . "'\n\nAlterações: " . implode(' | ', $chg);
                $rep = kanpro_card_machines_report($cidM);
                if ($rep !== '') $msg .= "\n\n" . $rep;
                kanpro_ticket_followup($tid, $msg);
            }
        }
        // qualquer alteração (status, diário, feito, etc.) move o chamado para Em atendimento
        $tidAtt = kanpro_card_ticket_id((int)$row['plugin_kanpro_cards_id']);
        if ($tidAtt) kanpro_ticket_set_attending($tidAtt);
        // quem mexeu ajuda no chamado: anexa como atribuído mesmo sem followup (ex: só escreveu no diário)
        if ($tidAtt) kanpro_ticket_assign($tidAtt, kanpro_acting_user_id());
        // log: mudanças reais geram entrada; diário sozinho mantém 1 entrada por máquina (anti-flood do autosave)
        $card = new PluginKanproCard();
        if ($card->getFromDB($row['plugin_kanpro_cards_id'])) {
            $cidM = (int)$card->getID();
            if (!empty($chg)) {
                $modelBit = trim((string)($row['model'] ?? '')) !== '' ? " '" . mb_substr(trim((string)$row['model']), 0, 60) . "'" : '';
                $detailUp = "Máquina #{$row['seq']}{$modelBit}: " . implode(' | ', $chg);
                PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cidM, $card->fields['plugin_kanpro_lists_id'], 'maintenance_update', mb_substr($detailUp, 0, 500));
            } elseif (array_key_exists('diary', $updates) && (string)($updates['diary'] ?? '') !== (string)($row['diary'] ?? '')) {
                $dlabel = "Relatório da Máquina #{$row['seq']} atualizado";
                $DB->delete('glpi_plugin_kanpro_activities', ['plugin_kanpro_cards_id'=>$cidM, 'action'=>'maintenance_diary', 'details'=>$dlabel]);
                PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cidM, $card->fields['plugin_kanpro_lists_id'], 'maintenance_diary', $dlabel);
            }
        }
        $newRow = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        // etiqueta roxa "Inventário" acompanha quem precisa inventariar
        if (array_key_exists('needs_inventory', $updates)) kanpro_sync_inventory_label((int)$row['plugin_kanpro_cards_id']);
        jexit(['success'=>true,'machine'=>$newRow]);

    case 'bulk_update_machines':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!$cid || !is_array($ids) || !count($ids)) jexit(['success'=>false,'msg'=>'Nada selecionado']);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        kanpro_need_card_editable($cid);
        kanpro_need_not_finalized($cid);
        kanpro_need_not_chamado_locked($cid);
        // status opcional
        $applyStatus = false; $st = null;
        if (array_key_exists('status', $_POST) && trim($_POST['status'] ?? '') !== '') {
            $map = ['pending'=>'pendente','pendente'=>'pendente','garantia'=>'garantia','ok'=>'ok',
                'inservivel'=>'inservivel','inservível'=>'inservivel','defect'=>'inservivel','defeito'=>'inservivel','nok'=>'inservivel'];
            $k = mb_strtolower(trim($_POST['status']), 'UTF-8');
            if (isset($map[$k])) { $st = $map[$k]; $applyStatus = true; }
        }
        $applyDone = array_key_exists('is_done', $_POST);
        $doneVal = $applyDone ? ((int)$_POST['is_done'] ? 1 : 0) : null;
        // inventário em massa (modo Selecionar): precisa + inventariado
        $applyNeeds = array_key_exists('needs_inventory', $_POST);
        $needsVal = $applyNeeds ? ((int)$_POST['needs_inventory'] ? 1 : 0) : null;
        $applyInv = array_key_exists('is_inventoried', $_POST) || array_key_exists('inventoried', $_POST);
        if (array_key_exists('is_inventoried', $_POST)) $invVal = ((int)$_POST['is_inventoried'] ? 1 : 0);
        elseif (array_key_exists('inventoried', $_POST)) $invVal = ((int)$_POST['inventoried'] ? 1 : 0);
        else $invVal = null;
        // diário/relatório em massa: mesmo texto para todas as selecionadas (sobrescreve)
        $applyDiary = array_key_exists('diary', $_POST);
        $diaryVal = $applyDiary ? (string)($_POST['diary'] ?? '') : null;
        if ($applyDiary && trim($diaryVal) === '') jexit(['success'=>false,'msg'=>'Escreva o relatório/diário para aplicar em massa.']);
        if (!$applyStatus && !$applyDone && !$applyNeeds && !$applyInv && !$applyDiary) jexit(['success'=>false,'msg'=>'Nada para aplicar']);
        $rows = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$ids,'plugin_kanpro_cards_id'=>$cid]]);
        $n = 0; $skippedLocked = 0;
        foreach ($rows as $r) {
            if (!empty($r['is_locked'])) { $skippedLocked++; continue; }
            $u = ['date_mod'=>date('Y-m-d H:i:s'),'users_id'=>kanpro_acting_user_id()];
            if ($applyStatus) {
                $u['status'] = $st;
                $u['is_ok'] = ($st === 'ok' ? 1 : 0);
            }
            $effStatus = $applyStatus ? $st : ($r['status'] ?? '');
            if ($applyDone) $u['is_done'] = ($effStatus === 'pendente') ? 0 : $doneVal;
            if ($applyNeeds) {
                $u['needs_inventory'] = $needsVal;
                if (!$needsVal) $u['is_inventoried'] = 0;
            }
            if ($applyInv) {
                if ($invVal) {
                    // inventariado exige "precisa inventariar"
                    $u['needs_inventory'] = 1;
                    $u['is_inventoried'] = 1;
                } else {
                    $u['is_inventoried'] = 0;
                }
            }
            if ($applyDiary) $u['diary'] = $diaryVal;
            $DB->update('glpi_plugin_kanpro_maintenance_machines', $u, ['id'=>$r['id']]);
            $n++;
        }
        if ($n) {
            kanpro_touch_member($cid);
            if ($applyNeeds || $applyInv) kanpro_sync_inventory_label($cid);
            $bits = [];
            if ($applyStatus) $bits[] = "status → " . kanpro_machine_status_label($st);
            if ($applyDone) $bits[] = $doneVal ? "marcadas como FEITAS" : "desmarcadas (não feitas)";
            if ($applyNeeds) $bits[] = $needsVal ? "marcadas como PRECISA INVENTARIAR" : "marcadas como NÃO precisa inventariar";
            if ($applyInv) $bits[] = $invVal ? "marcadas como INVENTARIADAS" : "inventário desmarcado";
            if ($applyDiary) {
                $prev = trim($diaryVal);
                $prev = function_exists('mb_substr') ? mb_substr($prev, 0, 80) : substr($prev, 0, 80);
                $bits[] = "diário/relatório aplicado (" . mb_strlen(trim($diaryVal)) . " chars): \"" . $prev . (mb_strlen(trim($diaryVal)) > 80 ? "…" : "") . "\"";
            }
            $tid = kanpro_card_ticket_id($cid);
            if ($tid) {
                $msg = "⚙ [KanPro] Atualização em massa\n\n{$n} máquina(s): " . implode(' | ', $bits);
                $rep = kanpro_card_machines_report($cid);
                if ($rep !== '') $msg .= "\n\n" . $rep;
                kanpro_ticket_followup($tid, $msg);
            }
            if ($tid) kanpro_ticket_set_attending($tid);
            PluginKanproBoard::logActivity((int)$card->fields['plugin_kanpro_boards_id'], $cid, (int)$card->fields['plugin_kanpro_lists_id'], 'maintenance_update', "Atualização em massa: {$n} máquina(s) (" . implode(' | ', $bits) . ")" . ($skippedLocked ? " — {$skippedLocked} travada(s) ignorada(s)" : ""));
        }
        jexit(['success'=>true,'updated'=>$n,'skipped_locked'=>$skippedLocked]);

    case 'set_all_needs_inventory':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $val = !empty($_POST['needs_inventory']) ? 1 : 0;
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        kanpro_need_card_editable($cid);
        kanpro_need_not_finalized($cid);
        kanpro_need_not_chamado_locked($cid);
        $upd = ['needs_inventory'=>$val, 'date_mod'=>date('Y-m-d H:i:s'), 'users_id'=>kanpro_acting_user_id()];
        if (!$val) $upd['is_inventoried'] = 0;
        $DB->update('glpi_plugin_kanpro_maintenance_machines', $upd, ['plugin_kanpro_cards_id'=>$cid]);
        kanpro_sync_inventory_label($cid);
        $n = countElementsInTable('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cid]);
        // quem marcou ajuda no chamado e vira membro do cartão
        $tidAll = kanpro_card_ticket_id($cid);
        if ($tidAll) kanpro_ticket_assign($tidAll, kanpro_acting_user_id());
        kanpro_touch_member($cid);
        PluginKanproBoard::logActivity((int)$card->fields['plugin_kanpro_boards_id'], $cid, (int)$card->fields['plugin_kanpro_lists_id'], 'maintenance_update', $val ? "Todas as {$n} máquinas marcadas como PRECISA INVENTARIAR" : "Marcas de 'precisa inventariar' removidas de {$n} máquinas");
        jexit(['success'=>true,'updated'=>$n]);

    case 'add_maintenance_machines':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 1);
        $model = trim($_POST['model'] ?? '');
        $raw = $_POST['machines_raw'] ?? $_POST['raw'] ?? '';
        $definitions_json = $_POST['definitions'] ?? '';
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Não é manutenção']);
        kanpro_need_not_finalized($cid);
        kanpro_need_not_chamado_locked($cid);
        $defs = [];
        if (!empty($definitions_json)) {
            $decoded = json_decode($definitions_json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $d) {
                    $qtyD = (int)($d['qty'] ?? $d['quantity'] ?? 1);
                    $modelD = trim($d['model'] ?? $d['name'] ?? '');
                    if ($modelD !== '' && $qtyD>0) $defs[] = ['qty'=>$qtyD,'model'=>$modelD];
                }
            }
        }
        if (empty($defs) && $raw !== '') $defs = kanpro_parse_maintenance_raw($raw);
        else if (empty($defs) && $model !== '') $defs[] = ['qty'=>max(1,min(500,$qty)),'model'=>$model];
        else if (empty($defs) && !empty($_POST['machines'])) {
            $tmp = json_decode($_POST['machines'], true);
            if (is_array($tmp) && isset($tmp[0]['model'])) {
                foreach ($tmp as $d) {
                    $qtyT = (int)($d['qty'] ?? 1);
                    $modelT = trim($d['model'] ?? '');
                    if ($modelT !== '' && $qtyT>0) $defs[] = ['qty'=>$qtyT,'model'=>$modelT];
                }
            }
        }
        if (empty($defs)) jexit(['success'=>false,'msg'=>'Informe modelo ou raw']);
        $row = $DB->request(['SELECT'=>['MAX'=>'seq AS m'],'FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid]])->current();
        $seq = (int)($row['m'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $uid = kanpro_acting_user_id();
        foreach ($defs as $def) {
            $q = (int)$def['qty'];
            $mod = trim($def['model']);
            for ($i=0;$i<$q;$i++) {
                $seq++;
                $DB->insert('glpi_plugin_kanpro_maintenance_machines', [
                    'plugin_kanpro_cards_id'=>$cid,
                    'seq'=>$seq,
                    'model'=>$mod,
                    'label'=>"Máquina {$seq} - {$mod}",
                    'diary'=>'',
                    'is_done'=>0,
                    'is_ok'=>0,
                    'status'=>'',
                    'is_inventoried'=>0,
                    'is_urgent'=>0,
                    'users_id'=>$uid,
                    'date_creation'=>$now,
                    'date_mod'=>$now
                ]);
            }
        }
        $all=[];
        $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach($iter as $r) $all[]=$r;
        kanpro_touch_member($cid);
        $tid = kanpro_card_ticket_id($cid);
        if ($tid && !empty($defs)) {
            $lst = [];
            foreach ($defs as $def) $lst[] = '• ' . $def['qty'] . 'x ' . $def['model'];
            kanpro_ticket_followup($tid, "⚙ [KanPro] Máquinas adicionadas\n\nForam incluídas as seguintes máquinas neste atendimento:\n\n" . implode("\n", array_slice($lst, 0, 20)));
        }
        // Split automático Tablet (adição posterior também separa)
        $tabletSplitAdd = ['new_id'=>0,'moved'=>0,'kept'=>0];
        try {
            if (function_exists('kanpro_split_tablet_machines')) {
                $tabletSplitAdd = kanpro_split_tablet_machines($cid);
            }
        } catch (Throwable $e) {}
        if (!empty($tabletSplitAdd['new_id'])) {
            $all=[];
            $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
            foreach($iter as $r) $all[]=$r;
        }
        jexit(['success'=>true,'machines'=>$all,'tablet_split'=>$tabletSplitAdd]);

    case 'delete_maintenance_machine':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_POST['id'] ?? 0);
        if (!$mid) jexit(['success'=>false,'msg'=>'ID inválido']);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Não encontrado']);
        if (!empty($row['is_locked'])) jexit(['success'=>false,'msg'=>'Máquina travada — aguardando Chamado criado','locked'=>true]);
        $cid = $row['plugin_kanpro_cards_id'];
        kanpro_need_card_editable((int)$cid);
        kanpro_need_not_finalized((int)$cid);
        kanpro_need_not_chamado_locked((int)$cid);
        $DB->delete('glpi_plugin_kanpro_maintenance_machines', ['id'=>$mid]);
        $tid = kanpro_card_ticket_id((int)$cid);
        if ($tid) {
            kanpro_ticket_followup($tid, "⚙ [KanPro] Remoção de máquina\n\nA máquina #{$row['seq']} '" . ($row['model'] ?? '') . "' foi removida deste atendimento.");
        }
        // apaga anotações da máquina
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_notes')) {
            $DB->delete('glpi_plugin_kanpro_maintenance_notes', ['machine_id'=>$mid]);
        }
        // Re-sequenciar restantes para manter 1..N contínuo
        $remaining=[];
        $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach($iter as $r) $remaining[]=$r;
        $seq=1;
        foreach($remaining as $r) {
            $newLabel = "Máquina {$seq} - {$r['model']}";
            $DB->update('glpi_plugin_kanpro_maintenance_machines', ['seq'=>$seq,'label'=>$newLabel,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$r['id']]);
            $seq++;
        }
        $all=[];
        $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach($iter as $r) $all[]=$r;
        kanpro_sync_inventory_label((int)$cid);
        jexit(['success'=>true,'machines'=>$all]);

    case 'get_info_sheet':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_REQUEST['cards_id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $d = kanpro_info_sheet_data($cid);
        if (!$d) jexit(['success'=>false,'msg'=>'Cartão sem máquinas ou não encontrado']);
        jexit(['success'=>true,'html'=>kanpro_info_sheet_html($d),'hasStatus'=>$d['hasStatus'],'total'=>count($d['machines'])]);

    case 'print_info_sheet':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $d = kanpro_info_sheet_data($cid);
        if (!$d) jexit(['success'=>false,'msg'=>'Cartão sem máquinas ou não encontrado']);
        // 1) PDF do cliente (html2pdf no navegador — igual ao termo no assetmgrstatus).
        // Garante impressão idêntica à prévia mesmo sem mPDF no servidor.
        $pdf = null;
        $pdfB64 = trim($_POST['pdf_base64'] ?? '');
        if ($pdfB64 !== '') {
            try {
                $raw = base64_decode($pdfB64, true);
                if ($raw !== false && strlen($raw) > 800 && substr($raw, 0, 5) === '%PDF-') {
                    $tmp = sys_get_temp_dir() . '/kanpro_folha_client_' . uniqid() . '.pdf';
                    if (@file_put_contents($tmp, $raw) !== false && file_exists($tmp) && filesize($tmp) > 800) {
                        $pdf = $tmp;
                        error_log('[kanpro] print_info_sheet: PDF cliente usado card=' . $cid . ' size=' . filesize($tmp));
                    }
                } else {
                    error_log('[kanpro] print_info_sheet: pdf_base64 invalido card=' . $cid);
                }
            } catch (Throwable $e) {}
        }
        // 2) Gera no servidor (mPDF/Dompdf/wkhtml/chromium + fallback puro PHP).
        // Strip .no-print/scripts: senao os botoes saem impressos no PDF.
        if (!$pdf) {
            $pdf = kanpro_html_to_pdf(kanpro_strip_no_print(kanpro_info_sheet_html($d)));
        }
        if (!$pdf) {
            $pdf = kanpro_info_sheet_simple_pdf($d);
        }
        if (!$pdf) jexit(['success'=>false,'msg'=>'Não foi possível gerar o PDF no servidor. Use a prévia para imprimir.']);
        $title = 'Folha-' . str_pad($cid, 4, '0', STR_PAD_LEFT);
        $res = kanpro_print_pdf_cups($pdf, $title, trim($_POST['printer'] ?? '') ?: null);
        if (!$res['ok']) jexit(['success'=>false,'msg'=>($res['error'] ?? 'Falha ao imprimir')]);
        $card = new PluginKanproCard();
        if ($card->getFromDB($cid)) {
            PluginKanproBoard::logActivity((int)$card->fields['plugin_kanpro_boards_id'], $cid, (int)$card->fields['plugin_kanpro_lists_id'], 'print_sheet', "Folha informativa impressa (" . ($res['printer'] ?? '?') . (isset($res['request_id']) && $res['request_id'] !== '' ? ' job ' . $res['request_id'] : '') . ")");
        }
        jexit(['success'=>true,'printer'=>($res['printer'] ?? ''),'request_id'=>($res['request_id'] ?? ''),'audit'=>($res['audit'] ?? '')]);

    case 'get_machine_notes':
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_REQUEST['machine_id'] ?? $_REQUEST['id'] ?? 0);
        if (!$mid) jexit(['success'=>false,'msg'=>'Máquina inválida']);
        $notes = [];
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_notes')) {
            $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_notes','WHERE'=>['machine_id'=>$mid],'ORDER'=>['date_creation ASC','id ASC']]);
            foreach ($iter as $r) $notes[] = $r;
        }
        // nomes dos autores em lote
        $uids = array_values(array_unique(array_filter(array_map(fn($n)=> (int)($n['users_id'] ?? 0), $notes))));
        $names = [];
        if (!empty($uids)) {
            foreach ($DB->request(['SELECT'=>['id','name','realname','firstname'],'FROM'=>'glpi_users','WHERE'=>['id'=>$uids]]) as $u) {
                $full = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
                if ($full === '') $full = $u['name'] ?? ('#' . $u['id']);
                $names[(int)$u['id']] = $full;
            }
        }
        foreach ($notes as &$n) {
            $n['user_name'] = ($n['users_id'] && isset($names[(int)$n['users_id']])) ? $names[(int)$n['users_id']] : 'Sistema';
        }
        unset($n);
        jexit(['success'=>true,'notes'=>$notes,'count'=>count($notes)]);

    case 'add_machine_note':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_POST['machine_id'] ?? $_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if (!$mid) jexit(['success'=>false,'msg'=>'Máquina inválida']);
        if ($note === '') jexit(['success'=>false,'msg'=>'Escreva a anotação']);
        $mrow = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        if (!$mrow) jexit(['success'=>false,'msg'=>'Máquina não encontrada']);
        if (!empty($mrow['is_locked'])) jexit(['success'=>false,'msg'=>'Máquina travada — aguardando Chamado criado','locked'=>true]);
        kanpro_need_not_finalized((int)$mrow['plugin_kanpro_cards_id']);
        kanpro_need_not_chamado_locked((int)$mrow['plugin_kanpro_cards_id']);
        $now = date('Y-m-d H:i:s');
        $nid = $DB->insert('glpi_plugin_kanpro_maintenance_notes', [
            'machine_id'    => $mid,
            'users_id'      => kanpro_acting_user_id(),
            'note'          => mb_substr($note, 0, 2000),
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);
        if (!$nid) jexit(['success'=>false,'msg'=>'Falha ao salvar anotação']);
        kanpro_touch_member((int)$mrow['plugin_kanpro_cards_id']);
        $cnt = countElementsInTable('glpi_plugin_kanpro_maintenance_notes', ['machine_id'=>$mid]);
        jexit(['success'=>true,'id'=>$nid,'count'=>$cnt]);

    case 'delete_machine_note':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $nid = (int)($_POST['id'] ?? $_POST['note_id'] ?? 0);
        if (!$nid) jexit(['success'=>false,'msg'=>'Anotação inválida']);
        $nrow = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_notes','WHERE'=>['id'=>$nid]])->current();
        if (!$nrow) jexit(['success'=>false,'msg'=>'Anotação não encontrada']);
        $mid = (int)$nrow['machine_id'];
        try {
            $mrow2 = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
            if ($mrow2) { kanpro_need_not_finalized((int)$mrow2['plugin_kanpro_cards_id']); kanpro_need_not_chamado_locked((int)$mrow2['plugin_kanpro_cards_id']); }
        } catch (Throwable $e) {}
        $DB->delete('glpi_plugin_kanpro_maintenance_notes', ['id'=>$nid]);
        $cnt = $DB->tableExists('glpi_plugin_kanpro_maintenance_notes') ? countElementsInTable('glpi_plugin_kanpro_maintenance_notes', ['machine_id'=>$mid]) : 0;
        jexit(['success'=>true,'count'=>$cnt,'machine_id'=>$mid]);

    case 'retirada_machine':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_POST['id'] ?? $_POST['machine_id'] ?? 0);
        if (!$mid) jexit(['success'=>false,'msg'=>'Máquina inválida']);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Máquina não encontrada']);
        if (!empty($row['is_locked'])) jexit(['success'=>false,'msg'=>'Máquina travada — aguardando Chamado criado','locked'=>true]);
        if (empty($row['is_urgent'])) jexit(['success'=>false,'msg'=>'Apenas máquinas com urgência podem ser retiradas']);
        $cid = (int)$row['plugin_kanpro_cards_id'];
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Card não é de manutenção']);
        kanpro_need_card_editable($cid);
        kanpro_need_not_finalized($cid);
        kanpro_need_not_chamado_locked($cid);
        // cria novo card com mesmo nome/entidade
        $origName = trim($card->fields['name']);
        $newName = mb_substr($origName, 0, 255);
        $newCard = new PluginKanproCard();
        $newId = $newCard->add([
            'plugin_kanpro_boards_id' => $card->fields['plugin_kanpro_boards_id'],
            'plugin_kanpro_lists_id'  => $card->fields['plugin_kanpro_lists_id'],
            'name'        => $newName,
            'description' => $card->fields['description'] ?? '',
        ]);
        if (!$newId) jexit(['success'=>false,'msg'=>'Falha ao criar card de retirada']);
        $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>1,'maintenance_date'=>date('Y-m-d H:i:s'),'maintenance_by'=>kanpro_acting_user_id(),'entities_id'=>(int)($card->fields['entities_id'] ?? 0)], ['id'=>$newId]);
        // move máquina para novo card, re-sequencia como 1 e mantém infos
        $newLabel = "Máquina 1 - {$row['model']}";
        $DB->update('glpi_plugin_kanpro_maintenance_machines', [
            'plugin_kanpro_cards_id'=>$newId,
            'seq'=>1,
            'label'=>$newLabel,
            'date_mod'=>date('Y-m-d H:i:s')
        ], ['id'=>$mid]);
        // re-sequencia card original
        $remaining=[];
        $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach($iter as $r) $remaining[]=$r;
        $seq=1;
        foreach($remaining as $r){
            $newLabel2 = "Máquina {$seq} - {$r['model']}";
            $DB->update('glpi_plugin_kanpro_maintenance_machines', ['seq'=>$seq,'label'=>$newLabel2,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$r['id']]);
            $seq++;
        }
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_retirada', "Máquina #{$row['seq']} ({$row['model']}) retirada para card #{$newId}");
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $newId, $card->fields['plugin_kanpro_lists_id'], 'maintenance_retirada_new', "Card de retirada criado a partir de #{$cid} máquina #{$row['seq']}");
        // cria transferência para assinatura (se plugin disponível)
        $transfer_id = null;
        $assinatura_url = null;
        if ($DB->tableExists('glpi_plugin_assetmgrstatus_transfers') && $DB->tableExists('glpi_plugin_assetmgrstatus_transfer_items')) {
            $board = new PluginKanproBoard();
            $board->getFromDB($card->fields['plugin_kanpro_boards_id']);
            $list = new PluginKanproList();
            $list->getFromDB($card->fields['plugin_kanpro_lists_id']);
            $board_name = $board->fields['name'] ?? 'Quadro';
            $list_name  = $list->fields['name'] ?? 'Lista';
            $entity_dest = (int)($board->fields['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
            $stRaw = mb_strtolower(trim($row['status'] ?? ''), 'UTF-8');
            $status_final = in_array($stRaw, ['garantia','ok','inservivel','pendente']) ? $stRaw : (!empty($stRaw) ? $stRaw : 'pendente');
            $work_status = !empty($row['is_done']) ? 'done' : 'pending';
            $reason = "[KanPro #{$newId} - Retirada Urgência] Quadro: {$board_name} | Lista: {$list_name} | Card origem: #{$cid} {$origName} | Máquina #{$row['seq']} {$row['model']} [{$status_final}] URGÊNCIA | Relatório: " . mb_substr($row['diary'] ?? '',0,300) . " | Gerado em ".date('d/m/Y H:i');
            $now = date('Y-m-d H:i:s');
            $uid = kanpro_acting_user_id();
            $tech_id = (int)($card->fields['maintenance_by'] ?? $uid);
            $DB->insert('glpi_plugin_assetmgrstatus_transfers', [
                'entity_dest'      => $entity_dest,
                'reason'           => $reason,
                'status'           => 'pronto',
                'users_id_created' => $uid,
                'users_id_tech'    => $tech_id,
                'date_pending'     => $now,
                'date_creation'    => $now,
                'date_pronto'      => $now,
            ]);
            $transfer_id = (int)$DB->insertId();
            if (!$transfer_id) {
                $r = $DB->request(['FROM'=>'glpi_plugin_assetmgrstatus_transfers','WHERE'=>['reason'=>$reason],'ORDER'=>'id DESC','LIMIT'=>1])->current();
                $transfer_id = (int)($r['id']??0);
            }
            if ($transfer_id) {
                $DB->insert('glpi_plugin_assetmgrstatus_transfer_items', [
                    'transfers_id'       => $transfer_id,
                    'items_id'           => (int)$mid,
                    'itemtype'           => 'KanPro',
                    'item_name'          => $row['label'] . ' - ' . $row['model'],
                    'origin_entity_id'   => $entity_dest,
                    'origin_entity_name' => $origName,
                    'final_status'       => $status_final,
                    'final_reason'       => $row['diary'] ?? '',
                    'final_components'   => json_encode(!empty(trim($row['diary'] ?? '')) ? ['diario'=>trim($row['diary'])] : [], JSON_UNESCAPED_UNICODE),
                    'work_log'           => $row['diary'] ?? '',
                    'work_components'    => json_encode([], JSON_UNESCAPED_UNICODE),
                    'work_status'        => $work_status,
                ]);
                try{ \GlpiPlugin\Assetmgrstatus\Transfer::logStatus($transfer_id, 'pronto', "KanPro Retirada Urgência: Máquina #{$row['seq']} '{$row['model']}' de #{$cid} para #{$newId}"); }catch(Throwable $e){}
                $base = Plugin::getWebDir('assetmgrstatus');
                if(!$base) $base = '/plugins/assetmgrstatus';
                $assinatura_url = $base.'/front/assinatura.php?f=pendente&highlight='.$transfer_id;
            }
        }
        // WhatsApp RETIRADA (máquinas prontas no card novo) — nunca quebra o fluxo
        if ($newId && class_exists('PluginKanproMaintenanceZap')) {
            try { PluginKanproMaintenanceZap::sendOnce('retirada', (int)$newId); } catch (Throwable $e) {}
        }
        jexit(['success'=>true,'new_card_id'=>$newId,'transfer_id'=>$transfer_id,'assinatura_url'=>$assinatura_url,'msg'=>'Retirada criada']);

    case 'revert_maintenance':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Não é manutenção']);
        // reverter deixaria um card normal travado na lista Pendente (sem como editar) — não deixa
        kanpro_need_card_editable($cid);
        kanpro_need_not_finalized($cid);
        kanpro_need_not_chamado_locked($cid);
        if (!kanpro_verify_password($password)) jexit(['success'=>false,'msg'=>'Senha incorreta']);
        // captura dados p/ WhatsApp CANCELADO antes de limpar
        $zapData = null;
        if (class_exists('PluginKanproMaintenanceZap')) {
            try { $zapData = PluginKanproMaintenanceZap::baseData($cid); } catch (Throwable $e) {}
        }
        $zapMotivo = trim($_POST['motivo'] ?? '');
        $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>0,'maintenance_date'=>null,'maintenance_by'=>0], ['id'=>$cid]);
        // opcional: manter máquinas para histórico, mas aqui mantém; se quiser apagar, descomente:
        // $DB->delete('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cid]);
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_revert', "Manutenção revertida");
        // WhatsApp CANCELADO (recebimento por engano — desconsidere)
        if ($zapData !== null) {
            try {
                $zapData['motivo'] = $zapMotivo !== '' ? $zapMotivo : 'Registro por engano';
                PluginKanproMaintenanceZap::sendOnce('cancelado', $cid, $zapData);
            } catch (Throwable $e) {}
        }
        jexit(['success'=>true]);

    case 'get_maintenance_term_data':
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_REQUEST['cards_id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $data = PluginKanproCard::getFullData($cid);
        if (!$data) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        // inclui máquinas
        $machines=[];
        $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach($iter as $r) $machines[]=$r;
        $total=count($machines);
        $done=0;
        foreach($machines as $m) if($m['is_done']) $done++;
        $percent=$total?round($done/$total*100):0;
        $canGenerate = ($total>0 && $done===$total);
        jexit(['success'=>true,'is_maintenance'=>!empty($data['is_maintenance'])?1:0,'card'=>$data,'machines'=>$machines,'progress'=>['total'=>$total,'done'=>$done,'percent'=>$percent,'canGenerate'=>$canGenerate]]);

    case 'finalize_maintenance':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? $_POST['id'] ?? 0);
        $force = !empty($_POST['force']);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Este cartão não é de manutenção']);
        // card na lista Pendente = atendimento nem começou; o caminho é Pegar
        kanpro_need_card_editable($cid);
        // autenticação por palavra (mesmo desafio da conversão p/ manutenção e do Pegar)
        $confirm = $_POST['confirm_text'] ?? $_POST['confirm'] ?? '';
        if (!kanpro_maint_challenge_ok((string)$confirm)) {
            jexit(['success'=>false,'msg'=>'Palavra de confirmação inválida. Digite exatamente a palavra desafio exibida (sem acento).','need_confirm'=>true]);
        }
        $machines = [];
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
            foreach ($iter as $r) $machines[] = $r;
        }
        $total = count($machines);
        if ($total===0) jexit(['success'=>false,'msg'=>'Nenhuma máquina cadastrada. Configure as máquinas antes de finalizar.']);
        // trava total: se há máquina aguardando chamado, não finaliza
        $locked = array_values(array_filter($machines, function ($m) { return !empty($m['is_locked']); }));
        if (!empty($locked)) {
            $seqs = implode(', #', array_slice(array_map(function ($m) { return (int)$m['seq']; }, $locked), 0, 10));
            jexit(['success'=>false,'msg'=>'Há ' . count($locked) . ' máquina(s) travada(s) aguardando Chamado criado (#' . $seqs . '). Libere antes de finalizar.','locked'=>true]);
        }
        // Validação obrigatória: Status Final não pode ficar em branco
        $missing = [];
        foreach ($machines as $m) {
            $st = trim($m['status'] ?? '');
            if ($st === '') $missing[] = $m['seq'];
        }
        if (!empty($missing)) {
            $list = implode(', ', array_slice($missing,0,10));
            if (count($missing)>10) $list .= ' ... (+'.(count($missing)-10).')';
            jexit(['success'=>false,'msg'=>"Selecione o Status Final de todas as máquinas antes de finalizar. Faltam: #". $list . " (" . count($missing) . "/" . $total . ")",'need_status'=>true,'missing'=>$missing,'progress'=>['total'=>$total,'missing'=>count($missing)]]);
        }
        // Classifica por status — pendente vai para novo card
        $pendingMachines = [];
        $nonPending = [];
        // normaliza contadores por status
        $cntGarantia=0; $cntOk=0; $cntInservivel=0; $cntPendente=0;
        foreach ($machines as $m) {
            $st = mb_strtolower(trim($m['status'] ?? ''), 'UTF-8');
            if ($st==='pending') $st='pendente';
            if ($st==='defect' || $st==='defeito' || $st==='nok') $st='inservivel';
            if ($st==='pendente') { $pendingMachines[]=$m; $cntPendente++; }
            elseif ($st==='garantia') { $nonPending[]=$m; $cntGarantia++; }
            elseif ($st==='ok') { $nonPending[]=$m; $cntOk++; }
            elseif ($st==='inservivel') { $nonPending[]=$m; $cntInservivel++; }
            else { // fallback trata como pendente
                $pendingMachines[]=$m; $cntPendente++;
            }
        }
        $pendingCount = count($pendingMachines);
        $nonCount = count($nonPending);
        $done = 0;
        foreach ($machines as $m){ if(!empty($m['is_done'])) $done++; }
        $doneNon = 0;
        foreach ($nonPending as $m){ if(!empty($m['is_done'])) $doneNon++; }
        $percent = $total? round($done/$total*100):0;
        $percentNon = $nonCount? round($doneNon/$nonCount*100):0;
        // ---------- FLUXO TABLET (2 finalizares) ----------
        // Card 100% Tablet/Smartphone/Celular: 1º Finalizar cria Pendência Chamado
        // (trava + zap), 2º Finalizar (após liberado) vai p/ Assinatura/Retirada.
        $isTabletOnly = false;
        try {
            if (function_exists('kanpro_card_tablet_info')) {
                $tiFin = kanpro_card_tablet_info($cid);
                $isTabletOnly = !empty($tiFin['is_tablet_only']);
            }
        } catch (Throwable $e) {}
        if ($isTabletOnly) {
            $tabPendSt = function_exists('kanpro_tablet_pendencia_status') ? kanpro_tablet_pendencia_status($cid) : 'none';
            if ($tabPendSt === 'pendente') {
                jexit(['success'=>false,'msg'=>'Card Tablet aguardando Chamado criado na Pendência Chamado. Libere antes de finalizar novamente.','tablet_waiting'=>true,'tablet'=>true]);
            }
            if ($tabPendSt === 'none') {
                // 1º Finalizar do Tablet: valida como normal, mas cria pendência em vez de termo
                if ($pendingCount>0 && $nonCount===0) {
                    // tudo pendente: só separa p/ novo card, sem pendência ainda (novo card sem status)
                    $origNameT = trim($card->fields['name']);
                    $newNameT = mb_substr($origNameT, 0, 255);
                    $newCardT = new PluginKanproCard();
                    $newIdT = $newCardT->add(['plugin_kanpro_boards_id'=>$card->fields['plugin_kanpro_boards_id'],'plugin_kanpro_lists_id'=>$card->fields['plugin_kanpro_lists_id'],'name'=>$newNameT,'description'=>($card->fields['description'] ?? '')]);
                    if (!$newIdT) jexit(['success'=>false,'msg'=>'Falha ao criar card de pendentes']);
                    $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>1,'maintenance_date'=>date('Y-m-d H:i:s'),'maintenance_by'=>kanpro_acting_user_id(),'entities_id'=>(int)($card->fields['entities_id'] ?? 0)], ['id'=>$newIdT]);
                    $seq=1;
                    foreach ($pendingMachines as $pm) {
                        $DB->update('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$newIdT,'seq'=>$seq,'label'=>"Máquina {$seq} - {$pm['model']}",'is_done'=>0,'is_ok'=>0,'status'=>'','diary'=>'','is_inventoried'=>0,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$pm['id']]);
                        $seq++;
                    }
                    PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $newIdT, $card->fields['plugin_kanpro_lists_id'], 'maintenance_pending_split', "Card Tablet de pendentes criado a partir de #{$cid} com {$pendingCount} máquinas");
                    jexit(['success'=>true,'pending_only'=>true,'tablet'=>true,'pending_card_id'=>$newIdT,'pending_count'=>$pendingCount,'msg'=>"Card Tablet: todas como Pendente. Novo card #{$newIdT} criado. Faça a manutenção e finalize novamente.",'progress'=>['total'=>$total,'pending'=>$pendingCount]]);
                }
                if ($nonCount>0 && !$force && $doneNon!==$nonCount) {
                    jexit(['success'=>false,'msg'=>"Card Tablet: conclua 'Feito' de todos os itens antes do 1º Finalizar (não pendentes: {$doneNon}/{$nonCount} • {$percentNon}%). Pendentes ({$pendingCount}) ficarão em novo card.",'need_100'=>true,'tablet'=>true,'progress'=>['total'=>$nonCount,'done'=>$doneNon,'percent'=>$percentNon,'pending'=>$pendingCount]]);
                }
                // separa pendentes (se houver) p/ novo card SEM auto-pendência
                $tabletPendingCardId = null;
                if ($pendingCount>0) {
                    $origNameT2 = trim($card->fields['name']);
                    $newCardT2 = new PluginKanproCard();
                    $newIdT2 = $newCardT2->add(['plugin_kanpro_boards_id'=>$card->fields['plugin_kanpro_boards_id'],'plugin_kanpro_lists_id'=>$card->fields['plugin_kanpro_lists_id'],'name'=>mb_substr($origNameT2,0,255),'description'=>($card->fields['description'] ?? '')]);
                    if ($newIdT2) {
                        $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>1,'maintenance_date'=>date('Y-m-d H:i:s'),'maintenance_by'=>kanpro_acting_user_id(),'entities_id'=>(int)($card->fields['entities_id'] ?? 0)], ['id'=>$newIdT2]);
                        $seq=1;
                        foreach ($pendingMachines as $pm) {
                            $DB->update('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$newIdT2,'seq'=>$seq,'label'=>"Máquina {$seq} - {$pm['model']}",'is_done'=>0,'is_ok'=>0,'status'=>'','diary'=>'','is_inventoried'=>0,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$pm['id']]);
                            $seq++;
                        }
                        $tabletPendingCardId = $newIdT2;
                        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $newIdT2, $card->fields['plugin_kanpro_lists_id'], 'maintenance_pending_split', "Card Tablet de pendentes #{$newIdT2} criado com {$pendingCount} máquinas de #{$cid} (1º Finalizar Tablet)");
                        // re-sequencia origem (só não-pendentes)
                        $remT = [];
                        $iterT = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
                        foreach ($iterT as $r) $remT[] = $r;
                        usort($remT, fn($a,$b)=> $a['seq']<=>$b['seq']);
                        $s=1;
                        foreach ($remT as $rm) { $DB->update('glpi_plugin_kanpro_maintenance_machines', ['seq'=>$s,'label'=>"Máquina {$s} - {$rm['model']}"], ['id'=>$rm['id']]); $s++; }
                    }
                }
                // cria Pendência Chamado com as máquinas restantes (trava + zap pendência)
                $restMids = [];
                try {
                    foreach ($DB->request(['SELECT'=>['id'],'FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid]]) as $rm2) $restMids[] = (int)$rm2['id'];
                } catch (Throwable $e) {}
                if (empty($restMids)) jexit(['success'=>false,'msg'=>'Nenhuma máquina restante para pendência (verifique o split).']);
                $tabPend = function_exists('kanpro_create_pendencia_chamado') ? kanpro_create_pendencia_chamado((int)$card->fields['plugin_kanpro_boards_id'], (int)$cid, $restMids, kanpro_acting_user_id(), 'Finalizar Tablet (1º)') : ['pendencia_id'=>0,'warning'=>'fluxo indisponível'];
                if (empty($tabPend['pendencia_id'])) {
                    jexit(['success'=>false,'msg'=>'Falha ao criar Pendência Chamado do Tablet: ' . ($tabPend['warning'] ?? $tabPend['zap_error'] ?? 'erro')]);
                }
                $finTidTab = function_exists('kanpro_card_ticket_id') ? kanpro_card_ticket_id($cid) : 0;
                if ($finTidTab) {
                    try { kanpro_ticket_followup($finTidTab, "[KanPro] Tablet 1o Finalizar\n\nManutencao concluida no KanPro. Pendencia Chamado #{$tabPend['pendencia_id']} criada (" . count($restMids) . " maquina(s)). Aguardando Chamado criado no CRM para liberar e finalizar novamente."); } catch (Throwable $e) {}
                }
                $respTab = ['success'=>true,'tablet_first'=>true,'tablet'=>true,'pendencia_id'=>$tabPend['pendencia_id'],'pendencia_locked'=>($tabPend['locked'] ?? count($restMids)),'zap_ok'=>!empty($tabPend['zap_ok']),'zap_error'=>($tabPend['zap_error'] ?? ''),'msg'=>"Tablet: 1º Finalizar criou a Pendência Chamado #{$tabPend['pendencia_id']} (" . count($restMids) . " máquina(s) travadas). Aguarde o Chamado criado no CRM (zap para o técnico) e finalize novamente para ir à Assinatura/Retirada."];
                if ($tabletPendingCardId) { $respTab['pending_card_id'] = $tabletPendingCardId; $respTab['pending_count'] = $pendingCount; }
                jexit($respTab);
            }
            // 'liberado': cai no fluxo normal abaixo (2º Finalizar -> Assinatura/Retirada)
        }
        // Se existem pendentes e nenhum item finalizável, apenas cria card de pendentes
        if ($pendingCount>0 && $nonCount===0) {
            // Cria novo card com todos os pendentes (nome igual à entidade, sem sufixo)
            $origName = trim($card->fields['name']);
            $newName = mb_substr($origName, 0, 255);
            $newCard = new PluginKanproCard();
            $newId = $newCard->add([
                'plugin_kanpro_boards_id' => $card->fields['plugin_kanpro_boards_id'],
                'plugin_kanpro_lists_id'  => $card->fields['plugin_kanpro_lists_id'],
                'name'        => $newName,
                'description' => $card->fields['description'] ?? '',
            ]);
            if (!$newId) jexit(['success'=>false,'msg'=>'Falha ao criar card de pendentes']);
            // garante que novo card também é manutenção
            $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>1,'maintenance_date'=>date('Y-m-d H:i:s'),'maintenance_by'=>kanpro_acting_user_id(),'entities_id'=>(int)($card->fields['entities_id'] ?? 0)], ['id'=>$newId]);
            // move pendentes para novo card com seq 1..N e zera Feito/Status/Relatório/Inventário
            $seq=1;
            foreach ($pendingMachines as $pm) {
                $newLabel = "Máquina {$seq} - {$pm['model']}";
                $DB->update('glpi_plugin_kanpro_maintenance_machines', [
                    'plugin_kanpro_cards_id'=>$newId,
                    'seq'=>$seq,
                    'label'=>$newLabel,
                    'is_done'=>0,
                    'is_ok'=>0,
                    'status'=>'',
                    'diary'=>'',
                    'is_inventoried'=>0,
                    'date_mod'=>date('Y-m-d H:i:s')
                ], ['id'=>$pm['id']]);
                $seq++;
            }
            PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $newId, $card->fields['plugin_kanpro_lists_id'], 'maintenance_pending_split', "Card de pendentes criado a partir de #{$cid} com {$pendingCount} máquinas");
            PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_pending_split', "Máquinas pendentes movidas para #{$newId} ({$pendingCount}) — card original ficou vazio");
            $splitTid = kanpro_card_ticket_id($cid);
            if ($splitTid) kanpro_ticket_followup($splitTid, "⚙ [KanPro] Máquinas pendentes\n\nTodas as máquinas estavam com status Pendente e foram movidas para o cartão #{$newId} ({$pendingCount} máquinas).\n\nNenhum termo foi gerado.");
            // Admin do quadro: novo card de pendentes já entra no fluxo Pendência Chamado (trava + zap)
            $splitPend = ['pendencia_id'=>0,'locked'=>0,'zap_ok'=>false,'zap_error'=>'','warning'=>''];
            if (function_exists('kanpro_can_manage_members') && kanpro_can_manage_members((int)$card->fields['plugin_kanpro_boards_id'])) {
                $splitPend = kanpro_create_pendencia_chamado((int)$card->fields['plugin_kanpro_boards_id'], (int)$newId, array_column($pendingMachines, 'id'), kanpro_acting_user_id(), 'Finalizar');
            }
            $splitResp = ['success'=>true,'pending_only'=>true,'pending_card_id'=>$newId,'pending_count'=>$pendingCount,'msg'=>"Todas as máquinas estavam como Pendente. Novo card #{$newId} criado com {$pendingCount} pendentes. Nenhum termo gerado para levar.",'progress'=>['total'=>$total,'pending'=>$pendingCount]];
            if (!empty($splitPend['pendencia_id'])) { $splitResp['pendencia_id'] = $splitPend['pendencia_id']; $splitResp['pendencia_locked'] = $splitPend['locked']; $splitResp['zap_ok'] = $splitPend['zap_ok']; $splitResp['zap_error'] = $splitPend['zap_error']; }
            elseif (!empty($splitPend['warning']) && function_exists('kanpro_can_manage_members') && kanpro_can_manage_members((int)$card->fields['plugin_kanpro_boards_id'])) { $splitResp['pendencia_warning'] = $splitPend['warning']; }
            jexit($splitResp);
        }
        // Valida progresso 100% apenas para itens que vão para o termo (não pendentes)
        if ($nonCount>0 && !$force && $doneNon!==$nonCount) {
            jexit(['success'=>false,'msg'=>"Conclua 'Feito' de todos os itens que vão para o termo antes de finalizar (não pendentes: {$doneNon}/{$nonCount} • {$percentNon}%). Pendentes ({$pendingCount}) ficarão em novo card.",'need_100'=>true,'progress'=>['total'=>$nonCount,'done'=>$doneNon,'percent'=>$percentNon,'pending'=>$pendingCount]]);
        }
        // verifica se assetmgrstatus está disponível (tabelas) — só necessário se houver itens para levar
        if ($nonCount>0 && (!$DB->tableExists('glpi_plugin_assetmgrstatus_transfers') || !$DB->tableExists('glpi_plugin_assetmgrstatus_transfer_items'))) {
            jexit(['success'=>false,'msg'=>'Plugin Assinatura (assetmgrstatus) não encontrado. Use Gerar Termo local.','need_fallback'=>true,'progress'=>['total'=>$total,'done'=>$done,'percent'=>$percent]]);
        }
        // evita duplicidade: se já existe transferência KanPro para este card, reutiliza (só se não há pendentes a separar)
        if ($pendingCount===0) {
            $existing = null;
            try{
                $like = "%[KanPro #{$cid}]%";
                $iter = $DB->request(['FROM'=>'glpi_plugin_assetmgrstatus_transfers','WHERE'=>['reason'=>['LIKE',$like]],'ORDER'=>'id DESC','LIMIT'=>1]);
                if($iter->count()>0) $existing = $iter->current();
            }catch(Throwable $e){}
            if($existing){
                $transfer_id = (int)$existing['id'];
                try {
                    $firstIt = $DB->request(['FROM'=>'glpi_plugin_assetmgrstatus_transfer_items','WHERE'=>['transfers_id'=>$transfer_id],'ORDER'=>'id ASC','LIMIT'=>1])->current();
                    if ($firstIt && trim($firstIt['origin_entity_name'] ?? '') !== trim($card->fields['name'] ?? '') && trim($card->fields['name'] ?? '') !== '') {
                        $DB->update('glpi_plugin_assetmgrstatus_transfer_items', ['origin_entity_name' => mb_substr($card->fields['name'],0,255)], ['transfers_id'=>$transfer_id]);
                    }
                } catch(Throwable $e) {}
                $base = Plugin::getWebDir('assetmgrstatus');
                if(!$base) $base = '/plugins/assetmgrstatus';
                $assinatura_url = $base.'/front/assinatura.php?f=pendente&highlight='.$transfer_id;
                $pdf_url = $base.'/front/transfer_pdf.php?id='.$transfer_id.'&stage=pronto';
                // WhatsApp RETIRADA (termo já existia — trava duplicado pelo zaplog)
                if (class_exists('PluginKanproMaintenanceZap')) {
                    try { PluginKanproMaintenanceZap::sendOnce('retirada', $cid); } catch (Throwable $e) {}
                }
                // card com termo vai para a lista Retirada (se existir)
                $retMove = kanpro_move_card_to_retirada($cid);
                $existResp = ['success'=>true,'transfer_id'=>$transfer_id,'assinatura_url'=>$assinatura_url,'pdf_url'=>$pdf_url,'msg'=>'Já existe termo para este card','existing'=>true];
                if (!empty($retMove['moved'])) { $existResp['moved_to_retirada'] = true; $existResp['retirada_lists_id'] = $retMove['lists_id']; $existResp['retirada_list_name'] = $retMove['list_name']; }
                jexit($existResp);
            }
        }
        // Se há pendentes, cria novo card com pendentes ANTES de gerar termo (nome igual, campos zerados)
        $pendingCardId = null;
        $pendenciaSplit = ['pendencia_id'=>0,'locked'=>0,'zap_ok'=>false,'zap_error'=>'','warning'=>''];
        if ($pendingCount>0) {
            $origName = trim($card->fields['name']);
            $newName = mb_substr($origName, 0, 255);
            $newCard = new PluginKanproCard();
            $newId = $newCard->add([
                'plugin_kanpro_boards_id' => $card->fields['plugin_kanpro_boards_id'],
                'plugin_kanpro_lists_id'  => $card->fields['plugin_kanpro_lists_id'],
                'name'        => $newName,
                'description' => $card->fields['description'] ?? '',
            ]);
            if ($newId) {
                $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>1,'maintenance_date'=>date('Y-m-d H:i:s'),'maintenance_by'=>kanpro_acting_user_id()], ['id'=>$newId]);
                $seq=1;
                foreach ($pendingMachines as $pm) {
                    $newLabel = "Máquina {$seq} - {$pm['model']}";
                    $DB->update('glpi_plugin_kanpro_maintenance_machines', [
                        'plugin_kanpro_cards_id'=>$newId,
                        'seq'=>$seq,
                        'label'=>$newLabel,
                        'is_done'=>0,
                        'is_ok'=>0,
                        'status'=>'',
                        'diary'=>'',
                        'is_inventoried'=>0,
                        'date_mod'=>date('Y-m-d H:i:s')
                    ], ['id'=>$pm['id']]);
                    $seq++;
                }
                $pendingCardId = $newId;
                PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $newId, $card->fields['plugin_kanpro_lists_id'], 'maintenance_pending_split', "Card de pendentes #{$newId} criado com {$pendingCount} máquinas de #{$cid}");
                // Admin do quadro: novo card de pendentes já entra no fluxo Pendência Chamado (trava + zap)
                if (function_exists('kanpro_can_manage_members') && kanpro_can_manage_members((int)$card->fields['plugin_kanpro_boards_id'])) {
                    $pendenciaSplit = kanpro_create_pendencia_chamado((int)$card->fields['plugin_kanpro_boards_id'], (int)$newId, array_column($pendingMachines, 'id'), kanpro_acting_user_id(), 'Finalizar');
                }
            }
            // re-sequencia card original (não pendentes) 1..N
            $remaining = $nonPending;
            usort($remaining, fn($a,$b)=> $a['seq']<=>$b['seq']);
            $seq=1;
            foreach ($remaining as $rm) {
                $newLabel = "Máquina {$seq} - {$rm['model']}";
                $DB->update('glpi_plugin_kanpro_maintenance_machines', ['seq'=>$seq,'label'=>$newLabel,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$rm['id']]);
                $seq++;
            }
            // atualiza array máquinas para termo (apenas não pendentes, já re-sequenciadas em memória)
            $machines = [];
            $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
            foreach ($iter as $r) $machines[] = $r;
            $total = count($machines); // agora é nonCount
        }
        // Se após split não há itens para termo (caso já tratado all-pendente), sai
        if (empty($machines) || $nonCount===0) {
            $splitTid2 = kanpro_card_ticket_id($cid);
            if ($splitTid2) kanpro_ticket_followup($splitTid2, "⚙ [KanPro] Máquinas pendentes\n\nOs itens pendentes foram movidos para o cartão #{$pendingCardId} ({$pendingCount} máquinas).\n\nNenhum termo foi gerado.");
            jexit(['success'=>true,'pending_card_id'=>$pendingCardId,'pending_count'=>$pendingCount,'msg'=>"Pendentes movidos para card #{$pendingCardId}. Nenhum termo gerado.","pending_only"=>true]);
        }
        // Bloco legado: só roda se o split acima não gerou card (evita duplicar o novo card)
        if ($pendingCount > 0 && $pendingCardId === null) {
            $newCard = new PluginKanproCard();
            $newName = $card->fields['name'] . ' - Pendentes ('.$pendingCount.')';
            $newName = mb_substr($newName, 0, 255);
            $newId = $newCard->add([
                'plugin_kanpro_boards_id' => $card->fields['plugin_kanpro_boards_id'],
                'plugin_kanpro_lists_id'  => $card->fields['plugin_kanpro_lists_id'],
                'name'        => $newName,
                'description' => $card->fields['description'] ?? '',
            ]);
            if ($newId) {
                $pendingCardId = (int)$newId;
                // marca como manutenção
                $DB->update('glpi_plugin_kanpro_cards', [
                    'is_maintenance'   => 1,
                    'maintenance_date' => date('Y-m-d H:i:s'),
                    'maintenance_by'   => kanpro_acting_user_id(),
                    'entities_id'      => (int)($card->fields['entities_id'] ?? 0),
                    'date_mod'         => date('Y-m-d H:i:s')
                ], ['id' => $pendingCardId]);
                // copia máquinas pendentes para novo card re-sequenciando 1..N
                $seq = 0;
                $now2 = date('Y-m-d H:i:s');
                $uid2 = kanpro_acting_user_id();
                foreach ($pendingMachines as $pm) {
                    $seq++;
                    $DB->insert('glpi_plugin_kanpro_maintenance_machines', [
                        'plugin_kanpro_cards_id' => $pendingCardId,
                        'seq'                    => $seq,
                        'model'                  => $pm['model'],
                        'label'                  => "Máquina {$seq} - {$pm['model']}",
                        'diary'                  => $pm['diary'] ?? '',
                        'is_done'                => $pm['is_done'] ?? 0,
                        'is_ok'                  => $pm['is_ok'] ?? 0,
                        'status'                 => 'pendente',
                        'users_id'               => $uid2,
                        'date_creation'          => $now2,
                        'date_mod'               => $now2,
                    ]);
                }
                PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $pendingCardId, $card->fields['plugin_kanpro_lists_id'], 'card_create', "Card pendente criado a partir de #{$cid} com {$pendingCount} máquina(s) pendente(s)");
                PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_pending_split', "Manutenção: {$pendingCount} pendente(s) movido(s) para card #{$pendingCardId}");
                // remove pendentes do card original (movido, não duplicado)
                $pendingIds = array_column($pendingMachines, 'id');
                if (!empty($pendingIds)) {
                    $DB->delete('glpi_plugin_kanpro_maintenance_machines', ['id' => $pendingIds]);
                    // re-sequencia restantes do card original
                    $remaining = [];
                    $iter2 = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
                    foreach ($iter2 as $r) $remaining[]=$r;
                    $s=1;
                    foreach ($remaining as $r) {
                        $DB->update('glpi_plugin_kanpro_maintenance_machines', ['seq'=>$s,'label'=>"Máquina {$s} - {$r['model']}"], ['id'=>$r['id']]);
                        $s++;
                    }
                }
                // Admin do quadro: novo card de pendentes já entra no fluxo Pendência Chamado (trava + zap)
                if (function_exists('kanpro_can_manage_members') && kanpro_can_manage_members((int)$card->fields['plugin_kanpro_boards_id'])) {
                    $newMids = [];
                    foreach ($DB->request(['SELECT'=>['id'],'FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$pendingCardId]]) as $nmr) $newMids[] = (int)$nmr['id'];
                    $pendenciaSplit = kanpro_create_pendencia_chamado((int)$card->fields['plugin_kanpro_boards_id'], (int)$pendingCardId, $newMids, kanpro_acting_user_id(), 'Finalizar');
                }
                // atualiza variáveis para transferência: apenas não-pendentes
                $machines = $nonPending;
                $total = count($machines);
                if ($total===0) {
                    // todos eram pendentes — não gera transferência, apenas informa novo card
                    $allPendResp = ['success'=>true,'msg'=>"Todos os itens estavam como Pendente. Criado novo card #{$pendingCardId} com {$pendingCount} máquina(s). Nenhum termo gerado para o card atual.",'pending_card_id'=>$pendingCardId,'pending_count'=>$pendingCount,'all_pending'=>true];
                    if (!empty($pendenciaSplit['pendencia_id'])) { $allPendResp['pendencia_id'] = $pendenciaSplit['pendencia_id']; $allPendResp['pendencia_locked'] = $pendenciaSplit['locked']; $allPendResp['zap_ok'] = $pendenciaSplit['zap_ok']; $allPendResp['zap_error'] = $pendenciaSplit['zap_error']; }
                    elseif (!empty($pendenciaSplit['warning']) && function_exists('kanpro_can_manage_members') && kanpro_can_manage_members((int)$card->fields['plugin_kanpro_boards_id'])) { $allPendResp['pendencia_warning'] = $pendenciaSplit['warning']; }
                    jexit($allPendResp);
                }
                // recalcula done/ok para não-pendentes
                $done=0; $okCount=0;
                foreach ($machines as $m){ if(!empty($m['is_done'])) $done++; if(($m['status']??'')==='ok') $okCount++; }
            }
        }
        // dados do quadro/lista para razão e entidade — usa $total já ajustado (após split)
        $board = new PluginKanproBoard();
        $board->getFromDB($card->fields['plugin_kanpro_boards_id']);
        $list = new PluginKanproList();
        $list->getFromDB($card->fields['plugin_kanpro_lists_id']);
        $board_name = $board->fields['name'] ?? 'Quadro';
        $list_name  = $list->fields['name'] ?? 'Lista';
        $entity_dest = (int)($board->fields['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
        // recalcula done/ok etc para termo (já só nonPending)
        $doneTerm = 0; $cntGarantiaTerm=0; $cntOkTerm=0; $cntInservivelTerm=0;
        foreach ($machines as $m){ if(!empty($m['is_done'])) $doneTerm++; $st=mb_strtolower(trim($m['status']??''),'UTF-8'); if($st==='garantia') $cntGarantiaTerm++; elseif($st==='ok') $cntOkTerm++; elseif($st==='inservivel') $cntInservivelTerm++; }
        $reason = "[KanPro #{$cid}] Quadro: {$board_name} | Lista: {$list_name} | Card: {$card->fields['name']} | Manutenção: {$total} máquinas ({$doneTerm} concluídas | Garantia:{$cntGarantiaTerm} Ok:{$cntOkTerm} Inservível:{$cntInservivelTerm} Pendente:{$pendingCount}→card #{$pendingCardId}) | Gerado em ".date('d/m/Y H:i');
        if(!empty($card->fields['description'])) $reason .= " | Desc: ".mb_substr($card->fields['description'],0,300);
        $summary = [];
        foreach(array_slice($machines,0,5) as $m){ $summary[] = "#{$m['seq']} {$m['model']} [{$m['status']}]: ".mb_substr($m['diary']??'',0,60); }
        if(count($machines)>5) $summary[] = "... e mais ".(count($machines)-5)." máquinas";
        if($summary) $reason .= " | Máquinas: ".implode("; ", $summary);
        if($pendingCount>0) $reason .= " | Pendentes movidos para card #{$pendingCardId} ({$pendingCount})";
        $now = date('Y-m-d H:i:s');
        $uid = kanpro_acting_user_id();
        $tech_id = (int)($card->fields['maintenance_by'] ?? $uid);
        // Nome do Recebedor fica vazio por padrão — preenchido apenas no momento da assinatura via tablet/lote
        $transfer_data = [
            'entity_dest'      => $entity_dest,
            'reason'           => $reason,
            'status'           => 'pronto',
            'users_id_created' => $uid,
            'users_id_tech'    => $tech_id,
            'date_pending'     => $now,
            'date_creation'    => $now,
            'date_pronto'      => $now,
        ];
        $DB->insert('glpi_plugin_assetmgrstatus_transfers', $transfer_data);
        $transfer_id = (int)$DB->insertId();
        if(!$transfer_id){
            $row = $DB->request(['FROM'=>'glpi_plugin_assetmgrstatus_transfers','WHERE'=>['reason'=>$reason],'ORDER'=>'id DESC','LIMIT'=>1])->current();
            $transfer_id = (int)($row['id']??0);
        }
        if(!$transfer_id) jexit(['success'=>false,'msg'=>'Falha ao criar transferência no Assinatura']);
        $origin_entity_id = $entity_dest;
        $origin_entity_name = $card->fields['name'] ?? $board_name;
        foreach($machines as $m){
            $stRaw = mb_strtolower(trim($m['status'] ?? ''), 'UTF-8');
            if ($stRaw==='pending') $stRaw='pendente';
            if ($stRaw==='defect' || $stRaw==='defeito' || $stRaw==='nok') $stRaw='inservivel';
            $status_final = 'pendente';
            if ($stRaw==='garantia') $status_final='garantia';
            elseif ($stRaw==='ok') $status_final='ok';
            elseif ($stRaw==='inservivel') $status_final='inservivel';
            elseif ($stRaw==='pendente') $status_final='pendente';
            elseif (!empty($m['is_ok'])) $status_final='ok';
            $work_status = !empty($m['is_done']) ? 'done' : 'pending';
            $DB->insert('glpi_plugin_assetmgrstatus_transfer_items', [
                'transfers_id'       => $transfer_id,
                'items_id'           => (int)$m['id'],
                'itemtype'           => 'KanPro',
                'item_name'          => $m['label'] . ' - ' . $m['model'],
                'origin_entity_id'   => $origin_entity_id,
                'origin_entity_name' => $origin_entity_name,
                'final_status'       => $status_final,
                'final_reason'       => $m['diary'] ?? '',
                'final_components'   => json_encode(!empty(trim($m['diary'] ?? '')) ? ['diario' => trim($m['diary'])] : [], JSON_UNESCAPED_UNICODE),
                'work_log'           => $m['diary'] ?? '',
                'work_components'    => json_encode([], JSON_UNESCAPED_UNICODE),
                'work_status'        => $work_status,
            ]);
        }
        try{ \GlpiPlugin\Assetmgrstatus\Transfer::logStatus($transfer_id, 'pronto', "KanPro Finalizado: Card #{$cid} '{$card->fields['name']}' — {$total} máquinas (Garantia:{$cntGarantiaTerm} Ok:{$cntOkTerm} Inservível:{$cntInservivelTerm}) pendentes→#{$pendingCardId}"); }catch(Throwable $e){}
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_finalize', "Manutenção finalizada e enviada para Assinatura #{$transfer_id} ({$total} itens) pendentes→#{$pendingCardId}");
        // quem finalizou vira membro do cartão
        kanpro_touch_member($cid);
        // espelha no chamado vinculado: atribui quem finalizou, relatório completo + soluciona
        $finTid = kanpro_card_ticket_id($cid);
        if ($finTid) {
            $finUid = kanpro_acting_user_id();
            kanpro_ticket_assign($finTid, $finUid);
            $finName = '';
            try { $fu = new User(); if ($fu->getFromDB($finUid)) $finName = $fu->getFriendlyName(); } catch (Throwable $e) {}
            $techName = '';
            try { $tu = new User(); if ($tu->getFromDB($tech_id)) $techName = $tu->getFriendlyName(); } catch (Throwable $e) {}
            $finMsg = "✅ [KanPro] Manutenção finalizada\n\n"
                . "Cartão #{$cid} '" . ($card->fields['name'] ?? '') . "'\n"
                . 'Quadro: ' . $board_name . "\n"
                . 'Lista: ' . $list_name . "\n"
                . ($techName !== '' ? 'Técnico responsável: ' . $techName . "\n" : '')
                . ($finName !== '' ? 'Finalizado por: ' . $finName . "\n" : '')
                . "\nResumo do atendimento: {$total} máquinas (Garantia: {$cntGarantiaTerm} | OK: {$cntOkTerm} | Inservível: {$cntInservivelTerm})\n"
                . ($pendingCount > 0 ? "{$pendingCount} item(ns) pendente(s) transferido(s) para o cartão #{$pendingCardId}.\n" : '')
                . "Termo de assinatura #{$transfer_id} gerado para coleta na escola.";
            $finRep = kanpro_card_machines_report($cid);
            if ($finRep !== '') $finMsg .= "\n\n" . $finRep;
            kanpro_ticket_followup($finTid, $finMsg);
            kanpro_ticket_solve($finTid, "Manutenção concluída pelo KanPro" . ($finName !== '' ? ' (finalizado por ' . $finName . ')' : '') . " — {$total} máquinas verificadas (Garantia: {$cntGarantiaTerm} | OK: {$cntOkTerm} | Inservível: {$cntInservivelTerm}). Termo de assinatura #{$transfer_id} gerado para coleta na escola.");
        }
        $base = Plugin::getWebDir('assetmgrstatus');
        if(!$base) $base = '/plugins/assetmgrstatus';
        $assinatura_url = $base.'/front/assinatura.php?f=pendente&highlight='.$transfer_id;
        $pdf_url = $base.'/front/transfer_pdf.php?id='.$transfer_id.'&stage=pronto';
        $resp = ['success'=>true,'transfer_id'=>$transfer_id,'assinatura_url'=>$assinatura_url,'pdf_url'=>$pdf_url,'progress'=>['total'=>$total,'done'=>$doneTerm,'percent'=>$total?round($doneTerm/$total*100):0,'garantia'=>$cntGarantiaTerm,'ok'=>$cntOkTerm,'inservivel'=>$cntInservivelTerm]];
        // card finalizado (com termo) vai para a lista Retirada (se existir no quadro)
        $retMove2 = kanpro_move_card_to_retirada($cid);
        if (!empty($retMove2['moved'])) { $resp['moved_to_retirada'] = true; $resp['retirada_lists_id'] = $retMove2['lists_id']; $resp['retirada_list_name'] = $retMove2['list_name']; }
        if ($pendingCardId) { $resp['pending_card_id']=$pendingCardId; $resp['pending_count']=$pendingCount; $resp['msg_pending']="Pendentes ({$pendingCount}) movidos para novo card #{$pendingCardId}"; }
        if (!empty($pendenciaSplit['pendencia_id'])) { $resp['pendencia_id']=$pendenciaSplit['pendencia_id']; $resp['pendencia_locked']=$pendenciaSplit['locked']; $resp['zap_ok']=$pendenciaSplit['zap_ok']; $resp['zap_error']=$pendenciaSplit['zap_error']; }
        elseif (!empty($pendenciaSplit['warning']) && $pendingCardId && function_exists('kanpro_can_manage_members') && kanpro_can_manage_members((int)$card->fields['plugin_kanpro_boards_id'])) { $resp['pendencia_warning']=$pendenciaSplit['warning']; }
        // WhatsApp RETIRADA (máquinas prontas — transferência criada)
        if (class_exists('PluginKanproMaintenanceZap')) {
            try { PluginKanproMaintenanceZap::sendOnce('retirada', $cid); } catch (Throwable $e) {}
        }
        jexit($resp);

    // --- PENDÊNCIA CHAMADO (Solicitar Chamado / Chamado criado / Pegar) ---
    case 'request_chamado':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $srcId = (int)($_POST['source_cards_id'] ?? $_POST['cards_id'] ?? 0);
        if (!$srcId) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $src = new PluginKanproCard();
        if (!$src->getFromDB($srcId)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($src->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Só card de manutenção pode solicitar chamado']);
        kanpro_need_not_finalized($srcId);
        kanpro_need_not_chamado_locked($srcId);
        $bid = (int)$src->fields['plugin_kanpro_boards_id'];
        $rawIds = $_POST['machine_ids'] ?? $_POST['machines'] ?? '[]';
        $mids = is_string($rawIds) ? (json_decode($rawIds, true) ?: []) : (is_array($rawIds) ? $rawIds : []);
        $mids = array_values(array_unique(array_map('intval', (array)$mids)));
        $mids = array_values(array_filter($mids, function ($v) { return $v > 0; }));
        if (empty($mids)) jexit(['success'=>false,'msg'=>'Selecione ao menos 1 máquina']);
        if (count($mids) > 200) jexit(['success'=>false,'msg'=>'Muitas máquinas (máx 200)']);
        // busca máquinas da origem
        $srcMachines = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['id' => $mids, 'plugin_kanpro_cards_id' => $srcId]]) as $m) $srcMachines[(int)$m['id']] = $m;
        if (count($srcMachines) !== count($mids)) jexit(['success'=>false,'msg'=>'Alguma máquina não pertence a este card']);
        foreach ($srcMachines as $m) {
            if (!empty($m['is_locked'])) jexit(['success'=>false,'msg'=>'Máquina #' . (int)$m['seq'] . ' já está travada aguardando chamado']);
        }
        $target = kanpro_find_list_by_type($bid, 'pend_chamado');
        if (!$target) jexit(['success'=>false,'msg'=>'Crie uma lista com categoria "Pendência chamados" neste quadro','need_list'=>true]);
        $targetLid = (int)$target['id'];
        $newCard = new PluginKanproCard();
        $newName = mb_substr(trim($src->fields['name'] ?? ('Card #' . $srcId)), 0, 255);
        $desc = "Solicitação de chamado a partir do card #{$srcId} '" . ($src->fields['name'] ?? '') . "'.\n"
            . count($mids) . " máquina(s): " . implode(', ', array_map(function ($m) { return '#' . (int)$m['seq'] . ' ' . ($m['model'] ?? ''); }, array_values($srcMachines))) . "\n\n"
            . "Ao clicar em 'Chamado criado' as máquinas são liberadas na origem.";
        $newId = $newCard->add([
            'plugin_kanpro_boards_id' => $bid,
            'plugin_kanpro_lists_id'  => $targetLid,
            'name'        => $newName,
            'description' => $desc,
        ]);
        if (!$newId) jexit(['success'=>false,'msg'=>'Falha ao criar card na Pendência Chamado']);
        $DB->update('glpi_plugin_kanpro_cards', [
            'chamado_source_id' => $srcId,
            'chamado_machines'  => json_encode(array_values($mids), JSON_UNESCAPED_UNICODE),
            'chamado_status'    => 'pendente',
            'chamado_by'        => kanpro_acting_user_id(),
            'entities_id'       => (int)($src->fields['entities_id'] ?? 0),
            'date_mod'          => date('Y-m-d H:i:s'),
        ], ['id' => $newId]);
        // checklist com as máquinas (espelho p/ acompanhar no card da pendência)
        $cl = new PluginKanproChecklist();
        $clId = (int)$cl->add(['plugin_kanpro_cards_id' => $newId, 'name' => 'Máquinas para chamado']);
        if ($clId) {
            $rk = 1024;
            foreach (array_values($srcMachines) as $m) {
                $it = new PluginKanproChecklistItem();
                $it->add(['plugin_kanpro_checklists_id' => $clId, 'name' => '#' . (int)$m['seq'] . ' ' . ($m['label'] ?: $m['model']) . ' [mid:' . (int)$m['id'] . ']', 'rank' => $rk]);
                $rk += 1024;
            }
        }
        // trava origem
        $now = date('Y-m-d H:i:s');
        $DB->update('glpi_plugin_kanpro_maintenance_machines', ['is_locked' => 1, 'locked_chamado_card_id' => $newId, 'date_mod' => $now], ['id' => $mids]);
        kanpro_touch_card($srcId);
        kanpro_touch_card($newId);
        PluginKanproBoard::logActivity($bid, $srcId, (int)$src->fields['plugin_kanpro_lists_id'], 'chamado_request', "Solicitado chamado p/ " . count($mids) . " máquina(s) → card #{$newId}");
        PluginKanproBoard::logActivity($bid, $newId, $targetLid, 'chamado_created', "Pendência Chamado criada a partir de #{$srcId} (" . count($mids) . " máquina(s))");
        // WhatsApp p/ o aprovador: 1 msg por card novo (nunca quebra o fluxo)
        $zapOk = false; $zapErr = '';
        try { if (class_exists('PluginKanproMaintenanceZap')) { $zr = PluginKanproMaintenanceZap::sendPendencia((int)$newId); $zapOk = !empty($zr['ok']); $zapErr = (string)($zr['error'] ?? ''); } } catch (Throwable $e) { $zapErr = $e->getMessage(); }
        jexit(['success'=>true,'pendencia_id'=>$newId,'target_lists_id'=>$targetLid,'locked'=>count($mids),'zap_ok'=>$zapOk,'zap_error'=>$zapErr]);

    case 'confirm_chamado_created':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $pid = (int)($_POST['pendencia_cards_id'] ?? $_POST['cards_id'] ?? $_POST['id'] ?? 0);
        if (!$pid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $pc = new PluginKanproCard();
        if (!$pc->getFromDB($pid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $srcId = (int)($pc->fields['chamado_source_id'] ?? 0);
        if (!$srcId) jexit(['success'=>false,'msg'=>'Este card não é uma Pendência Chamado']);
        if (($pc->fields['chamado_status'] ?? '') === 'liberado') jexit(['success'=>true,'already'=>true]);
        // só admin do quadro libera (criador/admin; UPDATE só em legado aberto)
        $bidC = (int)$pc->fields['plugin_kanpro_boards_id'];
        if (!kanpro_can_manage_members($bidC)) jexit(['success'=>false,'msg'=>'Somente admin do quadro pode confirmar Chamado criado']);
        $mids = [];
        try { $mids = json_decode((string)($pc->fields['chamado_machines'] ?? '[]'), true) ?: []; } catch (Throwable $e) { $mids = []; }
        $mids = array_values(array_unique(array_map('intval', (array)$mids)));
        $mids = array_values(array_filter($mids, function ($v) { return $v > 0; }));
        $nowUnlock = date('Y-m-d H:i:s');
        // Desbloqueio robusto: por IDs snapshot + por vínculo + rede de segurança na origem.
        // Antes só fazia um OU outro — se o snapshot estivesse defasado, a origem ficava travada.
        if (!empty($mids)) {
            $DB->update('glpi_plugin_kanpro_maintenance_machines', ['is_locked' => 0, 'locked_chamado_card_id' => 0, 'date_mod' => $nowUnlock], ['id' => $mids]);
        }
        // sempre limpa também por vínculo (cobre máquinas adicionadas/movidas após o snapshot)
        try { $DB->update('glpi_plugin_kanpro_maintenance_machines', ['is_locked' => 0, 'locked_chamado_card_id' => 0, 'date_mod' => $nowUnlock], ['locked_chamado_card_id' => $pid]); } catch (Throwable $e) {}
        // rede final: qualquer máquina ainda travada da origem é liberada
        try { $DB->update('glpi_plugin_kanpro_maintenance_machines', ['is_locked' => 0, 'locked_chamado_card_id' => 0, 'date_mod' => $nowUnlock], ['plugin_kanpro_cards_id' => $srcId, 'is_locked' => 1]); } catch (Throwable $e) {}
        $DB->update('glpi_plugin_kanpro_cards', ['chamado_status' => 'liberado', 'date_mod' => $nowUnlock], ['id' => $pid]);
        // Carimba a origem como liberada: a pendência auto-exclui em 30s e sem isso o
        // Tablet voltava p/ 'none' e o 2º Finalizar criava outra pendência (loop infinito).
        try { $DB->update('glpi_plugin_kanpro_cards', ['chamado_status' => 'liberado', 'date_mod' => $nowUnlock], ['id' => $srcId]); } catch (Throwable $e) {}
        kanpro_touch_card($srcId);
        kanpro_touch_card($pid);
        $srcCard = new PluginKanproCard();
        $srcBid = $bidC; $srcLid = 0;
        if ($srcCard->getFromDB($srcId)) { $srcBid = (int)$srcCard->fields['plugin_kanpro_boards_id']; $srcLid = (int)$srcCard->fields['plugin_kanpro_lists_id']; }
        // conta real de desbloqueadas (quantas ainda restam travadas na origem)
        $stillLocked = 0;
        try { $stillLocked = countElementsInTable('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id' => $srcId, 'is_locked' => 1]); } catch (Throwable $e) {}
        PluginKanproBoard::logActivity($srcBid, $srcId, $srcLid, 'chamado_released', "Chamado criado confirmado (pendência #{$pid}) — máquinas liberadas (restam {$stillLocked} travada(s))");
        PluginKanproBoard::logActivity($bidC, $pid, (int)$pc->fields['plugin_kanpro_lists_id'], 'chamado_released', "Chamado criado — origem #{$srcId} liberada (mids:" . count($mids) . " restam:{$stillLocked})");
        jexit(['success'=>true,'source_cards_id'=>$srcId,'unlocked'=>count($mids),'still_locked'=>$stillLocked]);

    case 'pegar_pending_card':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? $_POST['id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $c = new PluginKanproCard();
        if (!$c->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $bid = (int)$c->fields['plugin_kanpro_boards_id'];
        $curLid = (int)$c->fields['plugin_kanpro_lists_id'];
        // precisa estar na Pendente
        $curList = new PluginKanproList();
        $isPending = false;
        if ($curList->getFromDB($curLid)) {
            $lt = trim(strtolower($curList->fields['list_type'] ?? ''));
            if ($lt === 'pending') $isPending = true;
            else {
                $nm = function_exists('mb_strtolower') ? mb_strtolower(trim($curList->fields['name'] ?? ''), 'UTF-8') : strtolower(trim($curList->fields['name'] ?? ''));
                if ($nm === 'pendente') $isPending = true;
            }
        }
        if (!$isPending) jexit(['success'=>false,'msg'=>'Só card da lista Pendente pode ser pego']);
        // admin do quadro: fluxo completo (Pegar + Pendência Chamado com trava + zap).
        // membro do quadro: pega direto p/ Em Andamento, SEM gerar Pendência Chamado (sem trava/verificação).
        // (botão só aparece p/ admin/membro, mas valida no servidor; observer continua só-visualização)
        $isAdminPegar = kanpro_can_manage_members($bid);
        $myRolePegar = kanpro_my_board_role($bid);
        if (!$isAdminPegar && !in_array($myRolePegar, ['member','admin'], true)) jexit(['success'=>false,'msg'=>'Somente Membro ou Admin do quadro pode pegar']);
        $dest = kanpro_find_list_by_type($bid, 'andamento');
        if (!$dest) jexit(['success'=>false,'msg'=>'Crie uma lista com categoria "Em Andamento" neste quadro','need_list'=>true]);
        $destLid = (int)$dest['id'];
        $who = function_exists('kanpro_acting_user_id') ? kanpro_acting_user_id() : (int)Session::getLoginUserID();
        // move p/ Em Andamento (fim da fila)
        $last = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$destLid],'ORDER'=>'rank DESC','LIMIT'=>1])->current();
        $rank = $last ? ((float)$last['rank'] + 1024) : 1024;
        $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_lists_id'=>$destLid,'rank'=>$rank,'approval_from'=>0,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$cid]);
        // atribui quem pegou
        try {
            if ($who > 0 && $DB->tableExists('glpi_plugin_kanpro_cards_members')) {
                if (!countElementsInTable('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$cid,'users_id'=>$who])) {
                    $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$cid,'users_id'=>$who]);
                }
            }
        } catch (Throwable $e) {}
        kanpro_touch_member($cid, $who);
        // Tablet/Smartphone/Celular: vai direto p/ Em Andamento SEM pendência/trava
        // (mesmo para admin). 1º Finalizar é que cria a Pendência Chamado.
        $isTabletCard = false;
        try {
            if (function_exists('kanpro_card_tablet_info')) {
                $ti = kanpro_card_tablet_info($cid);
                $isTabletCard = !empty($ti['is_tablet_only']);
            }
        } catch (Throwable $e) {}
        $effAdminPegar = $isAdminPegar && !$isTabletCard;
        PluginKanproBoard::logActivity($bid, $cid, $destLid, 'card_move', $isTabletCard ? "Pego (Tablet) e movido direto para '{$dest['name']}' sem Pendência Chamado" : ($isAdminPegar ? "Pego por técnico e movido para '{$dest['name']}'" : "Pego por membro (direto, sem Pendência Chamado) e movido para '{$dest['name']}'"));
        // cria pendência com TODAS as máquinas (se for manutenção) e trava tudo — SÓ no pegar do admin NÃO-tablet.
        // Membro pega direto + Tablet pega direto: sem Pendência Chamado, sem trava, sem verificação.
        $pendId = 0; $lockedN = 0;
        $isMaint = !empty($c->fields['is_maintenance']);
        $allM = [];
        if ($effAdminPegar && $isMaint && $DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            foreach ($DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']) as $m) $allM[] = $m;
        }
        if (!empty($allM)) {
            $pendList = kanpro_find_list_by_type($bid, 'pend_chamado');
            if (!$pendList) {
                kanpro_touch_card($cid);
                $fresh0 = new PluginKanproCard();
                $fresh0->getFromDB($cid);
                jexit(['success'=>true,'warning'=>'Pego e movido, mas crie a lista "Pendência chamados" (categoria) para gerar a pendência','need_pend_list'=>true,'dest_lists_id'=>$destLid,'pendencia_id'=>0,'locked'=>0,'card'=>($fresh0->fields ?? null)]);
            }
            $pendLid = (int)$pendList['id'];
            $nc = new PluginKanproCard();
            $nm = mb_substr(trim($c->fields['name'] ?? ('Card #' . $cid)), 0, 255);
            $pendId = (int)$nc->add(['plugin_kanpro_boards_id'=>$bid,'plugin_kanpro_lists_id'=>$pendLid,'name'=>$nm,'description'=>"Pegar: card #{$cid} movido para '{$dest['name']}'. Todas as máquinas travadas até 'Chamado criado'."]);
            if ($pendId) {
                $midsAll = array_map(function ($m) { return (int)$m['id']; }, $allM);
                $DB->update('glpi_plugin_kanpro_cards', ['chamado_source_id'=>$cid,'chamado_machines'=>json_encode(array_values($midsAll), JSON_UNESCAPED_UNICODE),'chamado_status'=>'pendente','chamado_by'=>$who,'entities_id'=>(int)($c->fields['entities_id'] ?? 0),'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$pendId]);
                $cl = new PluginKanproChecklist();
                $clId = (int)$cl->add(['plugin_kanpro_cards_id'=>$pendId,'name'=>'Máquinas para chamado']);
                if ($clId) {
                    $rk = 1024;
                    foreach ($allM as $m) {
                        $it = new PluginKanproChecklistItem();
                        $it->add(['plugin_kanpro_checklists_id'=>$clId,'name'=>'#' . (int)$m['seq'] . ' ' . ($m['label'] ?: $m['model']) . ' [mid:' . (int)$m['id'] . ']','rank'=>$rk]);
                        $rk += 1024;
                    }
                }
                $DB->update('glpi_plugin_kanpro_maintenance_machines', ['is_locked'=>1,'locked_chamado_card_id'=>$pendId,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$midsAll]);
                $lockedN = count($midsAll);
                kanpro_touch_card($pendId);
                PluginKanproBoard::logActivity($bid, $pendId, $pendLid, 'chamado_created', "Pendência Chamado criada via Pegar de #{$cid} ({$lockedN} máquina(s))");
                // WhatsApp p/ o aprovador: 1 msg por card novo (nunca quebra o fluxo)
                $zapOk2 = false; $zapErr2 = '';
                try { if (class_exists('PluginKanproMaintenanceZap')) { $zr2 = PluginKanproMaintenanceZap::sendPendencia((int)$pendId); $zapOk2 = !empty($zr2['ok']); $zapErr2 = (string)($zr2['error'] ?? ''); } } catch (Throwable $e) { $zapErr2 = $e->getMessage(); }
            }
        }
        kanpro_touch_card($cid);
        $fresh = new PluginKanproCard();
        $fresh->getFromDB($cid);
        $respPeg = ['success'=>true,'dest_lists_id'=>$destLid,'pendencia_id'=>$pendId,'locked'=>$lockedN,'direct'=>!$effAdminPegar,'is_tablet'=>!empty($isTabletCard) ? 1 : 0,'card'=>$fresh->fields];
        if (isset($zapOk2)) { $respPeg['zap_ok'] = $zapOk2; $respPeg['zap_error'] = $zapErr2; }
        jexit($respPeg);

    // --- CARD <-> CHAMADO GLPI ---
    case 'link_ticket':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $tid = (int)($_POST['tickets_id'] ?? 0);
        if (!$cid || !$tid) jexit(['success'=>false,'msg'=>'Informe o nº do chamado']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $tk = new Ticket();
        if (!$tk->getFromDB($tid)) jexit(['success'=>false,'msg'=>'Chamado #'.$tid.' não encontrado']);
        if (!$tk->can($tid, READ)) jexit(['success'=>false,'msg'=>'Sem acesso ao chamado #'.$tid.' (perfil/entidade)']);
        $DB->update('glpi_plugin_kanpro_cards', ['tickets_id'=>$tid], ['id'=>$cid]);
        kanpro_touch_member($cid);
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'card_link_ticket', "Chamado #{$tid} vinculado ao cartão");
        jexit(['success'=>true,'ticket'=>kanpro_ticket_info($tid)]);

    case 'unlink_ticket':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $old = (int)($card->fields['tickets_id'] ?? 0);
        $DB->update('glpi_plugin_kanpro_cards', ['tickets_id'=>0], ['id'=>$cid]);
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'card_unlink_ticket', "Chamado #{$old} desvinculado do cartão");
        jexit(['success'=>true]);

    case 'create_ticket_from_card':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        try {
            $res = kanpro_create_ticket_from_card($cid);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Erro: '.$e->getMessage()]); }
        if (empty($res['ok'])) jexit(['success'=>false,'msg'=>$res['error'] ?? 'Falha ao criar chamado']);
        kanpro_touch_member($cid);
        jexit(['success'=>true,'ticket'=>$res['ticket'],'existed'=>!empty($res['existed'])]);

    default:
        jexit(['success'=>false,'msg'=>'Ação desconhecida: '.$action]);
}
