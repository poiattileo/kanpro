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
        $creator = (int)($b->fields['users_id'] ?? 0);
        try {
            $viewerIds = kanpro_viewer_ids();
            if ($creator > 0 && in_array($creator, $viewerIds, true)) return true;
        } catch (Throwable $e) {
            $me = (int)Session::getLoginUserID();
            if ($me > 0 && $creator === $me) return true;
        }
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
    // Quem pode escolher quem vê a lista: quem criou a lista, criador/admin do quadro.
    // UPDATE global só vale em quadro legado aberto (sem membros E sem perfis) p/ bootstrap —
    // senão todo membro com UPDATE (uso normal) viraria gestor.
    function kanpro_can_manage_list(int $boards_id, $list_row = null): bool {
        try {
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
                // fallback estrito: UPDATE só em quadro legado aberto
                if (Session::haveRight('plugin_kanpro', UPDATE)) {
                    try {
                        $hasM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => $boards_id]) > 0;
                        $hasP = $DB->tableExists('glpi_plugin_kanpro_boards_profiles') && countElementsInTable('glpi_plugin_kanpro_boards_profiles', ['plugin_kanpro_boards_id' => $boards_id]) > 0;
                        if (!$hasM && !$hasP) return true;
                    } catch (Throwable $e) {}
                }
            } else {
                if (Session::haveRight('plugin_kanpro', UPDATE)) return true;
            }
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('kanpro_can_view_list')) {
    // Pode ver a lista e os cards? Sem restrição = todos. Restrita = SÓ viewers (sem bypass).
    // Gestão (trocar quem vê) é separada em kanpro_can_manage_list — fantasma permite recuperar.
    // Fail-open: qualquer erro mostra a lista (evita sumir tudo do nada).
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
            return false;
        } catch (Throwable $e) {
            return true;
        }
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
    // Filtra listas que o usuário atual pode ver (mantém ordem). Fail-open: erro mostra tudo.
    function kanpro_filter_visible_lists(array $lists): array {
        try {
            if (empty($lists)) return $lists;
            global $DB;
            try {
                if (!$DB->tableExists('glpi_plugin_kanpro_lists_viewers')) return $lists;
            } catch (Throwable $e) { return $lists; }
            $out = [];
            foreach ($lists as $l) {
                try {
                    $bid = (int)($l['plugin_kanpro_boards_id'] ?? 0);
                    if (function_exists('kanpro_can_view_list') && !kanpro_can_view_list($l, $bid ?: null)) continue;
                } catch (Throwable $e) {
                    // erro numa lista específica: mostra ela (fail-open)
                }
                $out[] = $l;
            }
            // segurança anti-sumir-tudo: se filtrou TUDO mas tinha listas, volta tudo (fail-open)
            if (empty($out) && !empty($lists)) {
                try {
                    $totalRestrictions = 0;
                    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_kanpro_lists_viewers', 'LIMIT' => 1]) as $r) { $totalRestrictions++; break; }
                    if ($totalRestrictions === 0) return $lists;
                } catch (Throwable $e) { return $lists; }
            }
            return $out;
        } catch (Throwable $e) { return $lists; }
    }
}

if (!function_exists('kanpro_board_has_list_restrictions')) {
    // Tem alguma restrição de lista neste quadro? Se não, pula o filtro (rápido + seguro).
    function kanpro_board_has_list_restrictions(int $boards_id): bool {
        global $DB;
        try {
            if ($boards_id <= 0) return false;
            if (!$DB->tableExists('glpi_plugin_kanpro_lists_viewers')) return false;
            if (!$DB->tableExists('glpi_plugin_kanpro_lists')) return false;
            $listIds = [];
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id]]) as $l) {
                $listIds[] = (int)$l['id'];
            }
            if (empty($listIds)) return false;
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_kanpro_lists_viewers', 'WHERE' => ['plugin_kanpro_lists_id' => $listIds], 'LIMIT' => 1]) as $r) {
                return true;
            }
        } catch (Throwable $e) { return false; }
        return false;
    }
}

if (!function_exists('kanpro_split_visible_hidden_lists')) {
    // Separa [visíveis, fantasmas]. Fantasma = metadados sem cards (id, nome, tipo, qtd viewers, can_manage).
    function kanpro_split_visible_hidden_lists(array $lists): array {
        $vis = [];
        $hid = [];
        try {
            if (empty($lists)) return [[], []];
            foreach ($lists as $l) {
                try {
                    $bid = (int)($l['plugin_kanpro_boards_id'] ?? 0);
                    $can = function_exists('kanpro_can_view_list') ? kanpro_can_view_list($l, $bid ?: null) : true;
                } catch (Throwable $e) { $can = true; }
                if ($can) $vis[] = $l;
                else {
                    $hid[] = [
                        'id' => (int)($l['id'] ?? 0),
                        'plugin_kanpro_boards_id' => (int)($l['plugin_kanpro_boards_id'] ?? 0),
                        'name' => (string)($l['name'] ?? ''),
                        'rank' => (float)($l['rank'] ?? 0),
                        'list_type' => (string)($l['list_type'] ?? ''),
                        'viewer_ids' => array_values(array_unique((array)($l['viewer_ids'] ?? []))),
                        'is_restricted' => 1,
                        'can_manage_viewers' => (int)($l['can_manage_viewers'] ?? 0),
                        'hidden' => 1,
                    ];
                }
            }
        } catch (Throwable $e) { return [$lists, []]; }
        return [$vis, $hid];
    }
}
