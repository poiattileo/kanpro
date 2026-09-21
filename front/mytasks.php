<?php
// Minhas Tarefas — agrega cartões atribuídos ao usuário logado em todos os quadros
include('../../../inc/includes.php');
Session::checkRight('plugin_kanpro', READ);

global $DB, $CFG_GLPI;

$uid = (int)Session::getLoginUserID();
$filter_board = (int)($_GET['boards_id'] ?? 0);
$filter_overdue = isset($_GET['overdue']) && $_GET['overdue'] == 1;

Html::header('KanPro - Minhas tarefas', $_SERVER['PHP_SELF'], 'tools', 'PluginKanproBoard');

// cartões onde sou membro (não arquivados)
$memberCardIds = [];
$cmIter = $DB->request(['SELECT' => ['plugin_kanpro_cards_id'], 'FROM' => 'glpi_plugin_kanpro_cards_members', 'WHERE' => ['users_id' => $uid]]);
foreach ($cmIter as $r) $memberCardIds[] = (int)$r['plugin_kanpro_cards_id'];
$memberCardIds = array_values(array_unique($memberCardIds));

$tasks = [];
if (!empty($memberCardIds)) {
    $where = ['c.id' => $memberCardIds, 'c.is_archived' => 0];
    if ($filter_board > 0) $where['c.plugin_kanpro_boards_id'] = $filter_board;
    $iter = $DB->request([
        'SELECT' => ['c.*', 'b.name AS board_name', 'b.color AS board_color', 'l.name AS list_name'],
        'FROM'   => 'glpi_plugin_kanpro_cards AS c',
        'LEFT JOIN' => [
            'glpi_plugin_kanpro_boards AS b' => ['ON' => ['b' => 'id', 'c' => 'plugin_kanpro_boards_id']],
            'glpi_plugin_kanpro_lists AS l'  => ['ON' => ['l' => 'id', 'c' => 'plugin_kanpro_lists_id']],
        ],
        'WHERE'  => $where,
        'ORDER'  => 'c.due_date ASC',
    ]);
    foreach ($iter as $r) $tasks[] = $r;
}

// quadros com tarefas minhas (filtro)
$myBoards = [];
foreach ($tasks as $t) {
    $bid = (int)$t['plugin_kanpro_boards_id'];
    if (!isset($myBoards[$bid])) $myBoards[$bid] = ['name' => $t['board_name'] ?? ('Quadro #' . $bid), 'count' => 0];
    $myBoards[$bid]['count']++;
}

// ordena: atrasados primeiro, depois vencimento próximo, sem data por último
$now = time();
usort($tasks, function ($a, $b) use ($now) {
    $score = function ($t) use ($now) {
        if (!empty($t['is_completed'])) return [3, 0];
        if (empty($t['due_date'])) return [2, 0];
        $ts = strtotime($t['due_date']);
        if ($ts < $now) return [0, $ts];
        return [1, $ts];
    };
    return $score($a) <=> $score($b);
});

if ($filter_overdue) {
    $tasks = array_values(array_filter($tasks, function ($t) use ($now) {
        return empty($t['is_completed']) && !empty($t['due_date']) && strtotime($t['due_date']) < $now;
    }));
}

$overdueCount = 0;
foreach ($tasks as $t) {
    if (empty($t['is_completed']) && !empty($t['due_date']) && strtotime($t['due_date']) < $now) $overdueCount++;
}

$qsBase = function (array $over = []) {
    $p = [];
    if (!empty($_GET['boards_id'])) $p['boards_id'] = (int)$_GET['boards_id'];
    if (!empty($_GET['overdue'])) $p['overdue'] = 1;
    $p = array_merge($p, $over);
    foreach ($p as $k => $v) {
        if ($v === 0 || $v === '' || $v === null) unset($p[$k]);
    }
    return $p ? ('?' . http_build_query($p)) : '';
};

echo "<div class='kanpro-page' style='max-width:1100px;margin:0 auto;padding:20px'>";
echo "<div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px'>";
echo "<h1 style='margin:0;font-size:22px;display:flex;align-items:center;gap:10px'><i class='ti ti-user-check' style='font-size:28px;color:#0079bf'></i> Minhas tarefas <span style='font-size:13px;font-weight:400;color:#6b778c'>" . count($tasks) . " cartão(ões)" . ($overdueCount ? " • <strong style='color:#de350b'>{$overdueCount} atrasado(s)</strong>" : "") . "</span></h1>";
echo "<div style='display:flex;gap:8px'><a href='board.php' class='btn btn-outline-secondary btn-sm'><i class='ti ti-arrow-left'></i> Quadros</a></div>";
echo "</div>";

