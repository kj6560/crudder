<?php

declare(strict_types=1);

namespace Curdder\Schema;

use PDO;
use RuntimeException;

final class DatabaseSchemaInspector
{
    private PDO $pdo;
    private string $driver;

    public function __construct(string|PDO $dsnOrPdo, string $user = '', string $password = '')
    {
        if ($dsnOrPdo instanceof PDO) {
            $this->pdo = $dsnOrPdo;
        } else {
            $this->pdo = new PDO($dsnOrPdo, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        $this->driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function inspect(array $selectedTables = []): array
    {
        return match ($this->driver) {
            'sqlite' => $this->inspectSqlite($selectedTables),
            'mysql' => $this->inspectMysql($selectedTables),
            'pgsql' => $this->inspectPostgres($selectedTables),
            default => throw new RuntimeException("Unsupported database driver: {$this->driver}"),
        };
    }

    private function inspectSqlite(array $selectedTables): array
    {
        $tables = [];
        $tableNames = $selectedTables !== [] ? $selectedTables : $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tableNames as $tableName) {
            $columns = [];
            $primaryKey = null;
            $stmt = $this->pdo->query('PRAGMA table_info(' . $this->quoteIdentifier($tableName) . ')');
            foreach ($stmt->fetchAll() as $row) {
                $columns[] = [
                    'name' => $row['name'],
                    'type' => $row['type'] ?: 'text',
                    'nullable' => (int)$row['notnull'] === 0,
                    'default' => $row['dflt_value'] ?? null,
                    'primary' => (int)$row['pk'] > 0,
                    'auto_increment' => false,
                ];
                if ((int)$row['pk'] > 0) {
                    $primaryKey = $row['name'];
                }
            }

            $foreignKeys = $this->readSqliteForeignKeys($tableName);
            $tables[$tableName] = [
                'name' => $tableName,
                'primary_key' => $primaryKey ?? 'id',
                'columns' => $columns,
                'foreign_keys' => $foreignKeys,
            ];
        }

        return $tables;
    }

    private function readSqliteForeignKeys(string $tableName): array
    {
        $foreignKeys = [];
        $stmt = $this->pdo->query('PRAGMA foreign_key_list(' . $this->quoteIdentifier($tableName) . ')');
        foreach ($stmt->fetchAll() as $row) {
            $foreignKeys[$row['from']] = [
                'table' => $row['table'],
                'column' => $row['to'],
                'label_column' => $this->guessLabelColumn($row['table']),
            ];
        }

        return $foreignKeys;
    }

    private function inspectMysql(array $selectedTables): array
    {
        $tables = [];
        $tableNames = $this->readTableNamesMysql($selectedTables);
        $schema = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        foreach ($tableNames as $tableName) {
            $columns = [];
            $stmt = $this->pdo->prepare(
                "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION"
            );
            $stmt->execute([$schema, $tableName]);
            $primaryKey = null;
            foreach ($stmt->fetchAll() as $row) {
                $isPrimary = $row['COLUMN_KEY'] === 'PRI';
                $columns[] = [
                    'name' => $row['COLUMN_NAME'],
                    'type' => $row['COLUMN_TYPE'] ?: $row['DATA_TYPE'],
                    'nullable' => $row['IS_NULLABLE'] === 'YES',
                    'default' => $row['COLUMN_DEFAULT'],
                    'primary' => $isPrimary,
                    'auto_increment' => str_contains((string)$row['EXTRA'], 'auto_increment'),
                ];
                if ($isPrimary) {
                    $primaryKey = $row['COLUMN_NAME'];
                }
            }

            $tables[$tableName] = [
                'name' => $tableName,
                'primary_key' => $primaryKey ?? 'id',
                'columns' => $columns,
                'foreign_keys' => $this->readMysqlForeignKeys($schema, $tableName),
            ];
        }

        return $tables;
    }

    private function readTableNamesMysql(array $selectedTables): array
    {
        if ($selectedTables !== []) {
            return $selectedTables;
        }

        $stmt = $this->pdo->query('SHOW TABLES');
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function readMysqlForeignKeys(string $schema, string $tableName): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL"
        );
        $stmt->execute([$schema, $tableName]);

        $foreignKeys = [];
        foreach ($stmt->fetchAll() as $row) {
            $foreignKeys[$row['COLUMN_NAME']] = [
                'table' => $row['REFERENCED_TABLE_NAME'],
                'column' => $row['REFERENCED_COLUMN_NAME'],
                'label_column' => $this->guessLabelColumn($row['REFERENCED_TABLE_NAME']),
            ];
        }

        return $foreignKeys;
    }

    private function inspectPostgres(array $selectedTables): array
    {
        $tables = [];
        $tableNames = $selectedTables !== [] ? $selectedTables : $this->pdo->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
             ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tableNames as $tableName) {
            $columns = [];
            $primaryKey = null;
            $stmt = $this->pdo->prepare(
                "SELECT column_name, data_type, is_nullable, column_default
                 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = ?
                 ORDER BY ordinal_position"
            );
            $stmt->execute([$tableName]);
            foreach ($stmt->fetchAll() as $row) {
                $isPrimary = $this->isPrimaryPostgres($tableName, $row['column_name']);
                $columns[] = [
                    'name' => $row['column_name'],
                    'type' => $row['data_type'],
                    'nullable' => $row['is_nullable'] === 'YES',
                    'default' => $row['column_default'],
                    'primary' => $isPrimary,
                    'auto_increment' => $row['column_default'] !== null && str_contains((string)$row['column_default'], 'nextval('),
                ];
                if ($isPrimary) {
                    $primaryKey = $row['column_name'];
                }
            }

            $tables[$tableName] = [
                'name' => $tableName,
                'primary_key' => $primaryKey ?? 'id',
                'columns' => $columns,
                'foreign_keys' => $this->readPostgresForeignKeys($tableName),
            ];
        }

        return $tables;
    }

    private function isPrimaryPostgres(string $tableName, string $columnName): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT kcu.column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
             WHERE tc.constraint_type = 'PRIMARY KEY'
               AND tc.table_schema = 'public'
               AND tc.table_name = ?
               AND kcu.column_name = ?"
        );
        $stmt->execute([$tableName, $columnName]);
        return (bool)$stmt->fetchColumn();
    }

    private function readPostgresForeignKeys(string $tableName): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT kcu.column_name, ccu.table_name AS foreign_table_name, ccu.column_name AS foreign_column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name
              AND ccu.table_schema = tc.table_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_schema = 'public'
               AND tc.table_name = ?"
        );
        $stmt->execute([$tableName]);

        $foreignKeys = [];
        foreach ($stmt->fetchAll() as $row) {
            $foreignKeys[$row['column_name']] = [
                'table' => $row['foreign_table_name'],
                'column' => $row['foreign_column_name'],
                'label_column' => $this->guessLabelColumn($row['foreign_table_name']),
            ];
        }

        return $foreignKeys;
    }

    private function guessLabelColumn(string $tableName): string
    {
        try {
            $columns = $this->inspect([$tableName])[$tableName]['columns'] ?? [];
            foreach ($columns as $column) {
                $name = strtolower((string)$column['name']);
                $type = strtolower((string)$column['type']);
                if (!$column['primary'] && !str_contains($name, 'id') && (str_contains($type, 'char') || str_contains($type, 'text'))) {
                    return (string)$column['name'];
                }
            }
        } catch (\Throwable) {
        }

        return 'name';
    }

    private function quoteIdentifier(string $name): string
    {
        return $this->driver === 'mysql' ? '`' . str_replace('`', '``', $name) . '`' : '"' . str_replace('"', '""', $name) . '"';
    }
}
