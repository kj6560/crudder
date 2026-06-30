<?php

declare(strict_types=1);

namespace Curdder\Generator;

use Curdder\Schema\DatabaseSchemaInspector;
use RuntimeException;

final class ConfigGenerator
{
    public function __construct(
        private readonly DatabaseSchemaInspector $inspector
    ) {
    }

    public function generate(array $options): array
    {
        $output = rtrim((string)($options['output'] ?? ''), '/');
        if ($output === '') {
            throw new RuntimeException('Missing output directory.');
        }

        if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
            throw new RuntimeException("Unable to create output directory: {$output}");
        }

        $schema = $options['spec']['schema'] ?? $this->inspector->inspect($options['tables'] ?? []);
        $config = $this->makeConfig(
            schema: $schema,
            mode: (string)($options['mode'] ?? 'both'),
            database: $options['database'] ?? [],
            spec: $options['spec'] ?? null,
            joins: $options['joins'] ?? []
        );

        $configFile = $output . '/crudder.php';
        $this->writeConfigFile($configFile, $config);

        $publicDir = $output . '/public';
        if (!is_dir($publicDir) && !mkdir($publicDir, 0777, true) && !is_dir($publicDir)) {
            throw new RuntimeException("Unable to create public directory: {$publicDir}");
        }

        $webEntry = $publicDir . '/index.php';
        $apiEntry = $publicDir . '/api.php';
        file_put_contents($webEntry, $this->webEntryStub());
        file_put_contents($apiEntry, $this->apiEntryStub());

        file_put_contents($output . '/README.md', $this->projectReadmeStub());

