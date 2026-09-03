<?php
include('../../../inc/includes.php');
Session::checkRight('plugin_kanpro', READ);

$boards_id = (int)($_GET['boards_id'] ?? $_GET['id'] ?? 0);
if (!$boards_id) {
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/kanpro/front/board.php');
}

$board = new PluginKanproBoard();
if (!$board->getFromDB($boards_id)) {
    Session::addMessageAfterRedirect('Quadro não encontrado', false, ERROR);
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/kanpro/front/board.php');
}

$canedit = Session::haveRight('plugin_kanpro', UPDATE) ? 1 : 0;
$cancreate = Session::haveRight('plugin_kanpro', CREATE) ? 1 : 0;

// Header sem Html::header padrão para ter layout full-width Trello
Html::header($board->fields['name'] . ' — KanPro', $_SERVER['PHP_SELF'], 'tools', 'PluginKanproBoard', 'kanpro');

global $DB;

// Dados do quadro
$lists = PluginKanproList::getListsForBoard($boards_id);
$labels = PluginKanproLabel::getForBoard($boards_id);

// Membros do quadro
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
    $members_list[] = [
        'users_id' => $m['users_id'],
        'role'     => $m['role'],
        'name'     => $uname,
        'initials' => $initials,
    ];
}

// Dropdown usuários para convidar
$users_dropdown = [];
$uiter = $DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_deleted' => 0, 'is_active' => 1], 'ORDER' => 'realname ASC', 'LIMIT' => 200]);
foreach ($uiter as $u) {
    $users_dropdown[] = $u;
}

// Prepara JSON
$board_json  = json_encode($board->fields, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
$lists_json  = json_encode($lists, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
$labels_json = json_encode($labels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
$members_json = json_encode($members_list, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
// Usa root_doc para garantir /glpi prefix correto (corrige 404/403)
$ajax_url = $CFG_GLPI['root_doc'] . '/plugins/kanpro/front/ajax.php';
$board_color = htmlspecialchars($board->fields['color'] ?? '#0079bf');
$csrf_token = Session::getNewCSRFToken();

// Busca cartões por lista para render inicial (evita N+1 via JS)
$all_cards = [];
$cards_by_list = [];
$cards_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'is_archived' => 0], 'ORDER' => 'rank ASC']);
foreach ($cards_iter as $c) {
    $all_cards[] = $c;
    $cards_by_list[$c['plugin_kanpro_lists_id']][] = $c;
}
$cards_json = json_encode($all_cards, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);

// Card-labels e card-members mapas
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
$cm_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards_members', 'WHERE' => ['plugin_kanpro_cards_id' => ['IN' => array_column($all_cards, 'id') ?: [0]]]]);
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
$card_labels_json = json_encode($card_labels_map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
$card_members_json = json_encode($card_members_map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);

// Checklist progress
$check_progress = [];
$check_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['plugin_kanpro_cards_id' => ['IN' => array_column($all_cards, 'id') ?: [0]]]]);
$check_ids_by_card = [];
foreach ($check_iter as $cl) {
    $check_ids_by_card[$cl['plugin_kanpro_cards_id']][] = $cl['id'];
}
foreach ($check_ids_by_card as $cid => $cids) {
    $total = countElementsInTable('glpi_plugin_kanpro_checklist_items', ['plugin_kanpro_checklists_id' => $cids]);
    $done  = countElementsInTable('glpi_plugin_kanpro_checklist_items', ['plugin_kanpro_checklists_id' => $cids, 'is_checked' => 1]);
    $check_progress[$cid] = ['total' => $total, 'done' => $done];
}
$check_progress_json = json_encode($check_progress, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);

// Comentários count e anexos count
$comment_counts = [];
$att_counts = [];
foreach ($all_cards as $c) {
    $comment_counts[$c['id']] = countElementsInTable('glpi_plugin_kanpro_comments', ['plugin_kanpro_cards_id' => $c['id']]);
    $att_counts[$c['id']] = countElementsInTable('glpi_plugin_kanpro_attachments', ['plugin_kanpro_cards_id' => $c['id']]);
}
$comment_counts_json = json_encode($comment_counts);
$att_counts_json = json_encode($att_counts);

