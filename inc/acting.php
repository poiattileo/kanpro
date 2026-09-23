<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

// Identidade para atribuição no chamado (automático).
// Quando a equipe compartilha um login (ex: todos usam "glpi"), o mapa abaixo
// resolve automaticamente a pessoa real. Mantenha este mapa sincronizado — fonte única.
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

if (!function_exists('kanpro_migrate_shared_login')) {
    // Migra registros históricos do login compartilhado para a pessoa real (mapa acima).
    // Idempotente: roda a cada carga e só mexe no que ainda está no login antigo.
    // NÃO mexe em boards.users_id (dono gravado na sessão) nem presence (transitório).
    function kanpro_migrate_shared_login(): array {
        global $DB;
        $done = [];
        try {
            $map = defined('KANPRO_ACTING_MAP') ? KANPRO_ACTING_MAP : [];
            foreach ($map as $srcLogin => $dstLogin) {
                $src = kanpro_resolve_user_by_login((string)$srcLogin);
                $dst = kanpro_resolve_user_by_login((string)$dstLogin);
                if ($src <= 0 || $dst <= 0 || $src === $dst) continue;
                $simple = [
                    ['glpi_plugin_kanpro_activities', 'users_id'],
                    ['glpi_plugin_kanpro_cards', 'users_id'],
                    ['glpi_plugin_kanpro_cards', 'maintenance_by'],
                    ['glpi_plugin_kanpro_comments', 'users_id'],
                    ['glpi_plugin_kanpro_attachments', 'users_id'],
                    ['glpi_plugin_kanpro_maintenance_machines', 'users_id'],
                    ['glpi_plugin_kanpro_maintenance_notes', 'users_id'],
                    ['glpi_plugin_kanpro_checklist_items', 'users_id'],
                    ['glpi_plugin_kanpro_trash', 'users_id'],
                    ['glpi_plugin_kanpro_templates', 'users_id'],
                ];
                foreach ($simple as [$table, $col]) {
                    if (!$DB->tableExists($table) || !$DB->fieldExists($table, $col)) continue;
                    $DB->update($table, [$col => $dst], [$col => $src]);
                    $aff = $DB->affected_rows();
                    if ($aff > 0) $done[$table . '.' . $col] = ($done[$table . '.' . $col] ?? 0) + $aff;
                }
                // tabelas com UNIQUE (board,user) e (card,user): transfere ou apaga duplicado
                foreach ([
                    ['glpi_plugin_kanpro_boards_members', 'plugin_kanpro_boards_id'],
                    ['glpi_plugin_kanpro_cards_members', 'plugin_kanpro_cards_id'],
                ] as [$table, $parentCol]) {
                    if (!$DB->tableExists($table)) continue;
                    $rows = $DB->request(['FROM' => $table, 'WHERE' => ['users_id' => $src]]);
                    foreach ($rows as $r) {
                        $parent = (int)$r[$parentCol];
                        $exists = countElementsInTable($table, [$parentCol => $parent, 'users_id' => $dst]);
                        if ($exists) {
                            $DB->delete($table, ['id' => (int)$r['id']]);
                        } else {
                            $DB->update($table, ['users_id' => $dst], ['id' => (int)$r['id']]);
                        }
                        $done[$table] = ($done[$table] ?? 0) + 1;
                    }
                }
            }
        } catch (Throwable $e) {
            Toolbox::logError('KanPro migrate_shared_login: ' . $e->getMessage());
        }
        return $done;
    }
}

if (!function_exists('kanpro_acting_user_id')) {
    // Ordem: 1) mapa login compartilhado 2) logado.
    function kanpro_acting_user_id(): int {
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
