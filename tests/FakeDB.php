<?php
/**
 * tests/FakeDB.php — dublê mínimo do $DB do GLPI para testes sem banco.
 *
 * Implementa só o subconjunto usado pela camada de config do KanPro:
 * tableExists(), request() (SELECT/WHERE/LIMIT), insert(), update().
 */
/** @implements IteratorAggregate<int, array<string,mixed>> */
class FakeKanproResult implements IteratorAggregate {
    /** @var array<int,array<string,mixed>> */
    private array $rows;

    /** @param array<int,array<string,mixed>> $rows */
    public function __construct(array $rows) {
        $this->rows = array_values($rows);
    }

    public function getIterator(): Traversable {
        return new ArrayIterator($this->rows);
    }

    /** @return array<string,mixed>|null */
    public function current(): ?array {
        $row = $this->rows[0] ?? null;
        return is_array($row) ? $row : null;
    }
}

class FakeKanproDB {
    /** @var array<string,array{rows:array<int,array<string,mixed>>,auto:int}> */
    public array $tables = [];

    public function createTable(string $table): void {
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = ['rows' => [], 'auto' => 1];
        }
    }

    public function tableExists(string $table): bool {
        return isset($this->tables[$table]);
    }

    /** @param array<string,mixed> $criteria */
    public function request(array $criteria): FakeKanproResult {
        $from = (string)($criteria['FROM'] ?? '');
        $rows = $this->tables[$from]['rows'] ?? [];
        if (isset($criteria['WHERE']) && is_array($criteria['WHERE'])) {
            $rows = array_values(array_filter(
                $rows,
                fn($row) => self::matches($row, $criteria['WHERE'])
            ));
        }
        if (isset($criteria['SELECT'])) {
            $cols = array_flip((array)$criteria['SELECT']);
            $rows = array_map(fn($row) => array_intersect_key($row, $cols), $rows);
        }
        if (isset($criteria['LIMIT'])) {
            $rows = array_slice($rows, 0, max(0, (int)$criteria['LIMIT']));
        }
        return new FakeKanproResult($rows);
    }

    /** @param array<string,mixed> $fields */
    public function insert(string $table, array $fields): int|string|false {
        if (!isset($this->tables[$table])) {
            return false;
        }
        $id = $this->tables[$table]['auto']++;
        $this->tables[$table]['rows'][] = array_merge(['id' => $id], $fields);
        return $id;
    }

    /**
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $fields, array $where): bool {
        if (!isset($this->tables[$table])) {
            return false;
        }
        $touched = false;
        foreach ($this->tables[$table]['rows'] as &$row) {
            if (self::matches($row, $where)) {
                foreach ($fields as $k => $v) {
                    $row[$k] = $v;
                }
                $touched = true;
            }
        }
        return $touched;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $where
     */
    private static function matches(array $row, array $where): bool {
        foreach ($where as $k => $v) {
            if (!array_key_exists($k, $row) || $row[$k] != $v) {
                return false;
            }
        }
        return true;
    }

    /**
     * Apaga linhas — só p/ isolar testes.
     *
     * @param array<string,mixed> $where
     */
    public function delete_rows_for_test(string $table, array $where): void {
        if (!isset($this->tables[$table])) {
            return;
        }
        $this->tables[$table]['rows'] = array_values(array_filter(
            $this->tables[$table]['rows'],
            fn($row) => !self::matches($row, $where)
        ));
    }
}
