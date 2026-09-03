<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproChecklist extends CommonDBTM {
    static $rightname = 'plugin_kanpro';
    static function getTypeName($nb = 0) { return 'Checklist'; }

    function prepareInputForAdd($input) {
        if (empty($input['name'])) $input['name'] = 'Checklist';
        if (!isset($input['rank']) || $input['rank']==0) {
            global $DB;
            $row = $DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['plugin_kanpro_cards_id' => $input['plugin_kanpro_cards_id']]])->current();
            $input['rank'] = floatval($row['m'] ?? 0) + 1024;
        }
        return $input;
    }
}

class PluginKanproChecklistItem extends CommonDBTM {
    static $rightname = 'plugin_kanpro';
    static function getTypeName($nb = 0) { return 'Item Checklist'; }

    function prepareInputForAdd($input) {
        if (empty($input['name'])) return false;
        if (!isset($input['rank']) || $input['rank']==0) {
            global $DB;
            $row = $DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => 'glpi_plugin_kanpro_checklist_items', 'WHERE' => ['plugin_kanpro_checklists_id' => $input['plugin_kanpro_checklists_id']]])->current();
            $input['rank'] = floatval($row['m'] ?? 0) + 1024;
        }
        $input['users_id'] = $input['users_id'] ?? Session::getLoginUserID();
        return $input;
    }
}