echo <<<HTML
<style>
/* esconde header padrão GLPI breadcrumb para efeito Trello full */
#page { padding:0 !important; }
</style>
<div id="kanpro-app" style="display:flex;flex-direction:column;height:calc(100vh - 80px);background: {$board_color};margin:-15px -15px 0 -15px;position:relative">

  <!-- Topbar do quadro -->
  <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:rgba(0,0,0,.15);backdrop-filter:blur(6px);color:#fff;gap:12px;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:12px">
      <a href="{$CFG_GLPI['root_doc']}/plugins/kanpro/front/board.php" style="color:#fff;text-decoration:none;display:flex;align-items:center;gap:6px;background:rgba(255,255,255,.2);padding:6px 10px;border-radius:4px"><i class="ti ti-arrow-left"></i> Quadros</a>
      <h1 id="board-title" style="margin:0;font-size:18px;font-weight:700;background:rgba(255,255,255,.2);padding:6px 12px;border-radius:4px;cursor:pointer" onclick="Kanpro.renameBoard()" title="Clique para renomear">{$board->fields['name']}</h1>
      <button onclick="Kanpro.toggleStar()" style="background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 10px;border-radius:4px;cursor:pointer" title="Favoritar">⭐</button>
      <span style="background:rgba(255,255,255,.2);padding:4px 8px;border-radius:12px;font-size:12px"><i class="ti ti-lock"></i> {$board->fields['visibility']}</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <div id="board-members-avatars" style="display:flex;margin-right:8px"></div>
      <button onclick="Kanpro.openInvite()" style="background:#fff;color:#172b4d;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-weight:600"><i class="ti ti-user-plus"></i> Convidar</button>
      <button onclick="Kanpro.openBoardMenu()" style="background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 12px;border-radius:4px;cursor:pointer"><i class="ti ti-dots"></i> Mostrar menu</button>
      <div style="position:relative">
        <input id="kanpro-filter" type="text" placeholder="Filtrar cartões..." oninput="Kanpro.filterCards(this.value)" style="padding:6px 12px 6px 32px;border:none;border-radius:4px;background:rgba(255,255,255,.3);color:#fff;width:200px">
        <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#fff"></i>
      </div>
    </div>
  </div>

  <!-- Barra de ações secundária -->
  <div style="display:flex;gap:8px;padding:8px 16px;align-items:center;flex-wrap:wrap">
    <button onclick="Kanpro.openFilterMenu()" style="background:rgba(255,255,255,.9);border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:13px;color:#172b4d"><i class="ti ti-filter"></i> Filtrar</button>
    <button onclick="Kanpro.showCalendarView()" style="background:rgba(255,255,255,.9);border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:13px;color:#172b4d"><i class="ti ti-calendar"></i> Calendário</button>
    <span id="kanpro-stats" style="color:#fff;font-size:13px;margin-left:8px;opacity:.9"></span>
  </div>

  <!-- Kanban board -->
  <div id="kanpro-board" style="flex:1;display:flex;gap:12px;padding:12px 16px;overflow-x:auto;overflow-y:hidden;align-items:flex-start;scroll-behavior:smooth">
    <!-- listas injetadas via JS -->
  </div>

  <!-- botão adicionar lista -->
  <div style="position:absolute;bottom:16px;right:16px;display:none" id="add-list-fab"></div>
</div>

