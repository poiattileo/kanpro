<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproLabel extends CommonDBTM {
    static $rightname = 'plugin_kanpro';

    static function getTypeName($nb = 0) {
        return _n('Etiqueta', 'Etiquetas', $nb, 'kanpro');
    }

    static function getColors(): array {
        return [
            '#61bd4f' => 'Verde',
            '#f2d600' => 'Amarelo',
            '#ff9f1a' => 'Laranja',
            '#eb5a46' => 'Vermelho',
            '#c377e0' => 'Roxo',
            '#0079bf' => 'Azul',
            '#00b8d9' => 'Ciano',
            '#ff78cb' => 'Rosa',
            '#344563' => 'Preto',
            '#6b778c' => 'Cinza',
        ];
    }

    static function getForBoard($boards_id): array {
        global $DB;
        $iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_labels', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id], 'ORDER' => 'id ASC']);
        $out = [];
        foreach ($iter as $r) $out[] = $r;
        return $out;
    }
}
