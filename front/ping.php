<?php
include('../../../inc/includes.php');
header('Content-Type: application/json');
echo json_encode([
  'success'=>true,
  'msg'=>'pong',
  'uid'=>Session::getLoginUserID(),
  'profile'=>$_SESSION['glpiactiveprofile']['id'] ?? null,
  'haveREAD'=>(int)Session::haveRight('plugin_kanpro', READ),
  'haveCREATE'=>(int)Session::haveRight('plugin_kanpro', CREATE),
  'session_kanpro'=>$_SESSION['glpiactiveprofile']['plugin_kanpro'] ?? null,
]);
