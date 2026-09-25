<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproBoardGroup extends CommonDBTM {
    static $rightname = 'plugin_kanpro';

    static function getTypeName($nb = 0) {
        return _n('Grupo de quadros', 'Grupos de quadros', $nb, 'kanpro');
    }

    public function prepareInputForAdd($input) {
        if (isset($input['name'])) $input['name'] = trim(mb_substr($input['name'], 0, 100));
        if (empty($input['name'])) {
            Session::addMessageAfterRedirect('Dê um nome ao grupo', false, ERROR);
            return false;
        }
        if (empty($input['date_creation'])) $input['date_creation'] = date('Y-m-d H:i:s');
        return $input;
    }
}
