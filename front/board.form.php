<?php
if (function_exists('opcache_invalidate')) @opcache_invalidate(GLPI_ROOT . '/plugins/kanpro/inc/board.class.php', true);
include('../../../inc/includes.php');

$board = new PluginKanproBoard();

// migração silenciosa para quem atualizou via git sem reinstalar
try {
    global $DB;
    if ($DB->fieldExists('glpi_plugin_kanpro_boards', 'color')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` MODIFY `color` VARCHAR(255) NOT NULL DEFAULT '#0079bf'");
    }
} catch (Throwable $e) {}

if (!function_exists('kanpro_normalize_board_color_input')) {
// Normaliza cor/tema vinda do picker (hex ou linear-gradient). Aceita sólidos e degradês.
function kanpro_normalize_board_color_input(array &$input): void {
    $color = trim($input['color'] ?? '');
    $custom = trim($input['color_custom'] ?? '');
    // fallback: se color vazio mas custom tem hex válido, usa custom (compatibilidade picker antigo)
    if ($color === '' && preg_match('/^#[0-9a-fA-F]{6}$/', $custom)) {
        $color = $custom;
    }
    $isHex = (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $color);
    $isGradient = (strpos($color, 'linear-gradient') === 0);
    // Se não é hex nem gradient, tenta custom
    if (!$isHex && !$isGradient) {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $custom)) {
            $color = $custom;
            $isHex = true;
            $isGradient = false;
        } else {
            // em criação: fallback para azul padrão; em edição: mantém existente (remove do input para não sobrescrever com vazio)
            if (empty($input['id'])) {
                $color = '#0079bf';
                $isHex = true;
            } else {
                unset($input['color']);
                unset($input['color_custom']);
                return;
            }
        }
    }
    if (strlen($color) > 255) $color = substr($color, 0, 255);
    $input['color'] = $color;
    unset($input['color_custom']);
}
}

if (isset($_POST['add'])) {
    Session::checkRight('plugin_kanpro', CREATE);
    kanpro_normalize_board_color_input($_POST);
    $board->check(-1, CREATE, $_POST);
    $newID = $board->add($_POST);
    if ($newID) {
        Html::redirect($CFG_GLPI['root_doc'] . "/plugins/kanpro/front/kanban.php?boards_id={$newID}");
    } else {
        Html::back();
    }
} else if (isset($_POST['update'])) {
    Session::checkRight('plugin_kanpro', UPDATE);
    kanpro_normalize_board_color_input($_POST);
    $board->check($_POST['id'], UPDATE);
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
