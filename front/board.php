<?php
include('../../../inc/includes.php');
Session::checkRight('plugin_kanpro', READ);

Html::header('KanPro - Quadros', $_SERVER['PHP_SELF'], 'tools', 'PluginKanproBoard');

$canedit = Session::haveRight('plugin_kanpro', CREATE);
$entities = $_SESSION['glpiactiveentities'] ?? [0];

global $DB;
$show_archived = isset($_GET['archived']) && $_GET['archived'] == 1;
$search = $_GET['search'] ?? '';

$where = ['entities_id' => $entities];
if (!$show_archived) $where['is_archived'] = 0;
if (!empty($search)) $where['name'] = ['LIKE', "%{$search}%"];

$iterator = $DB->request([
    'FROM'  => 'glpi_plugin_kanpro_boards',
    'WHERE' => $where,
    'ORDER' => 'is_starred DESC, date_mod DESC',
]);

echo "<div class='kanpro-page' style='max-width:1400px;margin:0 auto;padding:20px'>";
echo "<div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px'>";
echo "<h1 style='margin:0;font-size:22px;display:flex;align-items:center;gap:10px'><i class='ti ti-layout-kanban' style='font-size:28px;color:#0079bf'></i> Seus Quadros</h1>";
echo "<div style='display:flex;gap:8px;align-items:center'>";
echo "<form method='get' style='display:flex;gap:6px'><input type='text' name='search' value='" . htmlspecialchars($search) . "' placeholder='Buscar quadros...' style='padding:8px 12px;border:1px solid #dfe1e6;border-radius:6px;min-width:220px'><button class='btn btn-outline-secondary btn-sm'><i class='ti ti-search'></i></button></form>";
if ($canedit) {
    echo "<a href='board.form.php' class='btn btn-primary' style='background:#0079bf;border-color:#0079bf'><i class='ti ti-plus'></i> Criar quadro</a>";
}
echo "</div></div>";

// filtros rápidos
echo "<div style='display:flex;gap:8px;margin-bottom:20px'>";
$active_all = !$show_archived ? 'background:#0079bf;color:#fff;border-color:#0079bf' : '';
$active_arc = $show_archived ? 'background:#0079bf;color:#fff;border-color:#0079bf' : '';
echo "<a href='?archived=0' class='btn btn-sm' style='border:1px solid #dfe1e6;{$active_all}'>Ativos</a>";
echo "<a href='?archived=1' class='btn btn-sm' style='border:1px solid #dfe1e6;{$active_arc}'>Arquivados</a>";
echo "</div>";

if (count($iterator) === 0) {
    echo "<div style='text-align:center;padding:60px 20px;background:#f4f5f7;border-radius:8px'>";
    echo "<i class='ti ti-layout-kanban' style='font-size:48px;color:#97a0af'></i>";
    echo "<h3 style='color:#172b4d;margin:16px 0 8px'>Nenhum quadro encontrado</h3>";
    echo "<p style='color:#6b778c'>Crie seu primeiro quadro para começar a organizar seus projetos no estilo Trello.</p>";
    if ($canedit) echo "<a href='board.form.php' class='btn btn-primary' style='margin-top:12px'><i class='ti ti-plus'></i> Criar quadro</a>";
    echo "</div>";
} else {
    echo "<div style='display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px'>";
    foreach ($iterator as $row) {
        $bid = (int)$row['id'];
        $kanban_url = "kanban.php?boards_id={$bid}";
        $edit_url   = "board.form.php?id={$bid}";
        $card_count = PluginKanproBoard::countCardsInBoard($bid);

        // conta membros
        $member_count = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => $bid]);

        // listas preview
        $lists_preview = '';
        $lists = $DB->request(['FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['plugin_kanpro_boards_id' => $bid, 'is_archived' => 0], 'ORDER' => 'rank ASC', 'LIMIT' => 3]);
        foreach ($lists as $l) {
            $lists_preview .= "<span style='background:rgba(255,255,255,.2);padding:2px 8px;border-radius:10px;font-size:11px;margin-right:4px'>" . htmlspecialchars(mb_strimwidth($l['name'], 0, 18, '…')) . "</span>";
        }

        $star = $row['is_starred'] ? '⭐' : '';
        $archived_badge = $row['is_archived'] ? "<span style='background:#ff5630;color:#fff;padding:2px 6px;border-radius:4px;font-size:11px'>Arquivado</span>" : '';

        echo "<div style='border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.15);background:#fff;display:flex;flex-direction:column;transition:transform .15s' onmouseover=\"this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,.15)'\" onmouseout=\"this.style.transform='none';this.style.boxShadow='0 1px 3px rgba(0,0,0,.15)'\">";
        // header colorido
        echo "<a href='{$kanban_url}' style='display:block;height:110px;background:" . htmlspecialchars($row['color']) . ";padding:12px;color:#fff;text-decoration:none;position:relative'>";
        echo "<div style='font-weight:700;font-size:16px;line-height:1.2;display:flex;justify-content:space-between;align-items:flex-start'><span>" . htmlspecialchars($row['name']) . " {$star}</span> {$archived_badge}</div>";
        if (!empty($row['comment'])) echo "<div style='font-size:12px;opacity:.9;margin-top:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap'>" . htmlspecialchars(mb_strimwidth($row['comment'], 0, 80, '…')) . "</div>";
        echo "<div style='position:absolute;bottom:10px;left:12px;right:12px;display:flex;gap:4px;flex-wrap:wrap'>{$lists_preview}</div>";
        echo "</a>";
        echo "<div style='padding:12px;display:flex;justify-content:space-between;align-items:center;background:#fff'>";
        echo "<div style='display:flex;gap:12px;font-size:12px;color:#6b778c'>";
        echo "<span><i class='ti ti-layout-kanban'></i> {$card_count} cartões</span>";
        echo "<span><i class='ti ti-users'></i> {$member_count}</span>";
        echo "</div>";
        echo "<div style='display:flex;gap:6px'>";
        echo "<a href='{$kanban_url}' class='btn btn-sm btn-primary' style='padding:4px 10px;font-size:12px'>Abrir</a>";
        if (Session::haveRight('plugin_kanpro', UPDATE)) {
            echo "<a href='{$edit_url}' class='btn btn-sm btn-outline-secondary' style='padding:4px 8px'><i class='ti ti-settings'></i></a>";
        }
        echo "</div>";
        echo "</div>";
        echo "</div>";
    }
    // card "Criar novo quadro"
    if ($canedit) {
        echo "<a href='board.form.php' style='border:2px dashed #dfe1e6;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:160px;text-decoration:none;color:#6b778c;background:#fafbfc;transition:.15s' onmouseover=\"this.style.borderColor='#0079bf';this.style.color='#0079bf';this.style.background='#e6fcff'\" onmouseout=\"this.style.borderColor='#dfe1e6';this.style.color='#6b778c';this.style.background='#fafbfc'\">";
        echo "<i class='ti ti-plus' style='font-size:28px'></i><span style='margin-top:8px;font-weight:600'>Criar novo quadro</span></a>";
    }
    echo "</div>";
}

echo "</div>";
Html::footer();
