<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

// Identidade para atribuição no chamado ("Agindo como").
// Quando a equipe compartilha um login (ex: todos usam "glpi"), o mapa abaixo
// resolve automaticamente a pessoa real. A troca manual (seletor na topbar)
// tem prioridade sobre o mapa. Mantenha este mapa sincronizado — fonte única.
if (!defined('KANPRO_ACTING_MAP')) {
    define('KANPRO_ACTING_MAP', [
        'glpi' => 'leonardo.facao@apoiofde.sp.gov.br',
    ]);
}

if (!function_exists('kanpro_resolve_user_by_login')) {
    function kanpro_resolve_user_by_login(string $login): int {
        global $DB;
        $login = trim($login);
        if ($login === '') return 0;
        try {
            $row = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => $login, 'is_deleted' => 0], 'LIMIT' => 1])->current();
            if ($row) {
                $u = new User();
                if ($u->getFromDB((int)$row['id']) && ($u->fields['is_active'] ?? 1)) return (int)$row['id'];
            }
            // fallback: e-mail cadastrado
            if ($DB->tableExists('glpi_useremails')) {
                $em = $DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_useremails', 'WHERE' => ['email' => $login], 'LIMIT' => 1])->current();
                if ($em) {
                    $u = new User();
                    if ($u->getFromDB((int)$em['users_id']) && empty($u->fields['is_deleted']) && ($u->fields['is_active'] ?? 1)) {
                        return (int)$em['users_id'];
                    }
                }
            }
        } catch (Throwable $e) {}
        return 0;
    }
}

if (!function_exists('kanpro_acting_user_id')) {
    // Ordem: 1) troca manual (seletor) 2) mapa login compartilhado 3) logado.
    function kanpro_acting_user_id(): int {
        $auid = (int)($_SESSION['kanpro_acting_user'] ?? 0);
        if ($auid > 0) {
            try {
                $u = new User();
                if ($u->getFromDB($auid) && empty($u->fields['is_deleted']) && ($u->fields['is_active'] ?? 1)) {
                    return $auid;
                }
            } catch (Throwable $e) {}
            unset($_SESSION['kanpro_acting_user']);
        }
        try {
            $me = (int)Session::getLoginUserID();
            if ($me > 0) {
                $mu = new User();
                if ($mu->getFromDB($me)) {
                    $map = defined('KANPRO_ACTING_MAP') ? KANPRO_ACTING_MAP : [];
                    $login = strtolower(trim($mu->fields['name'] ?? ''));
                    if (isset($map[$login])) {
                        $resolved = kanpro_resolve_user_by_login($map[$login]);
                        if ($resolved > 0) return $resolved;
                    }
                }
            }
        } catch (Throwable $e) {}
        return (int)Session::getLoginUserID();
    }
}