// filtros
echo "<div style='display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center'>";
echo "<a href='" . $qsBase(['boards_id' => 0, 'overdue' => 0]) . "' class='btn btn-sm' style='border:1px solid #dfe1e6;" . ($filter_board === 0 && !$filter_overdue ? 'background:#0079bf;color:#fff;border-color:#0079bf' : '') . "'>Todos os quadros</a>";
foreach ($myBoards as $bid => $b) {
    $act = ($filter_board === $bid && !$filter_overdue) ? 'background:#0079bf;color:#fff;border-color:#0079bf' : '';
    echo "<a href='" . $qsBase(['boards_id' => $bid, 'overdue' => 0]) . "' class='btn btn-sm' style='border:1px solid #dfe1e6;{$act}'>" . htmlspecialchars(mb_strimwidth($b['name'], 0, 24, '…')) . " ({$b['count']})</a>";
}
$actOd = $filter_overdue ? 'background:#de350b;color:#fff;border-color:#de350b' : '';
echo "<a href='" . $qsBase(['overdue' => $filter_overdue ? 0 : 1]) . "' class='btn btn-sm' style='border:1px solid #dfe1e6;{$actOd}'><i class='ti ti-alert-triangle'></i> Só atrasados</a>";
echo "</div>";

if (empty($tasks)) {
    echo "<div style='text-align:center;padding:60px 20px;background:#f4f5f7;border-radius:8px'>";
    echo "<i class='ti ti-checkbox' style='font-size:48px;color:#97a0af'></i>";
    echo "<h3 style='color:#172b4d;margin:16px 0 8px'>Nada por aqui 🎉</h3>";
    echo "<p style='color:#6b778c'>Nenhum cartão atribuído a você" . ($filter_board || $filter_overdue ? " neste filtro." : ". Peça para te adicionarem como membro de um cartão.") . "</p>";
    echo "</div>";
} else {
    echo "<div style='display:grid;gap:10px'>";
    foreach ($tasks as $t) {
        $cid = (int)$t['id'];
        $bid = (int)$t['plugin_kanpro_boards_id'];
        $url = "kanban.php?boards_id={$bid}&open_card={$cid}";
        $isDone = !empty($t['is_completed']);
        $dueTxt = 'Sem prazo';
        $dueStyle = 'background:#eaecf0;color:#5e6c84;';
        if (!empty($t['due_date'])) {
            $ts = strtotime($t['due_date']);
            $dueTxt = date('d/m/Y' . (date('H:i', $ts) !== '00:00' ? ' H:i' : ''), $ts);
            if ($isDone) {
                $dueStyle = 'background:#e3fcef;color:#006644;';
                $dueTxt = '✔ ' . $dueTxt;
            } elseif ($ts < $now) {
                $dueStyle = 'background:#ffebe6;color:#bf2600;font-weight:700;';
                $dueTxt = '⚠ Atrasado: ' . $dueTxt;
            } elseif ($ts - $now < 86400) {
                $dueStyle = 'background:#fffae6;color:#172b4d;font-weight:700;border:1px solid #ffab00;';
                $dueTxt = '⏰ Vence: ' . $dueTxt;
            } else {
                $dueTxt = '📅 ' . $dueTxt;
            }
        }
        $boardColor = htmlspecialchars($t['board_color'] ?? '#0079bf', ENT_QUOTES);
        echo "<a href='{$url}' style='display:flex;gap:12px;align-items:center;background:#fff;border:1px solid #dfe1e6;border-left:5px solid {$boardColor};border-radius:8px;padding:12px 16px;text-decoration:none;color:#172b4d;transition:.15s' onmouseover=\"this.style.boxShadow='0 2px 8px rgba(0,0,0,.12)'\" onmouseout=\"this.style.boxShadow='none'\">";
        echo "<div style='flex:1;min-width:0'>";
        echo "<div style='font-weight:700;font-size:15px;" . ($isDone ? 'text-decoration:line-through;color:#6b778c;' : '') . "'><span style='color:#5e6c84;font-weight:700;margin-right:4px'>#{$cid}</span>" . htmlspecialchars($t['name']) . "</div>";
        echo "<div style='font-size:12px;color:#6b778c;margin-top:4px'>" . htmlspecialchars($t['board_name'] ?? '') . " • " . htmlspecialchars($t['list_name'] ?? '') . "</div>";
        echo "</div>";
        echo "<span style='font-size:12px;padding:4px 10px;border-radius:12px;white-space:nowrap;{$dueStyle}'>{$dueTxt}</span>";
        echo "</a>";
    }
    echo "</div>";
}

echo "</div>";
Html::footer();
