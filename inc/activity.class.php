<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproActivity extends CommonDBTM {
    static $rightname = 'plugin_kanpro';
    static function getTypeName($nb = 0) { return 'Atividade'; }

    static function getForBoard($boards_id, $limit = 50): array {
        global $DB;
        $iter = $DB->request([
            'SELECT' => ['a.*', 'u.name AS user_name', 'u.realname', 'u.firstname'],
            'FROM'   => 'glpi_plugin_kanpro_activities AS a',
            'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['u' => 'id', 'a' => 'users_id']]],
            'WHERE'  => ['a.plugin_kanpro_boards_id' => $boards_id],
            'ORDER'  => 'a.date_creation DESC',
            'LIMIT'  => $limit,
        ]);
        $out = [];
        foreach ($iter as $r) $out[] = $r;
        return $out;
    }
}
