<?php
// Histórico — modificações do quadro com filtro por pessoa (quem tem acesso).
// Sem filtro: geral. Com filtro: só as ações da pessoa.
include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/kanpro/inc/acting.php');
Session::checkRight('plugin_kanpro', READ);

global $DB;

$me = (int)Session::getLoginUserID();
$boards_id = (int)($_GET['boards_id'] ?? 0);
$filter_user = (int)($_GET['users_id'] ?? 0);

// visibilidade (mesma regra do kanban: criador, membro ou quadro legado sem membros)
$__myBoards = [];
foreach ($DB->request(['SELECT' => 'plugin_kanpro_boards_id', 'FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['users_id' => $me]]) as $__r) {
    $__myBoards[(int)$__r['plugin_kanpro_boards_id']] = true;
}
$__restricted = [];
foreach ($DB->request(['SELECT' => 'plugin_kanpro_boards_id', 'FROM' => 'glpi_plugin_kanpro_boards_members', 'GROUPBY' => ['plugin_kanpro_boards_id']]) as $__r) {
    $__restricted[(int)$__r['plugin_kanpro_boards_id']] = true;
}
$canViewBoard = function (array $b) use ($me, $__myBoards, $__restricted) {
    $bid = (int)$b['id'];
    $creator = (int)($b['users_id'] ?? 0);
    return ($creator === $me) || isset($__myBoards[$bid]) || !isset($__restricted[$bid]);
};

// quadros acessíveis (p/ seletor)
$boards = [];
$biter = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards', 'WHERE' => ['is_archived' => 0], 'ORDER' => 'name ASC']);
foreach ($biter as $b) {
    if ($canViewBoard($b)) $boards[] = $b;
}

$board = null;
if ($boards_id) {
    $bchk = new PluginKanproBoard();
    if ($bchk->getFromDB($boards_id)) {
        if (!$canViewBoard($bchk->fields)) {
            Session::addMessageAfterRedirect('Você não tem acesso a este quadro.', false, ERROR);
            Html::redirect($CFG_GLPI['root_doc'] . '/plugins/kanpro/front/board.php');
        }
        $board = $bchk->fields;
    } else {
        $boards_id = 0;
    }
}

Html::header('KanPro - Histórico', $_SERVER['PHP_SELF'], 'tools', 'PluginKanproBoard');

$actionLabels = [
    'board_create' => 'criou o quadro', 'board_rename' => 'renomeou o quadro',
    'list_create' => 'criou a lista', 'list_move_all' => 'moveu lista',
    'card_create' => 'criou o cartão', 'card_move' => 'moveu o cartão',
    'card_archive' => 'arquivou o cartão', 'card_restore' => 'restaurou o cartão',
    'card_complete' => 'concluiu o cartão', 'card_reopen' => 'reabriu o cartão',
    'card_maintenance_convert' => 'converteu para manutenção',
    'maintenance_setup' => 'configurou máquinas', 'maintenance_update' => 'atualizou máquina',
    'maintenance_diary' => 'atualizou o diário', 'maintenance_finalize' => 'finalizou manutenção',
    'maintenance_revert' => 'reverteu manutenção', 'maintenance_retirada' => 'retirou máquina',
    'maintenance_pending_split' => 'separou pendentes',
    'member_add' => 'adicionou membro', 'member_remove' => 'removeu membro', 'member_role' => 'trocou papel',
    'card_create_ticket' => 'criou chamado', 'card_link_ticket' => 'vinculou chamado', 'card_unlink_ticket' => 'desvinculou chamado',
    'card_approval_request' => 'pediu aprovação', 'card_approval_ok' => 'aprovou movimentação',
];

echo "<div style='max-width:1000px;margin:0 auto;padding:20px'>";
echo "<div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px'>";
echo "<h1 style='margin:0;font-size:22px;display:flex;align-items:center;gap:10px'><i class='ti ti-history' style='font-size:28px;color:#0079bf'></i> Histórico" . ($board ? ' — ' . htmlspecialchars($board['name']) : '') . "</h1>";
echo "<a href='" . $CFG_GLPI['root_doc'] . "/plugins/kanpro/front/board.php' class='btn btn-outline-secondary btn-sm'><i class='ti ti-arrow-left'></i> Quadros</a>";
echo "</div>";

// seletor de quadro + filtro por pessoa
echo "<form method='get' style='display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:flex-end'>";
echo "<label style='font-size:12px;font-weight:600;color:#5e6c84'>Quadro<br><select name='boards_id' onchange='this.form.submit()' style='padding:8px;border:1px solid #dfe1e6;border-radius:6px;min-width:220px'>";
echo "<option value=''>— Selecione —</option>";
foreach ($boards as $b) {
    $sel = ((int)$b['id'] === $boards_id) ? ' selected' : '';
    echo "<option value='" . (int)$b['id'] . "'{$sel}>" . htmlspecialchars($b['name']) . "</option>";
}
echo "</select></label>";

$people = [];
if ($board) {
    // criador primeiro
    $creatorId = (int)($board['users_id'] ?? 0);
    $piter = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id], 'ORDER' => 'date_creation ASC']);
    $seen = [];
    $addPerson = function ($uid, $extra = '') use (&$people, &$seen) {
        $uid = (int)$uid;
        if ($uid <= 0 || isset($seen[$uid])) return;
        $seen[$uid] = true;
        $u = new User();
        $name = 'Usuário #' . $uid;
        if ($u->getFromDB($uid)) $name = $u->getFriendlyName();
        $people[] = ['id' => $uid, 'name' => $name, 'extra' => $extra];
    };
    $addPerson($creatorId, 'criador');
    foreach ($piter as $m) $addPerson($m['users_id'], $m['role'] ?? '');
    echo "<label style='font-size:12px;font-weight:600;color:#5e6c84'>Pessoa<br><select name='users_id' onchange='this.form.submit()' style='padding:8px;border:1px solid #dfe1e6;border-radius:6px;min-width:200px'>";
    echo "<option value=''>Todas (geral)</option>";
    foreach ($people as $p) {
        $sel = ($p['id'] === $filter_user) ? ' selected' : '';
        $tag = $p['extra'] !== '' ? ' (' . htmlspecialchars($p['extra']) . ')' : '';
        echo "<option value='" . $p['id'] . "'{$sel}>" . htmlspecialchars($p['name']) . $tag . "</option>";
    }
    echo "</select></label>";
}
echo "</form>";

