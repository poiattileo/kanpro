<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproCard extends CommonDBTM {

    static $rightname = 'plugin_kanpro';

    static function getTypeName($nb = 0) {
        return _n('Cartão', 'Cartões', $nb, 'kanpro');
    }

    function prepareInputForAdd($input) {
        if (empty($input['name'])) {
            Session::addMessageAfterRedirect('Nome do cartão obrigatório', false, ERROR);
            return false;
        }
        if (!isset($input['rank']) || $input['rank'] == 0) {
            global $DB;
            $row = $DB->request([
                'SELECT' => ['MAX' => 'rank AS maxrank'],
                'FROM'   => 'glpi_plugin_kanpro_cards',
                'WHERE'  => ['plugin_kanpro_lists_id' => $input['plugin_kanpro_lists_id']],
            ])->current();
            $input['rank'] = floatval($row['maxrank'] ?? 0) + 1024;
            // garante boards_id
            if (empty($input['plugin_kanpro_boards_id'])) {
                $list = new PluginKanproList();
                if ($list->getFromDB($input['plugin_kanpro_lists_id'])) {
                    $input['plugin_kanpro_boards_id'] = $list->fields['plugin_kanpro_boards_id'];
                }
            }
        }
        $input['users_id'] = $input['users_id'] ?? Session::getLoginUserID();
        $input['date_creation'] = date('Y-m-d H:i:s');
        $input['date_mod'] = $input['date_creation'];
        return $input;
    }

    function prepareInputForUpdate($input) {
        $input['date_mod'] = date('Y-m-d H:i:s');
        return $input;
    }

    function post_addItem() {
        PluginKanproBoard::logActivity($this->fields['plugin_kanpro_boards_id'], $this->getID(), $this->fields['plugin_kanpro_lists_id'], 'card_create', "Cartão '{$this->fields['name']}' criado");
    }

    function cleanDBonPurge() {
        global $DB;
        $cid = $this->getID();
        $DB->delete('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id' => $cid]);
        $DB->delete('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => $cid]);
        // checklists
        $cls = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['plugin_kanpro_cards_id' => $cid]]);
        foreach ($cls as $cl) {
            $DB->delete('glpi_plugin_kanpro_checklist_items', ['plugin_kanpro_checklists_id' => $cl['id']]);
        }
        $DB->delete('glpi_plugin_kanpro_checklists', ['plugin_kanpro_cards_id' => $cid]);
        $DB->delete('glpi_plugin_kanpro_comments', ['plugin_kanpro_cards_id' => $cid]);
        // apaga anexos físicos
        $atts = $DB->request(['FROM' => 'glpi_plugin_kanpro_attachments', 'WHERE' => ['plugin_kanpro_cards_id' => $cid]]);
        foreach ($atts as $att) {
            if (!empty($att['filepath']) && file_exists(GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $att['filepath'])) {
                @unlink(GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $att['filepath']);
            }
        }
        $DB->delete('glpi_plugin_kanpro_attachments', ['plugin_kanpro_cards_id' => $cid]);
        $DB->delete('glpi_plugin_kanpro_activities', ['plugin_kanpro_cards_id' => $cid]);
    }

    // Helpers
    static function getCardsForList($lists_id, $include_archived = false): array {
        global $DB;
        $where = ['plugin_kanpro_lists_id' => $lists_id];
        if (!$include_archived) $where['is_archived'] = 0;
        $iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => $where, 'ORDER' => 'rank ASC']);
        $out = [];
        foreach ($iter as $r) $out[] = $r;
        return $out;
    }

    static function getCardsForBoard($boards_id, $include_archived = false): array {
        global $DB;
        $where = ['plugin_kanpro_boards_id' => $boards_id];
        if (!$include_archived) $where['is_archived'] = 0;
        $iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => $where, 'ORDER' => 'rank ASC']);
        $out = [];
        foreach ($iter as $r) $out[] = $r;
        return $out;
    }

    static function reorderInList($lists_id, array $ordered_ids) {
        global $DB;
        $rank = 1024;
        foreach ($ordered_ids as $id) {
            $DB->update('glpi_plugin_kanpro_cards', ['rank' => $rank, 'plugin_kanpro_lists_id' => $lists_id], ['id' => $id]);
            $rank += 1024;
        }
    }

    static function moveCard($cards_id, $target_lists_id, $new_rank = null, $target_boards_id = null) {
        global $DB;
        $card = new self();
        if (!$card->getFromDB($cards_id)) return false;
        $old_list = $card->fields['plugin_kanpro_lists_id'];
        $old_rank = $card->fields['rank'];

        // se não deu rank, coloca no fim
        if ($new_rank === null) {
            $row = $DB->request([
                'SELECT' => ['MAX' => 'rank AS maxrank'],
                'FROM'   => 'glpi_plugin_kanpro_cards',
                'WHERE'  => ['plugin_kanpro_lists_id' => $target_lists_id],
            ])->current();
            $new_rank = floatval($row['maxrank'] ?? 0) + 1024;
        }

        $update = [
            'plugin_kanpro_lists_id' => $target_lists_id,
            'rank' => $new_rank,
            'date_mod' => date('Y-m-d H:i:s'),
        ];
        if ($target_boards_id !== null) {
            $update['plugin_kanpro_boards_id'] = $target_boards_id;
        }

        $DB->update('glpi_plugin_kanpro_cards', $update, ['id' => $cards_id]);

        PluginKanproBoard::logActivity(
            $target_boards_id ?? $card->fields['plugin_kanpro_boards_id'],
            $cards_id,
            $target_lists_id,
            'card_move',
            "Cartão movido de lista {$old_list} para {$target_lists_id}"
        );
        return true;
    }

    // Copy card
    static function duplicate($cards_id, $target_lists_id = null) {
        global $DB;
        $card = new self();
        if (!$card->getFromDB($cards_id)) return false;
        $orig = $card->fields;
        $new_list = $target_lists_id ?? $orig['plugin_kanpro_lists_id'];

        $new = new self();
        $new_id = $new->add([
            'plugin_kanpro_boards_id' => $orig['plugin_kanpro_boards_id'],
            'plugin_kanpro_lists_id'  => $new_list,
            'name'        => $orig['name'] . ' (cópia)',
            'description' => $orig['description'],
            'due_date'    => $orig['due_date'],
            'start_date'  => $orig['start_date'],
            'cover_color' => $orig['cover_color'],
        ]);
        if (!$new_id) return false;

        // copia etiquetas
        $labels = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards_labels', 'WHERE' => ['plugin_kanpro_cards_id' => $cards_id]]);
        foreach ($labels as $lbl) {
            $DB->insert('glpi_plugin_kanpro_cards_labels', [
                'plugin_kanpro_cards_id' => $new_id,
                'plugin_kanpro_labels_id' => $lbl['plugin_kanpro_labels_id'],
            ]);
        }
        // copia membros
        $members = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards_members', 'WHERE' => ['plugin_kanpro_cards_id' => $cards_id]]);
        foreach ($members as $m) {
            $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => $new_id, 'users_id' => $m['users_id']]);
        }
        // copia checklists
        $cls = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['plugin_kanpro_cards_id' => $cards_id]]);
        foreach ($cls as $cl) {
            $new_cl_id = $DB->insert('glpi_plugin_kanpro_checklists', [
                'plugin_kanpro_cards_id' => $new_id,
                'name' => $cl['name'],
                'rank' => $cl['rank'],
            ]);
            // pega id inserido
            $new_cl_row = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['plugin_kanpro_cards_id' => $new_id, 'name' => $cl['name']]])->current();
            $items = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklist_items', 'WHERE' => ['plugin_kanpro_checklists_id' => $cl['id']]]);
            foreach ($items as $it) {
                $DB->insert('glpi_plugin_kanpro_checklist_items', [
                    'plugin_kanpro_checklists_id' => $new_cl_row['id'],
                    'name' => $it['name'],
                    'is_checked' => $it['is_checked'],
                    'rank' => $it['rank'],
                ]);
            }
        }
        PluginKanproBoard::logActivity($orig['plugin_kanpro_boards_id'], $new_id, $new_list, 'card_copy', "Cartão copiado de #{$cards_id}");
        return $new_id;
    }

    // Dados completos do cartão para modal
    static function getFullData($cards_id): ?array {
        global $DB;
        $card = new self();
        if (!$card->getFromDB($cards_id)) return null;
        $data = $card->fields;

        // labels
        $data['labels'] = [];
        $iter = $DB->request([
            'SELECT' => ['l.*'],
            'FROM'   => 'glpi_plugin_kanpro_cards_labels AS cl',
            'LEFT JOIN' => ['glpi_plugin_kanpro_labels AS l' => ['ON' => ['l' => 'id', 'cl' => 'plugin_kanpro_labels_id']]],
            'WHERE'  => ['cl.plugin_kanpro_cards_id' => $cards_id],
        ]);
        foreach ($iter as $r) $data['labels'][] = $r;

        // members
        $data['members'] = [];
        $iter = $DB->request([
            'SELECT' => ['u.id', 'u.name', 'u.realname', 'u.firstname', 'u.picture'],
            'FROM'   => 'glpi_plugin_kanpro_cards_members AS cm',
            'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['u' => 'id', 'cm' => 'users_id']]],
            'WHERE'  => ['cm.plugin_kanpro_cards_id' => $cards_id],
        ]);
        foreach ($iter as $r) $data['members'][] = $r;

        // checklists com items
        $data['checklists'] = [];
        $cls = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['plugin_kanpro_cards_id' => $cards_id], 'ORDER' => 'rank ASC']);
        foreach ($cls as $cl) {
            $items = [];
            $its = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklist_items', 'WHERE' => ['plugin_kanpro_checklists_id' => $cl['id']], 'ORDER' => 'rank ASC']);
            foreach ($its as $it) $items[] = $it;
            $cl['items'] = $items;
            $data['checklists'][] = $cl;
        }

        // comments
        $data['comments'] = [];
        $coms = $DB->request([
            'SELECT' => ['c.*', 'u.name AS user_name', 'u.realname', 'u.firstname', 'u.picture'],
            'FROM'   => 'glpi_plugin_kanpro_comments AS c',
            'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['u' => 'id', 'c' => 'users_id']]],
            'WHERE'  => ['c.plugin_kanpro_cards_id' => $cards_id],
            'ORDER'  => 'c.date_creation ASC',
        ]);
        foreach ($coms as $c) $data['comments'][] = $c;

        // attachments
        $data['attachments'] = [];
        $atts = $DB->request(['FROM' => 'glpi_plugin_kanpro_attachments', 'WHERE' => ['plugin_kanpro_cards_id' => $cards_id], 'ORDER' => 'date_creation DESC']);
        foreach ($atts as $a) $data['attachments'][] = $a;

        // activities do cartão
        $data['activities'] = [];
        $acts = $DB->request([
            'SELECT' => ['a.*', 'u.name AS user_name', 'u.realname', 'u.firstname'],
            'FROM'   => 'glpi_plugin_kanpro_activities AS a',
            'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['u' => 'id', 'a' => 'users_id']]],
            'WHERE'  => ['a.plugin_kanpro_cards_id' => $cards_id],
            'ORDER'  => 'a.date_creation DESC',
            'LIMIT'  => 50,
        ]);
        foreach ($acts as $a) $data['activities'][] = $a;

        // list & board names
        $list = new PluginKanproList();
        if ($list->getFromDB($data['plugin_kanpro_lists_id'])) {
            $data['list_name'] = $list->fields['name'];
        }
        $board = new PluginKanproBoard();
        if ($board->getFromDB($data['plugin_kanpro_boards_id'])) {
            $data['board_name'] = $board->fields['name'];
            $data['board_color'] = $board->fields['color'];
        }

        return $data;
    }
}
