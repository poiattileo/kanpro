<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproProfile extends CommonDBTM {
    public static $rightname = 'profile';
    public const RIGHT_KANPRO = 'plugin_kanpro';

    public static function getAllRights(): array {
        return [
            [
                'itemtype' => self::class,
                'label'    => 'KanPro - Projetos Kanban',
                'field'    => self::RIGHT_KANPRO,
                'rights'   => [
                    READ   => __('Read'),
                    CREATE => __('Create'),
                    UPDATE => __('Update'),
                    DELETE => __('Delete'),
                    PURGE  => __('Purge'),
                ],
                'default' => READ | CREATE | UPDATE | DELETE,
            ],
        ];
    }

    public static function install(): bool {
        global $DB;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $profile) {
            self::addDefaultProfileInfos((int)$profile['id'], self::getDefaultRightsMap());
        }
        self::changeProfile();
        return true;
    }

    public static function uninstall(): bool {
        global $DB;
        foreach (self::getAllRights() as $right) {
            $DB->delete(ProfileRight::getTable(), ['name' => $right['field']]);
            unset($_SESSION['glpiactiveprofile'][$right['field']]);
        }
        return true;
    }

    public static function addDefaultProfileInfos(int $profiles_id, array $rights): void {
        $profileRight = new ProfileRight();
        foreach ($rights as $right_name => $right_value) {
            if (!countElementsInTable(ProfileRight::getTable(), [
                'profiles_id' => $profiles_id,
                'name'        => $right_name,
            ])) {
                $profileRight->add([
                    'profiles_id' => $profiles_id,
                    'name'        => $right_name,
                    'rights'      => $right_value,
                ]);
            }
        }
    }

    public static function changeProfile(): void {
        global $DB;
        $active_profile_id = (int)($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($active_profile_id <= 0) return;
        foreach (self::getAllRights() as $right) {
            unset($_SESSION['glpiactiveprofile'][$right['field']]);
        }
        $iterator = $DB->request([
            'SELECT' => ['name', 'rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $active_profile_id,
                'name'        => array_column(self::getAllRights(), 'field'),
            ],
        ]);
        foreach ($iterator as $row) {
            $_SESSION['glpiactiveprofile'][$row['name']] = (int)$row['rights'];
        }
    }

    private static function getDefaultRightsMap(): array {
        $rights = [];
        foreach (self::getAllRights() as $right) {
            $rights[$right['field']] = $right['default'];
        }
        return $rights;
    }

    private static function getProfileRightValue(int $profiles_id, string $right_name, int $default = 0): int {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => ['profiles_id' => $profiles_id, 'name' => $right_name],
        ])->current();
        return (is_array($row) && isset($row['rights'])) ? (int)$row['rights'] : $default;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof Profile && $item->getField('id')) {
            return "<span class='d-inline-flex align-items-center gap-1'><i class='ti ti-layout-kanban'></i><span>KanPro</span></span>";
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        global $CFG_GLPI;
        if (!($item instanceof Profile)) return false;
        if (!$item->canView()) return false;

        $profiles_id = (int)$item->getID();
        self::addDefaultProfileInfos($profiles_id, self::getDefaultRightsMap());
        $current_rights = self::getProfileRightValue($profiles_id, self::RIGHT_KANPRO, 0);
        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);
        $form_action = $CFG_GLPI['root_doc'] . '/plugins/kanpro/front/profile.form.php';

        echo "<form method='post' action='{$form_action}'>";
        echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
        echo "<tr class='headerRow'><th colspan='2'>🔖 Permissões — KanPro (Projetos Kanban)</th></tr>";
        echo "<tr class='tab_bg_1'><td width='40%'><strong>Nível de acesso</strong><br><small>Controla o acesso ao menu Ferramentas → Quadros Kanban</small></td><td>";
        if ($canedit) {
            $options = [
                0                                      => '— Sem acesso —',
                READ                                   => '🔍 Visualizar',
                READ | CREATE                          => '➕ Visualizar e Criar',
                READ | CREATE | UPDATE                 => '✏️ Visualizar, Criar e Editar',
                READ | CREATE | UPDATE | DELETE        => '🗑️ Visualizar, Criar, Editar e Apagar',
                READ | CREATE | UPDATE | DELETE | PURGE => '⚡ Acesso Total',
            ];
            Dropdown::showFromArray('rights', $options, ['value' => $current_rights]);
        } else {
            echo $current_rights;
        }
        echo "</td></tr>";
        if ($canedit) {
            echo "<tr class='tab_bg_2'><td colspan='2' class='center' style='padding:12px'>";
            echo Html::hidden('profiles_id', ['value' => $profiles_id]);
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'><i class='ti ti-device-floppy'></i> Salvar permissões</button>";
            echo "</td></tr>";
        }
        echo "</table></div>";
        Html::closeForm();
        return true;
    }
}
