<?php
include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/kanpro/inc/acting.php');
Session::checkRight('plugin_kanpro', READ);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); die('ID inválido'); }
global $DB;
$row = $DB->request(['FROM' => 'glpi_plugin_kanpro_attachments', 'WHERE' => ['id' => $id]])->current();
if (!$row) { http_response_code(404); die('Não encontrado'); }

// Confere acesso ao quadro (mesma regra do kanban.php): criador, membro, perfil GLPI ou legado aberto
$card_id = (int)($row['plugin_kanpro_cards_id'] ?? 0);
$card = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['id' => $card_id]])->current();
if (!$card) { http_response_code(404); die('Cartão não encontrado'); }
$boards_id = (int)($card['plugin_kanpro_boards_id'] ?? 0);
if (!kanpro_can_view_board($boards_id)) { http_response_code(403); die('Sem acesso a este quadro'); }

// Anti path-traversal: resolve caminho real e garante que está dentro de files/_plugins/kanpro
$base = realpath(GLPI_PLUGIN_DOC_DIR . '/kanpro');
$path = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . ($row['filepath'] ?? '');
$real = realpath($path);
if (!$real || !$base || strpos($real, $base) !== 0 || !is_file($real)) {
    http_response_code(404);
    die('Arquivo não encontrado');
}

// MIME confiável via finfo (não confia no mime gravado no banco, que vem do client)
$finfoMime = null;
if (function_exists('finfo_open')) {
    try {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if ($f) { $finfoMime = finfo_file($f, $real); finfo_close($f); }
    } catch (Throwable $e) {}
}
$mime = $finfoMime ?: 'application/octet-stream';
// SVG pode conter JS — força download em vez de inline
$ext = strtolower(pathinfo($row['name'] ?? '', PATHINFO_EXTENSION));
$disposition = ($mime === 'image/svg+xml' || $ext === 'svg') ? 'attachment' : 'inline';

// Nome seguro p/ header (anti header-injection + UTF-8)
$rawName = (string)($row['name'] ?? 'arquivo');
$rawName = str_replace(["\r", "\n", '"'], '', basename($rawName));
if ($rawName === '') $rawName = 'arquivo';
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', $rawName);
if ($asciiName === '' || $asciiName === null) $asciiName = 'arquivo';

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($rawName));
header('Content-Length: ' . filesize($real));
readfile($real);
