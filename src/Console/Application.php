<?php

declare(strict_types=1);

namespace Curdder\Console;

use Curdder\Generator\ConfigGenerator;
use Curdder\Schema\DatabaseSchemaInspector;
use RuntimeException;

final class Application
{
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'generate';
        $options = $this->parseOptions(array_slice($argv, 2));

        try {
            return match ($command) {
                'generate', 'make:crud', 'make' => $this->handleGenerate($options),
                'inspect' => $this->handleInspect($options),
                'help', '--help', '-h' => $this->printHelp(),
                default => $this->error("Unknown command: {$command}\n"),
            };
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }

    private function handleGenerate(array $options): int
    {
        $dsn = $this->requiredOption($options, 'dsn');
        $user = (string)($options['user'] ?? '');
        $password = (string)($options['password'] ?? '');
        $output = rtrim((string)($options['output'] ?? getcwd() . '/generated-crud'), '/');
        $specPath = $options['spec'] ?? null;
        $mode = (string)($options['mode'] ?? 'both');

        $spec = null;
        if (is_string($specPath) && $specPath !== '') {
            $spec = Spec\CrudSpecParser::fromFile($specPath);
        }

        $tables = $this->readListOption($options, 'table');
        $joins = $this->readListOption($options, 'join');

        $inspector = new DatabaseSchemaInspector($dsn, $user, $password);
        $generator = new ConfigGenerator($inspector);
        $result = $generator->generate([
            'output' => $output,
            'mode' => $mode,
            'tables' => $tables,
            'joins' => $joins,
            'spec' => $spec,
            'database' => [
                'dsn' => $dsn,
                'user' => $user,
                'password' => $password,
            ],
        ]);

        fwrite(STDOUT, "Generated CRUD project in {$result['output']}\n");
        fwrite(STDOUT, "Config: {$result['config_file']}\n");
        fwrite(STDOUT, "Front controllers: {$result['web_entry']}, {$result['api_entry']}\n");
        return 0;
    }

    private function handleInspect(array $options): int
    {
        $dsn = $this->requiredOption($options, 'dsn');
        $user = (string)($options['user'] ?? '');
        $password = (string)($options['password'] ?? '');
        $inspector = new DatabaseSchemaInspector($dsn, $user, $password);
        $schema = $inspector->inspect();

        foreach ($schema as $table) {
            fwrite(STDOUT, $table['name'] . "\n");
            foreach ($table['columns'] as $column) {
                $flag = $column['primary'] ? ' [pk]' : '';
                fwrite(STDOUT, "  - {$column['name']} ({$column['type']}){$flag}\n");
            }
        }

        return 0;
    }

    private function parseOptions(array $args): array
    {
        $options = [];
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $pair = substr($arg, 2);
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
            } else {
                $key = $pair;
                $next = $args[$i + 1] ?? null;
                if ($next !== null && !str_starts_with($next, '--')) {
                    $value = $next;
                    $i++;
                } else {
                    $value = true;
                }
            }

            if (in_array($key, ['table', 'join'], true)) {
                $options[$key] ??= [];
                $options[$key][] = $value;
                continue;
            }

            $options[$key] = $value;
        }

        return $options;
    }

    private function readListOption(array $options, string $key): array
    {
        $values = $options[$key] ?? [];
        if (!is_array($values)) {
            $values = [$values];
        }

        $items = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            foreach (explode(',', $value) as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $items[] = $piece;
                }
            }
        }

        return array_values(array_unique($items));
    }

    private function requiredOption(array $options, string $key): string
    {
        $value = $options[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException("Missing required option --{$key}");
        }

        return $value;
    }

    private function printHelp(): int
    {
        $message = <<<TXT
Curdder

Usage:
  curdder generate --dsn="mysql:host=127.0.0.1;dbname=app" --user=root --password=secret [options]
  curdder inspect --dsn="..." --user=... --password=...

Options:
  --output=PATH       Output directory for the generated CRUD project.
  --spec=FILE         JSON spec file with tables and relation overrides.
  --table=NAME        Limit generation to one or more tables.
  --join=RULE         Explicit join rule in the form posts.user_id=users.id:name.
  --mode=both|api|web Generate API, web, or both.

TXT;
        fwrite(STDOUT, $message);
        return 0;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, $message);
        fwrite(STDERR, "Run `curdder help` for usage.\n");
        return 1;
    }
}
