<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}
// Classe separada para autoload GLPI (PluginKanproChecklistItem -> inc/checklistitem.class.php)
if (!class_exists('PluginKanproChecklistItem', false)) {
    include_once(__DIR__ . '/checklist.class.php');
}
