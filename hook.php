<?php
/**
 * hook.php — Instalação / Desinstalação KanPro
 */

function plugin_kanpro_install(): bool {
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // --- BOARDS (Quadros) ---
    if (!$DB->tableExists('glpi_plugin_kanpro_boards')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_boards` (
                `id`              INT {$sign} NOT NULL AUTO_INCREMENT,
                `name`            VARCHAR(255) NOT NULL DEFAULT '',
                `entities_id`     INT {$sign} NOT NULL DEFAULT '0',
                `is_recursive`    TINYINT(1)   NOT NULL DEFAULT '0',
                `comment`         TEXT         DEFAULT NULL,
                `color`           VARCHAR(20)  NOT NULL DEFAULT '#0079bf',
                `background`      VARCHAR(255) DEFAULT NULL COMMENT 'cor ou url imagem',
                `is_archived`     TINYINT(1)   NOT NULL DEFAULT '0',
                `is_starred`      TINYINT(1)   NOT NULL DEFAULT '0',
                `visibility`      VARCHAR(20)  NOT NULL DEFAULT 'private',
                `users_id`        INT {$sign} NOT NULL DEFAULT '0',
                `date_creation`   DATETIME     DEFAULT NULL,
                `date_mod`        DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    } else {
        // migrações leves
        if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'color')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` ADD `color` VARCHAR(20) NOT NULL DEFAULT '#0079bf' AFTER `comment`");
        }
        if (!$DB->fieldExists('glpi_plugin_kanpro_boards', 'is_starred')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_boards` ADD `is_starred` TINYINT(1) NOT NULL DEFAULT '0'");
        }
    }

    // --- LISTS (Colunas) ---
    if (!$DB->tableExists('glpi_plugin_kanpro_lists')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_lists` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_boards_id`     INT {$sign} NOT NULL DEFAULT '0',
                `name`                        VARCHAR(255) NOT NULL DEFAULT '',
                `rank`                        DOUBLE       NOT NULL DEFAULT '0',
                `is_archived`                 TINYINT(1)   NOT NULL DEFAULT '0',
                `color`                       VARCHAR(20)  DEFAULT NULL,
                `date_creation`               DATETIME     DEFAULT NULL,
                `date_mod`                    DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_kanpro_boards_id` (`plugin_kanpro_boards_id`),
                KEY `rank` (`rank`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // --- CARDS (Cartões) ---
    if (!$DB->tableExists('glpi_plugin_kanpro_cards')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_cards` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_boards_id`     INT {$sign} NOT NULL DEFAULT '0',
                `plugin_kanpro_lists_id`      INT {$sign} NOT NULL DEFAULT '0',
                `name`                        VARCHAR(255) NOT NULL DEFAULT '',
                `description`                 LONGTEXT     DEFAULT NULL,
                `rank`                        DOUBLE       NOT NULL DEFAULT '0',
                `is_archived`                 TINYINT(1)   NOT NULL DEFAULT '0',
                `is_completed`                TINYINT(1)   NOT NULL DEFAULT '0',
                `due_date`                    DATETIME     DEFAULT NULL,
                `start_date`                  DATETIME     DEFAULT NULL,
                `cover_color`                 VARCHAR(20)  DEFAULT NULL,
                `cover_attachment_id`         INT {$sign} DEFAULT NULL,
                `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                `date_creation`               DATETIME     DEFAULT NULL,
                `date_mod`                    DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_kanpro_boards_id` (`plugin_kanpro_boards_id`),
                KEY `plugin_kanpro_lists_id` (`plugin_kanpro_lists_id`),
                KEY `rank` (`rank`),
                KEY `due_date` (`due_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    } else {
        if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'cover_color')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `cover_color` VARCHAR(20) DEFAULT NULL");
        }
        if (!$DB->fieldExists('glpi_plugin_kanpro_cards', 'is_completed')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kanpro_cards` ADD `is_completed` TINYINT(1) NOT NULL DEFAULT '0'");
        }
    }

    // --- LABELS (Etiquetas) ---
    if (!$DB->tableExists('glpi_plugin_kanpro_labels')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_labels` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_boards_id`     INT {$sign} NOT NULL DEFAULT '0',
                `name`                        VARCHAR(100) NOT NULL DEFAULT '',
                `color`                       VARCHAR(20)  NOT NULL DEFAULT '#61bd4f',
                PRIMARY KEY (`id`),
                KEY `plugin_kanpro_boards_id` (`plugin_kanpro_boards_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // --- CARD_LABELS ---
    if (!$DB->tableExists('glpi_plugin_kanpro_cards_labels')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_cards_labels` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_cards_id`      INT {$sign} NOT NULL DEFAULT '0',
                `plugin_kanpro_labels_id`     INT {$sign} NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_card_label` (`plugin_kanpro_cards_id`, `plugin_kanpro_labels_id`),
                KEY `plugin_kanpro_cards_id` (`plugin_kanpro_cards_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // --- CARD_MEMBERS (membros no cartão) ---
    if (!$DB->tableExists('glpi_plugin_kanpro_cards_members')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_cards_members` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_cards_id`      INT {$sign} NOT NULL DEFAULT '0',
                `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_card_user` (`plugin_kanpro_cards_id`, `users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // --- BOARD_MEMBERS (membros no quadro) ---
    if (!$DB->tableExists('glpi_plugin_kanpro_boards_members')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_boards_members` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_boards_id`     INT {$sign} NOT NULL DEFAULT '0',
                `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                `role`                        VARCHAR(20)  NOT NULL DEFAULT 'member' COMMENT 'admin,member,observer',
                `date_creation`               DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_board_user` (`plugin_kanpro_boards_id`, `users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // --- CHECKLISTS ---
    if (!$DB->tableExists('glpi_plugin_kanpro_checklists')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_checklists` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_cards_id`      INT {$sign} NOT NULL DEFAULT '0',
                `name`                        VARCHAR(255) NOT NULL DEFAULT '',
                `rank`                        DOUBLE       NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `plugin_kanpro_cards_id` (`plugin_kanpro_cards_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_kanpro_checklist_items')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_checklist_items` (
                `id`                              INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_checklists_id`     INT {$sign} NOT NULL DEFAULT '0',
                `name`                            VARCHAR(255) NOT NULL DEFAULT '',
                `is_checked`                      TINYINT(1)   NOT NULL DEFAULT '0',
                `users_id`                        INT {$sign} NOT NULL DEFAULT '0',
                `rank`                            DOUBLE       NOT NULL DEFAULT '0',
                `due_date`                        DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_kanpro_checklists_id` (`plugin_kanpro_checklists_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // --- COMMENTS ---
    if (!$DB->tableExists('glpi_plugin_kanpro_comments')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_comments` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_cards_id`      INT {$sign} NOT NULL DEFAULT '0',
                `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                `content`                     TEXT         NOT NULL,
                `date_creation`               DATETIME     DEFAULT NULL,
                `date_mod`                    DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_kanpro_cards_id` (`plugin_kanpro_cards_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // --- ATTACHMENTS ---
    if (!$DB->tableExists('glpi_plugin_kanpro_attachments')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_attachments` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_cards_id`      INT {$sign} NOT NULL DEFAULT '0',
                `name`                        VARCHAR(255) NOT NULL DEFAULT '',
                `filename`                    VARCHAR(255) NOT NULL DEFAULT '',
                `filepath`                    VARCHAR(512) DEFAULT NULL,
                `filesize`                    INT          NOT NULL DEFAULT '0',
                `mime`                        VARCHAR(100) DEFAULT NULL,
                `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                `date_creation`               DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_kanpro_cards_id` (`plugin_kanpro_cards_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // --- ACTIVITIES ---
    if (!$DB->tableExists('glpi_plugin_kanpro_activities')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_kanpro_activities` (
                `id`                          INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_kanpro_boards_id`     INT {$sign} NOT NULL DEFAULT '0',
                `plugin_kanpro_cards_id`      INT {$sign}  DEFAULT NULL,
                `plugin_kanpro_lists_id`      INT {$sign}  DEFAULT NULL,
                `users_id`                    INT {$sign} NOT NULL DEFAULT '0',
                `action`                      VARCHAR(100) NOT NULL DEFAULT '',
                `details`                     TEXT         DEFAULT NULL,
                `date_creation`               DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_kanpro_boards_id` (`plugin_kanpro_boards_id`),
                KEY `plugin_kanpro_cards_id` (`plugin_kanpro_cards_id`),
                KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    PluginKanproProfile::install();
    return true;
}

function plugin_kanpro_uninstall(): bool {
    global $DB;

    PluginKanproProfile::uninstall();

    $tables = [
        'glpi_plugin_kanpro_activities',
        'glpi_plugin_kanpro_attachments',
        'glpi_plugin_kanpro_comments',
        'glpi_plugin_kanpro_checklist_items',
        'glpi_plugin_kanpro_checklists',
        'glpi_plugin_kanpro_boards_members',
        'glpi_plugin_kanpro_cards_members',
        'glpi_plugin_kanpro_cards_labels',
        'glpi_plugin_kanpro_labels',
        'glpi_plugin_kanpro_cards',
        'glpi_plugin_kanpro_lists',
        'glpi_plugin_kanpro_boards',
    ];
    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    $upload_dir = GLPI_PLUGIN_DOC_DIR . '/kanpro/';
    if (is_dir($upload_dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($upload_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($upload_dir);
    }

    return true;
}
