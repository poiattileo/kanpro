<?php
if (function_exists('opcache_invalidate')) @opcache_invalidate(__FILE__, true);
include('../../../inc/includes.php');
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

$action = $_REQUEST['action'] ?? '';
global $DB;

function jexit($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function needEdit() {
    if (!Session::haveRight('plugin_kanpro', UPDATE) && !Session::haveRight('plugin_kanpro', CREATE)) {
        $have = $_SESSION['glpiactiveprofile']['plugin_kanpro'] ?? 0;
        $dbg = json_encode(['profile_id'=>$_SESSION['glpiactiveprofile']['id']??null,'have'=>$have,'haveREAD'=>Session::haveRight('plugin_kanpro',READ),'haveCREATE'=>Session::haveRight('plugin_kanpro',CREATE),'haveUPDATE'=>Session::haveRight('plugin_kanpro',UPDATE)]);
        jexit(['success'=>false,'msg'=>"Sem permissão (precisa CREATE ou UPDATE). Seu nível atual: {$have}. Faça logout/login.", 'debug'=>$dbg]);
    }
}

// ---------- Helpers Manutenção ----------
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

function kanpro_ensure_maintenance_tables() {
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
                `status`                      VARCHAR(20)  NOT NULL DEFAULT 'pending',
                `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                `date_creation`               DATETIME     DEFAULT NULL,
                `date_mod`                    DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_kanpro_cards_id` (`plugin_kanpro_cards_id`),
                KEY `seq` (`seq`),
                KEY `is_done` (`is_done`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ");
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
        $color = $_POST['color'] ?? '#0079bf';
        $DB->update('glpi_plugin_kanpro_boards', ['color'=>$color], ['id'=>$id]);
        jexit(['success'=>true]);

    case 'invite_member':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $uid = (int)($_POST['users_id'] ?? 0);
        $role = $_POST['role'] ?? 'member';
        if (!$uid) jexit(['success'=>false,'msg'=>'Usuário inválido']);
        $DB->insert('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>$uid,'role'=>$role,'date_creation'=>date('Y-m-d H:i:s')]);
        // ignora duplicado
        if ($DB->error() && strpos($DB->error(), 'Duplicate')!==false) jexit(['success'=>false,'msg'=>'Usuário já é membro']);
        PluginKanproBoard::logActivity($bid, null, null, 'member_add', "Membro {$uid} adicionado");
        jexit(['success'=>true]);

    case 'remove_member':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $uid = (int)($_POST['users_id'] ?? 0);
        $DB->delete('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>$uid]);
        jexit(['success'=>true]);

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

    case 'toggle_card_label':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
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
        $id = $list->add(['plugin_kanpro_boards_id'=>$bid,'name'=>$name]);
        PluginKanproBoard::logActivity($bid, null, $id, 'list_create', "Lista '{$name}' criada");
        jexit(['success'=>true,'id'=>$id]);

    case 'rename_list':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$name) jexit(['success'=>false,'msg'=>'Nome obrigatório']);
        $DB->update('glpi_plugin_kanpro_lists', ['name'=>$name], ['id'=>$id]);
        jexit(['success'=>true]);

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
        jexit(['success' => true]);

    case 'get_board_snapshot':
        $boards_id = (int) ($_POST['boards_id'] ?? 0);
        if (!$boards_id) jexit(['success' => false]);
        $board_chk = new PluginKanproBoard();
        if (!$board_chk->getFromDB($boards_id)) jexit(['success' => false]);

        $lists = PluginKanproList::getListsForBoard($boards_id);
        $labels = PluginKanproLabel::getForBoard($boards_id);

        $members_raw = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id]]);
        $members_list = [];
        foreach ($members_raw as $m) {
            $u = new User();
            $uname = 'Usuário #' . $m['users_id'];
            $initials = '?';
            if ($u->getFromDB($m['users_id'])) {
                $uname = $u->getFriendlyName();
                $initials = strtoupper(substr($u->fields['firstname'] ?? $u->fields['name'] ?? '?', 0, 1) . substr($u->fields['realname'] ?? '', 0, 1));
                if (trim($initials) === '') $initials = strtoupper(substr($uname, 0, 2));
            }
            $members_list[] = ['users_id' => $m['users_id'], 'role' => $m['role'], 'name' => $uname, 'initials' => $initials];
        }

        $all_cards = [];
        $cards_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'is_archived' => 0], 'ORDER' => 'rank ASC']);
        foreach ($cards_iter as $c) $all_cards[] = $c;

        $card_labels_map = [];
        $cl_iter = $DB->request([
            'SELECT' => ['cl.plugin_kanpro_cards_id', 'l.id', 'l.name', 'l.color'],
            'FROM'   => 'glpi_plugin_kanpro_cards_labels AS cl',
            'LEFT JOIN' => ['glpi_plugin_kanpro_labels AS l' => ['ON' => ['l' => 'id', 'cl' => 'plugin_kanpro_labels_id']]],
            'WHERE'  => ['l.plugin_kanpro_boards_id' => $boards_id],
        ]);
        foreach ($cl_iter as $r) {
            $card_labels_map[$r['plugin_kanpro_cards_id']][] = ['id' => $r['id'], 'name' => $r['name'], 'color' => $r['color']];
        }

        $card_members_map = [];
        $cm_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards_members', 'WHERE' => ['plugin_kanpro_cards_id' => array_column($all_cards, 'id') ?: [0]]]);
        foreach ($cm_iter as $r) {
            $u = new User();
            $initials = '?';
            $uname = '#' . $r['users_id'];
            if ($u->getFromDB($r['users_id'])) {
                $uname = $u->getFriendlyName();
                $initials = strtoupper(substr($u->fields['firstname'] ?? $u->fields['name'] ?? '?', 0, 1));
            }
            $card_members_map[$r['plugin_kanpro_cards_id']][] = ['users_id' => $r['users_id'], 'name' => $uname, 'initials' => $initials];
        }

        $check_progress = [];
        $__cp_ids = array_column($all_cards, 'id') ?: [0];
        $check_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['plugin_kanpro_cards_id' => $__cp_ids]]);
        $check_ids_by_card = [];
        foreach ($check_iter as $cl) $check_ids_by_card[$cl['plugin_kanpro_cards_id']][] = $cl['id'];
        foreach ($check_ids_by_card as $cid => $cids) {
            $total = countElementsInTable('glpi_plugin_kanpro_checklist_items', ['plugin_kanpro_checklists_id' => $cids]);
            $done  = countElementsInTable('glpi_plugin_kanpro_checklist_items', ['plugin_kanpro_checklists_id' => $cids, 'is_checked' => 1]);
            $check_progress[$cid] = ['total' => $total, 'done' => $done];
        }

        $maintenance_progress = [];
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            $maint_ids = array_column($all_cards, 'id') ?: [0];
            $maint_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['plugin_kanpro_cards_id' => $maint_ids]]);
            $maint_by_card = [];
            foreach ($maint_iter as $mm) $maint_by_card[$mm['plugin_kanpro_cards_id']][] = $mm;
            foreach ($maint_by_card as $cid => $machines) {
                $total = count($machines);
                $done = 0;
                foreach ($machines as $mm) if (!empty($mm['is_done'])) $done++;
                $maintenance_progress[$cid] = ['total'=>$total,'done'=>$done,'percent'=>$total?round($done/$total*100):0];
            }
            foreach ($all_cards as $c) {
                if (!empty($c['is_maintenance']) && !isset($maintenance_progress[$c['id']])) $maintenance_progress[$c['id']] = ['total'=>0,'done'=>0,'percent'=>0];
            }
        }

        $comment_counts = [];
        $att_counts = [];
        foreach ($all_cards as $c) {
            $comment_counts[$c['id']] = countElementsInTable('glpi_plugin_kanpro_comments', ['plugin_kanpro_cards_id' => $c['id']]);
            $att_counts[$c['id']] = countElementsInTable('glpi_plugin_kanpro_attachments', ['plugin_kanpro_cards_id' => $c['id']]);
        }

        $viewers = [];
        $cutoff = date('Y-m-d H:i:s', time() - 15);
        $viewers_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_presence', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'last_seen' => ['>', $cutoff]]]);
        foreach ($viewers_iter as $v) {
            $u = new User();
            $uname = '#' . $v['users_id'];
            $initials = '?';
            if ($u->getFromDB($v['users_id'])) {
                $uname = $u->getFriendlyName();
                $initials = strtoupper(substr($u->fields['firstname'] ?? $u->fields['name'] ?? '?', 0, 1));
            }
            $viewers[] = ['users_id' => (int) $v['users_id'], 'name' => $uname, 'initials' => $initials];
        }

        jexit([
            'success' => true,
            'lists' => $lists,
            'labels' => $labels,
            'cards' => $all_cards,
            'cardLabels' => $card_labels_map,
            'cardMembers' => $card_members_map,
            'checkProgress' => $check_progress,
            'maintenanceProgress' => $maintenance_progress,
            'commentCounts' => $comment_counts,
            'attCounts' => $att_counts,
            'members' => $members_list,
            'viewers' => $viewers,
        ]);

    case 'global_search_cards':
        $q = trim($_POST['q'] ?? '');
        if (mb_strlen($q) < 2) jexit(['success' => true, 'results' => []]);
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
            'LIMIT' => 40,
        ]);
        $results = [];
        foreach ($cards_iter as $c) {
            $board = $boards_by_id[$c['plugin_kanpro_boards_id']] ?? null;
            $list  = $lists_by_id[$c['plugin_kanpro_lists_id']] ?? null;
            if (!$board) continue;
            $results[] = [
                'card_id'    => (int) $c['id'],
                'card_name'  => $c['name'],
                'board_id'   => (int) $board['id'],
                'board_name' => $board['name'],
                'list_name'  => $list['name'] ?? '',
            ];
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
        $new_id = $l->add(['plugin_kanpro_boards_id'=>$l->fields['plugin_kanpro_boards_id'],'name'=>$l->fields['name'].' (cópia)']);
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
        $name = trim($_POST['name'] ?? '');
        if (!$name) jexit(['success'=>false,'msg'=>'Título obrigatório']);
        $list = new PluginKanproList();
        if (!$list->getFromDB($lists_id)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        $card = new PluginKanproCard();
        $id = $card->add(['plugin_kanpro_boards_id'=>$list->fields['plugin_kanpro_boards_id'],'plugin_kanpro_lists_id'=>$lists_id,'name'=>$name]);
        jexit(['success'=>true,'id'=>$id, 'card'=>$card->fields]);

    case 'get_card':
        $cid = (int)($_REQUEST['cards_id'] ?? 0);
        $data = PluginKanproCard::getFullData($cid);
        if (!$data) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        jexit(['success'=>true,'data'=>$data]);

    case 'update_card':
        needEdit();
        $cid = (int)($_POST['id'] ?? 0);
        $fields = [];
        if (isset($_POST['name'])) $fields['name'] = trim($_POST['name']);
        if (array_key_exists('description', $_POST)) $fields['description'] = $_POST['description'];
        if (array_key_exists('due_date', $_POST)) $fields['due_date'] = empty($_POST['due_date']) ? null : $_POST['due_date'];
        if (array_key_exists('start_date', $_POST)) $fields['start_date'] = empty($_POST['start_date']) ? null : $_POST['start_date'];
        if (array_key_exists('cover_color', $_POST)) $fields['cover_color'] = $_POST['cover_color'] ?: null;
        if (array_key_exists('is_completed', $_POST)) $fields['is_completed'] = (int)$_POST['is_completed'];
        if (empty($fields)) jexit(['success'=>false]);
        $fields['id'] = $cid;
        $c = new PluginKanproCard();
        $c->update($fields);
        jexit(['success'=>true]);

    case 'move_card':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $target_list = (int)($_POST['target_lists_id'] ?? 0);
        $pos = isset($_POST['position']) ? (int)$_POST['position'] : null;
        // Se position dado, calcula rank; senão joga pro fim
        if ($pos !== null) {
            // pega cartões da lista destino ordenados
            $cards = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$target_list,'is_archived'=>0],'ORDER'=>'rank ASC']);
            $ids = array_column(iterator_to_array($cards), 'id');
            // remove se já está
            $ids = array_values(array_filter($ids, fn($x)=>$x!=$cid));
            array_splice($ids, $pos, 0, [$cid]);
            // reordena
            $rank = 1024;
            foreach ($ids as $id) {
                if ($id == $cid) {
                    $DB->update('glpi_plugin_kanpro_cards', ['rank'=>$rank,'plugin_kanpro_lists_id'=>$target_list], ['id'=>$cid]);
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
            PluginKanproCard::moveCard($cid, $target_list);
        }
        jexit(['success'=>true]);

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
        $target_list = isset($_POST['target_lists_id']) ? (int)$_POST['target_lists_id'] : null;
        $new_id = PluginKanproCard::duplicate($cid, $target_list);
        jexit(['success'=>true,'id'=>$new_id]);

    case 'archive_card':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $c = new PluginKanproCard();
        $c->getFromDB($cid);
        $new = $c->fields['is_archived'] ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_cards', ['is_archived'=>$new], ['id'=>$cid]);
        jexit(['success'=>true,'is_archived'=>$new]);

    case 'delete_card':
        if (!Session::haveRight('plugin_kanpro', DELETE)) jexit(['success'=>false]);
        $cid = (int)($_POST['cards_id'] ?? 0);
        $c = new PluginKanproCard();
        $c->delete(['id'=>$cid], true);
        jexit(['success'=>true]);

    case 'toggle_card_member':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
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
        $name = trim($_POST['name'] ?? 'Checklist');
        $cl = new PluginKanproChecklist();
        $id = $cl->add(['plugin_kanpro_cards_id'=>$cid,'name'=>$name]);
        jexit(['success'=>true,'id'=>$id]);

    case 'rename_checklist':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $DB->update('glpi_plugin_kanpro_checklists', ['name'=>$name], ['id'=>$id]);
        jexit(['success'=>true]);

    case 'delete_checklist':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $DB->delete('glpi_plugin_kanpro_checklist_items', ['plugin_kanpro_checklists_id'=>$id]);
        $DB->delete('glpi_plugin_kanpro_checklists', ['id'=>$id]);
        jexit(['success'=>true]);

    case 'add_checkitem':
        needEdit();
        $clid = (int)($_POST['checklists_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$name) jexit(['success'=>false]);
        $it = new PluginKanproChecklistItem();
        $id = $it->add(['plugin_kanpro_checklists_id'=>$clid,'name'=>$name]);
        jexit(['success'=>true,'id'=>$id]);

    case 'toggle_checkitem':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_checklist_items','WHERE'=>['id'=>$id]])->current();
        if (!$row) jexit(['success'=>false]);
        $new = $row['is_checked'] ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_checklist_items', ['is_checked'=>$new], ['id'=>$id]);
        jexit(['success'=>true,'is_checked'=>$new]);

    case 'rename_checkitem':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $DB->update('glpi_plugin_kanpro_checklist_items', ['name'=>$name], ['id'=>$id]);
        jexit(['success'=>true]);

    case 'delete_checkitem':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $DB->delete('glpi_plugin_kanpro_checklist_items', ['id'=>$id]);
        jexit(['success'=>true]);

    case 'reorder_checkitems':
        needEdit();
        $clid = (int)($_POST['checklists_id'] ?? 0);
        $order = json_decode($_POST['order'] ?? '[]', true);
        $rank=1024;
        foreach ($order as $iid) {
            $DB->update('glpi_plugin_kanpro_checklist_items', ['rank'=>$rank], ['id'=>$iid,'plugin_kanpro_checklists_id'=>$clid]);
            $rank+=1024;
        }
        jexit(['success'=>true]);

    // --- COMMENTS ---
    case 'add_comment':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if (!$content) jexit(['success'=>false]);
        $co = new PluginKanproComment();
        $id = $co->add(['plugin_kanpro_cards_id'=>$cid,'content'=>$content]);
        jexit(['success'=>true,'id'=>$id]);

    case 'update_comment':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        $DB->update('glpi_plugin_kanpro_comments', ['content'=>$content,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$id,'users_id'=>Session::getLoginUserID()]);
        jexit(['success'=>true]);

    case 'delete_comment':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $DB->delete('glpi_plugin_kanpro_comments', ['id'=>$id]);
        jexit(['success'=>true]);

    // --- ATTACHMENTS ---
    case 'upload_attachment':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        if (!isset($_FILES['file'])) jexit(['success'=>false,'msg'=>'Nenhum arquivo']);
        $id = PluginKanproAttachment::handleUpload($cid, $_FILES['file']);
        jexit(['success'=> (bool)$id,'id'=>$id]);

    case 'delete_attachment':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_attachments','WHERE'=>['id'=>$id]])->current();
        if ($row && !empty($row['filepath'])) {
            $path = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $row['filepath'];
            if (file_exists($path)) @unlink($path);
        }
        $DB->delete('glpi_plugin_kanpro_attachments', ['id'=>$id]);
        jexit(['success'=>true]);

    case 'set_cover':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $color = $_POST['cover_color'] ?? null;
        $att_id = $_POST['attachment_id'] ?? null;
        // se att_id vier, usa cor nula
        $DB->update('glpi_plugin_kanpro_cards', ['cover_color'=>$color ?: null,'cover_attachment_id'=>$att_id ?: null], ['id'=>$cid]);
        jexit(['success'=>true]);

    // --- DATES ---
    case 'set_dates':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $start = empty($_POST['start_date']) ? null : $_POST['start_date'];
        $due = empty($_POST['due_date']) ? null : $_POST['due_date'];
        $DB->update('glpi_plugin_kanpro_cards', ['start_date'=>$start,'due_date'=>$due], ['id'=>$cid]);
        jexit(['success'=>true]);

    case 'toggle_complete':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['id'=>$cid]])->current();
        $new = $row['is_completed'] ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_cards', ['is_completed'=>$new], ['id'=>$cid]);
        jexit(['success'=>true,'is_completed'=>$new]);

    // --- BOARD ACTIVITY ---
    case 'get_board_activity':
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        $acts = PluginKanproActivity::getForBoard($bid, 50);
        jexit(['success'=>true,'data'=>$acts]);

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
        $norm = kanpro_normalize_confirm($confirm);
        $challenge_words = ["PAIVA","MASSON","FERRARI","TECNICO","SUPORTE","MANUTENCAO","REPARO","DIAGNOSTICO","HARDWARE","SOFTWARE","NOTEBOOK","DESKTOP","MONITOR","TECLADO","MOUSE","IMPRESSORA","REDE","SERVIDOR","BACKUP","SEGURANCA","ATUALIZACAO","LIMPEZA","FORMATACAO","INSTALACAO","CONFIGURACAO","ATENDIMENTO","CHAMADO","TICKET","PROTOCOLO","SISTEMA","PROCESSADOR","MEMORIA","SSD","HD","PLACA","FONTE","COOLER","GABINETE","BATERIA","CARREGADOR","CABO","CONECTOR","DRIVER","FIRMWARE","BIOS","WINDOWS","LINUX","OFFICE","ANTIVIRUS","FIREWALL","VPN","WIFI","ETHERNET","SWITCH","ROTEADOR","PATCH","CABEAMENTO","ESTRUTURADO","VOIP","TELEFONIA","RAMAL","NOBREAK","ESTABILIZADOR","PROJETOR","WEBCAM","HEADSET","SCANNER","PLOTTER","TABLET","CELULAR","SMARTPHONE","CHIP","BROWSER","NAVEGADOR","EMAIL","SENHA","LOGIN","USUARIO","PERFIL","PERMISSAO","BANCO","DADOS","RELATORIO","INVENTARIO","PATRIMONIO","ATIVO","GARANTIA","CONTRATO","FORNECEDOR","CLIENTE","DEPARTAMENTO","SETOR","ALMOXARIFADO","ESTOQUE","COMPRA","LICENCA","ATIVACAO","VALIDACAO","AUTENTICACAO","CONFIRMACAO"];
        $norm_allowed = array_map('kanpro_normalize_confirm', $challenge_words);
        if (!in_array($norm, $norm_allowed, true)) {
            jexit(['success'=>false,'msg'=>'Palavra de confirmação inválida. Digite exatamente a palavra desafio exibida (sem acento).','need_confirm'=>true]);
        }
        // senha opcional - fluxo atual só pede palavra (sem senha)
        if ($password !== '' && $password !== null && !kanpro_verify_password($password)) {
            jexit(['success'=>false,'msg'=>'Senha incorreta. Verifique sua senha do GLPI.','need_password'=>true]);
        }
        $DB->update('glpi_plugin_kanpro_cards', [
            'is_maintenance'   => 1,
            'maintenance_date' => date('Y-m-d H:i:s'),
            'maintenance_by'   => Session::getLoginUserID(),
            'date_mod'         => date('Y-m-d H:i:s')
        ], ['id' => $cid]);
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'card_maintenance_convert', "Cartão convertido para manutenção por ". Session::getLoginUserID());
        jexit(['success'=>true,'msg'=>'Card convertido para manutenção','is_maintenance'=>1]);

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
        $existing = countElementsInTable('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cid]);
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
        $uid = Session::getLoginUserID();
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
                    'status'                 => 'pending',
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
        // Retorna lista completa atualizada
        $all = [];
        $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach ($iter as $r) $all[] = $r;
        $done = count(array_filter($all, fn($x)=> $x['is_done']==1));
        jexit(['success'=>true,'total'=>$total,'created'=>count($created),'machines'=>$all,'progress'=>['total'=>count($all),'done'=>$done,'percent'=> count($all)? round($done/count($all)*100):0]]);

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

    case 'update_maintenance_machine':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_POST['id'] ?? 0);
        if (!$mid) jexit(['success'=>false,'msg'=>'Máquina inválida']);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Máquina não encontrada']);
        $updates = [];
        if (array_key_exists('diary', $_POST)) $updates['diary'] = $_POST['diary'];
        if (array_key_exists('is_done', $_POST)) $updates['is_done'] = (int)$_POST['is_done'] ? 1:0;
        if (array_key_exists('is_ok', $_POST)) $updates['is_ok'] = (int)$_POST['is_ok'] ? 1:0;
        if (array_key_exists('status', $_POST)) {
            $st = trim($_POST['status']);
            if (!in_array($st, ['pending','ok','defect','nok'])) $st = 'pending';
            // normaliza nok -> defect
            if ($st==='nok') $st='defect';
            $updates['status'] = $st;
            // sincroniza is_ok para compat
            $updates['is_ok'] = ($st==='ok'?1:0);
            // se ok ou defect, considera terminado? Não automático, deixa usuário marcar done
        }
        if (array_key_exists('model', $_POST)) {
            $model = trim($_POST['model']);
            if ($model !== '') {
                $updates['model'] = mb_substr($model,0,250);
                // atualiza label para manter seq
                $updates['label'] = "Máquina {$row['seq']} - {$updates['model']}";
            }
        }
        if (empty($updates)) jexit(['success'=>false,'msg'=>'Nada para atualizar']);
        $updates['date_mod'] = date('Y-m-d H:i:s');
        $updates['users_id'] = Session::getLoginUserID();
        $DB->update('glpi_plugin_kanpro_maintenance_machines', $updates, ['id'=>$mid]);
        // log
        $card = new PluginKanproCard();
        if ($card->getFromDB($row['plugin_kanpro_cards_id'])) {
            PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $card->getID(), $card->fields['plugin_kanpro_lists_id'], 'maintenance_update', "Máquina #{$row['seq']} atualizada");
        }
        $newRow = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        jexit(['success'=>true,'machine'=>$newRow]);

    case 'add_maintenance_machines':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 1);
        $model = trim($_POST['model'] ?? '');
        $raw = $_POST['machines_raw'] ?? '';
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Não é manutenção']);
        $defs = [];
        if ($raw !== '') $defs = kanpro_parse_maintenance_raw($raw);
        else if ($model !== '') $defs[] = ['qty'=>max(1,min(500,$qty)),'model'=>$model];
        else jexit(['success'=>false,'msg'=>'Informe modelo ou raw']);
        $row = $DB->request(['SELECT'=>['MAX'=>'seq AS m'],'FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid]])->current();
        $seq = (int)($row['m'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $uid = Session::getLoginUserID();
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
                    'status'=>'pending',
                    'users_id'=>$uid,
                    'date_creation'=>$now,
                    'date_mod'=>$now
                ]);
            }
        }
        $all=[];
        $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach($iter as $r) $all[]=$r;
        jexit(['success'=>true,'machines'=>$all]);

    case 'delete_maintenance_machine':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_POST['id'] ?? 0);
        if (!$mid) jexit(['success'=>false,'msg'=>'ID inválido']);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Não encontrado']);
        $cid = $row['plugin_kanpro_cards_id'];
        $DB->delete('glpi_plugin_kanpro_maintenance_machines', ['id'=>$mid]);
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
        jexit(['success'=>true,'machines'=>$all]);

    case 'revert_maintenance':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Não é manutenção']);
        if (!kanpro_verify_password($password)) jexit(['success'=>false,'msg'=>'Senha incorreta']);
        $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>0,'maintenance_date'=>null,'maintenance_by'=>0], ['id'=>$cid]);
        // opcional: manter máquinas para histórico, mas aqui mantém; se quiser apagar, descomente:
        // $DB->delete('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cid]);
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_revert', "Manutenção revertida");
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

    default:
        jexit(['success'=>false,'msg'=>'Ação desconhecida: '.$action]);
}
