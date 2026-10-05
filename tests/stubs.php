<?php
/**
 * tests/stubs.php — stubs mínimos do GLPI p/ testes e análise estática.
 *
 * O núcleo do GLPI não existe neste repo; os stubs dão aos testes e ao
 * PHPStan os símbolos necessários. NUNCA carregar em produção (lá as
 * classes reais existem e o class_exists abaixo impede redeclaração).
 *
 * PluginKanproBoard::logActivity captura as chamadas em $activityLog
 * para os testes verificarem o log sem banco real.
 */
if (!class_exists('CommonDBTM')) {
    class CommonDBTM {
        /** @var string */
        public static $rightname = '';
    }
}

if (!class_exists('PluginKanproBoard')) {
    class PluginKanproBoard {
        /** @var array<int,array<int|string,mixed>> */
        public static array $activityLog = [];

        /** @param mixed ...$args */
        public static function logActivity(...$args): void {
            self::$activityLog[] = $args;
        }

        public static function resetActivityLog(): void {
            self::$activityLog = [];
        }
    }
}

if (!class_exists('PluginKanproCard')) {
    class PluginKanproCard {
        /** @var array<string,mixed> */
        public array $fields = ['plugin_kanpro_boards_id' => 7, 'plugin_kanpro_lists_id' => 9];

        public function getFromDB(int $id): bool {
            return $id > 0;
        }
    }
}

if (!function_exists('countElementsInTable')) {
    /**
     * Conta linhas (igualdade simples; operadores do QueryBuilder real
     * como LIKE não são suportados — os caminhos testados usam igualdade).
     *
     * @param array<string,mixed> $conditions
     */
    function countElementsInTable(string $table, array $conditions = []): int {
        global $DB;
        $n = 0;
        foreach ($DB->request(['FROM' => $table, 'WHERE' => $conditions]) as $row) {
            $n++;
        }
        return $n;
    }
}
