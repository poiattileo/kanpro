<?php
include('../../../inc/includes.php');
Session::checkRight('profile', UPDATE);
$profile = new Profile();
if (isset($_POST['update'])) {
    $profiles_id = (int)$_POST['profiles_id'];
    $rights = (int)($_POST['rights'] ?? 0);
    $DB->update(ProfileRight::getTable(), ['rights' => $rights], ['profiles_id' => $profiles_id, 'name' => 'plugin_kanpro']);
    if ($DB->affectedRows() === 0) {
        // tenta inserir se não existia
        if (!countElementsInTable(ProfileRight::getTable(), ['profiles_id'=>$profiles_id,'name'=>'plugin_kanpro'])) {
            $pr = new ProfileRight();
            $pr->add(['profiles_id'=>$profiles_id,'name'=>'plugin_kanpro','rights'=>$rights]);
        }
    }
    // atualiza sessão se é o perfil ativo
    if ((int)($_SESSION['glpiactiveprofile']['id'] ?? 0) === $profiles_id) {
        $_SESSION['glpiactiveprofile']['plugin_kanpro'] = $rights;
    }
    Html::back();
}
Html::redirect($CFG_GLPI['root_doc'] . '/front/profile.php');
