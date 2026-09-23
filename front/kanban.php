<?php
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
    @opcache_invalidate(GLPI_ROOT . '/plugins/kanpro/inc/board.class.php', true);
}
include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/kanpro/inc/acting.php');
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

// Trava de visibilidade por membros (engrenagem em Seus Quadros): só criador/membros abrem.
// Quadros legados sem nenhum membro seguem abertos até a primeira pessoa ser cadastrada.
$__me = (int)Session::getLoginUserID();
$__creator = (int)($board->fields['users_id'] ?? 0);
$__canView = ($__me > 0 && $__me === $__creator);
if (!$__canView) {
    $__isMember = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => $boards_id, 'users_id' => kanpro_viewer_ids()]) > 0;
    $__hasMembers = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => $boards_id]) > 0;
    $__canView = $__isMember || !$__hasMembers;
}
if (!$__canView) {
    Session::addMessageAfterRedirect('Você não tem acesso a este quadro.', false, ERROR);
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/kanpro/front/board.php');
}

// Migra registros do login compartilhado para a pessoa real (idempotente — ver inc/acting.php)
try {
    if (function_exists('kanpro_migrate_shared_login')) kanpro_migrate_shared_login();
} catch (Throwable $e) {
    error_log('[KanPro] ' . 'KanPro migrate call: ' . $e->getMessage());
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

// Dropdown usuários para convidar — lista com nome para picker (evita digitar ID)
$users_dropdown = [];
$all_users_for_picker = [];
$uiter = $DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_deleted' => 0, 'is_active' => 1], 'ORDER' => 'realname ASC', 'LIMIT' => 300]);
foreach ($uiter as $u) {
    $users_dropdown[] = $u;
    $display = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? ''));
    if ($display === '') $display = $u['name'];
    $initials = strtoupper(substr($u['firstname'] ?? $u['name'] ?? '?', 0, 1) . substr($u['realname'] ?? '', 0, 1));
    if (trim($initials) === '') $initials = strtoupper(substr($display, 0, 2));
    $all_users_for_picker[] = [
        'id' => (int)$u['id'],
        'name' => $display . ' (' . $u['name'] . ')',
        'login' => $u['name'],
        'initials' => $initials,
    ];
}
$all_users_json = json_encode($all_users_for_picker, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);

