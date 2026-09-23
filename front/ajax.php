<?php
if (function_exists('opcache_invalidate')) @opcache_invalidate(__FILE__, true);
include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/kanpro/inc/acting.php');
@ob_clean();
header('Content-Type: application/json; charset=UTF-8');
// debug log para 403
$__dbg = sprintf("[%s] UID=%s IP=%s action=%s profile=%s haveREAD=%d haveCREATE=%d haveUPDATE=%d SESSION=%s\n",
    date('Y-m-d H:i:s'),
    Session::getLoginUserID() ?: '0',
    $_SERVER['REMOTE_ADDR'] ?? '-',
    $_REQUEST['action'] ?? '-',
    json_encode($_SESSION['glpiactiveprofile']['id'] ?? null),
    (int)Session::haveRight('plugin_kanpro', READ),
    (int)Session::haveRight('plugin_kanpro', CREATE),
    (int)Session::haveRight('plugin_kanpro', UPDATE),
    json_encode($_SESSION['glpiactiveprofile']['plugin_kanpro'] ?? 'null')
);
if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => 'Não autenticado', 'debug' => $__dbg]);
    exit;
}
if (!Session::haveRight('plugin_kanpro', READ)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Sem permissão (plugin_kanpro READ) - verifique Perfil > KanPro', 'debug' => $__dbg, 'have' => $_SESSION['glpiactiveprofile']['plugin_kanpro'] ?? 0]);
    exit;
}

$action = $_REQUEST['action'] ?? '';
global $DB;

function jexit($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function needEdit() {
    if (!Session::haveRight('plugin_kanpro', UPDATE) && !Session::haveRight('plugin_kanpro', CREATE)) {
        $have = $_SESSION['glpiactiveprofile']['plugin_kanpro'] ?? 0;
        $dbg = json_encode(['profile_id'=>$_SESSION['glpiactiveprofile']['id']??null,'have'=>$have,'haveREAD'=>Session::haveRight('plugin_kanpro',READ),'haveCREATE'=>Session::haveRight('plugin_kanpro',CREATE),'haveUPDATE'=>Session::haveRight('plugin_kanpro',UPDATE)]);
        jexit(['success'=>false,'msg'=>"Sem permissão (precisa CREATE ou UPDATE). Seu nível atual: {$have}. Faça logout/login.", 'debug'=>$dbg]);
    }
}

// ---------- Helpers Membros do Quadro ----------
// Quem pode gerenciar acesso: criador do quadro, admin do quadro ou UPDATE global (bootstrap de quadros legados).
// Identidades do visualizador: sessão + pessoa (login compartilhado) — visibilidade vale para ambas.
function kanpro_viewer_ids(): array {
    return array_values(array_unique(array_filter([(int)Session::getLoginUserID(), kanpro_acting_user_id()])));
}
function kanpro_my_board_role($bid) {
    global $DB;
    // identidade da pessoa primeiro (login compartilhado), sessão como fallback
    foreach (array_unique([kanpro_acting_user_id(), (int)Session::getLoginUserID()]) as $uid) {
        if ($uid <= 0) continue;
        $row = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $bid, 'users_id' => $uid]])->current();
        if ($row) return $row['role'] ?? 'member';
    }
    return null;
}
function kanpro_is_board_creator($bid) {
    $b = new PluginKanproBoard();
    if (!$b->getFromDB($bid)) return false;
    return (int)($b->fields['users_id'] ?? 0) === (int)Session::getLoginUserID();
}
function kanpro_can_manage_members($bid) {
    if (kanpro_is_board_creator($bid)) return true;
    if (kanpro_my_board_role($bid) === 'admin') return true;
    // fallback: UPDATE global (administradores do GLPI + quadros legados sem membros)
    if (Session::haveRight('plugin_kanpro', UPDATE)) return true;
    return false;
}
function kanpro_need_manage_members($bid) {
    if (!kanpro_can_manage_members($bid)) {
        jexit(['success'=>false,'msg'=>'Somente o criador ou administradores do quadro podem gerenciar o acesso.']);
    }
}
// Conta outros gestores (criador ou admins) além de $excludeUid — evita lockout.
function kanpro_count_other_managers($bid, $excludeUid) {
    global $DB;
    $count = 0;
    $b = new PluginKanproBoard();
    if ($b->getFromDB($bid) && (int)($b->fields['users_id'] ?? 0) !== (int)$excludeUid && (int)($b->fields['users_id'] ?? 0) > 0) {
        $count++;
    }
    $admins = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $bid, 'role' => 'admin']]);
    foreach ($admins as $a) {
        if ((int)$a['users_id'] !== (int)$excludeUid) $count++;
    }
    return $count;
}
function kanpro_user_brief($uid) {
    $u = new User();
    $name = 'Usuário #' . $uid;
    $initials = '?';
    $login = '';
    if ($u->getFromDB($uid)) {
        $name = $u->getFriendlyName();
        $login = $u->fields['name'] ?? '';
        $initials = strtoupper(substr($u->fields['firstname'] ?? $u->fields['name'] ?? '?', 0, 1) . substr($u->fields['realname'] ?? '', 0, 1));
        if (trim($initials) === '') $initials = strtoupper(substr($name, 0, 2));
    }
    return ['users_id' => (int)$uid, 'name' => $name, 'login' => $login, 'initials' => $initials];
}
// Migração runtime das novidades (fixar, aprovação, etiqueta com prazo, lixeira) — sem reinstalar.
function kanpro_ensure_board_extras() {
    global $DB;
    try {
        if ($DB->tableExists('glpi_plugin_kanpro_cards') && !$DB->fieldExists('glpi_plugin_kanpro_cards', 'is_pinned')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `is_pinned` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1=fixado no topo da lista'");
        }
        if ($DB->tableExists('glpi_plugin_kanpro_cards') && !$DB->fieldExists('glpi_plugin_kanpro_cards', 'approval_from')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `approval_from` INT NOT NULL DEFAULT '0' COMMENT 'lista de origem se aguardando aprovacao, 0=sem pendencia'");
        }
        if ($DB->tableExists('glpi_plugin_kanpro_lists') && !$DB->fieldExists('glpi_plugin_kanpro_lists', 'require_approval')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_lists` ADD `require_approval` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1=entrada de cartoes exige aprovacao de admin'");
        }
        if ($DB->tableExists('glpi_plugin_kanpro_labels') && !$DB->fieldExists('glpi_plugin_kanpro_labels', 'due_date')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_labels` ADD `due_date` DATETIME DEFAULT NULL COMMENT 'prazo: cartão fica vermelho ao vencer'");
        }
        if ($DB->tableExists('glpi_plugin_kanpro_comments') && !$DB->fieldExists('glpi_plugin_kanpro_comments', 'is_pinned')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_comments` ADD `is_pinned` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1=comentário fixado no topo'");
        }        if (!$DB->tableExists('glpi_plugin_kanpro_trash')) {
            $charset = DBConnection::getDefaultCharset();
            $collation = DBConnection::getDefaultCollation();
            $sign = DBConnection::getDefaultPrimaryKeySignOption();
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_kanpro_trash` (
                    `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                    `plugin_kanpro_boards_id`     INT {$sign} NOT NULL DEFAULT '0',
                    `plugin_kanpro_lists_id`      INT {$sign} NOT NULL DEFAULT '0',
                    `list_name`                   VARCHAR(255) NOT NULL DEFAULT '',
                    `card_name`                   VARCHAR(255) NOT NULL DEFAULT '',
                    `snapshot`                    LONGTEXT     DEFAULT NULL,
                    `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                    `date_creation`               DATETIME     DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `plugin_kanpro_boards_id` (`plugin_kanpro_boards_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }
    } catch (Throwable $e) {
        Toolbox::logError("KanPro ensure_board_extras: " . $e->getMessage());
    }
}

// ---------- Helpers Manutenção ----------
// Etiqueta roxa "Inventário": presente no cartão enquanto houver >=1 máquina que precisa inventariar.
function kanpro_sync_inventory_label($cards_id) {
    global $DB;
    $cards_id = (int)$cards_id;
    if (!$cards_id) return;
    $card = new PluginKanproCard();
    if (!$card->getFromDB($cards_id)) return;
    $bid = (int)$card->fields['plugin_kanpro_boards_id'];
    $needs = countElementsInTable('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cards_id, 'needs_inventory'=>1]);
    // acha ou cria a etiqueta roxa do quadro
    $lab = $DB->request(['FROM'=>'glpi_plugin_kanpro_labels','WHERE'=>['plugin_kanpro_boards_id'=>$bid,'name'=>'Inventário'],'LIMIT'=>1])->current();
    if ($needs > 0) {
        if (!$lab) {
            $nl = new PluginKanproLabel();
            $labId = $nl->add(['plugin_kanpro_boards_id'=>$bid,'name'=>'Inventário','color'=>'#6554c0']);
        } else {
            $labId = (int)$lab['id'];
        }
        if ($labId && !countElementsInTable('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cards_id,'plugin_kanpro_labels_id'=>$labId])) {
            $DB->insert('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cards_id,'plugin_kanpro_labels_id'=>$labId]);
        }
    } elseif ($lab) {
        $DB->delete('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cards_id,'plugin_kanpro_labels_id'=>(int)$lab['id']]);
    }
}
function kanpro_verify_password($input) {
    global $DB;
    $uid = Session::getLoginUserID();
    if (!$uid || $input === '' || $input === null) return false;
    $row = $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $uid]])->current();
    if (!$row) return false;
    $hash = $row['password'] ?? '';
    if (!$hash) return false;
    if (class_exists('Auth') && method_exists('Auth', 'checkPassword')) {
        try {
            if (Auth::checkPassword($input, $hash)) return true;
        } catch (Throwable $e) {}
    }
    if (function_exists('password_verify') && password_verify($input, $hash)) return true;
    if (md5($input) === $hash) return true;
    // legacy GLPI sha1 with salt? try GLPI 9 style: sha1 with maybe prefix
    return false;
}

function kanpro_normalize_confirm($t) {
    $t = trim($t ?? '');
    $t = mb_strtoupper($t, 'UTF-8');
    // remove accents
    $map = ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C'];
    $t = strtr($t, $map);
    return $t;
}

function kanpro_parse_maintenance_raw($raw) {
    $raw = trim($raw ?? '');
    if ($raw === '') return [];
    $defs = [];
    // Normaliza separadores , e ; para quebra de linha
    $normalized = str_replace([',',';'], "\n", $raw);
    // Tenta regex global no texto normalizado (captura mesmo sem quebra de linha, ex: "10x A 10x B")
    if (preg_match_all('/(\d+)\s*[xX]\s*([^\n]+?)(?=\s*\d+\s*[xX]\s*|$)/u', $normalized, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $qty = (int)trim($match[1]);
            $model = trim($match[2]);
            $model = trim($model, " \t\n\r\0\x0B,;.-");
            if ($qty > 0 && $qty <= 500 && $model !== '') {
                $defs[] = ['qty' => $qty, 'model' => $model];
            }
        }
        if (!empty($defs)) {
            $out = [];
            foreach ($defs as $d) {
                $d['qty'] = max(1, min(500, (int)$d['qty']));
                $d['model'] = trim($d['model']);
                if ($d['model'] !== '') $out[] = $d;
            }
            if (!empty($out)) return $out;
        }
        $defs = [];
    }
    // Fallback: split por quebras
    $parts = preg_split('/[\n]+/', $normalized);
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (preg_match('/^(\d+)\s*[xX]\s*(.+)$/u', $part, $mm)) {
            $defs[] = ['qty'=>(int)$mm[1], 'model'=>trim($mm[2], " \t,;.-")];
        } else if (preg_match('/^(\d+)\s+(.+)$/u', $part, $mm)) {
            $defs[] = ['qty'=>(int)$mm[1], 'model'=>trim($mm[2], " \t,;.-")];
        } else {
            $defs[] = ['qty'=>1, 'model'=>$part];
        }
    }
    $out = [];
    foreach ($defs as $d) {
        $d['qty'] = max(1, min(500, (int)$d['qty']));
        $d['model'] = trim($d['model']);
        if ($d['model'] !== '') $out[] = $d;
    }
    return $out;
}

function kanpro_ensure_maintenance_tables() {
    global $DB;
    $charset = method_exists('DBConnection','getDefaultCharset') ? DBConnection::getDefaultCharset() : 'utf8mb4';
    $collation = method_exists('DBConnection','getDefaultCollation') ? DBConnection::getDefaultCollation() : 'utf8mb4_unicode_ci';
    $sign = method_exists('DBConnection','getDefaultPrimaryKeySignOption') ? DBConnection::getDefaultPrimaryKeySignOption() : 'unsigned';
    if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_maintenance_machines` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_cards_id`      INT {$sign} NOT NULL DEFAULT '0',
                `seq`                         INT          NOT NULL DEFAULT '0',
                `model`                       VARCHAR(255) NOT NULL DEFAULT '',
                `label`                       VARCHAR(255) NOT NULL DEFAULT '',
                `diary`                       TEXT         DEFAULT NULL,
                `is_done`                     TINYINT(1)   NOT NULL DEFAULT '0',
                `is_ok`                       TINYINT(1)   NOT NULL DEFAULT '0',
                `status`                      VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'garantia,ok,inservivel,pendente',
                `is_inventoried`              TINYINT(1)   NOT NULL DEFAULT '0' COMMENT '0=nao,1=inventariado',
                `needs_inventory`             TINYINT(1)   NOT NULL DEFAULT '0' COMMENT '0=nao precisa,1=precisa inventariar',
                `is_urgent`                   TINYINT(1)   NOT NULL DEFAULT '0' COMMENT '0=normal,1=urgencia',
                `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                `date_creation`               DATETIME     DEFAULT NULL,
                `date_mod`                    DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_kanpro_cards_id` (`plugin_kanpro_cards_id`),
                KEY `seq` (`seq`),
                KEY `is_done` (`is_done`),
                KEY `is_inventoried` (`is_inventoried`),
                KEY `is_urgent` (`is_urgent`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ");
    } else {
        // garante status default '' (obrigatório) e migra legados pending/defect
        try {
            if ($DB->fieldExists('glpi_plugin_kanpro_maintenance_machines', 'status')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_maintenance_machines` MODIFY `status` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'garantia,ok,inservivel,pendente'");
                $DB->doQuery("UPDATE `glpi_plugin_kanpro_maintenance_machines` SET `status`='pendente' WHERE `status`='pending'");
                $DB->doQuery("UPDATE `glpi_plugin_kanpro_maintenance_machines` SET `status`='inservivel' WHERE `status`='defect' OR `status`='nok'");
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_maintenance_machines', 'is_inventoried')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_maintenance_machines` ADD `is_inventoried` TINYINT(1) NOT NULL DEFAULT '0' AFTER `status`");
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_maintenance_machines', 'needs_inventory')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_maintenance_machines` ADD `needs_inventory` TINYINT(1) NOT NULL DEFAULT '0' AFTER `is_inventoried`");
                // legado: quem já estava inventariado, precisava inventariar
                $DB->doQuery("UPDATE `glpi_plugin_kanpro_maintenance_machines` SET `needs_inventory`=1 WHERE `is_inventoried`=1");
            }
            if (!$DB->fieldExists('glpi_plugin_kanpro_maintenance_machines', 'is_urgent')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_maintenance_machines` ADD `is_urgent` TINYINT(1) NOT NULL DEFAULT '0' AFTER `is_inventoried`");
            }
            // limpeza: Nome do Recebedor deve ficar vazio por padrão — remove preenchimento automático antigo em transferências pendentes do KanPro
            try {
                if ($DB->tableExists('glpi_plugin_assetmgrstatus_transfers') && $DB->fieldExists('glpi_plugin_assetmgrstatus_transfers', 'assinatura_nome')) {
                    // limpa nome pré-preenchido em termos ainda não assinados pelo recebedor (imagem vazia)
                    $DB->doQuery("UPDATE `glpi_plugin_assetmgrstatus_transfers` SET `assinatura_nome` = NULL WHERE (`assinatura_image` IS NULL OR `assinatura_image` = '') AND `assinatura_nome` IS NOT NULL AND `reason` LIKE '%KanPro%'");
                }
            } catch (Throwable $e) {}
        } catch (Throwable $e) {}
    }
    // Anotações por máquina — cria se não existir (dispensa reinstalar o plugin)
    if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_notes')) {
        try {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_kanpro_maintenance_notes` (
                    `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                    `machine_id`                  INT {$sign} NOT NULL DEFAULT '0',
                    `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                    `note`                        TEXT         DEFAULT NULL,
                    `date_creation`               DATETIME     DEFAULT NULL,
                    `date_mod`                    DATETIME     DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `machine_id` (`machine_id`),
                    KEY `date_creation` (`date_creation`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        } catch (Throwable $e) {}
    }
    // garante colunas de card
    if ($DB->tableExists('glpi_plugin_kanpro_cards')) {
        if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'is_maintenance')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `is_maintenance` TINYINT(1) NOT NULL DEFAULT '0' AFTER `is_completed`");
        }
        if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'maintenance_date')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `maintenance_date` DATETIME DEFAULT NULL AFTER `is_maintenance`");
        }
        if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'maintenance_by')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `maintenance_by` INT NOT NULL DEFAULT '0' AFTER `maintenance_date`");
        }
    }
}

function kanpro_ticket_info(int $tid): ?array {
    if (!class_exists('Ticket')) return null;
    $tk = new Ticket();
    if (!$tk->getFromDB($tid)) return null;
    $can = false;
    try { $can = $tk->can($tid, READ); } catch (Throwable $e) { $can = false; }
    $status = (int)($tk->fields['status'] ?? 0);
    $label = '';
    if (method_exists('Ticket', 'getStatus')) {
        try { $label = Ticket::getStatus($status); } catch (Throwable $e) { $label = 'Status '.$status; }
    } else {
        $label = 'Status '.$status;
    }
    return [
        'id'           => $tid,
        'name'         => $can ? ($tk->fields['name'] ?? '') : '',
        'restricted'   => !$can,
        'status'       => $status,
        'status_label' => $label,
        'date_mod'     => $tk->fields['date_mod'] ?? null,
    ];
}

