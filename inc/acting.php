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
            error_log('[KanPro] ' . 'KanPro migrate_shared_login: ' . $e->getMessage());
        }
        return $done;
    }
}

if (!function_exists('kanpro_viewer_ids')) {
    // Identidades do visualizador: sessão + pessoa (login compartilhado) — visibilidade vale para ambas.
    // Nunca retorna vazio (IN () quebraria o SQL). Fica aqui (não no ajax.php)
    // porque board.php, kanban.php e mytasks.php também usam.
    function kanpro_viewer_ids(): array {
        $ids = [];
        try {
            $ids = array_values(array_unique(array_filter([(int)Session::getLoginUserID(), kanpro_acting_user_id()])));
        } catch (Throwable $e) {}
        return $ids ?: [0];
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

if (!function_exists('kanpro_my_profile_ids')) {
    // Perfis GLPI vinculados ao usuário (todas as entidades — vale sessão e pessoa)
    function kanpro_my_profile_ids(): array {
        global $DB;
        $ids = [];
        try {
            if (!$DB->tableExists('glpi_profiles_users')) return [];
            $uids = array_values(array_unique(array_filter([(int)Session::getLoginUserID(), kanpro_acting_user_id()])));
            if (empty($uids)) return [];
            foreach ($DB->request(['SELECT' => ['profiles_id'], 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $uids]]) as $r) {
                $ids[(int)$r['profiles_id']] = true;
            }
        } catch (Throwable $e) {}
        return array_keys($ids);
    }
}

if (!function_exists('kanpro_board_profile_role')) {
    // Melhor papel concedido ao usuário via perfis do quadro (admin > member > observer)
    function kanpro_board_profile_role($bid) {
        global $DB;
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_boards_profiles')) return null;
            $pids = kanpro_my_profile_ids();
            if (empty($pids)) return null;
            $rank = ['observer' => 1, 'member' => 2, 'admin' => 3];
            $best = null;
            foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_boards_profiles', 'WHERE' => ['plugin_kanpro_boards_id' => (int)$bid, 'profiles_id' => $pids]]) as $r) {
                $x = $r['role'] ?? 'member';
                if ($best === null || ($rank[$x] ?? 0) > ($rank[$best] ?? 0)) $best = $x;
            }
            return $best;
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('kanpro_can_view_board')) {
    // Pode ver o quadro? criador, membro direto, perfil GLPI ou legado aberto (sem membros E sem perfis)
    function kanpro_can_view_board($bid): bool {
        global $DB;
        $bid = (int)$bid;
        if ($bid <= 0) return false;
        $b = new PluginKanproBoard();
        if (!$b->getFromDB($bid)) return false;
        $me = (int)Session::getLoginUserID();
        if ($me > 0 && (int)($b->fields['users_id'] ?? 0) === $me) return true;
        try {
            if (countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => $bid, 'users_id' => kanpro_viewer_ids()]) > 0) return true;
            if (kanpro_board_profile_role($bid) !== null) return true;
            $hasM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => $bid]) > 0;
            $hasP = $DB->tableExists('glpi_plugin_kanpro_boards_profiles') && countElementsInTable('glpi_plugin_kanpro_boards_profiles', ['plugin_kanpro_boards_id' => $bid]) > 0;
            if (!$hasM && !$hasP) return true; // legado aberto
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('kanpro_board_is_restricted')) {
    // O quadro tem controle de acesso configurado (membros ou perfis)?
    function kanpro_board_is_restricted($bid): bool {
        global $DB;
        try {
            if (countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => (int)$bid]) > 0) return true;
            if ($DB->tableExists('glpi_plugin_kanpro_boards_profiles') && countElementsInTable('glpi_plugin_kanpro_boards_profiles', ['plugin_kanpro_boards_id' => (int)$bid]) > 0) return true;
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('kanpro_groups_owner_id')) {
    // Dono dos grupos pessoais = a pessoa (vale login compartilhado)
    function kanpro_groups_owner_id(): int {
        return (int)kanpro_acting_user_id();
    }
}

if (!function_exists('kanpro_list_viewer_ids')) {
    // Quem pode ver a lista (tabela lists_viewers). Vazio = todos do quadro.
    function kanpro_list_viewer_ids(int $lists_id): array {
        global $DB;
        $out = [];
        try {
            if ($lists_id <= 0) return [];
            if (!$DB->tableExists('glpi_plugin_kanpro_lists_viewers')) return [];
            foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_plugin_kanpro_lists_viewers', 'WHERE' => ['plugin_kanpro_lists_id' => $lists_id]]) as $r) {
                $out[] = (int)$r['users_id'];
            }
        } catch (Throwable $e) {}
        return array_values(array_unique($out));
    }
}

if (!function_exists('kanpro_list_is_restricted')) {
    function kanpro_list_is_restricted(int $lists_id): bool {
        return count(kanpro_list_viewer_ids($lists_id)) > 0;
    }
}

