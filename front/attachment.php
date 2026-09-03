<?php
include('../../../inc/includes.php');
Session::checkRight('plugin_kanpro', READ);
$id = (int)($_GET['id'] ?? 0);
global $DB;
$row = $DB->request(['FROM'=>'glpi_plugin_kanpro_attachments','WHERE'=>['id'=>$id]])->current();
if (!$row) { http_response_code(404); die('Não encontrado'); }
$path = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $row['filepath'];
if (!file_exists($path)) { http_response_code(404); die('Arquivo não encontrado'); }
header('Content-Type: ' . ($row['mime'] ?: 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . $row['name'] . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