<!-- Modal do cartão (Trello style) -->
<div id="kanpro-card-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.64);z-index:9999;overflow-y:auto;padding:40px 0">
  <div style="background:#f4f5f7;max-width:768px;margin:0 auto;border-radius:8px;overflow:hidden;position:relative;min-height:400px">
    <button onclick="Kanpro.closeCardModal()" style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,.08);border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;z-index:2"><i class="ti ti-x" style="font-size:18px"></i></button>
    <div id="card-modal-cover" style="height:0"></div>
    <div style="padding:16px 16px 16px 56px;position:relative">
      <i class="ti ti-credit-card" style="position:absolute;left:16px;top:18px;font-size:22px;color:#172b4d"></i>
      <div id="card-modal-title" style="font-size:20px;font-weight:700;color:#172b4d;cursor:pointer" onclick="Kanpro.editCardTitle()"></div>
      <div style="font-size:14px;color:#6b778c;margin-top:4px">na lista <span id="card-modal-listname" style="text-decoration:underline"></span></div>
      <div id="card-modal-badges" style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap"></div>
    </div>
    <div style="display:flex;gap:16px;padding:0 16px 16px 16px">
      <div style="flex:1;min-width:0">
        <!-- Membros + Etiquetas -->
        <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap">
          <div id="card-modal-members" style="display:none">
            <div style="font-size:12px;font-weight:600;color:#5e6c84;margin-bottom:6px;letter-spacing:.04em">MEMBROS</div>
            <div id="card-modal-members-list" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap"></div>
          </div>
          <div id="card-modal-labels" style="display:none">
            <div style="font-size:12px;font-weight:600;color:#5e6c84;margin-bottom:6px;letter-spacing:.04em">ETIQUETAS</div>
            <div id="card-modal-labels-list" style="display:flex;gap:4px;flex-wrap:wrap"></div>
          </div>
          <div id="card-modal-dates" style="display:none">
            <div style="font-size:12px;font-weight:600;color:#5e6c84;margin-bottom:6px">DATAS</div>
            <div id="card-modal-dates-val" style="background:#eaecf0;padding:6px 10px;border-radius:4px;font-size:13px"></div>
          </div>
        </div>

        <!-- Descrição -->
        <div style="margin-bottom:20px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><i class="ti ti-align-left"></i><strong>Descrição</strong><button onclick="Kanpro.editDescription()" style="margin-left:8px;background:#eaecf0;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px">Editar</button></div>
          <div id="card-modal-desc" style="background:#fff;padding:12px;border-radius:4px;min-height:56px;color:#172b4d;white-space:pre-wrap;word-break:break-word;box-shadow:0 1px 1px rgba(9,30,66,.13)"></div>
          <textarea id="card-desc-edit" style="display:none;width:100%;min-height:80px;padding:10px;border:2px solid #0079bf;border-radius:4px;resize:vertical"></textarea>
          <div id="card-desc-actions" style="display:none;margin-top:8px;gap:8px">
            <button onclick="Kanpro.saveDescription()" style="background:#0079bf;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer">Salvar</button>
            <button onclick="Kanpro.cancelDescription()" style="background:none;border:none;cursor:pointer;font-size:18px">✕</button>
          </div>
        </div>

        <!-- Checklists -->
        <div id="card-modal-checklists"></div>
        <button onclick="Kanpro.addChecklist()" style="background:#eaecf0;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;margin-bottom:16px"><i class="ti ti-plus"></i> Adicionar checklist</button>

        <!-- Anexos -->
        <div style="margin-bottom:20px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><i class="ti ti-paperclip"></i><strong>Anexos</strong></div>
          <div id="card-modal-attachments" style="display:grid;gap:8px"></div>
          <label style="display:inline-flex;align-items:center;gap:6px;background:#eaecf0;padding:6px 12px;border-radius:4px;cursor:pointer;margin-top:8px"><i class="ti ti-upload"></i> Adicionar anexo <input type="file" id="card-attach-input" style="display:none" onchange="Kanpro.uploadAttachment(this)"></label>
        </div>

        <!-- Comentários -->
        <div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><i class="ti ti-message"></i><strong>Comentários</strong></div>
          <div style="display:flex;gap:8px;margin-bottom:12px">
            <div style="width:32px;height:32px;border-radius:50%;background:#dfe1e6;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px">EU</div>
            <div style="flex:1">
              <textarea id="card-comment-input" placeholder="Escrever um comentário..." style="width:100%;padding:10px;border:none;border-radius:8px;box-shadow:0 1px 1px rgba(9,30,66,.13);min-height:40px;resize:vertical"></textarea>
              <button onclick="Kanpro.addComment()" style="margin-top:8px;background:#0079bf;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer">Salvar</button>
            </div>
          </div>
          <div id="card-modal-comments" style="display:grid;gap:12px"></div>
        </div>

        <!-- Atividade -->
        <div style="margin-top:20px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><i class="ti ti-activity"></i><strong>Atividade</strong><button onclick="Kanpro.toggleActivity()" style="margin-left:auto;background:#eaecf0;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px">Mostrar detalhes</button></div>
          <div id="card-modal-activity" style="display:grid;gap:8px"></div>
        </div>
      </div>

      <!-- Sidebar direita (ações Trello) -->
      <div style="width:168px;flex-shrink:0;display:grid;align-content:start;gap:16px">
        <div>
          <div style="font-size:12px;font-weight:600;color:#5e6c84;margin-bottom:8px">ADICIONAR AO CARTÃO</div>
          <div style="display:grid;gap:8px">
            <button class="kp-sidebar-btn" onclick="Kanpro.openMembersPicker()"><i class="ti ti-user"></i> Membros</button>
            <button class="kp-sidebar-btn" onclick="Kanpro.openLabelsPicker()"><i class="ti ti-tag"></i> Etiquetas</button>
            <button class="kp-sidebar-btn" onclick="Kanpro.openChecklistPicker()"><i class="ti ti-checkbox"></i> Checklist</button>
            <button class="kp-sidebar-btn" onclick="document.getElementById('card-attach-input').click()"><i class="ti ti-paperclip"></i> Anexo</button>
            <button class="kp-sidebar-btn" onclick="Kanpro.openDatesPicker()"><i class="ti ti-clock"></i> Datas</button>
            <button class="kp-sidebar-btn" onclick="Kanpro.openCoverPicker()"><i class="ti ti-photo"></i> Capa</button>
          </div>
        </div>
        <div>
          <div style="font-size:12px;font-weight:600;color:#5e6c84;margin-bottom:8px">AÇÕES</div>
          <div style="display:grid;gap:8px">
            <button class="kp-sidebar-btn" onclick="Kanpro.moveCardPicker()"><i class="ti ti-arrows-move"></i> Mover</button>
            <button class="kp-sidebar-btn" onclick="Kanpro.copyCard()"><i class="ti ti-copy"></i> Copiar</button>
            <button class="kp-sidebar-btn" onclick="Kanpro.archiveCard()"><i class="ti ti-archive"></i> Arquivar</button>
            <button class="kp-sidebar-btn" style="color:#eb5a46" onclick="Kanpro.deleteCard()"><i class="ti ti-trash"></i> Excluir</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Menu lateral do quadro -->