// Prepara JSON
$board_json  = json_encode($board->fields, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
$lists_json  = json_encode($lists, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
$labels_json = json_encode($labels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
$members_json = json_encode($members_list, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
// Endpoint AJAX do plugin
$ajax_url = Plugin::getWebDir('kanpro') . '/front/ajax.php';
$board_color = htmlspecialchars($board->fields['color'] ?? '#0079bf');
$csrf_token = Session::getNewCSRFToken();
// garante coluna color suporta degradês (migração automática sem reinstalar) — evita 500 em installs antigos
try {
    if ($DB->fieldExists('glpi_plugin_kanpro_boards', 'color')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` MODIFY `color` VARCHAR(255) NOT NULL DEFAULT '#0079bf'");
    }
    if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'background')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` ADD `background` VARCHAR(255) DEFAULT NULL AFTER `color`");
    }
    // garante coluna do chamado vinculado (Card-Chamado) sem depender do update do plugin
    if ($DB->tableExists('glpi_plugin_kanpro_cards') && !$DB->fieldExists('glpi_plugin_kanpro_cards', 'tickets_id')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `tickets_id` INT NOT NULL DEFAULT '0' AFTER `cover_attachment_id`");
    }
} catch (Throwable $e) {
    error_log('[KanPro] ' . "KanPro kanban auto-migration: " . $e->getMessage());
}
if (method_exists('PluginKanproBoard', 'getBoardThemes')) {
    $themes = PluginKanproBoard::getBoardThemes();
} elseif (method_exists('PluginKanproBoard', 'getBackgroundColors')) {
    $themes = ['solids' => PluginKanproBoard::getBackgroundColors(), 'gradients' => method_exists('PluginKanproBoard','getBackgroundGradients') ? PluginKanproBoard::getBackgroundGradients() : []];
} else {
    $themes = ['solids' => ['#0079bf'=>'Azul','#00aecc'=>'Ciano','#4bbf6b'=>'Verde'], 'gradients' => []];
}
$themes_json = json_encode($themes, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
// estilo de fundo do quadro: imagem com cover se existir, senão cor/degradê
$board_bg_raw = $board->fields['background'] ?? '';
$board_bg_style = $board_color;
if (!empty($board_bg_raw)) {
    $bgUrl = PluginKanproBoard::getBackgroundImageUrl((int)$board->fields['id'], $board_bg_raw);
    $bgUrlEsc = htmlspecialchars($bgUrl, ENT_QUOTES);
    $board_bg_style = "url('{$bgUrlEsc}') center / cover no-repeat, {$board_color}";
}

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
try {
    if ($DB->tableExists('glpi_plugin_kanpro_labels') && !$DB->fieldExists('glpi_plugin_kanpro_labels', 'due_date')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_labels` ADD `due_date` DATETIME DEFAULT NULL COMMENT 'prazo: cartão fica vermelho ao vencer'");
    }
} catch (Throwable $e) {
    error_log('[KanPro] ' . "KanPro kanban labels due_date migration: " . $e->getMessage());
}
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
$card_labels_json = json_encode($card_labels_map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
$card_members_json = json_encode($card_members_map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);

// Checklist progress
$check_progress = [];
$__cp_ids = array_column($all_cards, 'id') ?: [0];
$check_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['plugin_kanpro_cards_id' => $__cp_ids]]);
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

// Manutenção progress
$maintenance_progress = [];
if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
    $__maint_ids = array_column($all_cards, 'id') ?: [0];
    $maint_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['plugin_kanpro_cards_id' => $__maint_ids]]);
    $maint_by_card = [];
    foreach ($maint_iter as $mm) {
        $maint_by_card[$mm['plugin_kanpro_cards_id']][] = $mm;
    }
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
            } catch (Throwable $e) {
                error_log('[KanPro] ' . "KanPro kanban notes_by_card: " . $e->getMessage());
            }
        }
    }
    foreach ($maint_by_card as $cid => $machines) {
        $total = count($machines);
        $done = 0;
        $urgent = 0;
        foreach ($machines as $mm) {
            if (!empty($mm['is_done'])) $done++;
            if (!empty($mm['is_urgent'])) $urgent++;
        }
        $maintenance_progress[$cid] = ['total'=>$total,'done'=>$done,'percent'=>$total?round($done/$total*100):0,'urgent'=>$urgent,'notes'=>($notes_by_card[$cid] ?? 0)];
    }
    foreach ($all_cards as $c) {
        if (!empty($c['is_maintenance']) && !isset($maintenance_progress[$c['id']])) {
            $maintenance_progress[$c['id']] = ['total'=>0,'done'=>0,'percent'=>0,'urgent'=>0,'notes'=>0];
        }
    }
}
$maintenance_progress_json = json_encode($maintenance_progress, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);

// Transfer status para badge Retirada/Concluído (KanPro → assetmgrstatus)
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
        if ($isAssinado) {
            $transfer_status[$c['id']] = ['label'=>'Concluído','status'=>'concluido'];
        } elseif (!empty($tr['id'])) {
            // tem transferência mas falta assinatura → Retirada (amarelo)
            $transfer_status[$c['id']] = ['label'=>'Retirada','status'=>'retirada'];
        }
    }
}
$transfer_status_json = json_encode($transfer_status, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);

// Chamados GLPI vinculados (badge no card minimizado + seção no modal)
$ticket_map = [];
$__tk_ids = [];
foreach ($all_cards as $c) {
    if (!empty($c['tickets_id'] ?? 0)) $__tk_ids[(int)$c['id']] = (int)$c['tickets_id'];
}
if (!empty($__tk_ids) && class_exists('Ticket')) {
    $tkRows = $DB->request(['SELECT' => ['id','name','status'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => array_values(array_unique($__tk_ids))]]);
    $tkById = [];
    foreach ($tkRows as $tr) $tkById[(int)$tr['id']] = $tr;
    $tkObj = new Ticket();
    foreach ($__tk_ids as $cid => $tid) {
        if (!isset($tkById[$tid])) continue;
        $tr = $tkById[$tid];
        $can = false;
        try { $can = $tkObj->can($tid, READ); } catch (Throwable $e) { $can = false; }
        $st = (int)$tr['status'];
        $lbl = 'Status ' . $st;
        if (method_exists('Ticket', 'getStatus')) {
            try { $lbl = Ticket::getStatus($st); } catch (Throwable $e) {}
        }
        $ticket_map[$cid] = ['id' => $tid, 'name' => $can ? ($tr['name'] ?? '') : '', 'restricted' => !$can, 'status' => $st, 'status_label' => $lbl];
    }
}
$ticket_map_json = json_encode($ticket_map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);

// Comentários count e anexos count
$comment_counts = [];
$att_counts = [];
foreach ($all_cards as $c) {
    $comment_counts[$c['id']] = countElementsInTable('glpi_plugin_kanpro_comments', ['plugin_kanpro_cards_id' => $c['id']]);
    $att_counts[$c['id']] = countElementsInTable('glpi_plugin_kanpro_attachments', ['plugin_kanpro_cards_id' => $c['id']]);
}
$comment_counts_json = json_encode($comment_counts);
$att_counts_json = json_encode($att_counts);

$hasMaintenance = count(array_filter($all_cards, fn($c) => !empty($c['is_maintenance']))) > 0;
$assinatura_url = $CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/assinatura.php?f=pendente';
$assinatura_btn = '<a id="kanpro-assinatura-btn" href="' . $assinatura_url . '" target="_blank" rel="noopener" title="Ir para Assinaturas (termos pendentes)" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;border:none;padding:6px 14px;border-radius:4px;cursor:pointer;font-weight:700;text-decoration:none;display:' . ($hasMaintenance ? 'inline-flex' : 'none') . ';align-items:center;gap:6px;box-shadow:0 2px 6px rgba(79,70,229,.3)"><i class="ti ti-signature"></i> Assinaturas</a>';

$open_card_id = isset($_GET['open_card']) ? (int) $_GET['open_card'] : 0;
$open_card_id_json = json_encode($open_card_id ?: null);
$current_user_id = (int) Session::getLoginUserID();
$acting_user_id_json = function_exists('kanpro_acting_user_id') ? (int) kanpro_acting_user_id() : $current_user_id;

echo <<<HTML
<style>
/* esconde header padrão GLPI breadcrumb para efeito Trello full */
#page { padding:0 !important; }
/* Dark mode — só nas listas e nos botões Filtrar/Calendário/Relatório/Histórico */
.kanpro-dark .kp-list,
.kanpro-dark #kanpro-filter-btn,
.kanpro-dark #kanpro-calendar-btn,
.kanpro-dark #kanpro-history-btn { filter: invert(0.9) hue-rotate(180deg); }
.kanpro-dark .kp-list img,
.kanpro-dark .kp-list .kp-avatar,
.kanpro-dark .kp-list .ti { filter: invert(1) hue-rotate(180deg); }
</style>
<div id="kanpro-app" style="display:flex;flex-direction:column;height:calc(100vh - 80px);background: {$board_bg_style};margin:-15px -15px 0 -15px;position:relative;background-size:cover;background-position:center">

  <!-- Topbar do quadro -->
  <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:rgba(0,0,0,.15);backdrop-filter:blur(6px);color:#fff;gap:12px;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:12px">
      <a href="{$CFG_GLPI['root_doc']}/plugins/kanpro/front/board.php" style="color:#fff;text-decoration:none;display:flex;align-items:center;gap:6px;background:rgba(255,255,255,.2);padding:6px 10px;border-radius:4px"><i class="ti ti-arrow-left"></i> Quadros</a>
      <h1 id="board-title" style="margin:0;font-size:18px;font-weight:700;background:rgba(255,255,255,.2);padding:6px 12px;border-radius:4px;cursor:pointer" onclick="Kanpro.renameBoard()" title="Clique para renomear">{$board->fields['name']}</h1>
      <button onclick="Kanpro.toggleStar()" style="background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 10px;border-radius:4px;cursor:pointer" title="Favoritar">⭐</button>
      <span style="background:rgba(255,255,255,.2);padding:4px 8px;border-radius:12px;font-size:12px"><i class="ti ti-lock"></i> {$board->fields['visibility']}</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <div id="board-viewers-avatars" style="display:flex;margin-right:4px" title="Vendo agora"></div>
      <div id="board-members-avatars" style="display:flex;margin-right:8px"></div>
      {$assinatura_btn}
      <button onclick="Kanpro.toggleDarkMode()" id="kanpro-dark-btn" style="background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 10px;border-radius:4px;cursor:pointer" title="Alternar modo escuro"><i class="ti ti-moon"></i></button>
      <button onclick="Kanpro.openBoardMenu()" style="background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 12px;border-radius:4px;cursor:pointer"><i class="ti ti-dots"></i> Mostrar menu</button>
      <button onclick="Kanpro.openGlobalSearch()" style="background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 12px;border-radius:4px;cursor:pointer" title="Buscar em todos os quadros"><i class="ti ti-search"></i> Busca global</button>
      <div style="position:relative">
        <input id="kanpro-filter" type="text" placeholder="Filtrar cartões..." oninput="Kanpro.filterCards(this.value)" style="padding:6px 12px 6px 32px;border:none;border-radius:4px;background:rgba(255,255,255,.3);color:#fff;width:200px">
        <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#fff"></i>
      </div>
    </div>
  </div>

  <!-- Barra de ações secundária -->
  <div style="display:flex;gap:8px;padding:8px 16px;align-items:center;flex-wrap:wrap">
    <button id="kanpro-filter-btn" onclick="Kanpro.openFilterMenu()" style="background:rgba(255,255,255,.9);border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:13px;color:#172b4d"><i class="ti ti-filter"></i> Filtrar</button>
    <button id="kanpro-calendar-btn" onclick="Kanpro.showCalendarView()" style="background:rgba(255,255,255,.9);border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:13px;color:#172b4d"><i class="ti ti-calendar"></i> Calendário</button>
    <button id="kanpro-report-btn" onclick="Kanpro.openBoardReport()" style="background:rgba(255,255,255,.9);border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:13px;color:#172b4d"><i class="ti ti-chart-bar"></i> Relatório</button>
    <button id="kanpro-history-btn" onclick="KanproHistory.open(window.KANPRO.board.id)" style="background:rgba(255,255,255,.9);border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:13px;color:#172b4d"><i class="ti ti-history"></i> Histórico</button>
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
          <div id="card-modal-ticket" style="display:none">
            <div style="font-size:12px;font-weight:600;color:#5e6c84;margin-bottom:6px;letter-spacing:.04em">CHAMADO</div>
            <div id="card-modal-ticket-val"></div>
          </div>
        </div>

        <!-- Descrição -->
        <div style="margin-bottom:20px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><i class="ti ti-align-left"></i><strong>Descrição</strong><button onclick="Kanpro.editDescription()" style="margin-left:8px;background:#eaecf0;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px">Editar</button></div>
          <div id="card-modal-desc" style="background:#fff;padding:12px;border-radius:4px;min-height:56px;color:#172b4d;word-break:break-word;box-shadow:0 1px 1px rgba(9,30,66,.13)"></div>
          <textarea id="card-desc-edit" style="display:none;width:100%;min-height:80px;padding:10px;border:2px solid #0079bf;border-radius:4px;resize:vertical"></textarea>
          <div style="font-size:11px;color:#5e6c84;margin-top:4px">Suporta Markdown: <code>**negrito**</code> <code>*itálico*</code> <code>`código`</code> <code>[texto](link)</code></div>
          <div id="card-desc-actions" style="display:none;margin-top:8px;gap:8px">
            <button onclick="Kanpro.saveDescription()" style="background:#0079bf;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer">Salvar</button>
            <button onclick="Kanpro.cancelDescription()" style="background:none;border:none;cursor:pointer;font-size:18px">✕</button>
          </div>
        </div>

        <!-- Manutenção -->
        <div id="card-modal-maintenance" style="display:none"></div>

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
              <textarea id="card-comment-input" placeholder="Escrever um comentário... (@nome menciona, **negrito**)" style="width:100%;padding:10px;border:none;border-radius:8px;box-shadow:0 1px 1px rgba(9,30,66,.13);min-height:40px;resize:vertical"></textarea>
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

        <!-- Movimentação -->
        <div style="margin-top:20px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><i class="ti ti-route"></i><strong>Movimentação</strong></div>
          <div id="card-modal-moves" style="display:grid"></div>
        </div>

        <!-- Aprovação -->
        <div id="card-modal-approval" style="margin-top:20px"></div>
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
            <button class="kp-sidebar-btn" onclick="Kanpro.editMaintenanceCardTitle('Escola')" title="Escolher escola (entidade) como nome do cartão"><i class="ti ti-school"></i> Escola</button>
            <button class="kp-sidebar-btn" onclick="Kanpro.ticketButton()" title="Criar chamado a partir do cartão ou vincular existente"><i class="ti ti-ticket"></i> Chamado</button>
          </div>
        </div>
        <div>
          <div style="font-size:12px;font-weight:600;color:#5e6c84;margin-bottom:8px">AÇÕES</div>
          <div style="display:grid;gap:8px">
            <button id="kp-maintenance-btn" class="kp-sidebar-btn" onclick="Kanpro.openMaintenanceFlow()" style="background:#fffae6;border:1px solid #ffab00;color:#172b4d"><i class="ti ti-tool"></i> Manutenção</button>
            <button class="kp-sidebar-btn" onclick="Kanpro.moveCardPicker()"><i class="ti ti-arrows-move"></i> Mover</button>
            <button class="kp-sidebar-btn" onclick="Kanpro.copyCard()"><i class="ti ti-copy"></i> Copiar</button>
            <button class="kp-sidebar-btn" onclick="Kanpro.archiveCard()"><i class="ti ti-archive"></i> Arquivar</button>
            <button class="kp-sidebar-btn" id="kp-pin-btn" onclick="Kanpro.togglePin()"><i class="ti ti-pin"></i> Fixar no topo</button>
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
    <!-- 🎨 Troca de cor/tema dentro do quadro -->
    <div style="background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:10px">
      <div style="font-weight:700;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:8px">
        <span><i class="ti ti-palette" style="color:#6554c0"></i> Cor / Tema do Quadro</span>
        <span id="board-menu-color-label" style="font-size:11px;font-weight:400;color:#5e6c84;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
      </div>
      <div style="font-size:10px;font-weight:700;color:#5e6c84;letter-spacing:.04em;margin-bottom:6px">CORES SÓLIDAS</div>
      <div id="board-menu-colors" style="display:flex;flex-wrap:wrap;gap:6px"></div>
      <div style="font-size:10px;font-weight:700;color:#5e6c84;letter-spacing:.04em;margin:10px 0 6px">DEGRADÊS — TEMAS</div>
      <div id="board-menu-gradients" style="display:flex;flex-wrap:wrap;gap:6px"></div>
      <div style="margin-top:10px;display:flex;align-items:center;gap:8px;padding:8px;background:#f4f5f7;border-radius:6px;border:1px solid #eaecf0">
        <input type="color" id="board-menu-custom" value="#0079bf" style="width:36px;height:28px;border:none;padding:0;border-radius:4px;cursor:pointer;flex-shrink:0">
        <span style="font-size:12px;color:#5e6c84;flex:1">Cor personalizada</span>
        <button onclick="Kanpro.setBoardColor(document.getElementById('board-menu-custom').value)" style="background:#0079bf;color:#fff;border:none;padding:6px 10px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:700">Aplicar</button>
      </div>
      <div id="board-menu-color-preview" style="margin-top:8px;height:32px;border-radius:6px;border:1px solid #dfe1e6;box-shadow:inset 0 0 0 1px rgba(0,0,0,.06)"></div>
      <div style="margin-top:6px;font-size:11px;color:#5e6c84;text-align:center">Clique numa cor para trocar instantaneamente</div>
    </div>
    <!-- 🖼️ Imagem de fundo — tema com foto -->
    <div style="background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:10px">
      <div style="font-weight:700;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:8px">
        <span><i class="ti ti-photo" style="color:#0079bf"></i> Imagem de Fundo</span>
        <span id="board-menu-bg-label" style="font-size:11px;font-weight:400;color:#5e6c84"></span>
      </div>
      <div id="board-menu-bg-preview" style="display:none;height:90px;border-radius:6px;border:1px solid #dfe1e6;background:#f4f5f7;background-size:cover;background-position:center"></div>
      <div id="board-menu-bg-empty" style="display:none;height:90px;border-radius:6px;border:1px dashed #dfe1e6;background:#f4f5f7;display:flex;align-items:center;justify-content:center;color:#6b778c;font-size:12px"><i class="ti ti-photo-off" style="font-size:20px;margin-right:6px"></i> Nenhuma imagem</div>
      <div style="display:flex;gap:8px;margin-top:8px">
        <label style="flex:1;background:#0079bf;color:#fff;border:none;padding:7px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;text-align:center;display:flex;align-items:center;justify-content:center;gap:6px">
          <i class="ti ti-upload"></i> Enviar imagem
          <input type="file" id="board-menu-bg-input" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none" onchange="Kanpro.uploadBoardBackground(this)">
        </label>
        <button onclick="Kanpro.removeBoardBackground()" id="board-menu-bg-remove" style="display:none;background:#ffebe6;color:#bf2600;border:1px solid #ffbdad;padding:7px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600"><i class="ti ti-trash"></i></button>
      </div>
      <div style="margin-top:8px;background:#f4f5f7;border:1px solid #dfe1e6;border-radius:6px;padding:8px;font-size:11px;color:#5e6c84;line-height:1.5">
        <strong style="color:#172b4d">Resolução recomendada:</strong> <strong>1920×1080 (Full HD, 16:9)</strong> — mínimo <strong>1280×720</strong>. Para 4K: <strong>2560×1440</strong>.<br>
        Formatos: <strong>JPG, PNG, WebP, GIF</strong> • Máx <strong>5 MB</strong> (ideal &lt; 2 MB).<br>
        Exibição: <code style="background:#fff;padding:1px 4px;border-radius:4px;border:1px solid #dfe1e6">cover center</code> — preenche todo o fundo, corta bordas se necessário.
      </div>
    </div>
    <div>
      <div style="font-weight:600;margin-bottom:8px">Fundo do quadro</div>
      <div id="board-menu-color-preview" style="height:42px;border-radius:8px;border:1px solid #dfe1e6;margin-bottom:6px"></div>
      <div id="board-menu-color-label" style="font-size:12px;color:#5e6c84;margin-bottom:8px"></div>
      <div style="font-size:11px;font-weight:700;color:#5e6c84;letter-spacing:.04em;margin-bottom:6px">CORES SÓLIDAS</div>
      <div id="board-menu-colors" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px"></div>
      <div style="font-size:11px;font-weight:700;color:#5e6c84;letter-spacing:.04em;margin-bottom:6px">DEGRADÊS — TEMAS</div>
      <div id="board-menu-gradients" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px"></div>
      <div style="font-size:11px;font-weight:700;color:#5e6c84;letter-spacing:.04em;margin-bottom:6px">PAPÉIS DE PAREDE</div>
      <div id="board-menu-wallpapers" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:10px"></div>
      <div style="display:flex;align-items:center;gap:8px">
        <input type="color" id="board-menu-custom" value="#0079bf" style="width:44px;height:34px;border:none;padding:0;border-radius:6px;cursor:pointer">
        <button onclick="Kanpro.setBoardColor(document.getElementById('board-menu-custom').value)" style="background:#0079bf;color:#fff;border:none;padding:7px 12px;border-radius:4px;cursor:pointer;font-weight:700">Aplicar cor</button>
      </div>
    </div>
    <div>
      <div style="font-weight:600;margin-bottom:8px">Quadro</div>
      <div style="display:grid;gap:8px">
        <button onclick="Kanpro.duplicateBoard()" style="background:#fff;border:1px solid #dfe1e6;padding:8px 12px;border-radius:4px;cursor:pointer;width:100%;text-align:left"><i class="ti ti-copy"></i> Duplicar quadro</button>
        <button onclick="Kanpro.openTrash()" style="background:#fff;border:1px solid #dfe1e6;padding:8px 12px;border-radius:4px;cursor:pointer;width:100%;text-align:left"><i class="ti ti-trash"></i> Lixeira <span id="board-menu-trash-count" style="font-size:11px;color:#5e6c84"></span></button>
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
  maintenanceProgress: {$maintenance_progress_json},
  commentCounts: {$comment_counts_json},
  attCounts: {$att_counts_json},
  members: {$members_json},
  allUsers: {$all_users_json},
  transferStatus: {$transfer_status_json},
  ticketMap: {$ticket_map_json},
  ajax_url: "{$ajax_url}",
  csrf_token: "{$csrf_token}",
  canEdit: {$canedit},
  boardColor: "{$board_color}",
  openCardId: {$open_card_id_json},
  currentUserId: {$current_user_id},
  actingUserId: {$acting_user_id_json},
  themes: {$themes_json}
};
</script>
HTML;

echo Html::footer();
