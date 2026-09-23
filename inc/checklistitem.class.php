<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproChecklistItem extends CommonDBTM {
    static $rightname = 'plugin_kanpro';
    static function getTable($classname = null) { return 'glpi_plugin_kanpro_checklist_items'; }
    static function getTypeName($nb = 0) { return 'Item Checklist'; }

    function prepareInputForAdd($input) {
        if (empty($input['name'])) return false;
        if (!isset($input['rank']) || $input['rank']==0) {
            global $DB;
            $row = $DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => 'glpi_plugin_kanpro_checklist_items', 'WHERE' => ['plugin_kanpro_checklists_id' => $input['plugin_kanpro_checklists_id']]])->current();
            $input['rank'] = floatval($row['m'] ?? 0) + 1024;
        }
        $input['users_id'] = $input['users_id'] ?? (function_exists('kanpro_acting_user_id') ? kanpro_acting_user_id() : (int)Session::getLoginUserID());
        return $input;
    }
}
