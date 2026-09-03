<?php
include('../../../inc/includes.php');
@ob_clean();
header('Content-Type: application/json; charset=UTF-8');
if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => 'Não autenticado']);
    exit;
}
if (!Session::haveRight('plugin_kanpro', READ)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Sem permissão (plugin_kanpro READ) - verifique Perfil > KanPro']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
global $DB;

function jexit($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function needEdit() {
    // permite CREATE ou UPDATE (criar cartão/lista não deve exigir UPDATE estrito)
    if (!Session::haveRight('plugin_kanpro', UPDATE) && !Session::haveRight('plugin_kanpro', CREATE)) {
        $have = $_SESSION['glpiactiveprofile']['plugin_kanpro'] ?? 0;
        jexit(['success'=>false,'msg'=>"Sem permissão (precisa CREATE ou UPDATE). Seu nível atual: {$have}. Vá em Administração → Perfis → seu perfil → KanPro e marque Criar/Editar, depois saia e entre novamente."]);
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

    default:
        jexit(['success'=>false,'msg'=>'Ação desconhecida: '.$action]);
}
