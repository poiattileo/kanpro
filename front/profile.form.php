<?php
include('../../../inc/includes.php');
Session::checkRight('profile', UPDATE);

global $DB;

if (isset($_POST['update'])) {
    $profiles_id = (int)($_POST['profiles_id'] ?? 0);
    $rights      = (int)($_POST['rights'] ?? 0);

    if ($profiles_id > 0) {
        $profileRight = new ProfileRight();
        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => ['profiles_id' => $profiles_id, 'name' => 'plugin_kanpro'],
        ])->current();

        if ($existing) {
            $profileRight->update(['id' => (int)$existing['id'], 'rights' => $rights]);
        } else {
            $profileRight->add(['profiles_id' => $profiles_id, 'name' => 'plugin_kanpro', 'rights' => $rights]);
        }

        // atualiza perfil ativo sem precisar relogar
        PluginKanproProfile::changeProfile();
        Session::addMessageAfterRedirect('Permissões KanPro salvas com sucesso.');
    }
}

Html::back();
