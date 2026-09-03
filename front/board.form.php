<?php
include('../../../inc/includes.php');

$board = new PluginKanproBoard();

if (isset($_POST['add'])) {
    Session::checkRight('plugin_kanpro', CREATE);
    $board->check(-1, CREATE, $_POST);
    $newID = $board->add($_POST);
    if ($newID) {
        Html::redirect($CFG_GLPI['root_doc'] . "/plugins/kanpro/front/kanban.php?boards_id={$newID}");
    } else {
        Html::back();
    }
} else if (isset($_POST['update'])) {
    Session::checkRight('plugin_kanpro', UPDATE);
    $board->check($_POST['id'], UPDATE);
    // trata color_custom
    if (!empty($_POST['color_custom']) && preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color_custom'])) {
        // se não tem radio marcado ou custom diferente, usa custom
        $has_radio = false;
        foreach (PluginKanproBoard::getBackgroundColors() as $hex => $v) {
            if (($_POST['color'] ?? '') === $hex) { $has_radio = true; break; }
        }
        if (!$has_radio) $_POST['color'] = $_POST['color_custom'];
    }
    unset($_POST['color_custom']);
    $board->update($_POST);
    Html::back();
} else if (isset($_POST['delete'])) {
    Session::checkRight('plugin_kanpro', DELETE);
    $board->check($_POST['id'], DELETE);
    $board->delete($_POST, 1);
    Html::redirect($CFG_GLPI['root_doc'] . "/plugins/kanpro/front/board.php");
} else if (isset($_POST['purge'])) {
    Session::checkRight('plugin_kanpro', PURGE);
    $board->check($_POST['id'], PURGE);
    $board->delete($_POST, 1);
    Html::redirect($CFG_GLPI['root_doc'] . "/plugins/kanpro/front/board.php");
} else if (isset($_GET['id'])) {
    Session::checkRight('plugin_kanpro', READ);
    Html::header('Quadro', $_SERVER['PHP_SELF'], 'tools', 'PluginKanproBoard');
    $board->display(['id' => $_GET['id']]);
    Html::footer();
} else {
    Session::checkRight('plugin_kanpro', CREATE);
    Html::header('Novo Quadro', $_SERVER['PHP_SELF'], 'tools', 'PluginKanproBoard');
    $board->display(['id' => 0]);
    Html::footer();
}
