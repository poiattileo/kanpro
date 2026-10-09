<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproList extends CommonDBTM {

    static string $rightname = 'plugin_kanpro';

    static function getTypeName($nb = 0) {
        return _n('Lista', 'Listas', $nb, 'kanpro');
    }

    function prepareInputForAdd($input) {
        if (empty($input['name'])) {
            $input['name'] = 'Nova Lista';
        }
        if (!isset($input['rank']) || $input['rank'] == 0) {
            // pega maior rank + 1000
            global $DB;
            $row = $DB->request([
                'SELECT' => ['MAX' => 'rank AS maxrank'],
                'FROM'   => 'glpi_plugin_kanpro_lists',
                'WHERE'  => ['plugin_kanpro_boards_id' => $input['plugin_kanpro_boards_id']],
            ])->current();
            $max = $row['maxrank'] ?? 0;
            $input['rank'] = floatval($max) + 1024;
        }
        if (empty($input['users_id'])) {
            try {
                global $DB;
                if ($DB->tableExists('glpi_plugin_kanpro_lists') && $DB->fieldExists('glpi_plugin_kanpro_lists', 'users_id')) {
                    $input['users_id'] = function_exists('kanpro_acting_user_id') ? kanpro_acting_user_id() : (int)Session::getLoginUserID();
                } else {
                    unset($input['users_id']);
                }
            } catch (Throwable $e) {
                unset($input['users_id']);
            }
        }
        $input['date_creation'] = date('Y-m-d H:i:s');
        $input['date_mod'] = $input['date_creation'];
        return $input;
    }

    function prepareInputForUpdate($input) {
        $input['date_mod'] = date('Y-m-d H:i:s');
        return $input;
    }

    function cleanDBonPurge() {
        global $DB;
        $lid = $this->getID();
        if ($DB->tableExists('glpi_plugin_kanpro_lists_viewers')) {
            $DB->delete('glpi_plugin_kanpro_lists_viewers', ['plugin_kanpro_lists_id' => $lid]);
        }
        $cards = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['plugin_kanpro_lists_id' => $lid]]);
        foreach ($cards as $c) {
            $card = new PluginKanproCard();
            $card->delete(['id' => $c['id']], true);
        }
    }

    static function getListsForBoard($boards_id, $include_archived = false): array {
        global $DB;
        $where = ['plugin_kanpro_boards_id' => $boards_id];
        if (!$include_archived) $where['is_archived'] = 0;
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_kanpro_lists',
            'WHERE' => $where,
            'ORDER' => 'rank ASC',
        ]);
        $out = [];
        foreach ($iter as $row) $out[] = $row;
        return $out;
    }

    // Reordena listas via array de IDs
    static function reorder($boards_id, array $ordered_ids) {
        global $DB;
        $rank = 1024;
        foreach ($ordered_ids as $id) {
            $DB->update('glpi_plugin_kanpro_lists', ['rank' => $rank], ['id' => $id, 'plugin_kanpro_boards_id' => $boards_id]);
            $rank += 1024;
        }
    }
}
