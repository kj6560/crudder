<?php

declare(strict_types=1);

namespace Curdder\Runtime;

use PDO;
use RuntimeException;

final class Database
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromConfig(array $database): self
    {
        $dsn = (string)($database['dsn'] ?? '');
        if ($dsn === '') {
            throw new RuntimeException('Missing database DSN in config.');
        }

        $pdo = new PDO(
            $dsn,
            (string)($database['user'] ?? ''),
            (string)($database['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return new self($pdo);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function insert(string $table, array $data): string
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefixedParams($data));
        return (string)$this->pdo->lastInsertId();
    }

    public function update(string $table, string $pk, mixed $id, array $data): bool
    {
        $assignments = [];
        foreach (array_keys($data) as $column) {
            $assignments[] = $this->quoteIdentifier($column) . ' = :' . $column;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :__id',
            $this->quoteIdentifier($table),
            implode(', ', $assignments),
            $this->quoteIdentifier($pk)
        );

        $params = $this->prefixedParams($data);
        $params[':__id'] = $id;

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(string $table, string $pk, mixed $id): bool
    {
        $sql = sprintf('DELETE FROM %s WHERE %s = :id', $this->quoteIdentifier($table), $this->quoteIdentifier($pk));
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    public function find(string $table, string $pk, mixed $id): ?array
    {
        return $this->fetchOne(
            sprintf('SELECT * FROM %s WHERE %s = :id LIMIT 1', $this->quoteIdentifier($table), $this->quoteIdentifier($pk)),
            [':id' => $id]
        );
    }

    public function select(string $table, array $filters = [], ?string $orderBy = null): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->quoteIdentifier($table));
        $params = [];
        if ($filters !== []) {
            $clauses = [];
            foreach ($filters as $column => $value) {
                $clauses[] = $this->quoteIdentifier((string)$column) . ' = :' . $column;
                $params[':' . $column] = $value;
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if ($orderBy) {
            $sql .= ' ORDER BY ' . $orderBy;
        }

        return $this->fetchAll($sql, $params);
    }

    public function pluck(string $table, string $valueColumn, string $labelColumn, ?string $orderBy = null): array
    {
        $sql = sprintf(
            'SELECT %s AS value, %s AS label FROM %s',
            $this->quoteIdentifier($valueColumn),
            $this->quoteIdentifier($labelColumn),
            $this->quoteIdentifier($table)
        );

        if ($orderBy) {
            $sql .= ' ORDER BY ' . $orderBy;
        }

        return $this->fetchAll($sql);
    }

    private function prefixedParams(array $data): array
    {
        $params = [];
        foreach ($data as $key => $value) {
            $params[':' . $key] = $value;
        }
        return $params;
    }

    private function quoteIdentifier(string $name): string
    {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return $driver === 'mysql' ? '`' . str_replace('`', '``', $name) . '`' : '"' . str_replace('"', '""', $name) . '"';
    }
}
