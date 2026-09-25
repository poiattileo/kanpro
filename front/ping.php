<?php
include('../../../inc/includes.php');
header('Content-Type: application/json');
if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => 'Não autenticado']);
    exit;
}
Session::checkRight('plugin_kanpro', READ);
// Health-check mínimo — sem vazar perfil/direitos (antes expunha session p/ qualquer logado)
echo json_encode([
  'success' => true,
  'msg' => 'pong',
]);
