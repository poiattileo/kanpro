<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproComment extends CommonDBTM {
    static $rightname = 'plugin_kanpro';
    static function getTypeName($nb = 0) { return _n('Comentário', 'Comentários', $nb); }

    function prepareInputForAdd($input) {
        if (empty(trim($input['content'] ?? ''))) return false;
        $input['users_id'] = Session::getLoginUserID();
        $input['date_creation'] = date('Y-m-d H:i:s');
        $input['date_mod'] = $input['date_creation'];
        return $input;
    }
    function prepareInputForUpdate($input) {
        $input['date_mod'] = date('Y-m-d H:i:s');
        return $input;
    }
    function post_addItem() {
        global $DB;
        $card = new PluginKanproCard();
        if ($card->getFromDB($this->fields['plugin_kanpro_cards_id'])) {
            PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $card->getID(), $card->fields['plugin_kanpro_lists_id'], 'comment_add', substr($this->fields['content'], 0, 100));
        }
    }
}
