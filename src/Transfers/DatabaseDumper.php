<?php

declare(strict_types=1);

namespace OffsiteBackups\Transfers;

use Generator;
use OffsiteBackups\Panel\Panel;
use PDO;
use Pterodactyl\Models\Database;

/**
 * Plain SQL dump of a server database (tables and rows), read straight from its database host.
 */
final readonly class DatabaseDumper
{
    private const int ROWS_PER_INSERT = 100;

    private const int BYTES_PER_INSERT = 1024 * 1024;

    private const int CHUNK = 1024 * 1024;

    private const array BINARY = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection'];

    /** mysqlnd hands BIT columns over as decimal numbers. */
    private const array NUMERIC = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'year', 'bit'];

    public function __construct(private Panel $panel) {}

    public static function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /** @return Generator<int, string> */
    public function dump(Database $database): Generator
    {
        $login = $this->panel->databaseLogin($database);
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $login['host'], $login['port'], $login['database']),
            $login['username'],
            $login['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false],
        );

        try {
            yield from $this->dumpWith($pdo, $login['database']);
        } finally {
            $pdo = null;
        }
    }

    /** @return Generator<int, string> */
    public function dumpWith(PDO $pdo, string $database): Generator
    {
        $pdo->exec("SET NAMES 'utf8mb4'");
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $buffer = sprintf("-- Offsite Backups dump of %s, %s UTC\n\nSET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n", self::identifier($database), gmdate('Y-m-d H:i:s'));

        foreach ($this->tables($pdo) as $table) {
            $create = $this->all($pdo, 'SHOW CREATE TABLE '.self::identifier($table))[0][1] ?? null;

            if (! is_string($create)) {
                continue;
            }

            $buffer .= sprintf("DROP TABLE IF EXISTS %s;\n%s;\n\n", self::identifier($table), $create);

            foreach ($this->inserts($pdo, $database, $table) as $insert) {
                $buffer .= $insert;

                if (mb_strlen($buffer, '8bit') >= self::CHUNK) {
                    yield $buffer;
                    $buffer = '';
                }
            }

            $buffer .= "\n";
        }

        $pdo->exec('COMMIT');

        yield $buffer."SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n";
    }

    /** @return list<string> */
    private function tables(PDO $pdo): array
    {
        return array_map(static fn (array $row): string => (string) $row[0], $this->all($pdo, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
    }

    /** @return Generator<int, string> INSERT statements of up to 100 rows. */
    private function inserts(PDO $pdo, string $database, string $table): Generator
    {
        $types = $this->columnTypes($pdo, $database, $table);

        if ($types === []) {
            return;
        }

        $head = sprintf('INSERT INTO %s (%s) VALUES ', self::identifier($table), implode(', ', array_map(self::identifier(...), array_keys($types))));
        $kinds = array_values($types);
        $statement = $pdo->query('SELECT * FROM '.self::identifier($table), PDO::FETCH_NUM);
        $values = [];
        $bytes = 0;

        try {
            while (($row = $statement->fetch()) !== false) {
                $tuple = '('.implode(', ', array_map(fn (mixed $value, int $i): string => $this->literal($pdo, $value, $kinds[$i] ?? 'text'), $row, array_keys($row))).')';
                $values[] = $tuple;
                $bytes += mb_strlen($tuple, '8bit');

                if (count($values) >= self::ROWS_PER_INSERT || $bytes >= self::BYTES_PER_INSERT) {
                    yield $head.implode(",\n", $values).";\n";
                    $values = [];
                    $bytes = 0;
                }
            }
        } finally {
            $statement->closeCursor();
        }

        if ($values !== []) {
            yield $head.implode(",\n", $values).";\n";
        }
    }

    /** @return array<string, string> Column name to data type, in table order. */
    private function columnTypes(PDO $pdo, string $database, string $table): array
    {
        $statement = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $statement->execute([$database, $table]);
        $types = [];

        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            $types[(string) $row[0]] = mb_strtolower((string) $row[1]);
        }

        $statement->closeCursor();

        return $types;
    }

    private function literal(PDO $pdo, mixed $value, string $type): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $value = (string) $value;

        if (in_array($type, self::BINARY, true)) {
            return $value === '' ? "''" : '0x'.bin2hex($value);
        }

        if (in_array($type, self::NUMERIC, true) && is_numeric($value)) {
            return $value;
        }

        return (string) $pdo->quote($value);
    }

    /** @return list<array<int, mixed>> */
    private function all(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql, PDO::FETCH_NUM);
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        return $rows;
    }
}