// Migration em runtime: garante coluna do chamado vinculado sem depender do update do plugin
function kanpro_ensure_v11() {
    global $DB;
    try {
        if ($DB->tableExists('glpi_plugin_kanpro_cards') && !$DB->fieldExists('glpi_plugin_kanpro_cards', 'tickets_id')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `tickets_id` INT NOT NULL DEFAULT '0' AFTER `cover_attachment_id`");
        }
    } catch (Throwable $e) {}
}
kanpro_ensure_v11();
function kanpro_card_id_of_checklist(int $checklists_id): int {
    global $DB;
    try {
        $cl = $DB->request(['SELECT' => ['plugin_kanpro_cards_id'], 'FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['id' => $checklists_id]])->current();
        return (int)($cl['plugin_kanpro_cards_id'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

// Auto-membro: quem edita o card vira membro (passa a ver no Minhas Tarefas e no contexto)
function kanpro_touch_member(int $cards_id, ?int $users_id = null) {
    global $DB;
    try {
        $uid = $users_id ?: kanpro_acting_user_id();
        if ($cards_id <= 0 || $uid <= 0) return;
        if (!$DB->tableExists('glpi_plugin_kanpro_cards_members')) return;
        $exists = countElementsInTable('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => $cards_id, 'users_id' => $uid]);
        if (!$exists) {
            $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => $cards_id, 'users_id' => $uid]);
        }
    } catch (Throwable $e) {}
}

// Cria um chamado GLPI a partir do cartão e vincula (tickets_id).
// Usado na conversão para manutenção (automático) e no botão Chamado.
// Se o cartão já tem chamado válido, só retorna o vínculo existente.
function kanpro_create_ticket_from_card(int $cards_id): array {
    global $DB;
    if (!class_exists('Ticket')) return ['ok' => false, 'error' => 'Classe Ticket indisponível'];
    $card = new PluginKanproCard();
    if (!$card->getFromDB($cards_id)) return ['ok' => false, 'error' => 'Cartão não encontrado'];
    if (!Session::haveRight('ticket', CREATE)) return ['ok' => false, 'error' => 'Sem permissão para criar chamados (perfil sem ticket CREATE)'];
    $old = (int)($card->fields['tickets_id'] ?? 0);
    if ($old > 0) {
        $tkOld = new Ticket();
        if ($tkOld->getFromDB($old)) return ['ok' => true, 'id' => $old, 'existed' => true, 'ticket' => kanpro_ticket_info($old)];
    }
    $board = new PluginKanproBoard();
    $board->getFromDB((int)$card->fields['plugin_kanpro_boards_id']);
    $list = new PluginKanproList();
    $list->getFromDB((int)$card->fields['plugin_kanpro_lists_id']);
    $entities_id = (int)($board->fields['entities_id'] ?? 0);
    if ($entities_id <= 0) $entities_id = (int)($_SESSION['glpiactive_entity'] ?? 0);
    $content = "Chamado aberto automaticamente pelo KanPro.\n\n"
        . 'Quadro: ' . ($board->fields['name'] ?? '-') . "\n"
        . 'Lista: ' . ($list->fields['name'] ?? '-') . "\n"
        . 'Cartão: #' . $cards_id . ' ' . (trim($card->fields['name'] ?? '') ?: ('Cartão #' . $cards_id)) . "\n";
    if (!empty($card->fields['description'])) $content .= "\nDescrição do cartão:\n" . $card->fields['description'] . "\n";
    $content .= "\nAs atualizações da manutenção serão registradas como acompanhamentos neste chamado.";
    $tk = new Ticket();
    $tid = $tk->add([
        'name' => mb_substr(trim($card->fields['name'] ?? ('Cartão #' . $cards_id)), 0, 255),
        'content' => $content,
        'entities_id' => $entities_id,
        'status' => 1,
        '_users_id_requester' => kanpro_acting_user_id(),
    ]);
    if (!$tid) return ['ok' => false, 'error' => 'Falha ao criar chamado (verifique entidade/perfil)'];
    $tid = (int)$tid;
    $DB->update('glpi_plugin_kanpro_cards', ['tickets_id' => $tid], ['id' => $cards_id]);
    PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cards_id, $card->fields['plugin_kanpro_lists_id'], 'card_create_ticket', "Chamado #{$tid} criado a partir do cartão");
    return ['ok' => true, 'id' => $tid, 'ticket' => kanpro_ticket_info($tid)];
}

// Usuário para atribuição no chamado: ver inc/acting.php (kanpro_acting_user_id).

// ID do chamado vinculado ao cartão (0 se nenhum ou inválido)
function kanpro_card_ticket_id(int $cards_id): int {
    global $DB;
    try {
        if (!$DB->tableExists('glpi_plugin_kanpro_cards')) return 0;
        $row = $DB->request(['SELECT' => ['tickets_id'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['id' => $cards_id]])->current();
        $tid = (int)($row['tickets_id'] ?? 0);
        if ($tid <= 0 || !class_exists('Ticket')) return 0;
        $tk = new Ticket();
        if (!$tk->getFromDB($tid)) return 0;
        return $tid;
    } catch (Throwable $e) { return 0; }
}

// Acompanhamento no chamado vinculado (nunca quebra o fluxo principal).
// Por padrão também atribui quem agiu (type=2), sem duplicar — usa "Agindo como".
function kanpro_ticket_followup(int $tickets_id, string $content, bool $assignActingUser = true): bool {
    if ($tickets_id <= 0 || trim($content) === '' || !class_exists('ITILFollowup')) return false;
    global $DB;
    try {
        $auid = kanpro_acting_user_id();
        $tf = new ITILFollowup();
        $fid = $tf->add([
            'itemtype' => 'Ticket',
            'items_id' => $tickets_id,
            'content' => $content,
            'users_id' => $auid,
            'is_private' => 0,
        ]);
        if ($fid) {
            // garante o autor (o core pode forçar o usuário da sessão)
            foreach (['glpi_itilfollowups', 'glpi_ticketfollowups'] as $t) {
                if ($DB->tableExists($t)) { $DB->update($t, ['users_id' => $auid], ['id' => $fid]); break; }
            }
            if ($assignActingUser) kanpro_ticket_assign($tickets_id, $auid);
        }
        return (bool)$fid;
    } catch (Throwable $e) { return false; }
}

// Soluciona o chamado (forma oficial via ITILSolution; fallback update direto)
function kanpro_ticket_solve(int $tickets_id, string $solution): bool {
    if ($tickets_id <= 0 || !class_exists('Ticket')) return false;
    global $DB;
    try {
        if (class_exists('ITILSolution')) {
            $sol = new ITILSolution();
            $auid = kanpro_acting_user_id();
            $sid = $sol->add(['itemtype' => 'Ticket', 'items_id' => $tickets_id, 'content' => $solution, 'users_id' => $auid]);
            if ($sid) {
                foreach (['glpi_itilsolutions', 'glpi_solution'] as $t) {
                    if ($DB->tableExists($t)) { $DB->update($t, ['users_id' => $auid], ['id' => $sid]); break; }
                }
                return true;
            }
        }
        $tk = new Ticket();
        return (bool)$tk->update(['id' => $tickets_id, 'status' => (defined('Ticket::SOLVED') ? Ticket::SOLVED : 5)]);
    } catch (Throwable $e) { return false; }
}

// Move o chamado para Em atendimento (só se ainda estiver aberto — nunca reabre Solucionado/Fechado)
function kanpro_ticket_set_attending(int $tickets_id): bool {
    if ($tickets_id <= 0 || !class_exists('Ticket')) return false;
    try {
        $tk = new Ticket();
        if (!$tk->getFromDB($tickets_id)) return false;
        $st = (int)($tk->fields['status'] ?? 0);
        $attending = (defined('Ticket::ASSIGNED') ? Ticket::ASSIGNED : 2);
        $solved = (defined('Ticket::SOLVED') ? Ticket::SOLVED : 5);
        $closed = (defined('Ticket::CLOSED') ? Ticket::CLOSED : 6);
        if (in_array($st, [$attending, $solved, $closed], true)) return true;
        return (bool)$tk->update(['id' => $tickets_id, 'status' => $attending]);
    } catch (Throwable $e) { return false; }
}

// Vincula usuário como atribuído (type=2) no chamado, sem duplicar
function kanpro_ticket_assign(int $tickets_id, int $users_id): bool {
    if ($tickets_id <= 0 || $users_id <= 0 || !class_exists('Ticket_User')) return false;
    try {
        $exists = countElementsInTable('glpi_tickets_users', ['tickets_id' => $tickets_id, 'users_id' => $users_id, 'type' => 2]);
        if ($exists) return true;
        $tu = new Ticket_User();
        return (bool)$tu->add(['tickets_id' => $tickets_id, 'users_id' => $users_id, 'type' => 2]);
    } catch (Throwable $e) { return false; }
}

function kanpro_machine_status_label(string $st): string {
    $map = ['' => 'sem status', 'pendente' => 'Pendente', 'garantia' => 'Garantia', 'ok' => 'OK', 'inservivel' => 'Inservível'];
    $k = mb_strtolower(trim($st), 'UTF-8');
    return $map[$k] ?? ($st === '' ? 'sem status' : $st);
}

// Relatório completo das máquinas do card (vai no corpo do acompanhamento do chamado).
// Usa só caracteres de até 3 bytes (nada de emoji 4-byte — quebra no ticket).
function kanpro_card_machines_report(int $cards_id): string {
    global $DB;
    try {
        if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) return '';
        $iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['plugin_kanpro_cards_id' => $cards_id], 'ORDER' => 'seq ASC']);
        $lines = [];
        foreach ($iter as $m) {
            $bits = [];
            $bits[] = 'Status: ' . kanpro_machine_status_label($m['status'] ?? '');
            $bits[] = !empty($m['is_done']) ? 'Feita' : 'Pendente';
            if (!empty($m['is_urgent'])) $bits[] = 'URGENTE';
            if (!empty($m['is_inventoried'])) $bits[] = 'Inventariada';
            $diary = trim($m['diary'] ?? '');
            if ($diary !== '') $bits[] = 'Diário: ' . mb_substr($diary, 0, 150) . (mb_strlen($diary) > 150 ? '…' : '');
            $lines[] = '• #' . $m['seq'] . ' ' . ($m['label'] ?: $m['model']) . ' — ' . implode(' | ', $bits);
        }
        if (empty($lines)) return '';
        // separador entre "o que mudou" e "como está tudo agora" (só 3-byte: ticket não é utf8mb4)
        return str_repeat('─', 40) . "\nSituação atual das máquinas do cartão (" . count($lines) . "):\n\n" . implode("\n", $lines);
    } catch (Throwable $e) { return ''; }
}

// ZIP mínimo (método stored, sem compressão) — dependency-free p/ montar o xlsx.
function kanpro_zip_stored(array $files): string {
    $ts = time();
    $d = getdate($ts);
    $t = (($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1)) & 0xFFFF;
    $dt = ((($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']) & 0xFFFF;
    $body = '';
    $central = '';
    $offset = 0;
    foreach ($files as $name => $data) {
        $data = (string)$data;
        $crc = crc32($data);
        if ($crc < 0) $crc += 4294967296;
        $len = strlen($data);
        $nl = strlen($name);
        $local = "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0x0800, 0, $t, $dt, $crc, $len, $len, $nl, 0) . $name . $data;
        $body .= $local;
        $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 0, $t, $dt, $crc, $len, $len, $nl, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($local);
    }
    $cdLen = strlen($central);
    $count = count($files);
    return $body . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, $cdLen, $offset, 0);
}

// Planilha xlsx real (ZIP + XML inline strings, sem dependências).
function kanpro_build_xlsx(string $sheet, array $header, array $rows): string {
    $clean = function ($v) {
        $v = (string)($v ?? '');
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
        return htmlspecialchars($v, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    };
    $cell = function ($v) use ($clean) { return '<c t="inlineStr"><is><t>' . $clean($v) . '</t></is></c>'; };
    $sheetName = $clean(mb_substr(preg_replace('/[\\\\\\/\\?\\*\\[\\]]/', '', $sheet) ?: 'Planilha', 0, 31));
    $xml = '<row r="1">';
    foreach ($header as $h) $xml .= $cell($h);
    $xml .= '</row>';
    $r = 1;
    foreach ($rows as $row) {
        $r++;
        $xml .= '<row r="' . $r . '">';
        foreach ($row as $v) $xml .= $cell($v);
        $xml .= '</row>';
    }
    $ct = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxml-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $wb = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $wbr = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';
    $ws = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols><col min="1" max="1" width="19" customWidth="1"/><col min="2" max="2" width="30" customWidth="1"/><col min="3" max="3" width="24" customWidth="1"/><col min="4" max="4" width="32" customWidth="1"/><col min="5" max="5" width="70" customWidth="1"/></cols>'
        . '<sheetData>' . $xml . '</sheetData></worksheet>';
    $tmp = tempnam(sys_get_temp_dir(), 'kph');
    if ($tmp && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('[Content_Types].xml', $ct);
            $zip->addFromString('_rels/.rels', $rels);
            $zip->addFromString('xl/workbook.xml', $wb);
            $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);
            $zip->addFromString('xl/worksheets/sheet1.xml', $ws);
            $zip->close();
            $bin = @file_get_contents($tmp);
            @unlink($tmp);
            if ($bin) return $bin;
        }
        @unlink($tmp);
    }
    // fallback sem extensão: monta o ZIP na mão
    return kanpro_zip_stored([
        '[Content_Types].xml' => $ct,
        '_rels/.rels' => $rels,
        'xl/workbook.xml' => $wb,
        'xl/_rels/workbook.xml.rels' => $wbr,
        'xl/worksheets/sheet1.xml' => $ws,
    ]);
}

switch ($action) {

    // --- BOARD ---
    case 'rename_board':
        needEdit();
        $id = (int)($_POST['boards_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$name) jexit(['success'=>false,'msg'=>'Nome obrigatório']);
        $b = new PluginKanproBoard();
        if (!$b->getFromDB($id)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        $b->update(['id'=>$id,'name'=>$name]);
        PluginKanproBoard::logActivity($id, null, null, 'board_rename', "Renomeado para {$name}");
        jexit(['success'=>true]);

    case 'star_board':
        needEdit();
        $id = (int)($_POST['boards_id'] ?? 0);
        $b = new PluginKanproBoard();
        $b->getFromDB($id);
        $new = $b->fields['is_starred'] ? 0 : 1;
        $b->update(['id'=>$id,'is_starred'=>$new]);
        jexit(['success'=>true,'is_starred'=>$new]);

    case 'archive_board':
        needEdit();
        $id = (int)($_POST['boards_id'] ?? 0);
        $b = new PluginKanproBoard();
        $b->getFromDB($id);
        $b->update(['id'=>$id,'is_archived'=> $b->fields['is_archived'] ? 0 : 1]);
        jexit(['success'=>true]);

    case 'delete_board':
        if (!Session::haveRight('plugin_kanpro', PURGE)) jexit(['success'=>false,'msg'=>'Sem permissão PURGE']);
        $id = (int)($_POST['boards_id'] ?? 0);
        $b = new PluginKanproBoard();
        $b->delete(['id'=>$id], true);
        jexit(['success'=>true]);

    case 'update_board_color':
        needEdit();
        $id = (int)($_POST['boards_id'] ?? 0);
        $color = trim($_POST['color'] ?? '#0079bf');
        $isHex = (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $color);
        $isGrad = (strpos($color, 'linear-gradient') === 0);
        if (!$isHex && !$isGrad) {
            jexit(['success'=>false,'msg'=>'Cor inválida — use hex #rrggbb ou degradê']);
        }
        if (strlen($color) > 255) $color = substr($color, 0, 255);
        // migração automática: garante VARCHAR(255) para degradês (evita 500 Data too long)
        try {
            if ($DB->fieldExists('glpi_plugin_kanpro_boards', 'color')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` MODIFY `color` VARCHAR(255) NOT NULL DEFAULT '#0079bf'");
            }
        } catch (Throwable $e) {}
        try {
            $DB->update('glpi_plugin_kanpro_boards', ['color'=>$color], ['id'=>$id]);
            if ($DB->error() && stripos($DB->error(), 'Data too long') !== false) {
                // coluna antiga VARCHAR(20): tenta ampliar de novo e só salva se couber — nunca salva degradê truncado (quebrava o CSS da capa)
                try { $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` MODIFY `color` VARCHAR(255) NOT NULL DEFAULT '#0079bf'"); } catch (Throwable $e2) {}
                $DB->update('glpi_plugin_kanpro_boards', ['color'=>$color], ['id'=>$id]);
                if ($DB->error()) jexit(['success'=>false,'msg'=>'A coluna de cor do banco é antiga e não pôde ser ampliada automaticamente. Peça ao admin para rodar: ALTER TABLE glpi_plugin_kanpro_boards MODIFY color VARCHAR(255).']);
            } elseif ($DB->error()) {
                jexit(['success'=>false,'msg'=>'Erro ao salvar: '.$DB->error()]);
            }
        } catch (Throwable $e) {
            jexit(['success'=>false,'msg'=>'Erro ao salvar: '.$e->getMessage()]);
        }
        jexit(['success'=>true]);

    case 'upload_board_background':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $board = new PluginKanproBoard();
        if (!$board->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) jexit(['success'=>false,'msg'=>'Nenhum arquivo enviado']);
        // garante coluna background existe (migração automática)
        try {
            if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'background')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` ADD `background` VARCHAR(255) DEFAULT NULL AFTER `color`");
            }
        } catch (Throwable $e) {}
        $rel = PluginKanproBoard::handleBackgroundUpload($bid, $_FILES['file']);
        if (!$rel) jexit(['success'=>false,'msg'=>'Falha ao salvar imagem — verifique formato (JPG/PNG/WebP/GIF) e tamanho máximo 5MB. Resolução recomendada 1920×1080 (16:9)']);
        $board->getFromDB($bid);
        jexit(['success'=>true,'background'=>$rel,'url'=>PluginKanproBoard::getBackgroundImageUrl($bid, $rel)]);

    case 'remove_board_background':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $board = new PluginKanproBoard();
        if (!$board->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        PluginKanproBoard::deleteBackgroundFile($bid);
        jexit(['success'=>true]);

    case 'set_board_wallpaper':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $key = trim($_POST['wallpaper'] ?? '');
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $board = new PluginKanproBoard();
        if (!$board->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        $rel = PluginKanproBoard::setBoardWallpaper($bid, $key);
        if (!$rel) jexit(['success'=>false,'msg'=>'Papel de parede inválido']);
        $board->getFromDB($bid);
        jexit(['success'=>true,'background'=>$rel,'url'=>PluginKanproBoard::getBackgroundImageUrl($bid, $rel)]);

    case 'invite_member':
        $bid = (int)($_POST['boards_id'] ?? 0);
        $uid = (int)($_POST['users_id'] ?? 0);
        $role = $_POST['role'] ?? 'member';
        if (!in_array($role, ['admin','member','observer'], true)) $role = 'member';
        if (!$bid || !$uid) jexit(['success'=>false,'msg'=>'Quadro ou usuário inválido']);
        kanpro_need_manage_members($bid);
        $DB->insert('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>$uid,'role'=>$role,'date_creation'=>date('Y-m-d H:i:s')]);
        // ignora duplicado
        if ($DB->error() && strpos($DB->error(), 'Duplicate')!==false) jexit(['success'=>false,'msg'=>'Usuário já é membro']);
        PluginKanproBoard::logActivity($bid, null, null, 'member_add', "Membro {$uid} adicionado ({$role})");
        jexit(['success'=>true]);

    case 'remove_member':
        $bid = (int)($_POST['boards_id'] ?? 0);
        $uid = (int)($_POST['users_id'] ?? 0);
        if (!$bid || !$uid) jexit(['success'=>false,'msg'=>'Quadro ou usuário inválido']);
        kanpro_need_manage_members($bid);
        // não permite remover o criador nem se auto-remover sendo o último gestor
        $bchk = new PluginKanproBoard();
        if ($bchk->getFromDB($bid) && (int)($bchk->fields['users_id'] ?? 0) === $uid) {
            jexit(['success'=>false,'msg'=>'O criador do quadro não pode ser removido.']);
        }
        if (in_array($uid, [(int)Session::getLoginUserID(), kanpro_acting_user_id()], true) && kanpro_count_other_managers($bid, $uid) === 0) {
            jexit(['success'=>false,'msg'=>'Você é o último gestor. Promova outra pessoa a admin antes de sair.']);
        }
        $DB->delete('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>$uid]);
        PluginKanproBoard::logActivity($bid, null, null, 'member_remove', "Membro {$uid} removido");
        jexit(['success'=>true]);

    case 'set_member_role':
        $bid = (int)($_POST['boards_id'] ?? 0);
        $uid = (int)($_POST['users_id'] ?? 0);
        $role = $_POST['role'] ?? 'member';
        if (!in_array($role, ['admin','member'], true)) jexit(['success'=>false,'msg'=>'Papel inválido (use admin ou member)']);
        if (!$bid || !$uid) jexit(['success'=>false,'msg'=>'Quadro ou usuário inválido']);
        kanpro_need_manage_members($bid);
        $bchk = new PluginKanproBoard();
        if ($bchk->getFromDB($bid) && (int)($bchk->fields['users_id'] ?? 0) === $uid) {
            jexit(['success'=>false,'msg'=>'O criador do quadro já tem acesso total.']);
        }
        $exists = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>$uid]);
        if (!$exists) jexit(['success'=>false,'msg'=>'Usuário não é membro do quadro']);
        // não permite se rebaixar sendo o último gestor
        if ($role !== 'admin' && in_array($uid, [(int)Session::getLoginUserID(), kanpro_acting_user_id()], true) && kanpro_count_other_managers($bid, $uid) === 0) {
            jexit(['success'=>false,'msg'=>'Você é o último gestor. Promova outra pessoa a admin antes.']);
        }
        $DB->update('glpi_plugin_kanpro_boards_members', ['role'=>$role], ['plugin_kanpro_boards_id'=>$bid,'users_id'=>$uid]);
        PluginKanproBoard::logActivity($bid, null, null, 'member_role', "Membro {$uid} agora é {$role}");
        jexit(['success'=>true]);

    case 'get_board_members':
        $bid = (int)($_POST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $bchk = new PluginKanproBoard();
        if (!$bchk->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        // trava de visibilidade (criador, membro ou legado sem membros — vale sessão e pessoa)
        $__creator = (int)($bchk->fields['users_id'] ?? 0);
        if ($__creator !== (int)Session::getLoginUserID()) {
            $__isM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>kanpro_viewer_ids()]) > 0;
            $__hasM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid]) > 0;
            if (!$__isM && $__hasM) jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
        }
        $creatorId = (int)($bchk->fields['users_id'] ?? 0);
        $me = (int)Session::getLoginUserID();
        // só quem pode ver o quadro pode listar membros (criador, membro ou quadro legado sem membros)
        $__myRole = kanpro_my_board_role($bid);
        $__hasAny = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => $bid]) > 0;
        if ($me !== $creatorId && $__myRole === null && $__hasAny) {
            jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
        }
        $members = [];
        $memberIds = [];
        $miter = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $bid], 'ORDER' => 'date_creation ASC']);
        foreach ($miter as $m) {
            $brief = kanpro_user_brief((int)$m['users_id']);
            $brief['role'] = $m['role'];
            $brief['is_creator'] = ((int)$m['users_id'] === $creatorId);
            $members[] = $brief;
            $memberIds[(int)$m['users_id']] = true;
        }
        // garante que o criador apareça na lista mesmo sem linha em boards_members (quadros legados)
        if ($creatorId > 0 && !isset($memberIds[$creatorId])) {
            $brief = kanpro_user_brief($creatorId);
            $brief['role'] = 'admin';
            $brief['is_creator'] = true;
            array_unshift($members, $brief);
            $memberIds[$creatorId] = true;
        }
        // usuários disponíveis para adicionar
        $available = [];
        $uiter = $DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_deleted' => 0, 'is_active' => 1], 'ORDER' => 'realname ASC, firstname ASC', 'LIMIT' => 300]);
        foreach ($uiter as $u) {
            if (isset($memberIds[(int)$u['id']])) continue;
            $display = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? ''));
            if ($display === '') $display = $u['name'];
            $initials = strtoupper(substr($u['firstname'] ?? $u['name'] ?? '?', 0, 1) . substr($u['realname'] ?? '', 0, 1));
            if (trim($initials) === '') $initials = strtoupper(substr($display, 0, 2));
            $available[] = ['id' => (int)$u['id'], 'name' => $display . ' (' . $u['name'] . ')', 'login' => $u['name'], 'initials' => $initials];
        }
        jexit(['success'=>true, 'board_name'=>$bchk->fields['name'] ?? '', 'members'=>$members,
            'my_role'=>kanpro_my_board_role($bid), 'is_creator'=>($me === $creatorId),
            'can_manage'=>kanpro_can_manage_members($bid), 'available'=>$available]);

    // --- LABELS ---
    case 'add_label':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#61bd4f';
        $l = new PluginKanproLabel();
        $id = $l->add(['plugin_kanpro_boards_id'=>$bid,'name'=>$name,'color'=>$color]);
        jexit(['success'=>true,'id'=>$id]);

    case 'update_label':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#61bd4f';
        $DB->update('glpi_plugin_kanpro_labels', ['name'=>$name,'color'=>$color], ['id'=>$id]);
        jexit(['success'=>true]);

    case 'delete_label':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $DB->delete('glpi_plugin_kanpro_labels', ['id'=>$id]);
        $DB->delete('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_labels_id'=>$id]);
        jexit(['success'=>true]);

    case 'set_label_due':
        needEdit();
        kanpro_ensure_board_extras();
        $id = (int)($_POST['id'] ?? 0);
        $due = trim($_POST['due_date'] ?? '');
        $DB->update('glpi_plugin_kanpro_labels', ['due_date'=>($due !== '' ? $due : null)], ['id'=>$id]);
        jexit(['success'=>true]);

    case 'toggle_card_label':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $lid = (int)($_POST['labels_id'] ?? 0);
        $exists = countElementsInTable('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cid,'plugin_kanpro_labels_id'=>$lid]);
        if ($exists) {
            $DB->delete('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cid,'plugin_kanpro_labels_id'=>$lid]);
            jexit(['success'=>true,'added'=>false]);
        } else {
            $DB->insert('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$cid,'plugin_kanpro_labels_id'=>$lid]);
            jexit(['success'=>true,'added'=>true]);
        }

    // --- LISTS ---
    case 'add_list':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $name = trim($_POST['name'] ?? 'Nova Lista');
        if (!$name) $name = 'Nova Lista';
        $list = new PluginKanproList();
        $id = $list->add(['plugin_kanpro_boards_id'=>$bid,'name'=>$name]);
        PluginKanproBoard::logActivity($bid, null, $id, 'list_create', "Lista '{$name}' criada");
        jexit(['success'=>true,'id'=>$id]);

    case 'rename_list':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$name) jexit(['success'=>false,'msg'=>'Nome obrigatório']);
        $DB->update('glpi_plugin_kanpro_lists', ['name'=>$name], ['id'=>$id]);
        jexit(['success'=>true]);

    case 'archive_list':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $l = new PluginKanproList();
        $l->getFromDB($id);
        $new = $l->fields['is_archived'] ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_lists', ['is_archived'=>$new], ['id'=>$id]);
        jexit(['success'=>true,'is_archived'=>$new]);

    case 'delete_list':
        if (!Session::haveRight('plugin_kanpro', DELETE)) jexit(['success'=>false,'msg'=>'Sem permissão']);
        $id = (int)($_POST['id'] ?? 0);
        $l = new PluginKanproList();
        $l->delete(['id'=>$id], true);
        jexit(['success'=>true]);

    case 'presence_heartbeat':
        $boards_id = (int) ($_POST['boards_id'] ?? 0);
        if (!$boards_id) jexit(['success' => false]);
        $uid = Session::getLoginUserID();
        $existing = $DB->request(['FROM' => 'glpi_plugin_kanpro_presence', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'users_id' => $uid]])->current();
        if ($existing) {
            $DB->update('glpi_plugin_kanpro_presence', ['last_seen' => date('Y-m-d H:i:s')], ['id' => $existing['id']]);
        } else {
            $DB->insert('glpi_plugin_kanpro_presence', ['plugin_kanpro_boards_id' => $boards_id, 'users_id' => $uid, 'last_seen' => date('Y-m-d H:i:s')]);
        }
        jexit(['success' => true]);

    case 'get_board_snapshot':
        $boards_id = (int) ($_POST['boards_id'] ?? 0);
        if (!$boards_id) jexit(['success' => false]);
        $board_chk = new PluginKanproBoard();
        if (!$board_chk->getFromDB($boards_id)) jexit(['success' => false]);

        $lists = PluginKanproList::getListsForBoard($boards_id);
        $labels = PluginKanproLabel::getForBoard($boards_id);

        $members_raw = $DB->request(['FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id]]);
        $members_list = [];
        foreach ($members_raw as $m) {
            $u = new User();
            $uname = 'Usuário #' . $m['users_id'];
            $initials = '?';
            if ($u->getFromDB($m['users_id'])) {
                $uname = $u->getFriendlyName();
                $initials = strtoupper(substr($u->fields['firstname'] ?? $u->fields['name'] ?? '?', 0, 1) . substr($u->fields['realname'] ?? '', 0, 1));
                if (trim($initials) === '') $initials = strtoupper(substr($uname, 0, 2));
            }
            $members_list[] = ['users_id' => $m['users_id'], 'role' => $m['role'], 'name' => $uname, 'initials' => $initials];
        }

        $all_cards = [];
        $cards_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'is_archived' => 0], 'ORDER' => 'rank ASC']);
        foreach ($cards_iter as $c) $all_cards[] = $c;

        $card_labels_map = [];
        kanpro_ensure_board_extras();
        $cl_iter = $DB->request([
            'SELECT' => ['cl.plugin_kanpro_cards_id', 'l.id', 'l.name', 'l.color', 'l.due_date'],
            'FROM'   => 'glpi_plugin_kanpro_cards_labels AS cl',
            'LEFT JOIN' => ['glpi_plugin_kanpro_labels AS l' => ['ON' => ['l' => 'id', 'cl' => 'plugin_kanpro_labels_id']]],
            'WHERE'  => ['l.plugin_kanpro_boards_id' => $boards_id],
        ]);
        foreach ($cl_iter as $r) {
            $card_labels_map[$r['plugin_kanpro_cards_id']][] = ['id' => $r['id'], 'name' => $r['name'], 'color' => $r['color'], 'due_date' => ($r['due_date'] ?? null)];
        }

        $card_members_map = [];
        $cm_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_cards_members', 'WHERE' => ['plugin_kanpro_cards_id' => array_column($all_cards, 'id') ?: [0]]]);
        foreach ($cm_iter as $r) {
            $u = new User();
            $initials = '?';
            $uname = '#' . $r['users_id'];
            if ($u->getFromDB($r['users_id'])) {
                $uname = $u->getFriendlyName();
                $initials = strtoupper(substr($u->fields['firstname'] ?? $u->fields['name'] ?? '?', 0, 1));
            }
            $card_members_map[$r['plugin_kanpro_cards_id']][] = ['users_id' => $r['users_id'], 'name' => $uname, 'initials' => $initials];
        }

        $check_progress = [];
        $__cp_ids = array_column($all_cards, 'id') ?: [0];
        $check_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_checklists', 'WHERE' => ['plugin_kanpro_cards_id' => $__cp_ids]]);
        $check_ids_by_card = [];
        foreach ($check_iter as $cl) $check_ids_by_card[$cl['plugin_kanpro_cards_id']][] = $cl['id'];
        foreach ($check_ids_by_card as $cid => $cids) {
            $total = countElementsInTable('glpi_plugin_kanpro_checklist_items', ['plugin_kanpro_checklists_id' => $cids]);
            $done  = countElementsInTable('glpi_plugin_kanpro_checklist_items', ['plugin_kanpro_checklists_id' => $cids, 'is_checked' => 1]);
            $check_progress[$cid] = ['total' => $total, 'done' => $done];
        }

        $maintenance_progress = [];
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            $maint_ids = array_column($all_cards, 'id') ?: [0];
            $maint_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_machines', 'WHERE' => ['plugin_kanpro_cards_id' => $maint_ids]]);
            $maint_by_card = [];
            foreach ($maint_iter as $mm) $maint_by_card[$mm['plugin_kanpro_cards_id']][] = $mm;
            // anotações por card (selo no card minimizado) — 1 query
            $notes_by_card = [];
            if ($DB->tableExists('glpi_plugin_kanpro_maintenance_notes') && !empty($maint_by_card)) {
                $mid2cid = [];
                $all_mids = [];
                foreach ($maint_by_card as $cid => $machines) {
                    foreach ($machines as $mm) { $mid2cid[(int)$mm['id']] = (int)$cid; $all_mids[] = (int)$mm['id']; }
                }
                if (!empty($all_mids)) {
                    try {
                        foreach ($DB->request(['SELECT' => ['machine_id', 'COUNT' => 'id AS total'], 'FROM' => 'glpi_plugin_kanpro_maintenance_notes', 'WHERE' => ['machine_id' => $all_mids], 'GROUPBY' => ['machine_id']]) as $nr) {
                            $cc = $mid2cid[(int)$nr['machine_id']] ?? 0;
                            if ($cc) $notes_by_card[$cc] = ($notes_by_card[$cc] ?? 0) + (int)$nr['total'];
                        }
                    } catch (Throwable $e) {}
                }
            }
            foreach ($maint_by_card as $cid => $machines) {
                $total = count($machines);
                $done = 0;
                $urgent = 0;
                foreach ($machines as $mm) {
                    if (!empty($mm['is_done'])) $done++;
                    if (!empty($mm['is_urgent'])) $urgent++;
                }
                $maintenance_progress[$cid] = ['total'=>$total,'done'=>$done,'percent'=>$total?round($done/$total*100):0,'urgent'=>$urgent,'notes'=>($notes_by_card[$cid] ?? 0)];
            }
            foreach ($all_cards as $c) {
                if (!empty($c['is_maintenance']) && !isset($maintenance_progress[$c['id']])) $maintenance_progress[$c['id']] = ['total'=>0,'done'=>0,'percent'=>0,'urgent'=>0,'notes'=>0];
            }
        }

        $comment_counts = [];
        $att_counts = [];
        foreach ($all_cards as $c) {
            $comment_counts[$c['id']] = countElementsInTable('glpi_plugin_kanpro_comments', ['plugin_kanpro_cards_id' => $c['id']]);
            $att_counts[$c['id']] = countElementsInTable('glpi_plugin_kanpro_attachments', ['plugin_kanpro_cards_id' => $c['id']]);
        }

        $viewers = [];
        $cutoff = date('Y-m-d H:i:s', time() - 15);
        $viewers_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_presence', 'WHERE' => ['plugin_kanpro_boards_id' => $boards_id, 'last_seen' => ['>', $cutoff]]]);
        foreach ($viewers_iter as $v) {
            $u = new User();
            $uname = '#' . $v['users_id'];
            $initials = '?';
            if ($u->getFromDB($v['users_id'])) {
                $uname = $u->getFriendlyName();
                $initials = strtoupper(substr($u->fields['firstname'] ?? $u->fields['name'] ?? '?', 0, 1));
            }
            $viewers[] = ['users_id' => (int) $v['users_id'], 'name' => $uname, 'initials' => $initials];
        }

        $transfer_status = [];
        if ($DB->tableExists('glpi_plugin_assetmgrstatus_transfers')) {
            foreach ($all_cards as $c) {
                if (empty($c['is_maintenance'])) continue;
                $like = "%[KanPro #{$c['id']}]%";
                $trIter = $DB->request(['FROM'=>'glpi_plugin_assetmgrstatus_transfers','WHERE'=>['reason'=>['LIKE',$like]],'ORDER'=>'id DESC','LIMIT'=>1]);
                if ($trIter->count()===0) continue;
                $tr = $trIter->current();
                if (!$tr) continue;
                $hasRec = !empty($tr['assinatura_image']);
                $hasTec = !empty($tr['assinatura_tecnico_image']);
                $isAssinado = $hasRec && $hasTec;
                if ($isAssinado) $transfer_status[$c['id']] = ['label'=>'Concluído','status'=>'concluido'];
                else $transfer_status[$c['id']] = ['label'=>'Retirada','status'=>'retirada'];
            }
        }

        jexit([
            'success' => true,
            'lists' => $lists,
            'labels' => $labels,
            'cards' => $all_cards,
            'cardLabels' => $card_labels_map,
            'cardMembers' => $card_members_map,
            'checkProgress' => $check_progress,
            'maintenanceProgress' => $maintenance_progress,
            'commentCounts' => $comment_counts,
            'attCounts' => $att_counts,
            'members' => $members_list,
            'viewers' => $viewers,
            'transferStatus' => $transfer_status,
        ]);

    case 'global_search_cards':
        $q = trim($_POST['q'] ?? '');
        if (mb_strlen($q) < 2) jexit(['success' => true, 'results' => []]);
        $entities = $_SESSION['glpiactiveentities'] ?? [0];
        $boards_iter = $DB->request([
            'FROM'  => 'glpi_plugin_kanpro_boards',
            'WHERE' => ['entities_id' => $entities, 'is_archived' => 0],
        ]);
        $boards_by_id = [];
        foreach ($boards_iter as $b) { $boards_by_id[$b['id']] = $b; }
        if (empty($boards_by_id)) jexit(['success' => true, 'results' => []]);

        $lists_iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['plugin_kanpro_boards_id' => array_keys($boards_by_id)]]);
        $lists_by_id = [];
        foreach ($lists_iter as $l) { $lists_by_id[$l['id']] = $l; }

        $cards_iter = $DB->request([
            'FROM'  => 'glpi_plugin_kanpro_cards',
            'WHERE' => [
                'plugin_kanpro_boards_id' => array_keys($boards_by_id),
                'is_archived' => 0,
                'OR' => [
                    'name'        => ['LIKE', "%{$q}%"],
                    'description' => ['LIKE', "%{$q}%"],
                ],
            ],
            'ORDER' => 'date_mod DESC',
            'LIMIT' => 40,
        ]);
        $results = [];
        foreach ($cards_iter as $c) {
            $board = $boards_by_id[$c['plugin_kanpro_boards_id']] ?? null;
            $list  = $lists_by_id[$c['plugin_kanpro_lists_id']] ?? null;
            if (!$board) continue;
            $results[] = [
                'card_id'    => (int) $c['id'],
                'card_name'  => $c['name'],
                'board_id'   => (int) $board['id'],
                'board_name' => $board['name'],
                'list_name'  => $list['name'] ?? '',
            ];
        }
        jexit(['success' => true, 'results' => $results]);

    case 'reorder_lists':
        needEdit();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (!is_array($order)) jexit(['success'=>false]);
        PluginKanproList::reorder($bid, $order);
        jexit(['success'=>true]);

    case 'copy_list':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $l = new PluginKanproList();
        if (!$l->getFromDB($id)) jexit(['success'=>false]);
        $new_id = $l->add(['plugin_kanpro_boards_id'=>$l->fields['plugin_kanpro_boards_id'],'name'=>$l->fields['name'].' (cópia)']);
        // copia cartões
        $cards = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$id,'is_archived'=>0]]);
        foreach ($cards as $c) {
            PluginKanproCard::duplicate($c['id'], $new_id);
        }
        jexit(['success'=>true,'id'=>$new_id]);

    case 'move_list':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $target_board = (int)($_POST['target_boards_id'] ?? 0);
        if (!$target_board) jexit(['success'=>false]);
        $DB->update('glpi_plugin_kanpro_lists', ['plugin_kanpro_boards_id'=>$target_board], ['id'=>$id]);
        // move também cartões?
        $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_boards_id'=>$target_board], ['plugin_kanpro_lists_id'=>$id]);
        jexit(['success'=>true]);

    // --- CARDS ---
    case 'add_card':
        needEdit();
        $lists_id = (int)($_POST['lists_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$name) jexit(['success'=>false,'msg'=>'Título obrigatório']);
        $list = new PluginKanproList();
        if (!$list->getFromDB($lists_id)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        $card = new PluginKanproCard();
        $id = $card->add(['plugin_kanpro_boards_id'=>$list->fields['plugin_kanpro_boards_id'],'plugin_kanpro_lists_id'=>$lists_id,'name'=>$name]);
        jexit(['success'=>true,'id'=>$id, 'card'=>$card->fields]);

    case 'get_card':
        $cid = (int)($_REQUEST['cards_id'] ?? 0);
        kanpro_ensure_board_extras();
        $data = PluginKanproCard::getFullData($cid);
        if (!$data) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        jexit(['success'=>true,'data'=>$data]);

    case 'update_card':
        needEdit();
        $cid = (int)($_POST['id'] ?? 0);
        $fields = [];
        if (isset($_POST['name'])) $fields['name'] = trim($_POST['name']);
        if (array_key_exists('description', $_POST)) $fields['description'] = $_POST['description'];
        if (array_key_exists('due_date', $_POST)) $fields['due_date'] = empty($_POST['due_date']) ? null : $_POST['due_date'];
        if (array_key_exists('start_date', $_POST)) $fields['start_date'] = empty($_POST['start_date']) ? null : $_POST['start_date'];
        if (array_key_exists('cover_color', $_POST)) $fields['cover_color'] = $_POST['cover_color'] ?: null;
        if (array_key_exists('is_completed', $_POST)) $fields['is_completed'] = (int)$_POST['is_completed'];
        if (empty($fields)) jexit(['success'=>false]);
        $fields['id'] = $cid;
        $c = new PluginKanproCard();
        $c->update($fields);
        kanpro_touch_member($cid);
        jexit(['success'=>true]);

    case 'move_card':
        needEdit();
        kanpro_ensure_board_extras();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $target_list = (int)($_POST['target_lists_id'] ?? 0);
        // origem p/ histórico de movimentação
        $c0 = new PluginKanproCard();
        $from_list = 0; $from_name = ''; $bid0 = 0;
        if ($c0->getFromDB($cid)) {
            $from_list = (int)$c0->fields['plugin_kanpro_lists_id'];
            $bid0 = (int)$c0->fields['plugin_kanpro_boards_id'];
            $fl0 = new PluginKanproList();
            if ($fl0->getFromDB($from_list)) $from_name = $fl0->fields['name'];
        }
        $pos = isset($_POST['position']) ? (int)$_POST['position'] : null;
        // Se position dado, calcula rank; senão joga pro fim
        if ($pos !== null) {
            // pega cartões da lista destino ordenados
            $cards = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$target_list,'is_archived'=>0],'ORDER'=>'rank ASC']);
            $ids = array_column(iterator_to_array($cards), 'id');
            // remove se já está
            $ids = array_values(array_filter($ids, fn($x)=>$x!=$cid));
            array_splice($ids, $pos, 0, [$cid]);
            // reordena
            $rank = 1024;
            foreach ($ids as $id) {
                if ($id == $cid) {
                    $DB->update('glpi_plugin_kanpro_cards', ['rank'=>$rank,'plugin_kanpro_lists_id'=>$target_list], ['id'=>$cid]);
                } else {
                    $DB->update('glpi_plugin_kanpro_cards', ['rank'=>$rank], ['id'=>$id]);
                }
                $rank+=1024;
            }
            // atualiza boards_id se mudou de quadro
            $list = new PluginKanproList();
            if ($list->getFromDB($target_list)) {
                $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_boards_id'=>$list->fields['plugin_kanpro_boards_id']], ['id'=>$cid]);
            }
        } else {
            PluginKanproCard::moveCard($cid, $target_list);
        }
        // histórico de movimentação do cartão
        $pending = false;
        if ($from_list && $target_list && $from_list !== $target_list) {
            $tl0 = new PluginKanproList();
            $to_name = $tl0->getFromDB($target_list) ? $tl0->fields['name'] : ('#' . $target_list);
            PluginKanproBoard::logActivity($bid0, $cid, $target_list, 'card_move', "[from:{$from_list}] Saiu de '{$from_name}' → '{$to_name}'");
            // lista com aprovação: membro move, só admin/criador aprova
            $targetBid = $tl0->fields['plugin_kanpro_boards_id'] ?? $bid0;
            if (!empty($tl0->fields['require_approval']) && !kanpro_can_manage_members((int)$targetBid)) {
                $DB->update('glpi_plugin_kanpro_cards', ['approval_from'=>$from_list], ['id'=>$cid]);
                PluginKanproBoard::logActivity((int)$targetBid, $cid, $target_list, 'card_approval_request', "Aguardando aprovação de admin para entrar em '{$to_name}'");
                $pending = true;
            } else {
                $DB->update('glpi_plugin_kanpro_cards', ['approval_from'=>0], ['id'=>$cid]);
            }
        }
        jexit(['success'=>true,'pending_approval'=>$pending]);

    case 'move_all_cards':

    case 'move_all_cards':
        needEdit();
        kanpro_ensure_board_extras();
        $from = (int)($_POST['lists_id'] ?? 0);
        $to = (int)($_POST['target_lists_id'] ?? 0);
        if (!$from || !$to || $from === $to) jexit(['success'=>false,'msg'=>'Listas inválidas']);
        $fl = new PluginKanproList(); $tl = new PluginKanproList();
        if (!$fl->getFromDB($from) || !$tl->getFromDB($to)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        if ((int)$fl->fields['plugin_kanpro_boards_id'] !== (int)$tl->fields['plugin_kanpro_boards_id']) jexit(['success'=>false,'msg'=>'Listas de quadros diferentes']);
        $bid = (int)$fl->fields['plugin_kanpro_boards_id'];
        $cards = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$from,'is_archived'=>0],'ORDER'=>'rank ASC']);
        $last = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$to],'ORDER'=>'rank DESC','LIMIT'=>1])->current();
        $rank = $last ? ((float)$last['rank'] + 1024) : 1024;
        $count = 0;
        $needsAppr = !empty($tl->fields['require_approval']) && !kanpro_can_manage_members($bid);
        foreach ($cards as $c) {
            $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_lists_id'=>$to,'rank'=>$rank,'approval_from'=>($needsAppr ? $from : 0)], ['id'=>$c['id']]);
            PluginKanproBoard::logActivity($bid, (int)$c['id'], $to, 'card_move', "[from:{$from}] Saiu de '{$fl->fields['name']}' → '{$tl->fields['name']}' (mover todos)");
            if ($needsAppr) PluginKanproBoard::logActivity($bid, (int)$c['id'], $to, 'card_approval_request', "Aguardando aprovação de admin para entrar em '{$tl->fields['name']}'");
            $rank += 1024; $count++;
        }
        if ($count) PluginKanproBoard::logActivity($bid, null, $to, 'list_move_all', "{$count} cartão(ões) movidos de '{$fl->fields['name']}' → '{$tl->fields['name']}'");
        jexit(['success'=>true,'moved'=>$count,'pending_approval'=>$needsAppr]);

    case 'archive_all_cards':
        needEdit();
        $lid = (int)($_POST['lists_id'] ?? 0);
        $fl = new PluginKanproList();
        if (!$fl->getFromDB($lid)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        $bid = (int)$fl->fields['plugin_kanpro_boards_id'];
        $cards = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$lid,'is_archived'=>0]]);
        $count = 0;
        foreach ($cards as $c) {
            $DB->update('glpi_plugin_kanpro_cards', ['is_archived'=>1], ['id'=>$c['id']]);
            PluginKanproBoard::logActivity($bid, (int)$c['id'], $lid, 'card_archive', "Arquivado junto com a lista '{$fl->fields['name']}' (arquivar todos)");
            $count++;
        }
        jexit(['success'=>true,'archived'=>$count]);

    case 'toggle_pin':
        needEdit();
        kanpro_ensure_board_extras();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['id'=>$cid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $new = !empty($row['is_pinned']) ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_cards', ['is_pinned'=>$new], ['id'=>$cid]);
        jexit(['success'=>true,'is_pinned'=>$new]);

    case 'set_list_approval':
        $lid = (int)($_POST['lists_id'] ?? 0);
        $val = !empty($_POST['require']) ? 1 : 0;
        $fl = new PluginKanproList();
        if (!$fl->getFromDB($lid)) jexit(['success'=>false,'msg'=>'Lista não encontrada']);
        kanpro_ensure_board_extras();
        kanpro_need_manage_members((int)$fl->fields['plugin_kanpro_boards_id']);
        $DB->update('glpi_plugin_kanpro_lists', ['require_approval'=>$val], ['id'=>$lid]);
        jexit(['success'=>true,'require_approval'=>$val]);

    case 'approve_card':
        $cid = (int)($_POST['cards_id'] ?? 0);
        $c = new PluginKanproCard();
        if (!$c->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        kanpro_ensure_board_extras();
        kanpro_need_manage_members((int)$c->fields['plugin_kanpro_boards_id']);
        $DB->update('glpi_plugin_kanpro_cards', ['approval_from'=>0], ['id'=>$cid]);
        PluginKanproBoard::logActivity((int)$c->fields['plugin_kanpro_boards_id'], $cid, (int)$c->fields['plugin_kanpro_lists_id'], 'card_approval_ok', "Movimentação aprovada por admin");
        jexit(['success'=>true]);

    case 'devolve_card':
        $cid = (int)($_POST['cards_id'] ?? 0);
        $c = new PluginKanproCard();
        if (!$c->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        kanpro_ensure_board_extras();
        kanpro_need_manage_members((int)$c->fields['plugin_kanpro_boards_id']);
        $back = (int)($c->fields['approval_from'] ?? 0);
        if (!$back) jexit(['success'=>false,'msg'=>'Sem pendência']);
        $bl = new PluginKanproList();
        if (!$bl->getFromDB($back)) jexit(['success'=>false,'msg'=>'Lista de origem não existe mais']);
        $last = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_lists_id'=>$back],'ORDER'=>'rank DESC','LIMIT'=>1])->current();
        $rank = $last ? ((float)$last['rank'] + 1024) : 1024;
        $DB->update('glpi_plugin_kanpro_cards', ['plugin_kanpro_lists_id'=>$back,'rank'=>$rank,'approval_from'=>0], ['id'=>$cid]);
        PluginKanproBoard::logActivity((int)$c->fields['plugin_kanpro_boards_id'], $cid, $back, 'card_move', "Devolvido para '{$bl->fields['name']}' (aprovação negada)");
        jexit(['success'=>true]);

    case 'duplicate_board':
        if (!Session::haveRight('plugin_kanpro', CREATE)) jexit(['success'=>false,'msg'=>'Sem permissão']);
        kanpro_ensure_board_extras();
        $bid = (int)($_POST['boards_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $src = new PluginKanproBoard();
        if (!$src->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        if ($name === '') $name = $src->fields['name'] . ' (cópia)';
        $me = (int)Session::getLoginUserID();
        $nb = new PluginKanproBoard();
        $newBid = $nb->add([
            'name'=>$name, 'entities_id'=>($src->fields['entities_id'] ?? 0), 'is_recursive'=>($src->fields['is_recursive'] ?? 0),
            'comment'=>($src->fields['comment'] ?? ''), 'color'=>($src->fields['color'] ?? '#0079bf'),
            'is_archived'=>0, 'is_starred'=>0, 'generate_term'=>($src->fields['generate_term'] ?? 0),
            'visibility'=>($src->fields['visibility'] ?? 'private'), 'users_id'=>$me,
        ]);
        if (!$newBid) jexit(['success'=>false,'msg'=>'Falha ao criar quadro']);
        // imagem de fundo: copia o arquivo
        if (!empty($src->fields['background'])) {
            $srcPath = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $src->fields['background'];
            if (is_file($srcPath)) {
                $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) $ext = 'jpg';
                $dir = GLPI_PLUGIN_DOC_DIR . '/kanpro/boards/' . $newBid . '/';
                @mkdir($dir, 0755, true);
                $rel = 'boards/' . $newBid . '/bg_copy_' . time() . '.' . $ext;
                if (@copy($srcPath, GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $rel)) {
                    $DB->update('glpi_plugin_kanpro_boards', ['background'=>$rel], ['id'=>$newBid]);
                }
            }
        }
        // membros
        $miter = $DB->request(['FROM'=>'glpi_plugin_kanpro_boards_members','WHERE'=>['plugin_kanpro_boards_id'=>$bid]]);
        foreach ($miter as $m) {
            $DB->insert('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$newBid,'users_id'=>$m['users_id'],'role'=>$m['role'],'date_creation'=>date('Y-m-d H:i:s')]);
        }
        // garante o criador como admin
        if (!countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$newBid,'users_id'=>$me])) {
            $DB->insert('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$newBid,'users_id'=>$me,'role'=>'admin','date_creation'=>date('Y-m-d H:i:s')]);
        }
        // listas (mapa id antigo -> novo)
        $listMap = [];
        $liter = $DB->request(['FROM'=>'glpi_plugin_kanpro_lists','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'rank ASC']);
        foreach ($liter as $l) {
            $nl = new PluginKanproList();
            $newLid = $nl->add(['plugin_kanpro_boards_id'=>$newBid,'name'=>$l['name'],'rank'=>$l['rank'],'is_archived'=>$l['is_archived'],'color'=>($l['color'] ?? null)]);
            if ($newLid) $listMap[(int)$l['id']] = (int)$newLid;
        }
        // etiquetas (mapa)
        $labelMap = [];
        $labiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_labels','WHERE'=>['plugin_kanpro_boards_id'=>$bid]]);
        foreach ($labiter as $l) {
            $nlab = new PluginKanproLabel();
            $newLab = $nlab->add(['plugin_kanpro_boards_id'=>$newBid,'name'=>$l['name'],'color'=>$l['color']]);
            if ($newLab) $labelMap[(int)$l['id']] = (int)$newLab;
        }
        // cartões
        $citer = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'id ASC']);
        foreach ($citer as $c) {
            $newLid = $listMap[(int)$c['plugin_kanpro_lists_id']] ?? 0;
            if (!$newLid) continue;
            $nc = new PluginKanproCard();
            $newCid = $nc->add([
                'plugin_kanpro_boards_id'=>$newBid, 'plugin_kanpro_lists_id'=>$newLid,
                'name'=>$c['name'], 'description'=>($c['description'] ?? ''), 'rank'=>$c['rank'],
                'is_archived'=>$c['is_archived'], 'is_completed'=>$c['is_completed'],
                'due_date'=>($c['due_date'] ?? null), 'start_date'=>($c['start_date'] ?? null),
                'cover_color'=>($c['cover_color'] ?? null), 'is_maintenance'=>0, 'tickets_id'=>0,
            ]);
            if (!$newCid) continue;
            // etiquetas do cartão
            $cliter = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards_labels','WHERE'=>['plugin_kanpro_cards_id'=>$c['id']]]);
            foreach ($cliter as $cl) {
                $nlid = $labelMap[(int)$cl['plugin_kanpro_labels_id']] ?? 0;
                if ($nlid) $DB->insert('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$newCid,'plugin_kanpro_labels_id'=>$nlid]);
            }
            // membros do cartão
            $cmiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards_members','WHERE'=>['plugin_kanpro_cards_id'=>$c['id']]]);
            foreach ($cmiter as $cm) {
                $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$newCid,'users_id'=>$cm['users_id']]);
            }
            // checklists + itens
            $chkiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_checklists','WHERE'=>['plugin_kanpro_cards_id'=>$c['id']],'ORDER'=>'rank ASC']);
            foreach ($chkiter as $chk) {
                $nch = new PluginKanproChecklist();
                $newCh = $nch->add(['plugin_kanpro_cards_id'=>$newCid,'name'=>$chk['name'],'rank'=>$chk['rank']]);
                if ($newCh) {
                    $ititer = $DB->request(['FROM'=>'glpi_plugin_kanpro_checklist_items','WHERE'=>['plugin_kanpro_checklists_id'=>$chk['id']],'ORDER'=>'rank ASC']);
                    foreach ($ititer as $it) {
                        $ni = new PluginKanproChecklistItem();
                        $ni->add(['plugin_kanpro_checklists_id'=>$newCh,'name'=>$it['name'],'is_checked'=>$it['is_checked'],
                            'users_id'=>($it['users_id'] ?? 0),'rank'=>$it['rank'],'due_date'=>($it['due_date'] ?? null)]);
                    }
                }
            }
            // anexos: copia arquivo + linha
            $atiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_attachments','WHERE'=>['plugin_kanpro_cards_id'=>$c['id']]]);
            foreach ($atiter as $at) {
                $newRel = null;
                if (!empty($at['filepath'])) {
                    $srcFile = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $at['filepath'];
                    if (is_file($srcFile)) {
                        $newRel = dirname($at['filepath']) . '/dup_' . uniqid() . '_' . basename($at['filepath']);
                        if (!@copy($srcFile, GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $newRel)) $newRel = null;
                    }
                }
                if ($newRel !== null || empty($at['filepath'])) {
                    $DB->insert('glpi_plugin_kanpro_attachments', ['plugin_kanpro_cards_id'=>$newCid,
                        'name'=>$at['name'],'filename'=>$at['filename'],'filepath'=>($newRel ?? $at['filepath']),
                        'filesize'=>($newRel !== null && isset($at['filesize'])) ? (int)$at['filesize'] : 0,
                        'mime'=>($at['mime'] ?? null),'users_id'=>$me,'date_creation'=>date('Y-m-d H:i:s')]);
                }
            }
            // máquinas de manutenção (sem diário de execução? mantém modelo/seq p/ reaproveitar estrutura)
            $mmiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$c['id']],'ORDER'=>'seq ASC']);
            foreach ($mmiter as $mm) {
                $DB->insert('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$newCid,
                    'seq'=>$mm['seq'],'model'=>$mm['model'],'label'=>$mm['label'],'diary'=>null,
                    'is_done'=>0,'is_ok'=>0,'status'=>'','is_inventoried'=>0,'is_urgent'=>0,
                    'users_id'=>$me,'date_creation'=>date('Y-m-d H:i:s'),'date_mod'=>date('Y-m-d H:i:s')]);
            }
        }
        PluginKanproBoard::logActivity((int)$newBid, null, null, 'board_duplicate', "Quadro duplicado a partir de #{$bid} '{$src->fields['name']}'");
        jexit(['success'=>true,'id'=>(int)$newBid]);

    case 'reorder_cards':
        needEdit();
        $list_id = (int)($_POST['lists_id'] ?? 0);
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (!is_array($order)) jexit(['success'=>false]);
        PluginKanproCard::reorderInList($list_id, $order);
        jexit(['success'=>true]);

    case 'copy_card':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $target_list = isset($_POST['target_lists_id']) ? (int)$_POST['target_lists_id'] : null;
        $new_id = PluginKanproCard::duplicate($cid, $target_list);
        jexit(['success'=>true,'id'=>$new_id]);

    case 'archive_card':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $c = new PluginKanproCard();
        $c->getFromDB($cid);
        $new = $c->fields['is_archived'] ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_cards', ['is_archived'=>$new], ['id'=>$cid]);
        jexit(['success'=>true,'is_archived'=>$new]);

    case 'delete_card':
        if (!Session::haveRight('plugin_kanpro', DELETE)) jexit(['success'=>false]);
        kanpro_ensure_board_extras();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $c = new PluginKanproCard();
        if (!$c->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        // snapshot p/ lixeira antes do purge (anexos físicos não são restaurados)
        $full = PluginKanproCard::getFullData($cid);
        $ll = new PluginKanproList();
        $lname = $ll->getFromDB((int)$c->fields['plugin_kanpro_lists_id']) ? $ll->fields['name'] : '';
        $DB->insert('glpi_plugin_kanpro_trash', [
            'plugin_kanpro_boards_id'=>(int)$c->fields['plugin_kanpro_boards_id'],
            'plugin_kanpro_lists_id'=>(int)$c->fields['plugin_kanpro_lists_id'],
            'list_name'=>$lname, 'card_name'=>$c->fields['name'],
            'snapshot'=>json_encode($full, JSON_UNESCAPED_UNICODE),
            'users_id'=>kanpro_acting_user_id(), 'date_creation'=>date('Y-m-d H:i:s'),
        ]);
        $c->delete(['id'=>$cid], true);
        jexit(['success'=>true]);

    case 'get_history':
        try {
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $bchk = new PluginKanproBoard();
        if (!$bchk->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        // mesma trava de visibilidade: criador, membro ou quadro legado sem membros
        $__me = (int)Session::getLoginUserID();
        $__creator = (int)($bchk->fields['users_id'] ?? 0);
        if ($__me !== $__creator) {
            $__isM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>kanpro_viewer_ids()]) > 0;
            $__hasM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid]) > 0;
            if (!$__isM && $__hasM) jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
        }
        // pessoas com acesso (criador + membros)
        $people = []; $seen = [];
        $addP = function ($uid, $extra = '') use (&$people, &$seen) {
            $uid = (int)$uid;
            if ($uid <= 0 || isset($seen[$uid])) return;
            $seen[$uid] = true;
            $u = new User();
            $name = 'Usuário #' . $uid;
            if ($u->getFromDB($uid)) $name = $u->getFriendlyName();
            $people[] = ['id'=>$uid, 'name'=>$name, 'extra'=>$extra];
        };
        $addP($__creator, 'criador');
        $pmiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_boards_members','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'date_creation ASC']);
        foreach ($pmiter as $pm) $addP($pm['users_id'], $pm['role'] ?? '');
        // filtros (faction: "action" é o parâmetro de rota — não usar)
        $where = ['a.plugin_kanpro_boards_id' => $bid];
        $fuser = (int)($_REQUEST['users_id'] ?? 0);
        if ($fuser > 0) $where['a.users_id'] = $fuser;
        $faction = trim($_REQUEST['faction'] ?? '');
        if ($faction !== '') $where['a.action'] = $faction;
        $fcard = (int)($_REQUEST['card_id'] ?? 0);
        if ($fcard > 0) $where['a.plugin_kanpro_cards_id'] = $fcard;
        $ffrom = trim($_REQUEST['date_from'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ffrom)) $ffrom = '';
        $fto = trim($_REQUEST['date_to'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fto)) $fto = '';
        $rows = [];
        $aiter = $DB->request([
            'SELECT' => ['a.*', 'u.name AS user_name', 'u.realname', 'u.firstname', 'c.name AS card_name'],
            'FROM'   => 'glpi_plugin_kanpro_activities AS a',
            'LEFT JOIN' => [
                'glpi_users AS u' => ['ON' => ['u' => 'id', 'a' => 'users_id']],
                'glpi_plugin_kanpro_cards AS c' => ['ON' => ['c' => 'id', 'a' => 'plugin_kanpro_cards_id']],
            ],
            'WHERE'  => $where,
            'ORDER'  => 'a.date_creation DESC',
            'LIMIT'  => 500,
        ]);
        foreach ($aiter as $a) {
            $d = (string)($a['date_creation'] ?? '');
            if ($ffrom !== '' && substr($d, 0, 10) < $ffrom) continue;
            if ($fto !== '' && substr($d, 0, 10) > $fto) continue;
            $uname = trim(($a['realname'] ?? '') . ' ' . ($a['firstname'] ?? ''));
            if ($uname === '') $uname = $a['user_name'] ?? 'Sistema';
            $rows[] = ['id'=>(int)$a['id'], 'date'=>$d, 'user'=>$uname,
                'user_id'=>(int)($a['users_id'] ?? 0), 'action'=>($a['action'] ?? ''),
                'details'=>preg_replace('/^\[from:\d+\]\s*/', '', (string)($a['details'] ?? '')),
                'card_id'=>(int)($a['plugin_kanpro_cards_id'] ?? 0), 'card_name'=>($a['card_name'] ?? '')];
        }
        jexit(['success'=>true, 'board_id'=>$bid, 'board_name'=>($bchk->fields['name'] ?? ''),
            'people'=>$people, 'rows'=>$rows, 'filters'=>['users_id'=>$fuser,'faction'=>$faction,'card_id'=>$fcard,'date_from'=>$ffrom,'date_to'=>$fto]]);
        } catch (Throwable $e) {
            Toolbox::logError('KanPro get_history: ' . $e->getMessage());
            jexit(['success'=>false,'msg'=>'Falha ao carregar histórico']);
        }

    case 'export_history_xlsx':
        // download direto (GET ou POST) — mesmos filtros do get_history
        try {
            $bid = (int)($_REQUEST['boards_id'] ?? 0);
            if (!$bid) throw new Exception('Quadro inválido');
            $bchk = new PluginKanproBoard();
            if (!$bchk->getFromDB($bid)) throw new Exception('Quadro não encontrado');
            $__me = (int)Session::getLoginUserID();
            $__creator = (int)($bchk->fields['users_id'] ?? 0);
            if ($__me !== $__creator) {
                $__isM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>kanpro_viewer_ids()]) > 0;
                $__hasM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid]) > 0;
                if (!$__isM && $__hasM) throw new Exception('Sem acesso a este quadro');
            }
            $where = ['a.plugin_kanpro_boards_id' => $bid];
            $fuser = (int)($_REQUEST['users_id'] ?? 0);
            if ($fuser > 0) $where['a.users_id'] = $fuser;
            $faction = trim($_REQUEST['faction'] ?? '');
            if ($faction !== '') $where['a.action'] = $faction;
            $fcard = (int)($_REQUEST['card_id'] ?? 0);
            if ($fcard > 0) $where['a.plugin_kanpro_cards_id'] = $fcard;
            $ffrom = trim($_REQUEST['date_from'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ffrom)) $ffrom = '';
            $fto = trim($_REQUEST['date_to'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fto)) $fto = '';
            $actionLabels = [
                'board_create'=>'criou o quadro','board_rename'=>'renomeou o quadro','list_create'=>'criou a lista',
                'card_create'=>'criou o cartão','card_move'=>'moveu o cartão','card_archive'=>'arquivou o cartão',
                'card_restore'=>'restaurou o cartão','card_complete'=>'concluiu o cartão','card_reopen'=>'reabriu o cartão',
                'maintenance_setup'=>'configurou máquinas','maintenance_update'=>'atualizou máquina','maintenance_diary'=>'atualizou o diário',
                'maintenance_finalize'=>'finalizou manutenção','member_add'=>'adicionou membro','member_remove'=>'removeu membro',
            ];
            $rows = [];
            $aiter = $DB->request([
                'SELECT' => ['a.*', 'u.name AS user_name', 'u.realname', 'u.firstname', 'c.name AS card_name'],
                'FROM'   => 'glpi_plugin_kanpro_activities AS a',
                'LEFT JOIN' => [
                    'glpi_users AS u' => ['ON' => ['u' => 'id', 'a' => 'users_id']],
                    'glpi_plugin_kanpro_cards AS c' => ['ON' => ['c' => 'id', 'a' => 'plugin_kanpro_cards_id']],
                ],
                'WHERE'  => $where,
                'ORDER'  => 'a.date_creation DESC',
                'LIMIT'  => 5000,
            ]);
            foreach ($aiter as $a) {
                $d = (string)($a['date_creation'] ?? '');
                if ($ffrom !== '' && substr($d, 0, 10) < $ffrom) continue;
                if ($fto !== '' && substr($d, 0, 10) > $fto) continue;
                $uname = trim(($a['realname'] ?? '') . ' ' . ($a['firstname'] ?? ''));
                if ($uname === '') $uname = $a['user_name'] ?? 'Sistema';
                $cid = (int)($a['plugin_kanpro_cards_id'] ?? 0);
                $rows[] = [$d, $uname, ($actionLabels[$a['action']] ?? $a['action']),
                    ($cid > 0 ? ('#' . $cid . ' ' . ($a['card_name'] ?? '')) : ''),
                    preg_replace('/^\[from:\d+\]\s*/', '', (string)($a['details'] ?? ''))];
            }
            $bin = kanpro_build_xlsx('Histórico', ['Data', 'Pessoa', 'Tipo', 'Cartão', 'Detalhe'], $rows);
            if ($bin === '') throw new Exception('Falha ao gerar planilha');
            @ob_clean();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="historico-quadro-' . $bid . '.xlsx"');
            header('Content-Length: ' . strlen($bin));
            echo $bin;
            exit;
        } catch (Throwable $e) {
            Toolbox::logError('KanPro export_history_xlsx: ' . $e->getMessage());
            jexit(['success'=>false,'msg'=>$e->getMessage()]);
        }

    case 'get_trash':
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        kanpro_ensure_board_extras();
        $archived = [];
        $aiter = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_boards_id'=>$bid,'is_archived'=>1],'ORDER'=>'date_mod DESC','LIMIT'=>200]);
        $listNames = [];
        foreach ($aiter as $a) {
            $lid = (int)$a['plugin_kanpro_lists_id'];
            if (!isset($listNames[$lid])) {
                $ll = new PluginKanproList();
                $listNames[$lid] = $ll->getFromDB($lid) ? $ll->fields['name'] : ('#' . $lid);
            }
            $archived[] = ['id'=>(int)$a['id'],'name'=>$a['name'],'list_name'=>$listNames[$lid],'date_mod'=>$a['date_mod']];
        }
        $deleted = [];
        $titer = $DB->request(['FROM'=>'glpi_plugin_kanpro_trash','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'date_creation DESC','LIMIT'=>200]);
        foreach ($titer as $t) {
            $deleted[] = ['id'=>(int)$t['id'],'card_name'=>$t['card_name'],'list_name'=>$t['list_name'],'date'=>$t['date_creation']];
        }
        jexit(['success'=>true,'archived'=>$archived,'deleted'=>$deleted]);

    case 'restore_archived':
        $cid = (int)($_POST['cards_id'] ?? 0);
        $c = new PluginKanproCard();
        if (!$c->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        kanpro_need_manage_members((int)$c->fields['plugin_kanpro_boards_id']);
        $DB->update('glpi_plugin_kanpro_cards', ['is_archived'=>0], ['id'=>$cid]);
        PluginKanproBoard::logActivity((int)$c->fields['plugin_kanpro_boards_id'], $cid, (int)$c->fields['plugin_kanpro_lists_id'], 'card_restore', "Cartão restaurado da lixeira");
        jexit(['success'=>true]);

    case 'purge_trash':
        if (!Session::haveRight('plugin_kanpro', DELETE)) jexit(['success'=>false,'msg'=>'Sem permissão']);
        $tid = (int)($_POST['trash_id'] ?? 0);
        $DB->delete('glpi_plugin_kanpro_trash', ['id'=>$tid]);
        jexit(['success'=>true]);

    case 'restore_trash':
        kanpro_ensure_board_extras();
        $tid = (int)($_POST['trash_id'] ?? 0);
        $t = $DB->request(['FROM'=>'glpi_plugin_kanpro_trash','WHERE'=>['id'=>$tid]])->current();
        if (!$t) jexit(['success'=>false,'msg'=>'Item não encontrado']);
        kanpro_need_manage_members((int)$t['plugin_kanpro_boards_id']);
        $snap = json_decode($t['snapshot'] ?? '', true);
        if (!is_array($snap) || empty($snap['name'])) jexit(['success'=>false,'msg'=>'Snapshot inválido']);
        $bid = (int)$t['plugin_kanpro_boards_id'];
        $lid = (int)$t['plugin_kanpro_lists_id'];
        $ll = new PluginKanproList();
        if (!$ll->getFromDB($lid) || (int)$ll->fields['plugin_kanpro_boards_id'] !== $bid) {
            $first = $DB->request(['FROM'=>'glpi_plugin_kanpro_lists','WHERE'=>['plugin_kanpro_boards_id'=>$bid,'is_archived'=>0],'ORDER'=>'rank ASC','LIMIT'=>1])->current();
            if (!$first) jexit(['success'=>false,'msg'=>'Quadro sem listas ativas']);
            $lid = (int)$first['id'];
        }
        $nc = new PluginKanproCard();
        $newId = $nc->add([
            'plugin_kanpro_boards_id'=>$bid, 'plugin_kanpro_lists_id'=>$lid,
            'name'=>($snap['name'] ?? $t['card_name']), 'description'=>($snap['description'] ?? ''),
            'due_date'=>($snap['due_date'] ?? null), 'start_date'=>($snap['start_date'] ?? null),
            'cover_color'=>($snap['cover_color'] ?? null), 'is_completed'=>!empty($snap['is_completed']) ? 1 : 0,
            'is_maintenance'=>0, 'tickets_id'=>0,
        ]);
        if (!$newId) jexit(['success'=>false,'msg'=>'Falha ao recriar cartão']);
        // etiquetas (reaproveita por nome+cor ou cria)
        foreach (($snap['labels'] ?? []) as $sl) {
            $lname = trim($sl['name'] ?? ''); $lcolor = $sl['color'] ?? '#61bd4f';
            $ex = $DB->request(['FROM'=>'glpi_plugin_kanpro_labels','WHERE'=>['plugin_kanpro_boards_id'=>$bid,'name'=>$lname,'color'=>$lcolor],'LIMIT'=>1])->current();
            $labelId = $ex ? (int)$ex['id'] : (new PluginKanproLabel())->add(['plugin_kanpro_boards_id'=>$bid,'name'=>$lname,'color'=>$lcolor]);
            if ($labelId) $DB->insert('glpi_plugin_kanpro_cards_labels', ['plugin_kanpro_cards_id'=>$newId,'plugin_kanpro_labels_id'=>$labelId]);
        }
        // membros
        foreach (($snap['members'] ?? []) as $sm) {
            $muid = (int)($sm['id'] ?? $sm['users_id'] ?? 0);
            if ($muid) $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$newId,'users_id'=>$muid]);
        }
        // checklists + itens
        foreach (($snap['checklists'] ?? []) as $scl) {
            $ncl = new PluginKanproChecklist();
            $clid = $ncl->add(['plugin_kanpro_cards_id'=>$newId,'name'=>($scl['name'] ?? 'Checklist'),'rank'=>($scl['rank'] ?? 0)]);
            if ($clid) {
                foreach (($scl['items'] ?? []) as $it) {
                    $ni = new PluginKanproChecklistItem();
                    $ni->add(['plugin_kanpro_checklists_id'=>$clid,'name'=>($it['name'] ?? ''),'is_checked'=>!empty($it['is_checked']) ? 1 : 0,
                        'users_id'=>(int)($it['users_id'] ?? 0),'rank'=>($it['rank'] ?? 0),'due_date'=>($it['due_date'] ?? null)]);
                }
            }
        }
        // comentários
        foreach (($snap['comments'] ?? []) as $sc) {
            if (trim($sc['content'] ?? '') === '') continue;
            $DB->insert('glpi_plugin_kanpro_comments', ['plugin_kanpro_cards_id'=>$newId,'users_id'=>(int)($sc['users_id'] ?? 0),
                'content'=>$sc['content'],'date_creation'=>($sc['date_creation'] ?? date('Y-m-d H:i:s')),'date_mod'=>date('Y-m-d H:i:s')]);
        }
        $DB->delete('glpi_plugin_kanpro_trash', ['id'=>$tid]);
        PluginKanproBoard::logActivity($bid, (int)$newId, $lid, 'card_restore', "Cartão '{$t['card_name']}' restaurado da lixeira");
        jexit(['success'=>true,'id'=>(int)$newId]);

    case 'toggle_card_member':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $uid = (int)($_POST['users_id'] ?? 0);
        $exists = countElementsInTable('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$cid,'users_id'=>$uid]);
        if ($exists) {
            $DB->delete('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$cid,'users_id'=>$uid]);
            jexit(['success'=>true,'added'=>false]);
        } else {
            $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id'=>$cid,'users_id'=>$uid]);
            jexit(['success'=>true,'added'=>true]);
        }

    // --- CHECKLIST ---
    case 'add_checklist':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $name = trim($_POST['name'] ?? 'Checklist');
        $cl = new PluginKanproChecklist();
        $id = $cl->add(['plugin_kanpro_cards_id'=>$cid,'name'=>$name]);
        jexit(['success'=>true,'id'=>$id]);

    case 'rename_checklist':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $DB->update('glpi_plugin_kanpro_checklists', ['name'=>$name], ['id'=>$id]);
        jexit(['success'=>true]);

    case 'delete_checklist':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $DB->delete('glpi_plugin_kanpro_checklist_items', ['plugin_kanpro_checklists_id'=>$id]);
        $DB->delete('glpi_plugin_kanpro_checklists', ['id'=>$id]);
        jexit(['success'=>true]);

    case 'add_checkitem':
        needEdit();
        $clid = (int)($_POST['checklists_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$name) jexit(['success'=>false]);
        $it = new PluginKanproChecklistItem();
        $id = $it->add(['plugin_kanpro_checklists_id'=>$clid,'name'=>$name]);
        kanpro_touch_member(kanpro_card_id_of_checklist($clid));
        jexit(['success'=>true,'id'=>$id]);

    case 'toggle_checkitem':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_checklist_items','WHERE'=>['id'=>$id]])->current();
        if (!$row) jexit(['success'=>false]);
        $new = $row['is_checked'] ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_checklist_items', ['is_checked'=>$new], ['id'=>$id]);
        kanpro_touch_member(kanpro_card_id_of_checklist((int)$row['plugin_kanpro_checklists_id']));
        jexit(['success'=>true,'is_checked'=>$new]);

    case 'rename_checkitem':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $DB->update('glpi_plugin_kanpro_checklist_items', ['name'=>$name], ['id'=>$id]);
        jexit(['success'=>true]);

    case 'delete_checkitem':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $DB->delete('glpi_plugin_kanpro_checklist_items', ['id'=>$id]);
        jexit(['success'=>true]);

    case 'reorder_checkitems':
        needEdit();
        $clid = (int)($_POST['checklists_id'] ?? 0);
        $order = json_decode($_POST['order'] ?? '[]', true);
        $rank=1024;
        foreach ($order as $iid) {
            $DB->update('glpi_plugin_kanpro_checklist_items', ['rank'=>$rank], ['id'=>$iid,'plugin_kanpro_checklists_id'=>$clid]);
            $rank+=1024;
        }
        jexit(['success'=>true]);

    // --- COMMENTS ---
    case 'add_comment':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if (!$content) jexit(['success'=>false]);
        $co = new PluginKanproComment();
        $id = $co->add(['plugin_kanpro_cards_id'=>$cid,'content'=>$content]);
        kanpro_touch_member($cid);
        jexit(['success'=>true,'id'=>$id]);

    case 'update_comment':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        $DB->update('glpi_plugin_kanpro_comments', ['content'=>$content,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$id,'users_id'=>[Session::getLoginUserID(), kanpro_acting_user_id()]]);
        jexit(['success'=>true]);

    case 'delete_comment':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $DB->delete('glpi_plugin_kanpro_comments', ['id'=>$id]);
        jexit(['success'=>true]);

    case 'toggle_comment_pin':
        needEdit();
        kanpro_ensure_board_extras();
        $id = (int)($_POST['id'] ?? 0);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_comments','WHERE'=>['id'=>$id]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Comentário não encontrado']);
        $new = !empty($row['is_pinned']) ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_comments', ['is_pinned'=>$new], ['id'=>$id]);
        jexit(['success'=>true,'is_pinned'=>$new]);

    // --- ATTACHMENTS ---
    case 'upload_attachment':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        if (!isset($_FILES['file'])) jexit(['success'=>false,'msg'=>'Nenhum arquivo']);
        $id = PluginKanproAttachment::handleUpload($cid, $_FILES['file']);
        kanpro_touch_member($cid);
        jexit(['success'=> (bool)$id,'id'=>$id]);

    case 'delete_attachment':
        needEdit();
        $id = (int)($_POST['id'] ?? 0);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_attachments','WHERE'=>['id'=>$id]])->current();
        if ($row && !empty($row['filepath'])) {
            $path = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $row['filepath'];
            if (file_exists($path)) @unlink($path);
        }
        $DB->delete('glpi_plugin_kanpro_attachments', ['id'=>$id]);
        jexit(['success'=>true]);

    case 'set_cover':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $color = $_POST['cover_color'] ?? null;
        $att_id = $_POST['attachment_id'] ?? null;
        // se att_id vier, usa cor nula
        $DB->update('glpi_plugin_kanpro_cards', ['cover_color'=>$color ?: null,'cover_attachment_id'=>$att_id ?: null], ['id'=>$cid]);
        jexit(['success'=>true]);

    // --- DATES ---
    case 'set_dates':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $start = empty($_POST['start_date']) ? null : $_POST['start_date'];
        $due = empty($_POST['due_date']) ? null : $_POST['due_date'];
        $DB->update('glpi_plugin_kanpro_cards', ['start_date'=>$start,'due_date'=>$due], ['id'=>$cid]);
        jexit(['success'=>true]);

    case 'toggle_complete':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['id'=>$cid]])->current();
        $new = $row['is_completed'] ? 0 : 1;
        $DB->update('glpi_plugin_kanpro_cards', ['is_completed'=>$new], ['id'=>$cid]);
        PluginKanproBoard::logActivity((int)$row['plugin_kanpro_boards_id'], $cid, (int)$row['plugin_kanpro_lists_id'], $new ? 'card_complete' : 'card_reopen', $new ? 'Cartão concluído' : 'Cartão reaberto');
        jexit(['success'=>true,'is_completed'=>$new]);

    // --- BOARD ACTIVITY ---
    case 'get_board_activity':
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        $acts = PluginKanproActivity::getForBoard($bid, 50);
        jexit(['success'=>true,'data'=>$acts]);

    case 'get_board_report':
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        if (!$bid) jexit(['success'=>false,'msg'=>'Quadro inválido']);
        $bchk = new PluginKanproBoard();
        if (!$bchk->getFromDB($bid)) jexit(['success'=>false,'msg'=>'Quadro não encontrado']);
        // mesma trava de visibilidade do kanban: criador, membro ou quadro legado sem membros
        $__me = (int)Session::getLoginUserID();
        $__creator = (int)($bchk->fields['users_id'] ?? 0);
        if ($__me !== $__creator) {
            $__isM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid,'users_id'=>kanpro_viewer_ids()]) > 0;
            $__hasM = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id'=>$bid]) > 0;
            if (!$__isM && $__hasM) jexit(['success'=>false,'msg'=>'Sem acesso a este quadro']);
        }
        $lists = [];
        $liter = $DB->request(['FROM'=>'glpi_plugin_kanpro_lists','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'rank ASC']);
        foreach ($liter as $l) $lists[] = ['id'=>(int)$l['id'],'name'=>$l['name'],'is_archived'=>(int)$l['is_archived']];
        $cards = [];
        $citer = $DB->request(['FROM'=>'glpi_plugin_kanpro_cards','WHERE'=>['plugin_kanpro_boards_id'=>$bid],'ORDER'=>'date_creation ASC']);
        foreach ($citer as $c) {
            $cards[] = ['id'=>(int)$c['id'],'name'=>$c['name'],'list_id'=>(int)$c['plugin_kanpro_lists_id'],
                'date_creation'=>$c['date_creation'],'date_mod'=>$c['date_mod'],
                'is_completed'=>(int)$c['is_completed'],'is_archived'=>(int)$c['is_archived']];
        }
        $moves = [];
        $miter = $DB->request([
            'SELECT' => ['a.plugin_kanpro_cards_id', 'a.plugin_kanpro_lists_id', 'a.action', 'a.details', 'a.date_creation', 'u.name AS user_name', 'u.realname', 'u.firstname'],
            'FROM'   => 'glpi_plugin_kanpro_activities AS a',
            'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['u' => 'id', 'a' => 'users_id']]],
            'WHERE'  => ['a.plugin_kanpro_boards_id'=>$bid, 'a.action'=>['card_move','card_complete','card_reopen','card_create','card_archive']],
            'ORDER'  => 'a.date_creation ASC',
            'LIMIT'  => 5000,
        ]);
        foreach ($miter as $m) {
            $uname = trim(($m['realname'] ?? '') . ' ' . ($m['firstname'] ?? ''));
            if ($uname === '') $uname = $m['user_name'] ?? 'Sistema';
            $moves[] = ['card_id'=>(int)$m['plugin_kanpro_cards_id'],'list_id'=>(int)$m['plugin_kanpro_lists_id'],
                'action'=>$m['action'],'details'=>$m['details'],'date'=>$m['date_creation'],'user'=>$uname];
        }
        jexit(['success'=>true,'lists'=>$lists,'cards'=>$cards,'moves'=>$moves]);

    // --- SEARCH FILTER ---
    case 'search_cards':
        $bid = (int)($_REQUEST['boards_id'] ?? 0);
        $q = trim($_REQUEST['q'] ?? '');
        if (strlen($q) < 1) jexit(['success'=>true,'ids'=>[]]);
        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_kanpro_cards',
            'WHERE'  => [
                'plugin_kanpro_boards_id' => $bid,
                'is_archived' => 0,
                'OR' => [
                    ['name' => ['LIKE', "%{$q}%"]],
                    ['description' => ['LIKE', "%{$q}%"]],
                ]
            ]
        ]);
        $ids = [];
        foreach ($iter as $r) $ids[] = $r['id'];
        jexit(['success'=>true,'ids'=>$ids]);

    case 'list_entities':
        // Lista entidades GLPI para seleção ao converter manutenção — nome do Card vira nome da Entidade
        // include_root=1 (botão Escola): inclui a mãe "Unidade Regional de Ensino de Jales" na lista
        $includeRoot = !empty($_REQUEST['include_root']);
        $entities = [];
        try {
            $iter = $DB->request(['FROM' => 'glpi_entities', 'ORDER' => 'completename ASC']);
            foreach ($iter as $row) {
                // ignora lixeira se houver
                if (isset($row['is_deleted']) && $row['is_deleted']) continue;
                $cname = trim($row['completename'] ?? $row['name'] ?? '');
                $rname = trim($row['name'] ?? '');
                // remove a própria "Unidade Regional de Ensino de Jales" da lista (exceto botão Escola)
                if (!$includeRoot) {
                    if ($cname === 'Unidade Regional de Ensino de Jales' || $rname === 'Unidade Regional de Ensino de Jales') continue;
                    if (strcasecmp($cname, 'Unidade Regional de Ensino de Jales') === 0) continue;
                }
                $entities[] = [
                    'id'           => (int)$row['id'],
                    'name'         => $row['name'] ?? '',
                    'completename' => $row['completename'] ?? $row['name'] ?? '',
                ];
            }
        } catch (Throwable $e) {
            jexit(['success'=>false,'msg'=>'Erro ao listar entidades: '.$e->getMessage()]);
        }
        // limpeza retroativa: remove prefixo "Unidade Regional de Ensino de Jales > " de cards já convertidos
        try {
            $DB->doQuery("UPDATE `glpi_plugin_kanpro_cards` SET `name` = TRIM(SUBSTRING_INDEX(`name`, ' > ', -1)) WHERE `is_maintenance` = 1 AND `name` LIKE 'Unidade Regional de Ensino%' AND `name` LIKE '% > %'");
        } catch (Throwable $e) {}
        jexit(['success'=>true,'entities'=>$entities]);

    // ==================== MANUTENÇÃO (2FA + checklist por máquina) ====================
    case 'convert_to_maintenance':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? $_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_text'] ?? $_POST['confirm'] ?? '';
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (!empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Este cartão já é de manutenção']);
        // 2 etapas: confirmação textual (palavra aleatória sem acento/ç) + senha
        $norm = kanpro_normalize_confirm($confirm);
        $challenge_words = ["PAIVA","MASSON","FERRARI","MORANGO","SAWATA","TECNICO","SUPORTE","MANUTENCAO","REPARO","DIAGNOSTICO","HARDWARE","SOFTWARE","NOTEBOOK","DESKTOP","MONITOR","TECLADO","MOUSE","IMPRESSORA","REDE","SERVIDOR","BACKUP","SEGURANCA","ATUALIZACAO","LIMPEZA","FORMATACAO","INSTALACAO","CONFIGURACAO","ATENDIMENTO","CHAMADO","TICKET","PROTOCOLO","SISTEMA","PROCESSADOR","MEMORIA","SSD","HD","PLACA","FONTE","COOLER","GABINETE","BATERIA","CARREGADOR","CABO","CONECTOR","DRIVER","FIRMWARE","BIOS","WINDOWS","LINUX","OFFICE","ANTIVIRUS","FIREWALL","VPN","WIFI","ETHERNET","SWITCH","ROTEADOR","PATCH","CABEAMENTO","ESTRUTURADO","VOIP","TELEFONIA","RAMAL","NOBREAK","ESTABILIZADOR","PROJETOR","WEBCAM","HEADSET","SCANNER","PLOTTER","TABLET","CELULAR","SMARTPHONE","CHIP","BROWSER","NAVEGADOR","EMAIL","SENHA","LOGIN","USUARIO","PERFIL","PERMISSAO","BANCO","DADOS","RELATORIO","INVENTARIO","PATRIMONIO","ATIVO","GARANTIA","CONTRATO","FORNECEDOR","CLIENTE","DEPARTAMENTO","SETOR","ALMOXARIFADO","ESTOQUE","COMPRA","LICENCA","ATIVACAO","VALIDACAO","AUTENTICACAO","CONFIRMACAO"];
        $norm_allowed = array_map('kanpro_normalize_confirm', $challenge_words);
        if (!in_array($norm, $norm_allowed, true)) {
            jexit(['success'=>false,'msg'=>'Palavra de confirmação inválida. Digite exatamente a palavra desafio exibida (sem acento).','need_confirm'=>true]);
        }
        // senha opcional - fluxo atual só pede palavra (sem senha)
        if ($password !== '' && $password !== null && !kanpro_verify_password($password)) {
            jexit(['success'=>false,'msg'=>'Senha incorreta. Verifique sua senha do GLPI.','need_password'=>true]);
        }
        // Entidade selecionada — nome do Card vira nome da Entidade
        $entities_id = isset($_POST['entities_id']) ? (int)$_POST['entities_id'] : 0;
        $entity_name_input = trim($_POST['entity_name'] ?? $_POST['entities_name'] ?? '');
        $newName = null;
        if ($entities_id > 0) {
            $entRow = $DB->request(['FROM'=>'glpi_entities','WHERE'=>['id'=>$entities_id]])->current();
            if (!$entRow) jexit(['success'=>false,'msg'=>'Entidade não encontrada']);
            $rawName = trim($entRow['completename'] ?? $entRow['name'] ?? '');
            // remove prefixo "Unidade Regional de Ensino de Jales > " — usa apenas último nível
            if (strpos($rawName, ' > ') !== false) {
                $parts = explode(' > ', $rawName);
                $rawName = trim(end($parts));
            }
            // fallback: se ainda contiver "Unidade Regional", usa name direto
            if (stripos($rawName, 'Unidade Regional de Ensino') !== false) {
                $rawName = trim($entRow['name'] ?? $rawName);
            }
            $newName = $rawName;
            if ($newName === '') jexit(['success'=>false,'msg'=>'Nome da entidade vazio']);
            $newName = mb_substr($newName, 0, 255);
        } elseif ($entity_name_input !== '') {
            $rawInput = $entity_name_input;
            if (strpos($rawInput, ' > ') !== false) {
                $parts = explode(' > ', $rawInput);
                $rawInput = trim(end($parts));
            }
            $newName = mb_substr($rawInput, 0, 255);
        } else {
            jexit(['success'=>false,'msg'=>'Selecione a entidade. O nome do card virará o nome da entidade.','need_entity'=>true]);
        }
        $updateData = [
            'is_maintenance'   => 1,
            'maintenance_date' => date('Y-m-d H:i:s'),
            'maintenance_by'   => kanpro_acting_user_id(),
            'date_mod'         => date('Y-m-d H:i:s'),
            'name'             => $newName
        ];
        $DB->update('glpi_plugin_kanpro_cards', $updateData, ['id' => $cid]);
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'card_maintenance_convert', "Cartão convertido para manutenção por ". Session::getLoginUserID() . " — Entidade: {$newName} (#{$entities_id})");
        kanpro_touch_member($cid);
        // gera chamado GLPI automaticamente (não bloqueia a conversão se falhar)
        $autoTicketId = 0;
        $autoTicketWarn = '';
        try {
            $autoRes = kanpro_create_ticket_from_card($cid);
            if (!empty($autoRes['ok'])) {
                $autoTicketId = (int)($autoRes['id'] ?? 0);
            } else {
                $autoTicketWarn = $autoRes['error'] ?? 'falha desconhecida';
            }
        } catch (Throwable $e) { $autoTicketWarn = $e->getMessage(); }
        jexit(['success'=>true,'msg'=>'Card convertido para manutenção','is_maintenance'=>1,'new_name'=>$newName,'entities_id'=>$entities_id,'ticket_id'=>$autoTicketId,'ticket_warning'=>$autoTicketWarn]);

    case 'verify_maintenance_password':
        // endpoint auxiliar só para validar senha antes de converter (usado em fluxo 2 etapas separado)
        $pwd = $_POST['password'] ?? '';
        if (!kanpro_verify_password($pwd)) jexit(['success'=>false,'msg'=>'Senha incorreta']);
        jexit(['success'=>true]);

    case 'setup_maintenance_machines':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $machines_raw = $_POST['machines_raw'] ?? $_POST['raw'] ?? '';
        $definitions_json = $_POST['definitions'] ?? '';
        $replace = !empty($_POST['replace']) ? (int)$_POST['replace'] : 0;
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Cartão não é de manutenção. Converta primeiro.']);
        // Parse definições
        $defs = [];
        if (!empty($definitions_json)) {
            $decoded = json_decode($definitions_json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $d) {
                    $qty = (int)($d['qty'] ?? $d['quantity'] ?? 1);
                    $model = trim($d['model'] ?? $d['name'] ?? '');
                    if ($model !== '' && $qty>0) $defs[] = ['qty'=>$qty,'model'=>$model];
                }
            }
        }
        if (empty($defs) && $machines_raw !== '') {
            $defs = kanpro_parse_maintenance_raw($machines_raw);
        }
        // fallback: tenta definitions como raw json string
        if (empty($defs) && !empty($_POST['machines'])) {
            $tmp = json_decode($_POST['machines'], true);
            if (is_array($tmp) && isset($tmp[0]['model'])) {
                foreach ($tmp as $d) {
                    $qty = (int)($d['qty'] ?? 1);
                    $model = trim($d['model'] ?? '');
                    if ($model !== '' && $qty>0) $defs[] = ['qty'=>$qty,'model'=>$model];
                }
            }
        }
        if (empty($defs)) jexit(['success'=>false,'msg'=>'Informe pelo menos um modelo. Ex: 10x Notebook Positivo']);
        // Valida total
        $total = 0;
        foreach ($defs as $d) $total += $d['qty'];
        if ($total <=0 || $total > 500) jexit(['success'=>false,'msg'=>'Total de máquinas inválido (1-500). Informado: '.$total]);
        // Se já existem máquinas e não é replace, bloqueia
        $existing = countElementsInTable('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cid]);
        if ($existing >0 && !$replace) {
            jexit(['success'=>false,'msg'=>'Este cartão já possui máquinas cadastradas. Use replace=1 para substituir.','existing'=>$existing,'need_replace'=>true]);
        }
        if ($replace) {
            $DB->delete('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cid]);
        }
        // Busca max seq atual (se não replace e existir, continua)
        $maxSeq = 0;
        if (!$replace && $existing>0) {
            $row = $DB->request(['SELECT'=>['MAX'=>'seq AS m'],'FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid]])->current();
            $maxSeq = (int)($row['m'] ?? 0);
        }
        $seq = $maxSeq;
        $now = date('Y-m-d H:i:s');
        $uid = kanpro_acting_user_id();
        $created = [];
        foreach ($defs as $def) {
            $qty = (int)$def['qty'];
            $model = trim($def['model']);
            // Sanitiza modelo
            $model = mb_substr($model, 0, 250);
            for ($i=1; $i <= $qty; $i++) {
                $seq++;
                $label = "Máquina {$seq} - {$model}";
                $mid = $DB->insert('glpi_plugin_kanpro_maintenance_machines', [
                    'plugin_kanpro_cards_id' => $cid,
                    'seq'                    => $seq,
                    'model'                  => $model,
                    'label'                  => $label,
                    'diary'                  => '',
                    'is_done'                => 0,
                    'is_ok'                  => 0,
                    'status'                 => '',
                    'is_inventoried'         => 0,
                    'is_urgent'              => 0,
                    'users_id'               => $uid,
                    'date_creation'          => $now,
                    'date_mod'               => $now,
                ]);
                // Fallback se insert retorna false (algumas versões não retornam id, mas cria)
                // Busca último id
                if (!$mid) {
                    $last = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid,'seq'=>$seq],'ORDER'=>'id DESC','LIMIT'=>1])->current();
                    $mid = $last['id'] ?? 0;
                }
                $created[] = ['id'=>$mid,'seq'=>$seq,'model'=>$model,'label'=>$label];
            }
        }
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_setup', "Máquinas configuradas: {$total} ({$existing} existiam)");
        kanpro_touch_member($cid);
        // lista as máquinas no chamado vinculado
        $tid = kanpro_card_ticket_id($cid);
        if ($tid && !empty($created)) {
            $lst = [];
            foreach ($created as $mc) $lst[] = '• #' . $mc['seq'] . ' ' . $mc['model'];
            kanpro_ticket_followup($tid, "⚙ [KanPro] Máquinas configuradas\n\n" . count($created) . " nova(s) máquina(s) adicionada(s) a este atendimento:\n\n" . implode("\n", array_slice($lst, 0, 20)) . (count($lst) > 20 ? "\n... (+" . (count($lst) - 20) . " máquinas)" : ''));
        }
        // Retorna lista completa atualizada
        $all = [];
        $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach ($iter as $r) $all[] = $r;
        $done = count(array_filter($all, fn($x)=> $x['is_done']==1));
        $urgent = count(array_filter($all, fn($x)=> !empty($x['is_urgent'])));
        jexit(['success'=>true,'total'=>$total,'created'=>count($created),'machines'=>$all,'progress'=>['total'=>count($all),'done'=>$done,'percent'=> count($all)? round($done/count($all)*100):0,'urgent'=>$urgent]]);

    case 'get_maintenance':
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_REQUEST['cards_id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $isMaint = !empty($card->fields['is_maintenance']) ? 1 : 0;
        $machines = [];
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
            foreach ($iter as $r) $machines[] = $r;
        }
        $total = count($machines);
        $done = 0; $ok = 0;
        foreach ($machines as $m) { if ($m['is_done']) $done++; if ($m['is_ok'] || $m['status']==='ok') $ok++; }
        jexit(['success'=>true,'is_maintenance'=>$isMaint,'card'=>$card->fields,'machines'=>$machines,'progress'=>['total'=>$total,'done'=>$done,'percent'=>$total?round($done/$total*100):0,'ok'=>$ok]]);

    case 'update_maintenance_machine':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_POST['id'] ?? 0);
        if (!$mid) jexit(['success'=>false,'msg'=>'Máquina inválida']);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Máquina não encontrada']);
        $updates = [];
        if (array_key_exists('diary', $_POST)) $updates['diary'] = $_POST['diary'];
        if (array_key_exists('is_done', $_POST)) $updates['is_done'] = (int)$_POST['is_done'] ? 1:0;
        if (array_key_exists('is_ok', $_POST)) $updates['is_ok'] = (int)$_POST['is_ok'] ? 1:0;
        if (array_key_exists('status', $_POST)) {
            $st = trim($_POST['status']);
            $stLower = mb_strtolower($st, 'UTF-8');
            // normaliza aliases legados e aceita vazio (obrigatório — validação no finalize)
            $map = [
                'pending'   => 'pendente',
                'pendente'  => 'pendente',
                'garantia'  => 'garantia',
                'ok'        => 'ok',
                'inservivel'=> 'inservivel',
                'inservível'=> 'inservivel',
                'defect'    => 'inservivel',
                'defeito'   => 'inservivel',
                'nok'       => 'inservivel',
                ''          => '',
            ];
            if (array_key_exists($stLower, $map)) {
                $st = $map[$stLower];
            } else {
                // valor desconhecido -> vazio (força seleção)
                $st = '';
            }
            $updates['status'] = $st;
            // sincroniza is_ok para compat: apenas ok = 1
            $updates['is_ok'] = ($st==='ok'?1:0);
        }
        if (array_key_exists('model', $_POST)) {
            $model = trim($_POST['model']);
            if ($model !== '') {
                $updates['model'] = mb_substr($model,0,250);
                // atualiza label para manter seq
                $updates['label'] = "Máquina {$row['seq']} - {$updates['model']}";
            }
        }
        if (array_key_exists('is_inventoried', $_POST)) $updates['is_inventoried'] = (int)$_POST['is_inventoried'] ? 1:0;
        if (array_key_exists('inventoried', $_POST)) $updates['is_inventoried'] = (int)$_POST['inventoried'] ? 1:0;
        if (array_key_exists('needs_inventory', $_POST)) {
            $updates['needs_inventory'] = (int)$_POST['needs_inventory'] ? 1:0;
            // se não precisa inventariar, limpa confirmação
            if (!$updates['needs_inventory']) $updates['is_inventoried'] = 0;
        }
        if (array_key_exists('is_urgent', $_POST)) $updates['is_urgent'] = (int)$_POST['is_urgent'] ? 1:0;
        if (array_key_exists('urgent', $_POST)) $updates['is_urgent'] = (int)$_POST['urgent'] ? 1:0;
        if (array_key_exists('urgencia', $_POST)) $updates['is_urgent'] = (int)$_POST['urgencia'] ? 1:0;
        // Invariante: Pendente nunca é Feito (rede de segurança do servidor)
        $effStatus = $updates['status'] ?? ($row['status'] ?? '');
        if ($effStatus === 'pending') $effStatus = 'pendente';
        if ($effStatus === 'pendente') $updates['is_done'] = 0;
        if (empty($updates)) jexit(['success'=>false,'msg'=>'Nada para atualizar']);
        $updates['date_mod'] = date('Y-m-d H:i:s');
        $updates['users_id'] = kanpro_acting_user_id();
        $DB->update('glpi_plugin_kanpro_maintenance_machines', $updates, ['id'=>$mid]);
        kanpro_touch_member((int)$row['plugin_kanpro_cards_id']);
        // espelha mudanças relevantes no chamado (diário NÃO vai — salva a cada tecla)
        $chg = [];
        if (array_key_exists('status', $updates) && ($updates['status'] ?? '') !== ($row['status'] ?? '')) {
            $chg[] = 'status: ' . kanpro_machine_status_label($row['status'] ?? '') . ' → ' . kanpro_machine_status_label($updates['status']);
        }
        if (array_key_exists('is_done', $updates) && (int)$updates['is_done'] !== (int)($row['is_done'] ?? 0)) {
            $chg[] = !empty($updates['is_done']) ? 'marcada como FEITA' : 'desmarcada (não feita)';
        }
        if (array_key_exists('is_urgent', $updates) && (int)$updates['is_urgent'] !== (int)($row['is_urgent'] ?? 0)) {
            $chg[] = !empty($updates['is_urgent']) ? 'marcada como URGÊNCIA' : 'urgência removida';
        }
        if (array_key_exists('model', $updates) && $updates['model'] !== ($row['model'] ?? '')) {
            $chg[] = 'modelo: "' . ($row['model'] ?? '') . '" → "' . $updates['model'] . '"';
        }
        if (array_key_exists('is_inventoried', $updates) && (int)$updates['is_inventoried'] !== (int)($row['is_inventoried'] ?? 0)) {
            $chg[] = !empty($updates['is_inventoried']) ? 'marcada como INVENTARIADA' : 'desmarcada de inventariada';
        }
        if (array_key_exists('needs_inventory', $updates) && (int)$updates['needs_inventory'] !== (int)($row['needs_inventory'] ?? 0)) {
            $chg[] = !empty($updates['needs_inventory']) ? 'marcada como PRECISA INVENTARIAR' : 'marcada como NÃO precisa inventariar';
        }
        if (!empty($chg)) {
            $cidM = (int)$row['plugin_kanpro_cards_id'];
            $tid = kanpro_card_ticket_id($cidM);
            if ($tid) {
                $msg = "⚙ [KanPro] Atualização de máquina\n\nMáquina #{$row['seq']} '" . ($row['model'] ?? '') . "'\n\nAlterações: " . implode(' | ', $chg);
                $rep = kanpro_card_machines_report($cidM);
                if ($rep !== '') $msg .= "\n\n" . $rep;
                kanpro_ticket_followup($tid, $msg);
            }
        }
        // qualquer alteração (status, diário, feito, etc.) move o chamado para Em atendimento
        $tidAtt = kanpro_card_ticket_id((int)$row['plugin_kanpro_cards_id']);
        if ($tidAtt) kanpro_ticket_set_attending($tidAtt);
        // quem mexeu ajuda no chamado: anexa como atribuído mesmo sem followup (ex: só escreveu no diário)
        if ($tidAtt) kanpro_ticket_assign($tidAtt, kanpro_acting_user_id());
        // log: mudanças reais geram entrada; diário sozinho mantém 1 entrada por máquina (anti-flood do autosave)
        $card = new PluginKanproCard();
        if ($card->getFromDB($row['plugin_kanpro_cards_id'])) {
            $cidM = (int)$card->getID();
            if (!empty($chg)) {
                PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cidM, $card->fields['plugin_kanpro_lists_id'], 'maintenance_update', "Máquina #{$row['seq']} atualizada");
            } elseif (array_key_exists('diary', $updates) && (string)($updates['diary'] ?? '') !== (string)($row['diary'] ?? '')) {
                $dlabel = "Diário da Máquina #{$row['seq']} atualizado";
                $DB->delete('glpi_plugin_kanpro_activities', ['plugin_kanpro_cards_id'=>$cidM, 'action'=>'maintenance_diary', 'details'=>$dlabel]);
                PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cidM, $card->fields['plugin_kanpro_lists_id'], 'maintenance_diary', $dlabel);
            }
        }
        $newRow = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        // etiqueta roxa "Inventário" acompanha quem precisa inventariar
        if (array_key_exists('needs_inventory', $updates)) kanpro_sync_inventory_label((int)$row['plugin_kanpro_cards_id']);
        jexit(['success'=>true,'machine'=>$newRow]);

    case 'bulk_update_machines':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!$cid || !is_array($ids) || !count($ids)) jexit(['success'=>false,'msg'=>'Nada selecionado']);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        // status opcional
        $applyStatus = false; $st = null;
        if (array_key_exists('status', $_POST) && trim($_POST['status'] ?? '') !== '') {
            $map = ['pending'=>'pendente','pendente'=>'pendente','garantia'=>'garantia','ok'=>'ok',
                'inservivel'=>'inservivel','inservível'=>'inservivel','defect'=>'inservivel','defeito'=>'inservivel','nok'=>'inservivel'];
            $k = mb_strtolower(trim($_POST['status']), 'UTF-8');
            if (isset($map[$k])) { $st = $map[$k]; $applyStatus = true; }
        }
        $applyDone = array_key_exists('is_done', $_POST);
        $doneVal = $applyDone ? ((int)$_POST['is_done'] ? 1 : 0) : null;
        if (!$applyStatus && !$applyDone) jexit(['success'=>false,'msg'=>'Nada para aplicar']);
        $rows = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$ids,'plugin_kanpro_cards_id'=>$cid]]);
        $n = 0;
        foreach ($rows as $r) {
            $u = ['date_mod'=>date('Y-m-d H:i:s'),'users_id'=>kanpro_acting_user_id()];
            if ($applyStatus) {
                $u['status'] = $st;
                $u['is_ok'] = ($st === 'ok' ? 1 : 0);
            }
            $effStatus = $applyStatus ? $st : ($r['status'] ?? '');
            if ($applyDone) $u['is_done'] = ($effStatus === 'pendente') ? 0 : $doneVal;
            $DB->update('glpi_plugin_kanpro_maintenance_machines', $u, ['id'=>$r['id']]);
            $n++;
        }
        if ($n) {
            kanpro_touch_member($cid);
            $bits = [];
            if ($applyStatus) $bits[] = "status → " . kanpro_machine_status_label($st);
            if ($applyDone) $bits[] = $doneVal ? "marcadas como FEITAS" : "desmarcadas (não feitas)";
            $tid = kanpro_card_ticket_id($cid);
            if ($tid) {
                $msg = "⚙ [KanPro] Atualização em massa\n\n{$n} máquina(s): " . implode(' | ', $bits);
                $rep = kanpro_card_machines_report($cid);
                if ($rep !== '') $msg .= "\n\n" . $rep;
                kanpro_ticket_followup($tid, $msg);
            }
            if ($tid) kanpro_ticket_set_attending($tid);
            PluginKanproBoard::logActivity((int)$card->fields['plugin_kanpro_boards_id'], $cid, (int)$card->fields['plugin_kanpro_lists_id'], 'maintenance_update', "Atualização em massa: {$n} máquina(s) (" . implode(' | ', $bits) . ")");
        }
        jexit(['success'=>true,'updated'=>$n]);

    case 'set_all_needs_inventory':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $val = !empty($_POST['needs_inventory']) ? 1 : 0;
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $upd = ['needs_inventory'=>$val, 'date_mod'=>date('Y-m-d H:i:s'), 'users_id'=>kanpro_acting_user_id()];
        if (!$val) $upd['is_inventoried'] = 0;
        $DB->update('glpi_plugin_kanpro_maintenance_machines', $upd, ['plugin_kanpro_cards_id'=>$cid]);
        kanpro_sync_inventory_label($cid);
        $n = countElementsInTable('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cid]);
        // quem marcou ajuda no chamado
        $tidAll = kanpro_card_ticket_id($cid);
        if ($tidAll) kanpro_ticket_assign($tidAll, kanpro_acting_user_id());
        PluginKanproBoard::logActivity((int)$card->fields['plugin_kanpro_boards_id'], $cid, (int)$card->fields['plugin_kanpro_lists_id'], 'maintenance_update', $val ? "Todas as {$n} máquinas marcadas como PRECISA INVENTARIAR" : "Marcas de 'precisa inventariar' removidas de {$n} máquinas");
        jexit(['success'=>true,'updated'=>$n]);

    case 'add_maintenance_machines':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 1);
        $model = trim($_POST['model'] ?? '');
        $raw = $_POST['machines_raw'] ?? $_POST['raw'] ?? '';
        $definitions_json = $_POST['definitions'] ?? '';
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Não é manutenção']);
        $defs = [];
        if (!empty($definitions_json)) {
            $decoded = json_decode($definitions_json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $d) {
                    $qtyD = (int)($d['qty'] ?? $d['quantity'] ?? 1);
                    $modelD = trim($d['model'] ?? $d['name'] ?? '');
                    if ($modelD !== '' && $qtyD>0) $defs[] = ['qty'=>$qtyD,'model'=>$modelD];
                }
            }
        }
        if (empty($defs) && $raw !== '') $defs = kanpro_parse_maintenance_raw($raw);
        else if (empty($defs) && $model !== '') $defs[] = ['qty'=>max(1,min(500,$qty)),'model'=>$model];
        else if (empty($defs) && !empty($_POST['machines'])) {
            $tmp = json_decode($_POST['machines'], true);
            if (is_array($tmp) && isset($tmp[0]['model'])) {
                foreach ($tmp as $d) {
                    $qtyT = (int)($d['qty'] ?? 1);
                    $modelT = trim($d['model'] ?? '');
                    if ($modelT !== '' && $qtyT>0) $defs[] = ['qty'=>$qtyT,'model'=>$modelT];
                }
            }
        }
        if (empty($defs)) jexit(['success'=>false,'msg'=>'Informe modelo ou raw']);
        $row = $DB->request(['SELECT'=>['MAX'=>'seq AS m'],'FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid]])->current();
        $seq = (int)($row['m'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $uid = kanpro_acting_user_id();
        foreach ($defs as $def) {
            $q = (int)$def['qty'];
            $mod = trim($def['model']);
            for ($i=0;$i<$q;$i++) {
                $seq++;
                $DB->insert('glpi_plugin_kanpro_maintenance_machines', [
                    'plugin_kanpro_cards_id'=>$cid,
                    'seq'=>$seq,
                    'model'=>$mod,
                    'label'=>"Máquina {$seq} - {$mod}",
                    'diary'=>'',
                    'is_done'=>0,
                    'is_ok'=>0,
                    'status'=>'',
                    'is_inventoried'=>0,
                    'is_urgent'=>0,
                    'users_id'=>$uid,
                    'date_creation'=>$now,
                    'date_mod'=>$now
                ]);
            }
        }
        $all=[];
        $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach($iter as $r) $all[]=$r;
        kanpro_touch_member($cid);
        $tid = kanpro_card_ticket_id($cid);
        if ($tid && !empty($defs)) {
            $lst = [];
            foreach ($defs as $def) $lst[] = '• ' . $def['qty'] . 'x ' . $def['model'];
            kanpro_ticket_followup($tid, "⚙ [KanPro] Máquinas adicionadas\n\nForam incluídas as seguintes máquinas neste atendimento:\n\n" . implode("\n", array_slice($lst, 0, 20)));
        }
        jexit(['success'=>true,'machines'=>$all]);

    case 'delete_maintenance_machine':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_POST['id'] ?? 0);
        if (!$mid) jexit(['success'=>false,'msg'=>'ID inválido']);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Não encontrado']);
        $cid = $row['plugin_kanpro_cards_id'];
        $DB->delete('glpi_plugin_kanpro_maintenance_machines', ['id'=>$mid]);
        $tid = kanpro_card_ticket_id((int)$cid);
        if ($tid) {
            kanpro_ticket_followup($tid, "⚙ [KanPro] Remoção de máquina\n\nA máquina #{$row['seq']} '" . ($row['model'] ?? '') . "' foi removida deste atendimento.");
        }
        // apaga anotações da máquina
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_notes')) {
            $DB->delete('glpi_plugin_kanpro_maintenance_notes', ['machine_id'=>$mid]);
        }
        // Re-sequenciar restantes para manter 1..N contínuo
        $remaining=[];
        $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach($iter as $r) $remaining[]=$r;
        $seq=1;
        foreach($remaining as $r) {
            $newLabel = "Máquina {$seq} - {$r['model']}";
            $DB->update('glpi_plugin_kanpro_maintenance_machines', ['seq'=>$seq,'label'=>$newLabel,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$r['id']]);
            $seq++;
        }
        $all=[];
        $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach($iter as $r) $all[]=$r;
        kanpro_sync_inventory_label((int)$cid);
        jexit(['success'=>true,'machines'=>$all]);

    case 'get_machine_notes':
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_REQUEST['machine_id'] ?? $_REQUEST['id'] ?? 0);
        if (!$mid) jexit(['success'=>false,'msg'=>'Máquina inválida']);
        $notes = [];
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_notes')) {
            $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_notes','WHERE'=>['machine_id'=>$mid],'ORDER'=>['date_creation ASC','id ASC']]);
            foreach ($iter as $r) $notes[] = $r;
        }
        // nomes dos autores em lote
        $uids = array_values(array_unique(array_filter(array_map(fn($n)=> (int)($n['users_id'] ?? 0), $notes))));
        $names = [];
        if (!empty($uids)) {
            foreach ($DB->request(['SELECT'=>['id','name','realname','firstname'],'FROM'=>'glpi_users','WHERE'=>['id'=>$uids]]) as $u) {
                $full = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
                if ($full === '') $full = $u['name'] ?? ('#' . $u['id']);
                $names[(int)$u['id']] = $full;
            }
        }
        foreach ($notes as &$n) {
            $n['user_name'] = ($n['users_id'] && isset($names[(int)$n['users_id']])) ? $names[(int)$n['users_id']] : 'Sistema';
        }
        unset($n);
        jexit(['success'=>true,'notes'=>$notes,'count'=>count($notes)]);

    case 'add_machine_note':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_POST['machine_id'] ?? $_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if (!$mid) jexit(['success'=>false,'msg'=>'Máquina inválida']);
        if ($note === '') jexit(['success'=>false,'msg'=>'Escreva a anotação']);
        $mrow = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        if (!$mrow) jexit(['success'=>false,'msg'=>'Máquina não encontrada']);
        $now = date('Y-m-d H:i:s');
        $nid = $DB->insert('glpi_plugin_kanpro_maintenance_notes', [
            'machine_id'    => $mid,
            'users_id'      => kanpro_acting_user_id(),
            'note'          => mb_substr($note, 0, 2000),
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);
        if (!$nid) jexit(['success'=>false,'msg'=>'Falha ao salvar anotação']);
        kanpro_touch_member((int)$mrow['plugin_kanpro_cards_id']);
        $cnt = countElementsInTable('glpi_plugin_kanpro_maintenance_notes', ['machine_id'=>$mid]);
        jexit(['success'=>true,'id'=>$nid,'count'=>$cnt]);

    case 'delete_machine_note':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $nid = (int)($_POST['id'] ?? $_POST['note_id'] ?? 0);
        if (!$nid) jexit(['success'=>false,'msg'=>'Anotação inválida']);
        $nrow = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_notes','WHERE'=>['id'=>$nid]])->current();
        if (!$nrow) jexit(['success'=>false,'msg'=>'Anotação não encontrada']);
        $mid = (int)$nrow['machine_id'];
        $DB->delete('glpi_plugin_kanpro_maintenance_notes', ['id'=>$nid]);
        $cnt = $DB->tableExists('glpi_plugin_kanpro_maintenance_notes') ? countElementsInTable('glpi_plugin_kanpro_maintenance_notes', ['machine_id'=>$mid]) : 0;
        jexit(['success'=>true,'count'=>$cnt,'machine_id'=>$mid]);

    case 'retirada_machine':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $mid = (int)($_POST['id'] ?? $_POST['machine_id'] ?? 0);
        if (!$mid) jexit(['success'=>false,'msg'=>'Máquina inválida']);
        $row = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['id'=>$mid]])->current();
        if (!$row) jexit(['success'=>false,'msg'=>'Máquina não encontrada']);
        if (empty($row['is_urgent'])) jexit(['success'=>false,'msg'=>'Apenas máquinas com urgência podem ser retiradas']);
        $cid = (int)$row['plugin_kanpro_cards_id'];
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Card não é de manutenção']);
        // cria novo card com mesmo nome/entidade
        $origName = trim($card->fields['name']);
        $newName = mb_substr($origName, 0, 255);
        $newCard = new PluginKanproCard();
        $newId = $newCard->add([
            'plugin_kanpro_boards_id' => $card->fields['plugin_kanpro_boards_id'],
            'plugin_kanpro_lists_id'  => $card->fields['plugin_kanpro_lists_id'],
            'name'        => $newName,
            'description' => $card->fields['description'] ?? '',
        ]);
        if (!$newId) jexit(['success'=>false,'msg'=>'Falha ao criar card de retirada']);
        $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>1,'maintenance_date'=>date('Y-m-d H:i:s'),'maintenance_by'=>kanpro_acting_user_id()], ['id'=>$newId]);
        // move máquina para novo card, re-sequencia como 1 e mantém infos
        $newLabel = "Máquina 1 - {$row['model']}";
        $DB->update('glpi_plugin_kanpro_maintenance_machines', [
            'plugin_kanpro_cards_id'=>$newId,
            'seq'=>1,
            'label'=>$newLabel,
            'date_mod'=>date('Y-m-d H:i:s')
        ], ['id'=>$mid]);
        // re-sequencia card original
        $remaining=[];
        $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach($iter as $r) $remaining[]=$r;
        $seq=1;
        foreach($remaining as $r){
            $newLabel2 = "Máquina {$seq} - {$r['model']}";
            $DB->update('glpi_plugin_kanpro_maintenance_machines', ['seq'=>$seq,'label'=>$newLabel2,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$r['id']]);
            $seq++;
        }
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_retirada', "Máquina #{$row['seq']} ({$row['model']}) retirada para card #{$newId}");
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $newId, $card->fields['plugin_kanpro_lists_id'], 'maintenance_retirada_new', "Card de retirada criado a partir de #{$cid} máquina #{$row['seq']}");
        // cria transferência para assinatura (se plugin disponível)
        $transfer_id = null;
        $assinatura_url = null;
        if ($DB->tableExists('glpi_plugin_assetmgrstatus_transfers') && $DB->tableExists('glpi_plugin_assetmgrstatus_transfer_items')) {
            $board = new PluginKanproBoard();
            $board->getFromDB($card->fields['plugin_kanpro_boards_id']);
            $list = new PluginKanproList();
            $list->getFromDB($card->fields['plugin_kanpro_lists_id']);
            $board_name = $board->fields['name'] ?? 'Quadro';
            $list_name  = $list->fields['name'] ?? 'Lista';
            $entity_dest = (int)($board->fields['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
            $stRaw = mb_strtolower(trim($row['status'] ?? ''), 'UTF-8');
            $status_final = in_array($stRaw, ['garantia','ok','inservivel','pendente']) ? $stRaw : (!empty($stRaw) ? $stRaw : 'pendente');
            $work_status = !empty($row['is_done']) ? 'done' : 'pending';
            $reason = "[KanPro #{$newId} - Retirada Urgência] Quadro: {$board_name} | Lista: {$list_name} | Card origem: #{$cid} {$origName} | Máquina #{$row['seq']} {$row['model']} [{$status_final}] URGÊNCIA | Diário: " . mb_substr($row['diary'] ?? '',0,300) . " | Gerado em ".date('d/m/Y H:i');
            $now = date('Y-m-d H:i:s');
            $uid = kanpro_acting_user_id();
            $tech_id = (int)($card->fields['maintenance_by'] ?? $uid);
            $DB->insert('glpi_plugin_assetmgrstatus_transfers', [
                'entity_dest'      => $entity_dest,
                'reason'           => $reason,
                'status'           => 'pronto',
                'users_id_created' => $uid,
                'users_id_tech'    => $tech_id,
                'date_pending'     => $now,
                'date_creation'    => $now,
                'date_pronto'      => $now,
            ]);
            $transfer_id = (int)$DB->insertId();
            if (!$transfer_id) {
                $r = $DB->request(['FROM'=>'glpi_plugin_assetmgrstatus_transfers','WHERE'=>['reason'=>$reason],'ORDER'=>'id DESC','LIMIT'=>1])->current();
                $transfer_id = (int)($r['id']??0);
            }
            if ($transfer_id) {
                $DB->insert('glpi_plugin_assetmgrstatus_transfer_items', [
                    'transfers_id'       => $transfer_id,
                    'items_id'           => (int)$mid,
                    'itemtype'           => 'KanPro',
                    'item_name'          => $row['label'] . ' - ' . $row['model'],
                    'origin_entity_id'   => $entity_dest,
                    'origin_entity_name' => $origName,
                    'final_status'       => $status_final,
                    'final_reason'       => $row['diary'] ?? '',
                    'final_components'   => json_encode(!empty(trim($row['diary'] ?? '')) ? ['diario'=>trim($row['diary'])] : [], JSON_UNESCAPED_UNICODE),
                    'work_log'           => $row['diary'] ?? '',
                    'work_components'    => json_encode([], JSON_UNESCAPED_UNICODE),
                    'work_status'        => $work_status,
                ]);
                try{ \GlpiPlugin\Assetmgrstatus\Transfer::logStatus($transfer_id, 'pronto', "KanPro Retirada Urgência: Máquina #{$row['seq']} '{$row['model']}' de #{$cid} para #{$newId}"); }catch(Throwable $e){}
                $base = Plugin::getWebDir('assetmgrstatus');
                if(!$base) $base = '/plugins/assetmgrstatus';
                $assinatura_url = $base.'/front/assinatura.php?f=pendente&highlight='.$transfer_id;
            }
        }
        jexit(['success'=>true,'new_card_id'=>$newId,'transfer_id'=>$transfer_id,'assinatura_url'=>$assinatura_url,'msg'=>'Retirada criada']);

    case 'revert_maintenance':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Não é manutenção']);
        if (!kanpro_verify_password($password)) jexit(['success'=>false,'msg'=>'Senha incorreta']);
        $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>0,'maintenance_date'=>null,'maintenance_by'=>0], ['id'=>$cid]);
        // opcional: manter máquinas para histórico, mas aqui mantém; se quiser apagar, descomente:
        // $DB->delete('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id'=>$cid]);
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_revert', "Manutenção revertida");
        jexit(['success'=>true]);

    case 'get_maintenance_term_data':
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_REQUEST['cards_id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $data = PluginKanproCard::getFullData($cid);
        if (!$data) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        // inclui máquinas
        $machines=[];
        $iter=$DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
        foreach($iter as $r) $machines[]=$r;
        $total=count($machines);
        $done=0;
        foreach($machines as $m) if($m['is_done']) $done++;
        $percent=$total?round($done/$total*100):0;
        $canGenerate = ($total>0 && $done===$total);
        jexit(['success'=>true,'is_maintenance'=>!empty($data['is_maintenance'])?1:0,'card'=>$data,'machines'=>$machines,'progress'=>['total'=>$total,'done'=>$done,'percent'=>$percent,'canGenerate'=>$canGenerate]]);

    case 'finalize_maintenance':
        needEdit();
        kanpro_ensure_maintenance_tables();
        $cid = (int)($_POST['cards_id'] ?? $_POST['id'] ?? 0);
        $force = !empty($_POST['force']);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        if (empty($card->fields['is_maintenance'])) jexit(['success'=>false,'msg'=>'Este cartão não é de manutenção']);
        $machines = [];
        if ($DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) {
            $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
            foreach ($iter as $r) $machines[] = $r;
        }
        $total = count($machines);
        if ($total===0) jexit(['success'=>false,'msg'=>'Nenhuma máquina cadastrada. Configure as máquinas antes de finalizar.']);
        // Validação obrigatória: Status Final não pode ficar em branco
        $missing = [];
        foreach ($machines as $m) {
            $st = trim($m['status'] ?? '');
            if ($st === '') $missing[] = $m['seq'];
        }
        if (!empty($missing)) {
            $list = implode(', ', array_slice($missing,0,10));
            if (count($missing)>10) $list .= ' ... (+'.(count($missing)-10).')';
            jexit(['success'=>false,'msg'=>"Selecione o Status Final de todas as máquinas antes de finalizar. Faltam: #". $list . " (" . count($missing) . "/" . $total . ")",'need_status'=>true,'missing'=>$missing,'progress'=>['total'=>$total,'missing'=>count($missing)]]);
        }
        // Classifica por status — pendente vai para novo card
        $pendingMachines = [];
        $nonPending = [];
        // normaliza contadores por status
        $cntGarantia=0; $cntOk=0; $cntInservivel=0; $cntPendente=0;
        foreach ($machines as $m) {
            $st = mb_strtolower(trim($m['status'] ?? ''), 'UTF-8');
            if ($st==='pending') $st='pendente';
            if ($st==='defect' || $st==='defeito' || $st==='nok') $st='inservivel';
            if ($st==='pendente') { $pendingMachines[]=$m; $cntPendente++; }
            elseif ($st==='garantia') { $nonPending[]=$m; $cntGarantia++; }
            elseif ($st==='ok') { $nonPending[]=$m; $cntOk++; }
            elseif ($st==='inservivel') { $nonPending[]=$m; $cntInservivel++; }
            else { // fallback trata como pendente
                $pendingMachines[]=$m; $cntPendente++;
            }
        }
        $pendingCount = count($pendingMachines);
        $nonCount = count($nonPending);
        $done = 0;
        foreach ($machines as $m){ if(!empty($m['is_done'])) $done++; }
        $doneNon = 0;
        foreach ($nonPending as $m){ if(!empty($m['is_done'])) $doneNon++; }
        $percent = $total? round($done/$total*100):0;
        $percentNon = $nonCount? round($doneNon/$nonCount*100):0;
        // Se existem pendentes e nenhum item finalizável, apenas cria card de pendentes
        if ($pendingCount>0 && $nonCount===0) {
            // Cria novo card com todos os pendentes (nome igual à entidade, sem sufixo)
            $origName = trim($card->fields['name']);
            $newName = mb_substr($origName, 0, 255);
            $newCard = new PluginKanproCard();
            $newId = $newCard->add([
                'plugin_kanpro_boards_id' => $card->fields['plugin_kanpro_boards_id'],
                'plugin_kanpro_lists_id'  => $card->fields['plugin_kanpro_lists_id'],
                'name'        => $newName,
                'description' => $card->fields['description'] ?? '',
            ]);
            if (!$newId) jexit(['success'=>false,'msg'=>'Falha ao criar card de pendentes']);
            // garante que novo card também é manutenção
            $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>1,'maintenance_date'=>date('Y-m-d H:i:s'),'maintenance_by'=>kanpro_acting_user_id()], ['id'=>$newId]);
            // move pendentes para novo card com seq 1..N e zera Feito/Status/Diário/Inventário
            $seq=1;
            foreach ($pendingMachines as $pm) {
                $newLabel = "Máquina {$seq} - {$pm['model']}";
                $DB->update('glpi_plugin_kanpro_maintenance_machines', [
                    'plugin_kanpro_cards_id'=>$newId,
                    'seq'=>$seq,
                    'label'=>$newLabel,
                    'is_done'=>0,
                    'is_ok'=>0,
                    'status'=>'',
                    'diary'=>'',
                    'is_inventoried'=>0,
                    'date_mod'=>date('Y-m-d H:i:s')
                ], ['id'=>$pm['id']]);
                $seq++;
            }
            PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $newId, $card->fields['plugin_kanpro_lists_id'], 'maintenance_pending_split', "Card de pendentes criado a partir de #{$cid} com {$pendingCount} máquinas");
            PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_pending_split', "Máquinas pendentes movidas para #{$newId} ({$pendingCount}) — card original ficou vazio");
            $splitTid = kanpro_card_ticket_id($cid);
            if ($splitTid) kanpro_ticket_followup($splitTid, "⚙ [KanPro] Máquinas pendentes\n\nTodas as máquinas estavam com status Pendente e foram movidas para o cartão #{$newId} ({$pendingCount} máquinas).\n\nNenhum termo foi gerado.");
            jexit(['success'=>true,'pending_only'=>true,'pending_card_id'=>$newId,'pending_count'=>$pendingCount,'msg'=>"Todas as máquinas estavam como Pendente. Novo card #{$newId} criado com {$pendingCount} pendentes. Nenhum termo gerado para levar.",'progress'=>['total'=>$total,'pending'=>$pendingCount]]);
        }
        // Valida progresso 100% apenas para itens que vão para o termo (não pendentes)
        if ($nonCount>0 && !$force && $doneNon!==$nonCount) {
            jexit(['success'=>false,'msg'=>"Conclua 'Feito' de todos os itens que vão para o termo antes de finalizar (não pendentes: {$doneNon}/{$nonCount} • {$percentNon}%). Pendentes ({$pendingCount}) ficarão em novo card.",'need_100'=>true,'progress'=>['total'=>$nonCount,'done'=>$doneNon,'percent'=>$percentNon,'pending'=>$pendingCount]]);
        }
        // verifica se assetmgrstatus está disponível (tabelas) — só necessário se houver itens para levar
        if ($nonCount>0 && (!$DB->tableExists('glpi_plugin_assetmgrstatus_transfers') || !$DB->tableExists('glpi_plugin_assetmgrstatus_transfer_items'))) {
            jexit(['success'=>false,'msg'=>'Plugin Assinatura (assetmgrstatus) não encontrado. Use Gerar Termo local.','need_fallback'=>true,'progress'=>['total'=>$total,'done'=>$done,'percent'=>$percent]]);
        }
        // evita duplicidade: se já existe transferência KanPro para este card, reutiliza (só se não há pendentes a separar)
        if ($pendingCount===0) {
            $existing = null;
            try{
                $like = "%[KanPro #{$cid}]%";
                $iter = $DB->request(['FROM'=>'glpi_plugin_assetmgrstatus_transfers','WHERE'=>['reason'=>['LIKE',$like]],'ORDER'=>'id DESC','LIMIT'=>1]);
                if($iter->count()>0) $existing = $iter->current();
            }catch(Throwable $e){}
            if($existing){
                $transfer_id = (int)$existing['id'];
                try {
                    $firstIt = $DB->request(['FROM'=>'glpi_plugin_assetmgrstatus_transfer_items','WHERE'=>['transfers_id'=>$transfer_id],'ORDER'=>'id ASC','LIMIT'=>1])->current();
                    if ($firstIt && trim($firstIt['origin_entity_name'] ?? '') !== trim($card->fields['name'] ?? '') && trim($card->fields['name'] ?? '') !== '') {
                        $DB->update('glpi_plugin_assetmgrstatus_transfer_items', ['origin_entity_name' => mb_substr($card->fields['name'],0,255)], ['transfers_id'=>$transfer_id]);
                    }
                } catch(Throwable $e) {}
                $base = Plugin::getWebDir('assetmgrstatus');
                if(!$base) $base = '/plugins/assetmgrstatus';
                $assinatura_url = $base.'/front/assinatura.php?f=pendente&highlight='.$transfer_id;
                $pdf_url = $base.'/front/transfer_pdf.php?id='.$transfer_id.'&stage=pronto';
                jexit(['success'=>true,'transfer_id'=>$transfer_id,'assinatura_url'=>$assinatura_url,'pdf_url'=>$pdf_url,'msg'=>'Já existe termo para este card','existing'=>true]);
            }
        }
        // Se há pendentes, cria novo card com pendentes ANTES de gerar termo (nome igual, campos zerados)
        $pendingCardId = null;
        if ($pendingCount>0) {
            $origName = trim($card->fields['name']);
            $newName = mb_substr($origName, 0, 255);
            $newCard = new PluginKanproCard();
            $newId = $newCard->add([
                'plugin_kanpro_boards_id' => $card->fields['plugin_kanpro_boards_id'],
                'plugin_kanpro_lists_id'  => $card->fields['plugin_kanpro_lists_id'],
                'name'        => $newName,
                'description' => $card->fields['description'] ?? '',
            ]);
            if ($newId) {
                $DB->update('glpi_plugin_kanpro_cards', ['is_maintenance'=>1,'maintenance_date'=>date('Y-m-d H:i:s'),'maintenance_by'=>kanpro_acting_user_id()], ['id'=>$newId]);
                $seq=1;
                foreach ($pendingMachines as $pm) {
                    $newLabel = "Máquina {$seq} - {$pm['model']}";
                    $DB->update('glpi_plugin_kanpro_maintenance_machines', [
                        'plugin_kanpro_cards_id'=>$newId,
                        'seq'=>$seq,
                        'label'=>$newLabel,
                        'is_done'=>0,
                        'is_ok'=>0,
                        'status'=>'',
                        'diary'=>'',
                        'is_inventoried'=>0,
                        'date_mod'=>date('Y-m-d H:i:s')
                    ], ['id'=>$pm['id']]);
                    $seq++;
                }
                $pendingCardId = $newId;
                PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $newId, $card->fields['plugin_kanpro_lists_id'], 'maintenance_pending_split', "Card de pendentes #{$newId} criado com {$pendingCount} máquinas de #{$cid}");
            }
            // re-sequencia card original (não pendentes) 1..N
            $remaining = $nonPending;
            usort($remaining, fn($a,$b)=> $a['seq']<=>$b['seq']);
            $seq=1;
            foreach ($remaining as $rm) {
                $newLabel = "Máquina {$seq} - {$rm['model']}";
                $DB->update('glpi_plugin_kanpro_maintenance_machines', ['seq'=>$seq,'label'=>$newLabel,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$rm['id']]);
                $seq++;
            }
            // atualiza array máquinas para termo (apenas não pendentes, já re-sequenciadas em memória)
            $machines = [];
            $iter = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
            foreach ($iter as $r) $machines[] = $r;
            $total = count($machines); // agora é nonCount
        }
        // Se após split não há itens para termo (caso já tratado all-pendente), sai
        if (empty($machines) || $nonCount===0) {
            $splitTid2 = kanpro_card_ticket_id($cid);
            if ($splitTid2) kanpro_ticket_followup($splitTid2, "⚙ [KanPro] Máquinas pendentes\n\nOs itens pendentes foram movidos para o cartão #{$pendingCardId} ({$pendingCount} máquinas).\n\nNenhum termo foi gerado.");
            jexit(['success'=>true,'pending_card_id'=>$pendingCardId,'pending_count'=>$pendingCount,'msg'=>"Pendentes movidos para card #{$pendingCardId}. Nenhum termo gerado.","pending_only"=>true]);
        }
        // se há pendentes, cria novo card com eles antes de finalizar o atual
        $pendingCardId = null;
        if ($pendingCount > 0) {
            $newCard = new PluginKanproCard();
            $newName = $card->fields['name'] . ' - Pendentes ('.$pendingCount.')';
            $newName = mb_substr($newName, 0, 255);
            $newId = $newCard->add([
                'plugin_kanpro_boards_id' => $card->fields['plugin_kanpro_boards_id'],
                'plugin_kanpro_lists_id'  => $card->fields['plugin_kanpro_lists_id'],
                'name'        => $newName,
                'description' => $card->fields['description'] ?? '',
            ]);
            if ($newId) {
                $pendingCardId = (int)$newId;
                // marca como manutenção
                $DB->update('glpi_plugin_kanpro_cards', [
                    'is_maintenance'   => 1,
                    'maintenance_date' => date('Y-m-d H:i:s'),
                    'maintenance_by'   => kanpro_acting_user_id(),
                    'date_mod'         => date('Y-m-d H:i:s')
                ], ['id' => $pendingCardId]);
                // copia máquinas pendentes para novo card re-sequenciando 1..N
                $seq = 0;
                $now2 = date('Y-m-d H:i:s');
                $uid2 = kanpro_acting_user_id();
                foreach ($pendingMachines as $pm) {
                    $seq++;
                    $DB->insert('glpi_plugin_kanpro_maintenance_machines', [
                        'plugin_kanpro_cards_id' => $pendingCardId,
                        'seq'                    => $seq,
                        'model'                  => $pm['model'],
                        'label'                  => "Máquina {$seq} - {$pm['model']}",
                        'diary'                  => $pm['diary'] ?? '',
                        'is_done'                => $pm['is_done'] ?? 0,
                        'is_ok'                  => $pm['is_ok'] ?? 0,
                        'status'                 => 'pendente',
                        'users_id'               => $uid2,
                        'date_creation'          => $now2,
                        'date_mod'               => $now2,
                    ]);
                }
                PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $pendingCardId, $card->fields['plugin_kanpro_lists_id'], 'card_create', "Card pendente criado a partir de #{$cid} com {$pendingCount} máquina(s) pendente(s)");
                PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_pending_split', "Manutenção: {$pendingCount} pendente(s) movido(s) para card #{$pendingCardId}");
                // remove pendentes do card original (movido, não duplicado)
                $pendingIds = array_column($pendingMachines, 'id');
                if (!empty($pendingIds)) {
                    $DB->delete('glpi_plugin_kanpro_maintenance_machines', ['id' => $pendingIds]);
                    // re-sequencia restantes do card original
                    $remaining = [];
                    $iter2 = $DB->request(['FROM'=>'glpi_plugin_kanpro_maintenance_machines','WHERE'=>['plugin_kanpro_cards_id'=>$cid],'ORDER'=>'seq ASC']);
                    foreach ($iter2 as $r) $remaining[]=$r;
                    $s=1;
                    foreach ($remaining as $r) {
                        $DB->update('glpi_plugin_kanpro_maintenance_machines', ['seq'=>$s,'label'=>"Máquina {$s} - {$r['model']}"], ['id'=>$r['id']]);
                        $s++;
                    }
                }
                // atualiza variáveis para transferência: apenas não-pendentes
                $machines = $nonPendingMachines;
                $total = count($machines);
                if ($total===0) {
                    // todos eram pendentes — não gera transferência, apenas informa novo card
                    jexit(['success'=>true,'msg'=>"Todos os itens estavam como Pendente. Criado novo card #{$pendingCardId} com {$pendingCount} máquina(s). Nenhum termo gerado para o card atual.",'pending_card_id'=>$pendingCardId,'pending_count'=>$pendingCount,'all_pending'=>true]);
                }
                // recalcula done/ok para não-pendentes
                $done=0; $okCount=0;
                foreach ($machines as $m){ if(!empty($m['is_done'])) $done++; if(($m['status']??'')==='ok') $okCount++; }
            }
        }
        // dados do quadro/lista para razão e entidade — usa $total já ajustado (após split)
        $board = new PluginKanproBoard();
        $board->getFromDB($card->fields['plugin_kanpro_boards_id']);
        $list = new PluginKanproList();
        $list->getFromDB($card->fields['plugin_kanpro_lists_id']);
        $board_name = $board->fields['name'] ?? 'Quadro';
        $list_name  = $list->fields['name'] ?? 'Lista';
        $entity_dest = (int)($board->fields['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
        // recalcula done/ok etc para termo (já só nonPending)
        $doneTerm = 0; $cntGarantiaTerm=0; $cntOkTerm=0; $cntInservivelTerm=0;
        foreach ($machines as $m){ if(!empty($m['is_done'])) $doneTerm++; $st=mb_strtolower(trim($m['status']??''),'UTF-8'); if($st==='garantia') $cntGarantiaTerm++; elseif($st==='ok') $cntOkTerm++; elseif($st==='inservivel') $cntInservivelTerm++; }
        $reason = "[KanPro #{$cid}] Quadro: {$board_name} | Lista: {$list_name} | Card: {$card->fields['name']} | Manutenção: {$total} máquinas ({$doneTerm} concluídas | Garantia:{$cntGarantiaTerm} Ok:{$cntOkTerm} Inservível:{$cntInservivelTerm} Pendente:{$pendingCount}→card #{$pendingCardId}) | Gerado em ".date('d/m/Y H:i');
        if(!empty($card->fields['description'])) $reason .= " | Desc: ".mb_substr($card->fields['description'],0,300);
        $summary = [];
        foreach(array_slice($machines,0,5) as $m){ $summary[] = "#{$m['seq']} {$m['model']} [{$m['status']}]: ".mb_substr($m['diary']??'',0,60); }
        if(count($machines)>5) $summary[] = "... e mais ".(count($machines)-5)." máquinas";
        if($summary) $reason .= " | Máquinas: ".implode("; ", $summary);
        if($pendingCount>0) $reason .= " | Pendentes movidos para card #{$pendingCardId} ({$pendingCount})";
        $now = date('Y-m-d H:i:s');
        $uid = kanpro_acting_user_id();
        $tech_id = (int)($card->fields['maintenance_by'] ?? $uid);
        // Nome do Recebedor fica vazio por padrão — preenchido apenas no momento da assinatura via tablet/lote
        $transfer_data = [
            'entity_dest'      => $entity_dest,
            'reason'           => $reason,
            'status'           => 'pronto',
            'users_id_created' => $uid,
            'users_id_tech'    => $tech_id,
            'date_pending'     => $now,
            'date_creation'    => $now,
            'date_pronto'      => $now,
        ];
        $DB->insert('glpi_plugin_assetmgrstatus_transfers', $transfer_data);
        $transfer_id = (int)$DB->insertId();
        if(!$transfer_id){
            $row = $DB->request(['FROM'=>'glpi_plugin_assetmgrstatus_transfers','WHERE'=>['reason'=>$reason],'ORDER'=>'id DESC','LIMIT'=>1])->current();
            $transfer_id = (int)($row['id']??0);
        }
        if(!$transfer_id) jexit(['success'=>false,'msg'=>'Falha ao criar transferência no Assinatura']);
        $origin_entity_id = $entity_dest;
        $origin_entity_name = $card->fields['name'] ?? $board_name;
        foreach($machines as $m){
            $stRaw = mb_strtolower(trim($m['status'] ?? ''), 'UTF-8');
            if ($stRaw==='pending') $stRaw='pendente';
            if ($stRaw==='defect' || $stRaw==='defeito' || $stRaw==='nok') $stRaw='inservivel';
            $status_final = 'pendente';
            if ($stRaw==='garantia') $status_final='garantia';
            elseif ($stRaw==='ok') $status_final='ok';
            elseif ($stRaw==='inservivel') $status_final='inservivel';
            elseif ($stRaw==='pendente') $status_final='pendente';
            elseif (!empty($m['is_ok'])) $status_final='ok';
            $work_status = !empty($m['is_done']) ? 'done' : 'pending';
            $DB->insert('glpi_plugin_assetmgrstatus_transfer_items', [
                'transfers_id'       => $transfer_id,
                'items_id'           => (int)$m['id'],
                'itemtype'           => 'KanPro',
                'item_name'          => $m['label'] . ' - ' . $m['model'],
                'origin_entity_id'   => $origin_entity_id,
                'origin_entity_name' => $origin_entity_name,
                'final_status'       => $status_final,
                'final_reason'       => $m['diary'] ?? '',
                'final_components'   => json_encode(!empty(trim($m['diary'] ?? '')) ? ['diario' => trim($m['diary'])] : [], JSON_UNESCAPED_UNICODE),
                'work_log'           => $m['diary'] ?? '',
                'work_components'    => json_encode([], JSON_UNESCAPED_UNICODE),
                'work_status'        => $work_status,
            ]);
        }
        try{ \GlpiPlugin\Assetmgrstatus\Transfer::logStatus($transfer_id, 'pronto', "KanPro Finalizado: Card #{$cid} '{$card->fields['name']}' — {$total} máquinas (Garantia:{$cntGarantiaTerm} Ok:{$cntOkTerm} Inservível:{$cntInservivelTerm}) pendentes→#{$pendingCardId}"); }catch(Throwable $e){}
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'maintenance_finalize', "Manutenção finalizada e enviada para Assinatura #{$transfer_id} ({$total} itens) pendentes→#{$pendingCardId}");
        // espelha no chamado vinculado: atribui quem finalizou, relatório completo + soluciona
        $finTid = kanpro_card_ticket_id($cid);
        if ($finTid) {
            $finUid = kanpro_acting_user_id();
            kanpro_ticket_assign($finTid, $finUid);
            $finName = '';
            try { $fu = new User(); if ($fu->getFromDB($finUid)) $finName = $fu->getFriendlyName(); } catch (Throwable $e) {}
            $techName = '';
            try { $tu = new User(); if ($tu->getFromDB($tech_id)) $techName = $tu->getFriendlyName(); } catch (Throwable $e) {}
            $finMsg = "✅ [KanPro] Manutenção finalizada\n\n"
                . "Cartão #{$cid} '" . ($card->fields['name'] ?? '') . "'\n"
                . 'Quadro: ' . $board_name . "\n"
                . 'Lista: ' . $list_name . "\n"
                . ($techName !== '' ? 'Técnico responsável: ' . $techName . "\n" : '')
                . ($finName !== '' ? 'Finalizado por: ' . $finName . "\n" : '')
                . "\nResumo do atendimento: {$total} máquinas (Garantia: {$cntGarantiaTerm} | OK: {$cntOkTerm} | Inservível: {$cntInservivelTerm})\n"
                . ($pendingCount > 0 ? "{$pendingCount} item(ns) pendente(s) transferido(s) para o cartão #{$pendingCardId}.\n" : '')
                . "Termo de assinatura #{$transfer_id} gerado para coleta na escola.";
            $finRep = kanpro_card_machines_report($cid);
            if ($finRep !== '') $finMsg .= "\n\n" . $finRep;
            kanpro_ticket_followup($finTid, $finMsg);
            kanpro_ticket_solve($finTid, "Manutenção concluída pelo KanPro" . ($finName !== '' ? ' (finalizado por ' . $finName . ')' : '') . " — {$total} máquinas verificadas (Garantia: {$cntGarantiaTerm} | OK: {$cntOkTerm} | Inservível: {$cntInservivelTerm}). Termo de assinatura #{$transfer_id} gerado para coleta na escola.");
        }
        $base = Plugin::getWebDir('assetmgrstatus');
        if(!$base) $base = '/plugins/assetmgrstatus';
        $assinatura_url = $base.'/front/assinatura.php?f=pendente&highlight='.$transfer_id;
        $pdf_url = $base.'/front/transfer_pdf.php?id='.$transfer_id.'&stage=pronto';
        $resp = ['success'=>true,'transfer_id'=>$transfer_id,'assinatura_url'=>$assinatura_url,'pdf_url'=>$pdf_url,'progress'=>['total'=>$total,'done'=>$doneTerm,'percent'=>$total?round($doneTerm/$total*100):0,'garantia'=>$cntGarantiaTerm,'ok'=>$cntOkTerm,'inservivel'=>$cntInservivelTerm]];
        if ($pendingCardId) { $resp['pending_card_id']=$pendingCardId; $resp['pending_count']=$pendingCount; $resp['msg_pending']="Pendentes ({$pendingCount}) movidos para novo card #{$pendingCardId}"; }
        jexit($resp);

    // --- CARD <-> CHAMADO GLPI ---
    case 'link_ticket':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $tid = (int)($_POST['tickets_id'] ?? 0);
        if (!$cid || !$tid) jexit(['success'=>false,'msg'=>'Informe o nº do chamado']);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $tk = new Ticket();
        if (!$tk->getFromDB($tid)) jexit(['success'=>false,'msg'=>'Chamado #'.$tid.' não encontrado']);
        if (!$tk->can($tid, READ)) jexit(['success'=>false,'msg'=>'Sem acesso ao chamado #'.$tid.' (perfil/entidade)']);
        $DB->update('glpi_plugin_kanpro_cards', ['tickets_id'=>$tid], ['id'=>$cid]);
        kanpro_touch_member($cid);
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'card_link_ticket', "Chamado #{$tid} vinculado ao cartão");
        jexit(['success'=>true,'ticket'=>kanpro_ticket_info($tid)]);

    case 'unlink_ticket':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        $card = new PluginKanproCard();
        if (!$card->getFromDB($cid)) jexit(['success'=>false,'msg'=>'Cartão não encontrado']);
        $old = (int)($card->fields['tickets_id'] ?? 0);
        $DB->update('glpi_plugin_kanpro_cards', ['tickets_id'=>0], ['id'=>$cid]);
        PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cid, $card->fields['plugin_kanpro_lists_id'], 'card_unlink_ticket', "Chamado #{$old} desvinculado do cartão");
        jexit(['success'=>true]);

    case 'create_ticket_from_card':
        needEdit();
        $cid = (int)($_POST['cards_id'] ?? 0);
        if (!$cid) jexit(['success'=>false,'msg'=>'Cartão inválido']);
        try {
            $res = kanpro_create_ticket_from_card($cid);
        } catch (Throwable $e) { jexit(['success'=>false,'msg'=>'Erro: '.$e->getMessage()]); }
        if (empty($res['ok'])) jexit(['success'=>false,'msg'=>$res['error'] ?? 'Falha ao criar chamado']);
        kanpro_touch_member($cid);
        jexit(['success'=>true,'ticket'=>$res['ticket'],'existed'=>!empty($res['existed'])]);

    default:
        jexit(['success'=>false,'msg'=>'Ação desconhecida: '.$action]);
}