        return [
            'output' => $output,
            'config_file' => $configFile,
            'web_entry' => $webEntry,
            'api_entry' => $apiEntry,
        ];
    }

    public function makeConfig(array $schema, string $mode, array $database, ?array $spec, array $joins): array
    {
        $resources = [];
        foreach ($schema as $tableName => $table) {
            $resources[$tableName] = [
                'table' => $tableName,
                'label' => $table['label'] ?? $this->humanize($tableName),
                'primary_key' => $table['primary_key'] ?? 'id',
                'columns' => $table['columns'] ?? [],
                'foreign_keys' => $table['foreign_keys'] ?? [],
                'search_columns' => $table['search_columns'] ?? $this->defaultSearchColumns($table['columns'] ?? []),
            ];
        }

        $resources = $this->applyJoinOverrides($resources, $joins);
        $resources = $this->applySpecOverrides($resources, $spec);

        return [
            'app' => [
                'name' => is_array($spec) ? ($spec['name'] ?? 'Curdder CRUD') : 'Curdder CRUD',
                'mode' => $mode,
            ],
            'database' => $database,
            'resources' => $resources,
        ];
    }

    public function writeConfigFile(string $path, array $config): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create config directory: {$directory}");
        }

        file_put_contents($path, "<?php\n\nreturn " . var_export($config, true) . ";\n");
    }

    private function applyJoinOverrides(array $resources, array $joins): array
    {
        foreach ($joins as $join) {
            $parsed = $this->parseJoinRule($join);
            if ($parsed === null) {
                continue;
            }

            [$table, $column, $refTable, $refColumn, $labelColumn] = $parsed;
            if (!isset($resources[$table])) {
                continue;
            }

            $resources[$table]['foreign_keys'][$column] = [
                'table' => $refTable,
                'column' => $refColumn,
                'label_column' => $labelColumn,
            ];
        }

        return $resources;
    }

    private function applySpecOverrides(array $resources, ?array $spec): array
    {
        if (!$spec) {
            return $resources;
        }

        if (isset($spec['tables']) && is_array($spec['tables'])) {
            $filtered = [];
            foreach ($spec['tables'] as $tableSpec) {
                if (is_string($tableSpec)) {
                    if (isset($resources[$tableSpec])) {
                        $filtered[$tableSpec] = $resources[$tableSpec];
                    }
                    continue;
                }

                if (!is_array($tableSpec) || empty($tableSpec['name'])) {
                    continue;
                }

                $name = (string)$tableSpec['name'];
                if (!isset($resources[$name])) {
                    continue;
                }

                $filtered[$name] = array_replace($resources[$name], array_filter([
                    'label' => $tableSpec['label'] ?? null,
                    'search_columns' => $tableSpec['search_columns'] ?? null,
                ], static fn ($value) => $value !== null));
            }

            if ($filtered !== []) {
                $resources = $filtered;
            }
        }

        if (isset($spec['relations']) && is_array($spec['relations'])) {
            foreach ($spec['relations'] as $relation) {
                if (!is_array($relation)) {
                    continue;
                }

                $table = (string)($relation['table'] ?? '');
                $column = (string)($relation['column'] ?? '');
                $references = (string)($relation['references'] ?? '');
                if ($table === '' || $column === '' || $references === '') {
                    continue;
                }

                [$refTable, $refColumn] = array_pad(explode('.', $references, 2), 2, 'id');
                if (!isset($resources[$table])) {
                    continue;
                }

                $resources[$table]['foreign_keys'][$column] = [
                    'table' => $refTable,
                    'column' => $refColumn,
                    'label_column' => $relation['label_column'] ?? null,
                ];
            }
        }

        return $resources;
    }

    private function defaultSearchColumns(array $columns): array
    {
        $searchable = [];
        foreach ($columns as $column) {
            if (empty($column['primary']) && !$this->isSystemColumn((string)($column['name'] ?? ''))) {
                $type = strtolower((string)($column['type'] ?? ''));
                if (str_contains($type, 'char') || str_contains($type, 'text') || str_contains($type, 'json')) {
                    $searchable[] = (string)$column['name'];
                }
            }
        }

        return $searchable !== [] ? $searchable : array_values(array_map(
            static fn (array $column): string => (string)$column['name'],
            array_filter($columns, static fn (array $column): bool => empty($column['primary']))
        ));
    }

    private function isSystemColumn(string $name): bool
    {
        return in_array($name, ['created_at', 'updated_at', 'deleted_at'], true);
    }

    private function parseJoinRule(string $rule): ?array
    {
        if (!str_contains($rule, '=')) {
            return null;
        }

        [$left, $right] = explode('=', $rule, 2);
        [$table, $column] = array_pad(explode('.', $left, 2), 2, null);
        [$refTable, $refColumnAndLabel] = array_pad(explode('.', $right, 2), 2, null);
        if (!$table || !$column || !$refTable || !$refColumnAndLabel) {
            return null;
        }

        $refColumn = $refColumnAndLabel;
        $labelColumn = null;
        if (str_contains($refColumnAndLabel, ':')) {
            [$refColumn, $labelColumn] = explode(':', $refColumnAndLabel, 2);
        }

        return [$table, $column, $refTable, $refColumn, $labelColumn];
    }

    private function humanize(string $value): string
    {
        $value = str_replace(['_', '-'], ' ', $value);
        return ucwords($value);
    }

    private function webEntryStub(): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

require dirname(__DIR__, 1) . '/vendor/autoload.php';

\$config = require dirname(__DIR__, 1) . '/crudder.php';
(new \\Curdder\\Runtime\\CrudApp(\$config))->handleWeb();
PHP;
    }

    private function apiEntryStub(): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

require dirname(__DIR__, 1) . '/vendor/autoload.php';

\$config = require dirname(__DIR__, 1) . '/crudder.php';
(new \\Curdder\\Runtime\\CrudApp(\$config))->handleApi();
PHP;
    }

    private function projectReadmeStub(): string
    {
        return <<<MD
# Generated CRUD App

This project was generated by Curdder.

Run a PHP server from the `public/` directory:

```bash
php -S 127.0.0.1:8000 -t public
```

Web CRUD:
- `/?resource=users`

API CRUD:
- `/api.php?resource=users`

MD;
    }
}
