<?php
define('PLUGIN_KANPRO_VERSION', '1.0.1');
define('PLUGIN_KANPRO_MIN_GLPI', '11.0.0');

function plugin_init_kanpro() {
    global $PLUGIN_HOOKS, $CFG_GLPI;

    $PLUGIN_HOOKS['csrf_compliant']['kanpro'] = true;

    Plugin::registerClass('PluginKanproProfile', ['addtabon' => 'Profile']);
    Plugin::registerClass('PluginKanproBoard', ['addtabon' => []]);

    if (Session::haveRight('plugin_kanpro', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['kanpro'] = ['tools' => 'PluginKanproBoard'];
    }

    $PLUGIN_HOOKS['add_css']['kanpro']        = ['public/css/kanpro.css'];
    $PLUGIN_HOOKS['add_javascript']['kanpro'] = ['public/js/kanpro.js'];

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