if ($board) {
    $where = ['a.plugin_kanpro_boards_id' => $boards_id];
    if ($filter_user > 0) $where['a.users_id'] = $filter_user;
    $acts = $DB->request([
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
    $rows = [];
    foreach ($acts as $a) $rows[] = $a;
    $whoName = 'Todas as pessoas';
    if ($filter_user > 0) {
        foreach ($people as $p) {
            if ($p['id'] === $filter_user) {
                $whoName = $p['name'];
                break;
            }
        }
    }
    echo "<div style='font-size:13px;color:#5e6c84;margin-bottom:12px'>Mostrando <strong>" . count($rows) . "</strong> modificações — <strong>" . htmlspecialchars($whoName) . "</strong></div>";
    if (!count($rows)) {
        echo "<div style='text-align:center;padding:40px;background:#f4f5f7;border-radius:8px;color:#6b778c'>Nenhuma modificação" . ($filter_user ? ' desta pessoa' : '') . " ainda.</div>";
    } else {
        echo "<div style='display:grid'>";
        foreach ($rows as $i => $a) {
            $uname = trim(($a['realname'] ?? '') . ' ' . ($a['firstname'] ?? ''));
            if ($uname === '') $uname = $a['user_name'] ?? 'Sistema';
            $verb = $actionLabels[$a['action']] ?? $a['action'];
            $cardRef = '';
            if (!empty($a['plugin_kanpro_cards_id'])) {
                $cardRef = ' <a href="' . $CFG_GLPI['root_doc'] . '/plugins/kanpro/front/kanban.php?boards_id=' . $boards_id . '&open_card=' . (int)$a['plugin_kanpro_cards_id'] . '" style="color:#0747a6">#' . (int)$a['plugin_kanpro_cards_id'] . ' ' . htmlspecialchars($a['card_name'] ?? '') . '</a>';
            }
            $detail = trim($a['details'] ?? '');
            $detail = preg_replace('/^\[from:\d+\]\s*/', '', $detail);
            $last = ($i === count($rows) - 1);
            echo "<div style='display:flex;gap:10px'>"
                . "<div style='display:flex;flex-direction:column;align-items:center;flex-shrink:0;width:14px'><span style='width:10px;height:10px;border-radius:50%;background:#0079bf;margin-top:4px'></span>" . ($last ? '' : '<span style="width:2px;flex:1;background:#dfe1e6;min-height:12px"></span>') . "</div>"
                . "<div style='padding-bottom:14px;min-width:0'><div style='font-size:13px'><strong>" . htmlspecialchars($uname) . "</strong> " . htmlspecialchars($verb) . $cardRef . "</div>"
                . ($detail !== '' ? "<div style='font-size:12px;color:#5e6c84;margin-top:2px'>" . htmlspecialchars($detail) . "</div>" : '')
                . "<div style='font-size:11px;color:#97a0af;margin-top:2px'>" . htmlspecialchars($a['date_creation'] ?? '') . "</div></div>"
                . "</div>";
        }
        echo "</div>";
    }
} else {
    echo "<div style='text-align:center;padding:60px 20px;background:#f4f5f7;border-radius:8px'>";
    echo "<i class='ti ti-history' style='font-size:48px;color:#97a0af'></i>";
    echo "<h3 style='color:#172b4d;margin:16px 0 8px'>Escolha um quadro</h3>";
    echo "<p style='color:#6b778c'>Selecione acima para ver o histórico de modificações, geral ou por pessoa.</p>";
    echo "</div>";
}

echo "</div>";
Html::footer();