if (!function_exists('kanpro_can_manage_list')) {
    // Quem pode escolher quem vê a lista: quem criou a lista, criador/admin do quadro ou UPDATE global.
    function kanpro_can_manage_list(int $boards_id, $list_row = null): bool {
        try {
            if (Session::haveRight('plugin_kanpro', UPDATE)) return true;
            $viewerIds = function_exists('kanpro_viewer_ids') ? kanpro_viewer_ids() : [(int)Session::getLoginUserID()];
            // criador da lista
            if (is_array($list_row) && isset($list_row['users_id']) && (int)$list_row['users_id'] > 0) {
                if (in_array((int)$list_row['users_id'], $viewerIds, true)) return true;
            } elseif (is_int($list_row) && $list_row > 0) {
                global $DB;
                try {
                    $lr = $DB->request(['FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['id' => (int)$list_row]])->current();
                    if ($lr && (int)($lr['users_id'] ?? 0) > 0 && in_array((int)$lr['users_id'], $viewerIds, true)) return true;
                } catch (Throwable $e) {}
            }
            if ($boards_id > 0) {
                $b = new PluginKanproBoard();
                if ($b->getFromDB($boards_id) && in_array((int)($b->fields['users_id'] ?? 0), $viewerIds, true) && (int)($b->fields['users_id'] ?? 0) > 0) return true;
                global $DB;
                try {
                    foreach ($DB->request(['SELECT' => ['role'], 'FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'users_id' => $viewerIds]]) as $mr) {
                        if (($mr['role'] ?? '') === 'admin') return true;
                    }
                } catch (Throwable $e) {}
                if (function_exists('kanpro_board_profile_role') && kanpro_board_profile_role($boards_id) === 'admin') return true;
            }
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('kanpro_can_view_list')) {
    // Pode ver a lista? Sem restrição = todos. Restrita = viewers + quem gerencia (criador lista/quadro, admin).
    function kanpro_can_view_list($list_row, ?int $boards_id = null): bool {
        try {
            $lid = is_array($list_row) ? (int)($list_row['id'] ?? 0) : (int)$list_row;
            if ($lid <= 0) return false;
            global $DB;
            if (!$DB->tableExists('glpi_plugin_kanpro_lists_viewers')) return true;
            $viewers = kanpro_list_viewer_ids($lid);
            if (empty($viewers)) return true;
            $viewerIds = function_exists('kanpro_viewer_ids') ? kanpro_viewer_ids() : [(int)Session::getLoginUserID()];
            foreach ($viewerIds as $uid) {
                if (in_array((int)$uid, $viewers, true)) return true;
            }
            $bid = $boards_id;
            if ($bid === null && is_array($list_row) && isset($list_row['plugin_kanpro_boards_id'])) $bid = (int)$list_row['plugin_kanpro_boards_id'];
            if ($bid === null) {
                try {
                    $lr = $DB->request(['SELECT' => ['plugin_kanpro_boards_id', 'users_id'], 'FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['id' => $lid]])->current();
                    if ($lr) {
                        $bid = (int)($lr['plugin_kanpro_boards_id'] ?? 0);
                        $list_row = $lr + (is_array($list_row) ? $list_row : []);
                    }
                } catch (Throwable $e) {}
            }
            // criador da lista sempre vê
            if (is_array($list_row) && (int)($list_row['users_id'] ?? 0) > 0 && in_array((int)$list_row['users_id'], $viewerIds, true)) return true;
            if ($bid && function_exists('kanpro_can_manage_list') && kanpro_can_manage_list((int)$bid, $list_row)) return true;
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('kanpro_enrich_lists_with_viewers')) {
    // Adiciona viewer_ids / is_restricted / can_manage em cada lista (p/ JSON do kanban/snapshot).
    function kanpro_enrich_lists_with_viewers(array $lists): array {
        global $DB;
        try {
            $hasTable = $DB->tableExists('glpi_plugin_kanpro_lists_viewers');
            $map = [];
            if ($hasTable && !empty($lists)) {
                $ids = array_map(function ($l) { return (int)($l['id'] ?? 0); }, $lists);
                $ids = array_values(array_filter($ids));
                if (!empty($ids)) {
                    foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_lists_viewers', 'WHERE' => ['plugin_kanpro_lists_id' => $ids]]) as $r) {
                        $map[(int)$r['plugin_kanpro_lists_id']][] = (int)$r['users_id'];
                    }
                }
            }
            foreach ($lists as &$l) {
                $lid = (int)($l['id'] ?? 0);
                $v = array_values(array_unique($map[$lid] ?? []));
                $l['viewer_ids'] = $v;
                $l['is_restricted'] = !empty($v) ? 1 : 0;
                $bid = (int)($l['plugin_kanpro_boards_id'] ?? 0);
                $l['can_manage_viewers'] = function_exists('kanpro_can_manage_list') && $bid ? (kanpro_can_manage_list($bid, $l) ? 1 : 0) : 0;
            }
            unset($l);
        } catch (Throwable $e) {}
        return $lists;
    }
}

if (!function_exists('kanpro_filter_visible_lists')) {
    // Filtra listas que o usuário atual pode ver (mantém ordem).
    function kanpro_filter_visible_lists(array $lists): array {
        $out = [];
        foreach ($lists as $l) {
            $bid = (int)($l['plugin_kanpro_boards_id'] ?? 0);
            if (function_exists('kanpro_can_view_list') && !kanpro_can_view_list($l, $bid ?: null)) continue;
            $out[] = $l;
        }
        return $out;
    }
}
