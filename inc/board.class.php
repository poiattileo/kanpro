<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproBoard extends CommonDBTM {

    static $rightname = 'plugin_kanpro';

    static function getTypeName($nb = 0) {
        return _n('Quadro', 'Quadros', $nb, 'kanpro');
    }

    static function getMenuName() {
        return 'Projeto';
    }

    static function getMenuContent() {
        $menu = parent::getMenuContent();
        $menu['icon'] = 'ti ti-layout-kanban';
        $menu['title'] = 'Projeto';
        $menu['page']  = '/plugins/kanpro/front/board.php';
        return $menu;
    }

    static function canView(): bool {
        return Session::haveRight(self::$rightname, READ);
    }
    static function canCreate(): bool {
        return Session::haveRight(self::$rightname, CREATE);
    }
    public function canViewItem(): bool {
        return Session::haveRight(self::$rightname, READ);
    }
    public function canCreateItem(): bool {
        return Session::haveRight(self::$rightname, CREATE);
    }
    public function canUpdateItem(): bool {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    function defineTabs($options = []) {
        $ong = [];
        $this->addDefaultFormTab($ong);
        return $ong;
    }

    function prepareInputForAdd($input) {
        $input['users_id'] = $input['users_id'] ?? Session::getLoginUserID();
        if (empty($input['name'])) {
            Session::addMessageAfterRedirect(__('Nome obrigatório', 'kanpro'), false, ERROR);
            return false;
        }
        $input['date_creation'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $input['date_mod'] = $input['date_creation'];
        $input['color'] = $input['color'] ?? '#0079bf';
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = $_SESSION['glpiactive_entity'] ?? 0;
        }
        return $input;
    }

    function prepareInputForUpdate($input) {
        $input['date_mod'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        return $input;
    }

    function post_addItem() {
        global $DB;
        // cria etiquetas padrão Trello
        $defaults = [
            ['name' => '', 'color' => '#61bd4f'],
            ['name' => '', 'color' => '#f2d600'],
            ['name' => '', 'color' => '#ff9f1a'],
            ['name' => '', 'color' => '#eb5a46'],
            ['name' => '', 'color' => '#c377e0'],
            ['name' => '', 'color' => '#0079bf'],
        ];
        foreach ($defaults as $l) {
            $DB->insert('glpi_plugin_kanpro_labels', [
                'plugin_kanpro_boards_id' => $this->getID(),
                'name'  => $l['name'],
                'color' => $l['color'],
            ]);
        }
        // cria listas padrão
        $lists = ['A Fazer', 'Em Progresso', 'Concluído'];
        $rank = 1000;
        foreach ($lists as $lname) {
            $list = new PluginKanproList();
            $list->add([
                'plugin_kanpro_boards_id' => $this->getID(),
                'name' => $lname,
                'rank' => $rank,
            ]);
            $rank += 1000;
        }
        // adiciona criador como membro admin
        $DB->insert('glpi_plugin_kanpro_boards_members', [
            'plugin_kanpro_boards_id' => $this->getID(),
            'users_id' => Session::getLoginUserID(),
            'role' => 'admin',
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        self::logActivity($this->getID(), null, null, 'board_create', 'Quadro criado');
    }

    function cleanDBonPurge() {
        global $DB;
        $bid = $this->getID();
        // cascata: listas -> cartões -> tudo
        $lists = $DB->request(['FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['plugin_kanpro_boards_id' => $bid]]);
        foreach ($lists as $l) {
            $obj = new PluginKanproList();
            $obj->delete(['id' => $l['id']], true);
        }
        $DB->delete('glpi_plugin_kanpro_labels', ['plugin_kanpro_boards_id' => $bid]);
        $DB->delete('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => $bid]);
        $DB->delete('glpi_plugin_kanpro_activities', ['plugin_kanpro_boards_id' => $bid]);
        // anexos são apagados via card purge
    }

    static function logActivity($boards_id, $cards_id = null, $lists_id = null, $action = '', $details = '') {
        global $DB;
        $DB->insert('glpi_plugin_kanpro_activities', [
            'plugin_kanpro_boards_id' => $boards_id,
            'plugin_kanpro_cards_id'  => $cards_id,
            'plugin_kanpro_lists_id'  => $lists_id,
            'users_id'   => Session::getLoginUserID(),
            'action'     => $action,
            'details'    => $details,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    // Cores de fundo Trello-like
    static function getBackgroundColors(): array {
        return [
            '#0079bf' => 'Azul',
            '#00aecc' => 'Ciano',
            '#4bbf6b' => 'Verde',
            '#519839' => 'Verde Escuro',
            '#d29034' => 'Laranja',
            '#f2d600' => 'Amarelo',
            '#c377e0' => 'Roxo',
            '#ff78cb' => 'Rosa',
            '#344563' => 'Grafite',
            '#172b4d' => 'Azul Marinho',
            '#b04632' => 'Vermelho',
        ];
    }

    function showForm($ID, array $options = []) {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $canedit = $this->canUpdateItem();
        $is_new  = ($ID <= 0);

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='name'>Nome do Quadro *</label></td>";
        echo "<td>";
        echo Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 40, 'required' => true]);
        echo "</td>";

        echo "<td>Cor de Fundo</td><td>";
        $colors = self::getBackgroundColors();
        $current = $this->fields['color'] ?? '#0079bf';
        echo "<div style='display:flex;gap:6px;flex-wrap:wrap;align-items:center'>";
        foreach ($colors as $hex => $label) {
            $checked = ($hex === $current) ? 'checked' : '';
            $border = ($hex === $current) ? '3px solid #172b4d' : '2px solid transparent';
            echo "<label title='{$label}' style='cursor:pointer'>
                    <input type='radio' name='color' value='{$hex}' {$checked} style='display:none'>
                    <span style='display:inline-block;width:32px;height:32px;border-radius:4px;background:{$hex};border:{$border};box-shadow:0 1px 3px rgba(0,0,0,.2)'></span>
                  </label>";
        }
        echo "</div>";
        // custom color picker
        echo "<div style='margin-top:8px'><input type='color' name='color_custom' value='{$current}' onchange=\"document.querySelectorAll('input[name=color]').forEach(r=>r.checked=false); this.previousElementSibling; document.querySelector('input[name=color][value=\\'\"+this.value+\"\\']')?.click() || (this.name='color')\" style='width:40px;height:32px;border:none;padding:0'> <small>Cor personalizada</small></div>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>Entidade</td><td>";
        Entity::dropdown(['value' => $this->fields['entities_id'] ?? $_SESSION['glpiactive_entity'], 'entity' => $_SESSION['glpiactiveentities'] ?? [0]]);
        echo "</td>";
        echo "<td>Visibilidade</td><td>";
        Dropdown::showFromArray('visibility', [
            'private' => '🔒 Privado',
            'team'    => '👥 Equipe',
            'public'  => '🌐 Público',
        ], ['value' => $this->fields['visibility'] ?? 'private']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>Descrição</td><td colspan='3'>";
        echo "<textarea name='comment' rows='3' style='width:100%'>" . htmlspecialchars($this->fields['comment'] ?? '') . "</textarea>";
        echo "</td></tr>";

        if (!$is_new) {
            echo "<tr class='tab_bg_1'><td colspan='4' style='text-align:center;padding:12px'>";
            $kanban_url = Plugin::getWebDir('kanpro') . "/front/kanban.php?boards_id={$ID}";
            echo "<a href='{$kanban_url}' class='btn btn-primary' style='padding:10px 24px;font-size:14px'><i class='ti ti-layout-kanban'></i> Abrir Quadro Kanban</a> ";
            echo "<small style='margin-left:12px;color:#6b778c'>ID #{$ID} • Criado em " . Html::convDateTime($this->fields['date_creation'] ?? '') . "</small>";
            echo "</td></tr>";
        }

        $this->showFormButtons($options);
        return true;
    }

    // Lista de quadros para front/board.php
    static function getBoardsForEntity($entities_id = null, $include_archived = false) {
        global $DB;
        $entities = $entities_id ?? ($_SESSION['glpiactiveentities'] ?? [0]);
        if (!is_array($entities)) $entities = [$entities];
        $where = ['entities_id' => $entities];
        if (!$include_archived) $where['is_archived'] = 0;
        return $DB->request([
            'FROM'  => 'glpi_plugin_kanpro_boards',
            'WHERE' => $where,
            'ORDER' => 'is_starred DESC, date_mod DESC',
        ]);
    }

    static function countCardsInBoard($boards_id): int {
        global $DB;
        return countElementsInTable('glpi_plugin_kanpro_cards', [
            'plugin_kanpro_boards_id' => $boards_id,
            'is_archived' => 0,
        ]);
    }
}
