<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproCard extends CommonDBTM {

    static string $rightname = 'plugin_kanpro';

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
        $input['users_id'] = $input['users_id'] ?? (function_exists('kanpro_acting_user_id') ? kanpro_acting_user_id() : (int)Session::getLoginUserID());
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
        // manutenção
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            // apaga anotações das máquinas antes das máquinas
            if ($DB->tableExists('glpi_plugin_kanpro_maintenance_notes')) {
                $mids = [];
                foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['plugin_kanpro_cards_id' => $cid]]) as $mr) {
                    $mids[] = (int)$mr['id'];
                }
                if (!empty($mids)) $DB->delete('glpi_plugin_kanpro_maintenance_notes', ['machine_id' => $mids]);
            }
            $DB->delete('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id' => $cid]);
        }
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

    /**
     * Código exibido no card: 300/2 para aprovações, 382/1 para split,
     * #123 para cards normais. O código de aprovação usa o ID do card de
     * origem + o sequencial persistido no próprio card.
     */
    static function getDisplayCode($card): string {
        if (is_numeric($card)) {
            $id = (int)$card;
            $obj = new self();
            $card = $obj->getFromDB($id) ? $obj->fields : ['id' => $id];
        }
        if (!is_array($card)) {
            return '';
        }
        $sourceId = (int)($card['chamado_source_id'] ?? 0);
        $seq = (int)($card['pendencia_seq'] ?? 0);
        if ($sourceId > 0 && $seq > 0) {
            return $sourceId . '/' . $seq;
        }
        $splitGroup = (int)($card['split_group'] ?? 0);
        if ($splitGroup > 0) {
            $splitIndex = (int)($card['split_index'] ?? 1) === 2 ? 2 : 1;
            return $splitGroup . '/' . $splitIndex;
        }
        $id = (int)($card['id'] ?? 0);
        return $id > 0 ? '#' . $id : '';
    }

    /**
     * Reserva o próximo sequencial de aprovação no card de origem.
     * O contador não regride quando os cards aprovados são excluídos:
     * 300/2, 300/3, 300/4, ...
     */
    static function nextPendenciaSeq($source_id): int {
        global $DB;
        $source_id = (int)$source_id;
        if ($source_id <= 0) {
            return 0;
        }
        try {
            if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'pendencia_next_seq')) {
                return 0;
            }
            // UPDATE atômico: 0/1 viram 2; depois 3, 4, 5...
            $DB->doQuery(
                "UPDATE `glpi_plugin_kanpro_cards`
                 SET `pendencia_next_seq` = IF(`pendencia_next_seq` < 2, 2, `pendencia_next_seq`) + 1
                 WHERE `id` = " . $source_id
            );
            $row = $DB->request([
                'SELECT' => ['pendencia_next_seq'],
                'FROM'   => 'glpi_plugin_kanpro_cards',
                'WHERE'  => ['id' => $source_id],
                'LIMIT'  => 1,
            ])->current();
            $next = (int)($row['pendencia_next_seq'] ?? 0);
            // O UPDATE guarda o próximo; o card recebe o valor anterior (2 no primeiro).
            return $next > 2 ? $next - 1 : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Backfill único para cards de aprovação criados antes das colunas
     * pendencia_seq/pendencia_next_seq. Só considera listas de Pendência
     * Chamados, nunca clones de "Abrir chamado".
     */
    static function backfillPendenciaCodes(): bool {
        global $DB;
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_cards')
                || !$DB->tableExists('glpi_plugin_kanpro_lists')
                || !$DB->fieldExists('glpi_plugin_kanpro_cards', 'pendencia_seq')
                || !$DB->fieldExists('glpi_plugin_kanpro_cards', 'pendencia_next_seq')) {
                return false;
            }

            $pendListIds = [];
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'list_type'],
                'FROM'   => 'glpi_plugin_kanpro_lists',
            ]) as $list) {
                $type = strtolower(trim((string)($list['list_type'] ?? '')));
                $name = (string)($list['name'] ?? '');
                $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
                $name = strtr($name, [
                    'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
                    'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                    'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
                    'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
                    'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
                    'ç' => 'c',
                ]);
                $isPend = ($type === 'pend_chamado');
                if (!$isPend && $type === '') {
                    $isPend = strpos($name, 'pendencia') !== false && strpos($name, 'chamado') !== false;
                }
                if ($isPend) {
                    $pendListIds[] = (int)$list['id'];
                }
            }
            if (empty($pendListIds)) {
                return true;
            }

            $bySource = [];
            foreach ($DB->request([
                'SELECT' => ['id', 'chamado_source_id', 'pendencia_seq'],
                'FROM'   => 'glpi_plugin_kanpro_cards',
                'WHERE'  => [
                    'plugin_kanpro_lists_id' => $pendListIds,
                    'chamado_source_id'      => ['>', 0],
                ],
                'ORDER'  => ['chamado_source_id ASC', 'id ASC'],
            ]) as $row) {
                $srcId = (int)($row['chamado_source_id'] ?? 0);
                if ($srcId > 0) {
                    $bySource[$srcId][] = $row;
                }
            }

            foreach ($bySource as $srcId => $rows) {
                $max = 0;
                $pending = [];
                foreach ($rows as $row) {
                    $seq = (int)($row['pendencia_seq'] ?? 0);
                    if ($seq > $max) {
                        $max = $seq;
                    }
                    if ($seq <= 0) {
                        $pending[] = (int)$row['id'];
                    }
                }
                $next = max(2, $max + 1);
                foreach ($pending as $cardId) {
                    try {
                        $DB->update('glpi_plugin_kanpro_cards', ['pendencia_seq' => $next], ['id' => $cardId]);
                    } catch (Throwable $e) {}
                    $next++;
                }
                try {
                    $srcRow = $DB->request([
                        'SELECT' => ['pendencia_next_seq'],
                        'FROM'   => 'glpi_plugin_kanpro_cards',
                        'WHERE'  => ['id' => $srcId],
                        'LIMIT'  => 1,
                    ])->current();
                    $current = (int)($srcRow['pendencia_next_seq'] ?? 2);
                    if ($next > $current) {
                        $DB->update('glpi_plugin_kanpro_cards', ['pendencia_next_seq' => $next], ['id' => $srcId]);
                    }
                } catch (Throwable $e) {}
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
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
        $newFields = [
            'plugin_kanpro_boards_id' => $orig['plugin_kanpro_boards_id'],
            'plugin_kanpro_lists_id'  => $new_list,
            'name'        => $orig['name'] . ' (cópia)',
            'description' => $orig['description'],
            'due_date'    => $orig['due_date'],
            'start_date'  => $orig['start_date'],
            'cover_color' => $orig['cover_color'],
        ];
        if (isset($orig['whatsapp_notify']) && $DB->fieldExists('glpi_plugin_kanpro_cards', 'whatsapp_notify')) {
            $newFields['whatsapp_notify'] = !empty($orig['whatsapp_notify']) ? 1 : 0;
        }
        $new_id = $new->add($newFields);
        if (!$new_id) return false;
        if (!empty($newFields['whatsapp_notify'])) {
            try { $DB->update('glpi_plugin_kanpro_cards', ['whatsapp_notify'=>1], ['id'=>$new_id]); } catch (Throwable $e) {}
        }

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
        // copia manutenção se for card de manutenção
        if (!empty($orig['is_maintenance']) && $DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            $machines = $DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['plugin_kanpro_cards_id' => $cards_id], 'ORDER' => 'seq ASC']);
            foreach ($machines as $m) {
                $DB->insert('glpi_plugin_kanpro_maintenance_machines', [
                    'plugin_kanpro_cards_id' => $new_id,
                    'seq'                    => $m['seq'],
                    'model'                  => $m['model'],
                    'label'                  => $m['label'],
                    'diary'                  => $m['diary'],
                    'is_done'                => $m['is_done'],
                    'is_ok'                  => $m['is_ok'],
                    'status'                 => $m['status'],
                    'is_inventoried'         => $m['is_inventoried'] ?? 0,
                    'is_urgent'              => $m['is_urgent'] ?? 0,
                    'users_id'               => $m['users_id'],
                    'date_creation'          => date('Y-m-d H:i:s'),
                    'date_mod'               => date('Y-m-d H:i:s'),
                ]);
            }
            // marca novo card também como manutenção
            $DB->update('glpi_plugin_kanpro_cards', [
                'is_maintenance'   => 1,
                'maintenance_date' => date('Y-m-d H:i:s'),
                'maintenance_by'   => Session::getLoginUserID()
            ], ['id' => $new_id]);
        }
        PluginKanproBoard::logActivity($orig['plugin_kanpro_boards_id'], $new_id, $new_list, 'card_copy', "Cartão copiado de #{$cards_id}");
        return $new_id;
    }

    // Dados completos do cartão para modal
    static function getFullData($cards_id): ?array {
        global $DB, $CFG_GLPI;
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
        foreach ($iter as $r) {
            $r['picture_url'] = (!empty($r['picture']) ? ($CFG_GLPI['root_doc'] ?? '') . '/front/document.send.php?file=_pictures/' . $r['picture'] : '');
            $data['members'][] = $r;
        }

        // checklists com items (+ anexos por item, quando a coluna existir)
        $data['checklists'] = [];
        $hasItemAtt = false;
        try { $hasItemAtt = $DB->fieldExists('glpi_plugin_kanpro_attachments', 'plugin_kanpro_checklist_items_id'); } catch (Throwable $e) {}
        $itemAtts = [];
        if ($hasItemAtt) {
            try {
                foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_attachments', 'WHERE' => ['plugin_kanpro_cards_id' => $cards_id], 'ORDER' => 'date_creation DESC']) as $ar) {
                    $iid = (int)($ar['plugin_kanpro_checklist_items_id'] ?? 0);
                    if ($iid > 0) $itemAtts[$iid][] = $ar;
                }
            } catch (Throwable $e) {}
        }
        $cls = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['plugin_kanpro_cards_id' => $cards_id], 'ORDER' => 'rank ASC']);
        foreach ($cls as $cl) {
            $items = [];
            $its = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklist_items', 'WHERE' => ['plugin_kanpro_checklists_id' => $cl['id']], 'ORDER' => 'rank ASC']);
            foreach ($its as $it) {
                $it['attachments'] = $itemAtts[(int)$it['id']] ?? [];
                $items[] = $it;
            }
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
            'ORDER'  => 'c.is_pinned DESC, c.date_creation ASC',
        ]);
        foreach ($coms as $c) {
            $c['picture_url'] = (!empty($c['picture']) ? ($CFG_GLPI['root_doc'] ?? '') . '/front/document.send.php?file=_pictures/' . $c['picture'] : '');
            $data['comments'][] = $c;
        }

        // attachments do cartão (exclui os de itens do checklist, que vão nos itens)
        $data['attachments'] = [];
        $attWhere = ['plugin_kanpro_cards_id' => $cards_id];
        if ($hasItemAtt) $attWhere['plugin_kanpro_checklist_items_id'] = 0;
        $atts = $DB->request(['FROM' => 'glpi_plugin_kanpro_attachments', 'WHERE' => $attWhere, 'ORDER' => 'date_creation DESC']);
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

        // chamado GLPI vinculado
        $data['ticket'] = null;
        $linkedTid = (int)($data['tickets_id'] ?? 0);
        if ($linkedTid > 0 && class_exists('Ticket')) {
            $tk = new Ticket();
            if ($tk->getFromDB($linkedTid)) {
                $can = false;
                try { $can = $tk->can($linkedTid, READ); } catch (Throwable $e) { $can = false; }
                $tStatus = (int)($tk->fields['status'] ?? 0);
                $tLabel = 'Status ' . $tStatus;
                if (method_exists('Ticket', 'getStatus')) {
                    try { $tLabel = Ticket::getStatus($tStatus); } catch (Throwable $e) {}
                }
                $data['ticket'] = [
                    'id' => $linkedTid,
                    'name' => $can ? ($tk->fields['name'] ?? '') : '',
                    'restricted' => !$can,
                    'status' => $tStatus,
                    'status_label' => $tLabel,
                    'date_mod' => $tk->fields['date_mod'] ?? null,
                ];
            }
        }

        // manutenção
        $data['is_maintenance'] = !empty($data['is_maintenance']) ? 1 : 0;
        $data['maintenance_date'] = $data['maintenance_date'] ?? null;
        $data['maintenance_by'] = $data['maintenance_by'] ?? 0;
        $data['maintenance_machines'] = [];
        $data['maintenance_progress'] = ['total'=>0,'done'=>0,'percent'=>0,'urgent'=>0,'notes'=>0,'locked'=>0];
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            $mm = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cards_id],'ORDER'=>'seq ASC']);
            foreach ($mm as $r) $data['maintenance_machines'][] = $r;
            // contagem de anotações por máquina (1 query)
            if ($DB->tableExists('glpi_plugin_kanpro_maintenance_notes') && !empty($data['maintenance_machines'])) {
                $noteCounts = [];
                try {
                    foreach ($DB->request(['SELECT' => ['machine_id', 'COUNT' => 'id AS total'], 'FROM' => 'glpi_plugin_kanpro_maintenance_notes', 'WHERE' => ['machine_id' => array_column($data['maintenance_machines'], 'id')], 'GROUPBY' => ['machine_id']]) as $nc) {
                        $noteCounts[(int)$nc['machine_id']] = (int)$nc['total'];
                    }
                } catch (\Throwable $e) {}
                foreach ($data['maintenance_machines'] as &$mref) {
                    $mref['notes_count'] = $noteCounts[(int)$mref['id']] ?? 0;
                }
                unset($mref);
            } else {
                foreach ($data['maintenance_machines'] as &$mref) {
                    $mref['notes_count'] = 0;
                }
                unset($mref);
            }
            $total = count($data['maintenance_machines']);
            $done = 0;
            $urgent = 0;
            $notes = 0;
            $locked = 0;
            foreach ($data['maintenance_machines'] as $m) {
                if (!empty($m['is_done'])) $done++;
                if (!empty($m['is_urgent'])) $urgent++;
                if (!empty($m['is_locked'])) $locked++;
                $notes += (int)($m['notes_count'] ?? 0);
            }
            $data['maintenance_progress'] = ['total'=>$total,'done'=>$done,'percent'=>$total? (int)round($done/$total*100):0,'urgent'=>$urgent,'notes'=>$notes,'locked'=>$locked];
        }

        // pendência chamado: origem/destino
        $data['chamado_source_id'] = (int)($data['chamado_source_id'] ?? 0);
        $data['chamado_status'] = (string)($data['chamado_status'] ?? '');
        $data['chamado_source_name'] = '';
        if ($data['chamado_source_id'] > 0) {
            $sc = new self();
            if ($sc->getFromDB($data['chamado_source_id'])) $data['chamado_source_name'] = $sc->fields['name'] ?? ('#' . $data['chamado_source_id']);
        }
        // quem solicitou/pegou (p/ o card exclusivo da pendência mostrar sem query extra)
        $data['chamado_by'] = (int)($data['chamado_by'] ?? 0);
        $data['chamado_by_name'] = '';
        if ($data['chamado_by'] > 0) {
            try {
                $su = new User();
                if ($su->getFromDB($data['chamado_by'])) $data['chamado_by_name'] = $su->getFriendlyName();
                else $data['chamado_by_name'] = 'Usuário #' . $data['chamado_by'];
            } catch (\Throwable $e) { $data['chamado_by_name'] = 'Usuário #' . $data['chamado_by']; }
        }
        $data['chamado_pendencias'] = [];
        try {
            foreach ($DB->request([
                'SELECT' => ['id','name','chamado_status','chamado_source_id','pendencia_seq','split_group','split_index'],
                'FROM'   => 'glpi_plugin_kanpro_cards',
                'WHERE'  => ['chamado_source_id' => $cards_id],
                'ORDER'  => ['id ASC'],
            ]) as $pr) {
                $data['chamado_pendencias'][] = [
                    'id' => (int)$pr['id'],
                    'name' => $pr['name'] ?? '',
                    'status' => $pr['chamado_status'] ?? '',
                    'pendencia_seq' => (int)($pr['pendencia_seq'] ?? 0),
                    'display_code' => self::getDisplayCode($pr),
                ];
            }
        } catch (\Throwable $e) {}

        $data['pendencia_seq'] = (int)($data['pendencia_seq'] ?? 0);
        $data['display_code'] = self::getDisplayCode($data);

        return $data;
    }
}
