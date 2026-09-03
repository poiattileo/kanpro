<?php
include('../../../inc/includes.php');
header('Content-Type: text/plain; charset=UTF-8');
echo "UID=" . Session::getLoginUserID() . "\n";
echo "profile_id=" . ($_SESSION['glpiactiveprofile']['id'] ?? 'null') . "\n";
echo "profile_name=" . ($_SESSION['glpiactiveprofile']['name'] ?? 'null') . "\n";
echo "plugin_kanpro rights in SESSION=" . ($_SESSION['glpiactiveprofile']['plugin_kanpro'] ?? 'null') . "\n";
echo "have READ=" . (int)Session::haveRight('plugin_kanpro', READ) . "\n";
echo "have CREATE=" . (int)Session::haveRight('plugin_kanpro', CREATE) . "\n";
echo "have UPDATE=" . (int)Session::haveRight('plugin_kanpro', UPDATE) . "\n";
echo "have DELETE=" . (int)Session::haveRight('plugin_kanpro', DELETE) . "\n";
echo "have PURGE=" . (int)Session::haveRight('plugin_kanpro', PURGE) . "\n";
global $DB;
$rows = $DB->request(['FROM'=>'glpi_profilerights','WHERE'=>['name'=>'plugin_kanpro']]);
foreach ($rows as $r) echo "DB profile {$r['profiles_id']} rights={$r['rights']}\n";
echo "CFG root_doc=" . ($CFG_GLPI['root_doc'] ?? 'null') . "\n";
echo "PLUGIN_HOOKS csrf_compliant=" . json_encode($PLUGIN_HOOKS['csrf_compliant']['kanpro'] ?? null) . "\n";