<div id="kanpro-board-menu" style="display:none;position:fixed;top:0;right:0;width:340px;height:100vh;background:#f4f5f7;box-shadow:-2px 0 8px rgba(0,0,0,.2);z-index:9998;overflow-y:auto">
  <div style="padding:12px;border-bottom:1px solid #dfe1e6;display:flex;justify-content:space-between;align-items:center">
    <strong>Menu</strong><button onclick="Kanpro.closeBoardMenu()" style="background:none;border:none;cursor:pointer;font-size:18px">✕</button>
  </div>
  <div style="padding:12px;display:grid;gap:16px">
    <div>
      <div style="font-weight:600;margin-bottom:8px">Sobre este quadro</div>
      <div style="font-size:13px;color:#5e6c84">{$board->fields['comment']}</div>
      <div style="margin-top:8px;display:flex;gap:8px">
        <button onclick="Kanpro.openBoardSettings()" style="background:#eaecf0;border:none;padding:6px 10px;border-radius:4px;cursor:pointer;flex:1">Configurações</button>
        <button onclick="Kanpro.archiveBoard()" style="background:#fff3cd;border:1px solid #ffc107;padding:6px 10px;border-radius:4px;cursor:pointer">Arquivar quadro</button>
      </div>
    </div>
    <div>
      <div style="font-weight:600;margin-bottom:8px">Etiquetas</div>
      <div id="board-menu-labels" style="display:grid;gap:6px"></div>
      <button onclick="Kanpro.addBoardLabel()" style="margin-top:8px;background:#eaecf0;border:none;padding:6px 10px;border-radius:4px;cursor:pointer;width:100%">

 Adicionar etiqueta</button>
    </div>
    <div>
      <div style="font-weight:600;margin-bottom:8px">Membros</div>
      <div id="board-menu-members" style="display:grid;gap:6px"></div>
    </div>
    <div>
      <div style="font-weight:600;margin-bottom:8px">Atividade</div>
      <div id="board-menu-activity" style="display:grid;gap:8px;max-height:300px;overflow-y:auto"></div>
    </div>
    <div>
      <button onclick="if(confirm('Excluir quadro e todo seu conteúdo?')) Kanpro.deleteBoard()" style="background:#eb5a46;color:#fff;border:none;padding:8px 12px;border-radius:4px;cursor:pointer;width:100%">Excluir quadro permanentemente</button>
    </div>
  </div>
</div>

<!-- Picker genérico -->
<div id="kanpro-picker" style="display:none;position:fixed;z-index:10000;background:#fff;border-radius:8px;box-shadow:0 8px 16px rgba(0,0,0,.2);min-width:300px;max-width:360px;overflow:hidden">
  <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid #dfe1e6"><strong id="picker-title">Picker</strong><button onclick="Kanpro.closePicker()" style="background:none;border:none;cursor:pointer">✕</button></div>
  <div id="picker-body" style="padding:12px;max-height:400px;overflow-y:auto"></div>
</div>

<input type="hidden" id="kanpro-csrf" value="{$csrf_token}">
<script>
window.glpi_csrf_token = "{$csrf_token}";
window.KANPRO = {
  board: {$board_json},
  lists: {$lists_json},
  labels: {$labels_json},
  cards: {$cards_json},
  cardLabels: {$card_labels_json},
  cardMembers: {$card_members_json},
  checkProgress: {$check_progress_json},
  commentCounts: {$comment_counts_json},
  attCounts: {$att_counts_json},
  members: {$members_json},
  ajax_url: "{$ajax_url}",
  csrf_token: "{$csrf_token}",
  canEdit: {$canedit},
  boardColor: "{$board_color}"
};
</script>
HTML;

echo Html::footer();
