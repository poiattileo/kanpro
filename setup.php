<?php
define('PLUGIN_KANPRO_VERSION', '1.5.3');
define('PLUGIN_KANPRO_MIN_GLPI', '11.0.0');

function plugin_init_kanpro() {
    global $PLUGIN_HOOKS, $CFG_GLPI;

    $PLUGIN_HOOKS['csrf_compliant']['kanpro'] = true;

    Plugin::registerClass('PluginKanproProfile', ['addtabon' => 'Profile']);
    Plugin::registerClass('PluginKanproBoard', ['addtabon' => []]);

    if (Session::haveRight('plugin_kanpro', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['kanpro'] = ['tools' => 'PluginKanproBoard'];
    }

    // Cache-buster via filemtime (antes ?v= manual — Apache max-age 30d grudava JS velho).
    // Novos módulos carregam DEPOIS do core e estendem window.Kanpro via Object.assign.
    $__kpBase = __DIR__;
    $__kpVer = function (string $rel) use ($__kpBase): string {
        $f = $__kpBase . '/' . ltrim($rel, '/');
        $m = @filemtime($f);
        return $rel . ($m ? '?v=' . $m : '?v=' . PLUGIN_KANPRO_VERSION);
    };
    $PLUGIN_HOOKS['add_css']['kanpro'] = [$__kpVer('public/css/kanpro.css')];
    $PLUGIN_HOOKS['add_javascript']['kanpro'] = [
        $__kpVer('public/js/kanpro.js'),
        $__kpVer('public/js/kanpro.modal.js'),
        $__kpVer('public/js/kanpro.dnd.js'),
        $__kpVer('public/js/kanpro.maintenance.js'),
        $__kpVer('public/js/kanpro.butler.js'),
        $__kpVer('public/js/kanpro.chamado.js'),
        $__kpVer('public/js/history.js'),
    ];

    // Hook para mudança de perfil
    $PLUGIN_HOOKS['change_profile']['kanpro'] = ['PluginKanproProfile', 'changeProfile'];
}

function plugin_version_kanpro() {
    return [
        'name'         => '[URE] KanPro',
        'version'      => PLUGIN_KANPRO_VERSION,
        'author'       => 'URE',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => ['glpi' => ['min' => PLUGIN_KANPRO_MIN_GLPI]],
    ];
}

function plugin_kanpro_check_prerequisites() {
    if (version_compare(GLPI_VERSION, PLUGIN_KANPRO_MIN_GLPI, 'lt')) {
        echo 'Este plugin requer GLPI >= ' . PLUGIN_KANPRO_MIN_GLPI;
        return false;
    }
    return true;
}

function plugin_kanpro_check_config() {
    return true;
}
