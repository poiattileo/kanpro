<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

// Identidade para atribuição no chamado (automático).
// Quando a equipe compartilha um login (ex: todos usam "glpi"), o mapa abaixo
// resolve automaticamente a pessoa real. FONTE ÚNICA: config 'acting_map'
// (JSON {"login_origem":"login_destino"}, editável em Configurações do KanPro).
// A constante KANPRO_ACTING_MAP abaixo é só o valor legado p/ instalações sem
// a tabela de config (fail closed: sem config nem legado, sem migração).
if (!defined('KANPRO_ACTING_MAP')) {
    define('KANPRO_ACTING_MAP', [
        'glpi' => 'leonardo.facao@apoiofde.sp.gov.br', // LEGACY-SEED-ALLOW (fallback p/ instalação sem tabela de config)
    ]);
}

if (!function_exists('kanpro_config_get')) {
    // Config global do plugin (tabela glpi_plugin_kanpro_configs, name/value).
    // Fail closed: sem tabela (instalação antiga sem update) devolve o default.
    function kanpro_config_get(string $name, string $default = ''): string {
        global $DB;
        try {
            if (!isset($DB) || !method_exists($DB, 'tableExists') || !$DB->tableExists('glpi_plugin_kanpro_configs')) {
                return $default;
            }
            $row = $DB->request([
                'SELECT' => ['value'],
                'FROM'   => 'glpi_plugin_kanpro_configs',
                'WHERE'  => ['name' => $name],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($row) && array_key_exists('value', $row)) {
                return (string)$row['value'];
            }
        } catch (Throwable $e) {}
        return $default;
    }
}

if (!function_exists('kanpro_config_set')) {
    // Upsert por name. Devolve false sem tabela (nada a gravar).
    function kanpro_config_set(string $name, string $value): bool {
        global $DB;
        try {
            if (!isset($DB) || !method_exists($DB, 'tableExists') || !$DB->tableExists('glpi_plugin_kanpro_configs')) {
                return false;
            }
            $now = date('Y-m-d H:i:s');
            $row = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_plugin_kanpro_configs',
                'WHERE'  => ['name' => $name],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($row)) {
                return (bool)$DB->update('glpi_plugin_kanpro_configs', ['value' => $value, 'date_mod' => $now], ['name' => $name]);
            }
            return $DB->insert('glpi_plugin_kanpro_configs', ['name' => $name, 'value' => $value, 'date_creation' => $now]) !== false;
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('kanpro_config_parse_post')) {
    // Valida o POST da tela de Configurações. Pura (sem $DB/GLPI) p/ ser testável.
    // Devolve ['values' => [chave => valor pronto p/ kanpro_config_set], 'errors' => [...]].
    // All-or-nothing: com qualquer erro, o chamador NÃO deve salvar nada.
    function kanpro_config_parse_post(array $post): array {
        $errors = [];
        $values = [];

        // Aprovador: login ou e-mail único (vazio = não configurado -> fail closed).
        $approver = trim((string)($post['zap_approver'] ?? ''));
        if ($approver !== '' && preg_match('/\s/', $approver)) {
            $errors[] = 'Aprovador inválido (não pode conter espaços).';
        } else {
            $values['zap_approver'] = function_exists('kanpro_clean_text')
                ? kanpro_clean_text($approver, 255)
                : substr($approver, 0, 255);
        }

        // Destinatários: um por linha (login ou e-mail). Linhas # são comentários.
        foreach (['zap_reminder_recipients' => 'Lembrete', 'zap_chamado_recipients' => 'Chamado'] as $key => $label) {
            $lines = preg_split('/\r\n|\r|\n/', (string)($post[$key] ?? ''));
            $list = [];
            foreach ((array)$lines as $line) {
                $line = trim((string)$line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (preg_match('/\s/', $line)) {
                    $errors[] = "{$label}: destinatário inválido '{$line}' (não pode conter espaços).";
                    continue;
                }
                $list[] = $line;
            }
            $values[$key] = json_encode(array_values(array_unique($list)), JSON_UNESCAPED_UNICODE);
        }

        // Mapa login compartilhado -> pessoa real (JSON {"origem":"destino"}).
        $mapRaw = trim((string)($post['acting_map'] ?? ''));
        if ($mapRaw === '') {
            $values['acting_map'] = '';
        } else {
            $map = json_decode($mapRaw, true);
            if (!is_array($map)) {
                $errors[] = 'Mapa inválido (precisa ser JSON como {"glpi":"pessoa@exemplo"}).';
            } else {
                $clean = [];
                foreach ($map as $src => $dst) {
                    $src = trim((string)$src);
                    $dst = trim((string)$dst);
                    if ($src === '' || $dst === '' || preg_match('/\s/', $src) || preg_match('/\s/', $dst)) {
                        $errors[] = "Mapa inválido na entrada '{$src}'.";
                        continue;
                    }
                    $clean[$src] = $dst;
                }
                $values['acting_map'] = json_encode($clean, JSON_UNESCAPED_UNICODE);
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }
}
if (!function_exists('kanpro_entitycontact_validate')) {
    // Valida contato por entidade. Pura (sem $DB/GLPI) p/ ser testável.
    // Modelo: 1 linha = 1 valor; o tipo sai do campo preenchido
    // (só e-mail ou só telefone por vez — sem select de tipo no form).
    // Devolve ['values' => [...pronto p/ gravar...], 'errors' => [...]].
    function kanpro_entitycontact_validate(array $input): array {
        $errors = [];
        $entities_id = (int)($input['entities_id'] ?? 0);
        if ($entities_id <= 0) {
            $errors[] = 'Entidade inválida.';
        }
        $name = function_exists('kanpro_clean_text')
            ? kanpro_clean_text($input['name'] ?? '', 255)
            : substr(trim((string)($input['name'] ?? '')), 0, 255);
        $emailRaw = strtolower(trim((string)($input['email'] ?? '')));
        $phoneRaw = (string)preg_replace('/[^0-9]/', '', (string)($input['phone'] ?? ''));
        $kind = '';
        if ($emailRaw !== '' && $phoneRaw !== '') {
            $errors[] = 'Preencha só e-mail ou só telefone (1 contato por linha).';
        } elseif ($emailRaw !== '') {
            $kind = 'email';
            if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'E-mail inválido.';
            }
        } elseif ($phoneRaw !== '') {
            $kind = 'phone';
            if (strlen($phoneRaw) < 8 || strlen($phoneRaw) > 15) {
                $errors[] = 'Telefone inválido (8 a 15 dígitos).';
            }
        } else {
            $errors[] = 'Informe e-mail ou telefone.';
        }
        return [
            'values' => [
                'entities_id' => $entities_id,
                'kind'        => $kind,
                'name'        => $name,
                'email'       => $kind === 'email' ? $emailRaw : '',
                'phone'       => $kind === 'phone' ? $phoneRaw : '',
                'is_active'   => !empty($input['is_active']) ? 1 : 0,
            ],
            'errors' => $errors,
        ];
    }
}

if (!function_exists('kanpro_entity_short_name')) {
    // Nome curto da entidade (só "EE X", sem o caminho "URE > EE X"). Pura p/ testes.
    // Prefere glpi_entities.name; sem ele, usa o último nível do completename.
    function kanpro_entity_short_name(array $row): string {
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $complete = trim((string)($row['completename'] ?? ''));
        if ($complete === '') {
            return '';
        }
        $parts = explode('>', $complete);
        $last = trim((string)end($parts));
        return $last !== '' ? $last : $complete;
    }
}

if (!function_exists('kanpro_acting_map')) {
    // Fonte única do mapa login compartilhado -> pessoa real.
    // Lê do config 'acting_map' (JSON); JSON inválido/ausente cai no legado.
    function kanpro_acting_map(): array {
        $raw = kanpro_config_get('acting_map', '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return defined('KANPRO_ACTING_MAP') ? (array)KANPRO_ACTING_MAP : [];
    }
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
            $map = kanpro_acting_map();
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
                    $map = kanpro_acting_map();
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
                $rank = ['observer' => 1, 'member' => 2, 'admin' => 3, 'gerente' => 4];
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

if (!function_exists('kanpro_my_board_role')) {
    // Papel direto do visualizador no quadro (observer < member < admin < gerente; vale pessoa + sessão + perfil GLPI).
    // Movido de front/ajax.php p/ a lib compartilhada (vale no form, kanban e gear).
    function kanpro_my_board_role($bid) {
        global $DB;
        $best = null;
        $rank = ['observer' => 1, 'member' => 2, 'admin' => 3];
        try {
            foreach (array_unique([kanpro_acting_user_id(), (int)Session::getLoginUserID()]) as $uid) {
                if ($uid <= 0) continue;
                $row = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $bid, 'users_id' => $uid]])->current();
                if ($row) {
                    $r = $row['role'] ?? 'member';
                    if ($best === null || ($rank[$r] ?? 0) > ($rank[$best] ?? 0)) $best = $r;
                }
            }
        } catch (Throwable $e) {}
        try {
            $prof = function_exists('kanpro_board_profile_role') ? kanpro_board_profile_role($bid) : null;
            if ($prof !== null && ($best === null || ($rank[$prof] ?? 0) > ($rank[$best] ?? 0))) $best = $prof;
        } catch (Throwable $e) {}
        return $best;
    }
}

if (!function_exists('kanpro_is_board_creator')) {
    function kanpro_is_board_creator($bid) {
        $b = new PluginKanproBoard();
        if (!$b->getFromDB($bid)) return false;
        $creator = (int)($b->fields['users_id'] ?? 0);
        if ($creator <= 0) return false;
        try {
            if ($creator === (int)Session::getLoginUserID()) return true;
            if (function_exists('kanpro_acting_user_id') && $creator === (int)kanpro_acting_user_id()) return true;
        } catch (Throwable $e) {
            if ($creator === (int)Session::getLoginUserID()) return true;
        }
        return false;
    }
}

if (!function_exists('kanpro_can_manage_members')) {
    // Quem pode gerenciar acesso/família: criador, admin ou gerente do quadro (ou UPDATE global em legado aberto).
    function kanpro_can_manage_members($bid) {
        if (kanpro_is_board_creator($bid)) return true;
        if (in_array(kanpro_my_board_role($bid), ['admin', 'gerente'], true)) return true;
        if (Session::haveRight('plugin_kanpro', UPDATE)) {
            try {
                global $DB;
                $hasM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => (int)$bid]) > 0;
                $hasP = $DB->tableExists('glpi_plugin_kanpro_boards_profiles') && countElementsInTable('glpi_plugin_kanpro_boards_profiles', ['plugin_kanpro_boards_id' => (int)$bid]) > 0;
                if (!$hasM && !$hasP) return true;
            } catch (Throwable $e) {}
        }
        return false;
    }
}

if (!function_exists('kanpro_can_see_all')) {
    // Ver-tudo (ex.: Chamado finalizado): criador ou gerente. Admin vê só vinculados.
    function kanpro_can_see_all($bid) {
        try {
            if (kanpro_is_board_creator($bid)) return true;
            if (kanpro_my_board_role($bid) === 'gerente') return true;
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('kanpro_can_name_gerente')) {
    // Nomear/alterar/remover Gerente: criador ou quem já é gerente.
    function kanpro_can_name_gerente($bid) {
        try {
            if (kanpro_is_board_creator($bid)) return true;
            if (kanpro_my_board_role($bid) === 'gerente') return true;
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('kanpro_can_manage_models')) {
    // Modelos de máquinas: criador/admin (via manage_members) + membro do quadro.
    function kanpro_can_manage_models($bid) {
        try {
            if (function_exists('kanpro_can_manage_members') && kanpro_can_manage_members($bid)) return true;
        } catch (Throwable $e) {}
        try {
            $role = function_exists('kanpro_my_board_role') ? kanpro_my_board_role($bid) : null;
            if (in_array($role, ['member', 'admin', 'gerente'], true)) return true;
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
                        if (in_array(($mr['role'] ?? ''), ['admin', 'gerente'], true)) return true;
                    }
                } catch (Throwable $e) {}
                if (function_exists('kanpro_board_profile_role') && in_array(kanpro_board_profile_role($boards_id), ['admin', 'gerente'], true)) return true;
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

if (!function_exists('kanpro_board_id_for_list')) {
    // Resolve boards_id a partir de lists_id (0 se não achar). Sem query pesada.
    function kanpro_board_id_for_list(int $lists_id): int {
        global $DB;
        try {
            if ($lists_id <= 0) return 0;
            $r = $DB->request(['SELECT' => ['plugin_kanpro_boards_id'], 'FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['id' => $lists_id]])->current();
            return (int)($r['plugin_kanpro_boards_id'] ?? 0);
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('kanpro_board_id_for_card')) {
    // Resolve boards_id a partir de cards_id (0 se não achar).
    function kanpro_board_id_for_card(int $cards_id): int {
        global $DB;
        try {
            if ($cards_id <= 0) return 0;
            $r = $DB->request(['SELECT' => ['plugin_kanpro_boards_id'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['id' => $cards_id]])->current();
            return (int)($r['plugin_kanpro_boards_id'] ?? 0);
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('kanpro_csrf_bridge')) {
    // GLPI 11 valida via header X-Glpi-Csrf-Token no CheckCsrfListener, mas Session::checkCSRF()
    // lê de $_POST. Copia o header p/ $_POST/$_REQUEST p/ validação explícita funcionar no AJAX.
    // Também corrige '+' truncado como espaço no FormData.
    function kanpro_csrf_bridge(): void {
        try {
            $h = $_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] ?? '';
            if ($h !== '' && !isset($_POST['_glpi_csrf_token'])) {
                $_POST['_glpi_csrf_token'] = $h;
            }
            if ($h !== '' && !isset($_REQUEST['_glpi_csrf_token'])) {
                $_REQUEST['_glpi_csrf_token'] = $h;
            }
            foreach (['_glpi_csrf_token'] as $k) {
                if (isset($_POST[$k]) && is_string($_POST[$k]) && strpos($_POST[$k], ' ') !== false) {
                    $_POST[$k] = str_replace(' ', '+', $_POST[$k]);
                }
            }
        } catch (Throwable $e) {}
    }
}

if (!function_exists('kanpro_clean_text')) {
    // Texto puro (título, nome): sem HTML, sem controle, com limite. Previne stored XSS.
    function kanpro_clean_text($s, int $max = 255): string {
        $s = (string)($s ?? '');
        $s = trim(strip_tags($s));
        // remove controles (exceto \n\t)
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        if (function_exists('mb_substr')) $s = mb_substr($s, 0, $max);
        else $s = substr($s, 0, $max);
        return $s;
    }
}

if (!function_exists('kanpro_clean_rich')) {
    // HTML rico (descrição, comentário com markdown?): permite formatação segura, remove script/eventos.
    // Usa o cleaner do GLPI quando disponível; senão cai p/ texto puro.
    function kanpro_clean_rich($html): string {
        $html = (string)($html ?? '');
        try {
            if (class_exists('Sanitizer') && method_exists('Sanitizer', 'sanitize')) {
                return (string)Sanitizer::sanitize($html, false);
            }
        } catch (Throwable $e) {}
        try {
            if (class_exists('Html') && method_exists('Html', 'clean')) {
                return (string)Html::clean($html);
            }
        } catch (Throwable $e) {}
        return kanpro_clean_text($html, 65535);
    }
}

if (!function_exists('kanpro_readonly_actions')) {
    // Actions que só LEEM (sem CSRF explícito, mas ainda exigem kanpro_can_view_board).
    // Todo o resto é mutação: exige POST + CSRF.
    function kanpro_readonly_actions(): array {
        return [
            'presence_heartbeat', // heartbeat escreve last_seen mas é idempotente/polling — CSRF via framework
            'get_board_stamp',
            'get_board_snapshot',
            'get_card',
            'get_history',
            'get_board_members',
            'search_board_users',
            'global_search_cards',
            'search_cards',
            'get_list_viewers',
            'get_board_activity',
            'get_board_report',
            'get_retirada_schools',
            'list_entities',
            'get_card_term',
            'my_board_groups',
            'get_trash',
            'zap_lembrete_diagnose',
            'zap_lembrete_calendar',
            'rule_list',
            'chamado_detail',
            'list_maintenance_models',
        ];
    }
}
    // Separa [visíveis, fantasmas]. Fantasma = metadados sem cards (id, nome, tipo, qtd viewers, can_manage).
if (!function_exists('kanpro_split_visible_hidden_lists')) {
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

if (!function_exists('kanpro_ensure_family_column')) {
    // Garante parent_boards_id sem reinstalar (1x por request; ALTER só roda 1x na vida).
    function kanpro_ensure_family_column(): void {
        global $DB;
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_boards')) return;
            if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'parent_boards_id')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` ADD `parent_boards_id` INT NOT NULL DEFAULT '0' COMMENT 'quadro pai (0=raiz)'");
            }
            try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` ADD KEY `parent_boards_id` (`parent_boards_id`)"); } catch (Throwable $e) {}
        } catch (Throwable $e) {}
    }
}

if (!function_exists('kanpro_board_parent_id')) {
    function kanpro_board_parent_id(int $bid): int {
        global $DB;
        try {
            if ($bid <= 0) return 0;
            if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'parent_boards_id')) return 0;
            $r = $DB->request(['SELECT' => ['parent_boards_id'], 'FROM' => 'glpi_plugin_kanpro_boards', 'WHERE' => ['id' => $bid]])->current();
            return (int)($r['parent_boards_id'] ?? 0);
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('kanpro_board_descendant_ids')) {
    // Todos os descendentes (proteção contra ciclo: pai nunca pode ser filho/neto).
    function kanpro_board_descendant_ids(int $bid): array {
        global $DB;
        $out = [];
        try {
            if ($bid <= 0) return [];
            if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'parent_boards_id')) return [];
            $queue = [$bid];
            $seen = [$bid => true];
            while (!empty($queue)) {
                $cur = array_shift($queue);
                foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_kanpro_boards', 'WHERE' => ['parent_boards_id' => $cur]]) as $r) {
                    $id = (int)$r['id'];
                    if (isset($seen[$id])) continue;
                    $seen[$id] = true;
                    $out[] = $id;
                    $queue[] = $id;
                }
            }
        } catch (Throwable $e) {}
        return $out;
    }
}

if (!function_exists('kanpro_get_board_family')) {
    // Família p/ o switcher do header: pai + irmãos + filhos, só o que pode ver.
    // Retorna lista ordenada [pai, eu, irmãos..., filhos...] com ['id','name','color','current'=>0/1,'rel'=>'parent|self|sibling|child'].
    function kanpro_get_board_family(int $bid): array {
        global $DB;
        $fam = [];
        try {
            if ($bid <= 0) return [];
            if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'parent_boards_id')) return [];
            $me = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards', 'WHERE' => ['id' => $bid]])->current();
            if (!$me) return [];
            $pid = (int)($me['parent_boards_id'] ?? 0);
            $ids = [$bid];
            $rel = [$bid => 'self'];
            if ($pid > 0) {
                $ids[] = $pid;
                $rel[$pid] = 'parent';
                // irmãos: filhos do mesmo pai
                foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_kanpro_boards', 'WHERE' => ['parent_boards_id' => $pid]]) as $r) {
                    $sid = (int)$r['id'];
                    if ($sid !== $bid && !isset($rel[$sid])) { $ids[] = $sid; $rel[$sid] = 'sibling'; }
                }
            }
            // filhos
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_kanpro_boards', 'WHERE' => ['parent_boards_id' => $bid]]) as $r) {
                $cid = (int)$r['id'];
                if (!isset($rel[$cid])) { $ids[] = $cid; $rel[$cid] = 'child'; }
            }
            if (count($ids) <= 1) return []; // sem família: não mostra nada
            $rows = [];
            foreach ($DB->request(['SELECT' => ['id', 'name', 'color'], 'FROM' => 'glpi_plugin_kanpro_boards', 'WHERE' => ['id' => $ids]]) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            // ordena: pai, eu, irmãos (nome), filhos (nome)
            $order = [];
            if ($pid > 0 && isset($rows[$pid])) $order[] = $pid;
            $order[] = $bid;
            $sibs = [];
            $kids = [];
            foreach ($ids as $id) {
                if ($id === $bid || $id === $pid) continue;
                if (($rel[$id] ?? '') === 'child') $kids[] = $id;
                else $sibs[] = $id;
            }
            $byName = function ($a, $b) use ($rows) { return strcasecmp((string)($rows[$a]['name'] ?? ''), (string)($rows[$b]['name'] ?? '')); };
            usort($sibs, $byName);
            usort($kids, $byName);
            $order = array_merge($order, $sibs, $kids);
            foreach ($order as $id) {
                if (!isset($rows[$id])) continue;
                if (function_exists('kanpro_can_view_board') && !kanpro_can_view_board($id)) continue;
                $fam[] = [
                    'id' => $id,
                    'name' => (string)($rows[$id]['name'] ?? ('#' . $id)),
                    'color' => (string)($rows[$id]['color'] ?? '#0079bf'),
                    'current' => $id === $bid ? 1 : 0,
                    'rel' => $rel[$id] ?? '',
                ];
            }
            if (count($fam) <= 1) return []; // só eu visível: esconde
        } catch (Throwable $e) { return []; }
        return $fam;
    }
}

if (!function_exists('kanpro_family_candidates')) {
    // Quadros que podem virar pai: vejo + não sou eu + não é meu descendente + não arquivado.
    function kanpro_family_candidates(int $bid): array {
        global $DB;
        $out = [];
        try {
            if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'parent_boards_id')) return [];
            $desc = $bid > 0 ? kanpro_board_descendant_ids($bid) : [];
            $skip = array_flip(array_merge([$bid], $desc));
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_plugin_kanpro_boards', 'WHERE' => ['is_archived' => 0], 'ORDER' => 'name ASC']) as $r) {
                $id = (int)$r['id'];
                if (isset($skip[$id])) continue;
                if (function_exists('kanpro_can_view_board') && !kanpro_can_view_board($id)) continue;
                $out[] = ['id' => $id, 'name' => (string)($r['name'] ?? ('#' . $id))];
                if (count($out) >= 200) break;
            }
        } catch (Throwable $e) {}
        return $out;
    }
}

if (!function_exists('kanpro_set_board_parent')) {
    // Troca o pai do quadro. Exige gerenciar o quadro MOVIDO + ver o novo pai. Retorna [ok, msg].
    function kanpro_set_board_parent(int $bid, int $parentId): array {
        global $DB;
        try {
            if ($bid <= 0) return [false, 'Quadro inválido'];
            kanpro_ensure_family_column();
            $b = new PluginKanproBoard();
            if (!$b->getFromDB($bid)) return [false, 'Quadro não encontrado'];
            if ($parentId === $bid) return [false, 'Um quadro não pode ser filho dele mesmo.'];
            if ($parentId > 0) {
                $p = new PluginKanproBoard();
                if (!$p->getFromDB($parentId)) return [false, 'Quadro pai não encontrado'];
                if (function_exists('kanpro_can_view_board') && !kanpro_can_view_board($parentId)) {
                    return [false, 'Você não tem acesso ao quadro pai.'];
                }
                $desc = kanpro_board_descendant_ids($bid);
                if (in_array($parentId, $desc, true)) return [false, 'Ciclo detectado: o pai não pode ser um descendente.'];
            }
            $DB->update('glpi_plugin_kanpro_boards', ['parent_boards_id' => $parentId, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $bid]);
            if ($DB->error()) return [false, 'Erro ao salvar: ' . $DB->error()];
            return [true, ''];
        } catch (Throwable $e) {
            return [false, 'Erro ao salvar'];
        }
    }
}

if (!function_exists('kanpro_run_rules')) {
    // Butler-like MVP: ao entrar na lista, executa regras ativas do quadro (add_label, assign_member, set_due_days).
    function kanpro_run_rules(int $bid, int $cid, int $lists_id): void {
        global $DB;
        try {
            if ($bid <= 0 || $cid <= 0 || $lists_id <= 0) return;
            if (!$DB->tableExists('glpi_plugin_kanpro_rules')) return;
            $rules = $DB->request(['FROM' => 'glpi_plugin_kanpro_rules', 'WHERE' => ['plugin_kanpro_boards_id' => $bid, 'plugin_kanpro_lists_id' => $lists_id, 'trigger' => 'enter_list', 'is_active' => 1]]);
            foreach ($rules as $ru) {
                $act = (string)($ru['action'] ?? '');
                $par = trim((string)($ru['params'] ?? ''));
                try {
                    if ($act === 'add_label' && $par !== '') {
                        $lid = (int)$par;
                        $lok = $DB->request(['FROM' => 'glpi_plugin_kanpro_labels', 'WHERE' => ['id' => $lid, 'plugin_kanpro_boards_id' => $bid]])->current();
                        if ($lok && !countElementsInTable('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id' => $cid, 'plugin_kanpro_labels_id' => $lid])) {
                            $DB->insert('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id' => $cid, 'plugin_kanpro_labels_id' => $lid]);
                        }
                    } elseif ($act === 'assign_member' && $par !== '') {
                        $uid = (int)$par;
                        if ($uid > 0 && !countElementsInTable('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => $cid, 'users_id' => $uid])) {
                            $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => $cid, 'users_id' => $uid]);
                        }
                    } elseif ($act === 'set_due_days' && is_numeric($par)) {
                        $days = (int)$par;
                        $due = date('Y-m-d H:i:s', strtotime("+{$days} days"));
                        $DB->update('glpi_plugin_kanpro_cards', ['due_date' => $due, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $cid]);
                    }
                } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {}
    }
}
