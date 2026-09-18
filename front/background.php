<?php
// Serve imagem de fundo do quadro (tema)
include('../../../inc/includes.php');
Session::checkRight('plugin_kanpro', READ);

$boards_id = (int)($_GET['boards_id'] ?? $_GET['id'] ?? 0);
if (!$boards_id) {
    http_response_code(400);
    die('boards_id obrigatório');
}

$board = new PluginKanproBoard();
if (!$board->getFromDB($boards_id)) {
    http_response_code(404);
    die('Quadro não encontrado');
}
if (!$board->canViewItem()) {
    http_response_code(403);
    die('Sem permissão');
}

$bg = $board->fields['background'] ?? '';
if (empty($bg)) {
    http_response_code(404);
    die('Sem imagem de fundo');
}

// segurança: só permite dentro de boards/
if (strpos($bg, '..') !== false || strpos($bg, 'boards/') !== 0) {
    http_response_code(400);
    die('Caminho inválido');
}

$path = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $bg;
if (!is_file($path)) {
    http_response_code(404);
    die('Arquivo não encontrado');
}

// mime
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
$mime = $map[$ext] ?? mime_content_type($path) ?: 'application/octet-stream';
if (strpos($mime, 'image/') !== 0) $mime = 'image/jpeg';

$etag = md5_file($path);
$mtime = filemtime($path);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
header('Expires: ' . gmdate('D, d M Y H:i:s', time()+86400) . ' GMT');
header('ETag: "' . $etag . '"');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
// 304 se não modificado
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
    http_response_code(304);
    exit;
}
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime) {
    http_response_code(304);
    exit;
}
readfile($path);
