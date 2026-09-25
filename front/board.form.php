<?php
if (function_exists('opcache_invalidate')) @opcache_invalidate(GLPI_ROOT . '/plugins/kanpro/inc/board.class.php', true);
include('../../../inc/includes.php');

$board = new PluginKanproBoard();

// Schema canônico em hook.php — sem DDL no caminho quente.

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
    // garante que background não vá via add (será tratado após criar ID)
    $bgFile = $_FILES['background_image'] ?? null;
    $tmpBg = $_POST['background'] ?? null;
    unset($_POST['background']);
    $board->check(-1, CREATE, $_POST);
    $newID = $board->add($_POST);
    if ($newID) {
        // upload de imagem de fundo (tema)
        if (!empty($bgFile) && ($bgFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            PluginKanproBoard::handleBackgroundUpload((int)$newID, $bgFile);
        }
        Html::redirect($CFG_GLPI['root_doc'] . "/plugins/kanpro/front/kanban.php?boards_id={$newID}");
    } else {
        Html::back();
    }
} else if (isset($_POST['update'])) {
    Session::checkRight('plugin_kanpro', UPDATE);
    kanpro_normalize_board_color_input($_POST);
    $bid = (int)($_POST['id'] ?? 0);
    // remove imagem se marcado
    $removeBg = !empty($_POST['remove_background']);
    unset($_POST['remove_background']);
    $bgFile = $_FILES['background_image'] ?? null;
    unset($_POST['background']);
    $board->check($_POST['id'], UPDATE);
    $board->update($_POST);
    if ($bid) {
        if ($removeBg) {
            PluginKanproBoard::deleteBackgroundFile($bid);
        }
        if (!empty($bgFile) && ($bgFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            PluginKanproBoard::handleBackgroundUpload($bid, $bgFile);
        }
    }
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
