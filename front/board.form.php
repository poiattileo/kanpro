<?php
if (function_exists('opcache_invalidate')) @opcache_invalidate(GLPI_ROOT . '/plugins/kanpro/inc/board.class.php', true);
include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/kanpro/inc/acting.php');
if (function_exists('kanpro_ensure_family_column')) kanpro_ensure_family_column();

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
    // whitelist anti mass-assignment: ignora users_id/date_* vindos do POST (antes $board->add($_POST) aceitava tudo)
    $__allow = ['name','entities_id','is_recursive','comment','color','visibility','generate_term','whatsapp_notify','parent_boards_id'];
    $__input = array_intersect_key($_POST, array_flip($__allow));
    $__input['parent_boards_id'] = max(0, (int)($__input['parent_boards_id'] ?? 0));
    // pai precisa ser visível (senão volta p/ raiz)
    if ($__input['parent_boards_id'] > 0 && function_exists('kanpro_can_view_board') && !kanpro_can_view_board($__input['parent_boards_id'])) {
        $__input['parent_boards_id'] = 0;
        Session::addMessageAfterRedirect('Quadro pai sem acesso — criado como raiz.', false, WARNING);
    }
    $__input['name'] = trim(strip_tags($__input['name'] ?? ''));
    $__input['comment'] = trim(strip_tags($__input['comment'] ?? ''));
    $__input['users_id'] = (int)Session::getLoginUserID();
    // garante que background não vá via add (será tratado após criar ID)
    $bgFile = $_FILES['background_image'] ?? null;
    $tmpBg = $_POST['background'] ?? null;
    unset($_POST['background']);
    $board->check(-1, CREATE, $__input);
    $newID = $board->add($__input);
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
    // whitelist: nunca aceita users_id/date_creation via POST
    $__allowU = ['id','name','entities_id','is_recursive','comment','color','visibility','generate_term','whatsapp_notify','is_archived','is_starred','parent_boards_id'];
    $__inputU = array_intersect_key($_POST, array_flip($__allowU));
    if (isset($__inputU['name'])) $__inputU['name'] = trim(strip_tags($__inputU['name']));
    if (isset($__inputU['comment'])) $__inputU['comment'] = trim(strip_tags($__inputU['comment']));
    // familia: só gestor do quadro troca o pai (senão mantém o atual)
    if (array_key_exists('parent_boards_id', $__inputU)) {
        $__newParent = max(0, (int)$__inputU['parent_boards_id']);
        $__curParent = function_exists('kanpro_board_parent_id') ? kanpro_board_parent_id($bid) : 0;
        if ($__newParent !== $__curParent) {
            $canFam = function_exists('kanpro_can_manage_members') ? kanpro_can_manage_members($bid) : false;
            if (!$canFam) {
                unset($__inputU['parent_boards_id']);
                Session::addMessageAfterRedirect('Somente o criador ou admin do quadro pode trocar a família.', false, WARNING);
            } elseif ($__newParent === $bid) {
                unset($__inputU['parent_boards_id']);
                Session::addMessageAfterRedirect('Um quadro não pode ser filho dele mesmo.', false, ERROR);
            } elseif ($__newParent > 0 && function_exists('kanpro_can_view_board') && !kanpro_can_view_board($__newParent)) {
                unset($__inputU['parent_boards_id']);
                Session::addMessageAfterRedirect('Você não tem acesso ao quadro pai.', false, ERROR);
            } elseif ($__newParent > 0 && function_exists('kanpro_board_descendant_ids') && in_array($__newParent, kanpro_board_descendant_ids($bid), true)) {
                unset($__inputU['parent_boards_id']);
                Session::addMessageAfterRedirect('Ciclo detectado: o pai não pode ser um descendente.', false, ERROR);
            }
        }
    }
    $board->check($_POST['id'], UPDATE);
    $board->update($__inputU);
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
