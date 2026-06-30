<?php

declare(strict_types=1);

namespace Curdder\Laravel\Http\Controllers;

use Curdder\Generator\ConfigGenerator;
use Curdder\Runtime\Database;
use Curdder\Schema\DatabaseSchemaInspector;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CrudderWizardController extends Controller
{
    public function index(Request $request)
    {
        $config = $this->loadGeneratedConfig();
        if ($config === null || empty($config['resources'])) {
            return response()->make($this->renderWizard($request));
        }

        return response()->make($this->renderDashboard($config));
    }

    public function generate(Request $request)
    {
        $inspector = $this->inspector();
        $schema = $inspector->inspect();

        $selectedTables = array_values(array_filter((array)$request->input('tables', []), static fn ($value): bool => is_string($value) && $value !== ''));
        if ($selectedTables === []) {
            return response()->make($this->renderWizard($request, ['Please choose at least one table.']), 422);
        }

        $joins = $this->normalizeJoins([
            ...((array)$request->input('joins', [])),
            ...$this->splitManualJoins((string)$request->input('manual_joins', '')),
        ]);

        $selectedSchema = $inspector->inspect($selectedTables);
        $generator = new ConfigGenerator($inspector);
        $config = $generator->makeConfig(
            schema: $selectedSchema,
            mode: 'laravel',
            database: $this->databaseConfig(),
            spec: ['name' => (string)$request->input('app_name', config('app.name', 'Curdder CRUD'))],
            joins: $joins
        );

        $generator->writeConfigFile($this->generatedConfigPath(), $config);

        return response()->make($this->renderSuccess($config));
    }

    public function showResource(string $resource)
    {
        $config = $this->loadGeneratedConfig();
        if ($config === null || !isset($config['resources'][$resource])) {
            abort(404);
        }

        $resourceConfig = $config['resources'][$resource];
        $rows = $this->database()->select($resourceConfig['table']);

        return response()->make($this->renderResourceIndex($resource, $resourceConfig, $rows, $config));
    }

    public function create(string $resource)
    {
        $config = $this->requireConfig();
        $resourceConfig = $this->requireResource($config, $resource);

        return response()->make($this->renderForm($resource, $resourceConfig, null, route('crudder.resource.store', ['resource' => $resource])));
    }

    public function store(Request $request, string $resource)
    {
        $config = $this->requireConfig();
        $resourceConfig = $this->requireResource($config, $resource);
        $data = $this->requestData($request, $resourceConfig, true);
        $this->database()->insert($resourceConfig['table'], $data);

        return redirect()->route('crudder.resource.show', ['resource' => $resource]);
    }

    public function edit(string $resource, mixed $id)
    {
        $config = $this->requireConfig();
        $resourceConfig = $this->requireResource($config, $resource);
        $row = $this->database()->find($resourceConfig['table'], (string)$resourceConfig['primary_key'], $id);
        if ($row === null) {
            abort(404);
        }

        return response()->make($this->renderForm($resource, $resourceConfig, $row, route('crudder.resource.update', ['resource' => $resource, 'id' => $id]), $id));
    }

    public function update(Request $request, string $resource, mixed $id)
    {
        $config = $this->requireConfig();
        $resourceConfig = $this->requireResource($config, $resource);
        $data = $this->requestData($request, $resourceConfig, false);
        $this->database()->update($resourceConfig['table'], (string)$resourceConfig['primary_key'], $id, $data);

        return redirect()->route('crudder.resource.show', ['resource' => $resource]);
    }

    public function destroy(string $resource, mixed $id)
    {
        $config = $this->requireConfig();
        $resourceConfig = $this->requireResource($config, $resource);
        $this->database()->delete($resourceConfig['table'], (string)$resourceConfig['primary_key'], $id);

        return redirect()->route('crudder.resource.show', ['resource' => $resource]);
    }

    private function inspector(): DatabaseSchemaInspector
    {
        return new DatabaseSchemaInspector(DB::connection()->getPdo());
    }

    private function database(): Database
    {
        return new Database(DB::connection()->getPdo());
    }

    private function generatedConfigPath(): string
    {
        return (string)config('crudder.generated_file', storage_path('app/crudder.php'));
    }

    private function loadGeneratedConfig(): ?array
    {
        $path = $this->generatedConfigPath();
        if (!is_file($path)) {
            return null;
        }

        $config = require $path;
        return is_array($config) ? $config : null;
    }

    private function requireConfig(): array
    {
        $config = $this->loadGeneratedConfig();
        if ($config === null) {
            throw new RuntimeException('No generated CRUD config found. Open /crudder and generate it first.');
        }

        return $config;
    }

    private function requireResource(array $config, string $resource): array
    {
        if (!isset($config['resources'][$resource])) {
            abort(404);
        }

        return $config['resources'][$resource];
    }

    private function databaseConfig(): array
    {
        $connection = config('database.default');
        $settings = (array)config("database.connections.{$connection}", []);

        return [
            'connection' => $connection,
            'driver' => $settings['driver'] ?? null,
            'host' => $settings['host'] ?? null,
            'port' => $settings['port'] ?? null,
            'database' => $settings['database'] ?? null,
            'username' => $settings['username'] ?? null,
            'password' => $settings['password'] ?? null,
        ];
    }

    private function splitManualJoins(string $manualJoins): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $manualJoins) ?: [])));
    }

    private function normalizeJoins(array $joins): array
    {
        $normalized = [];
        foreach ($joins as $join) {
            if (!is_string($join) || $join === '') {
                continue;
            }

            $normalized[] = $join;
        }

        return array_values(array_unique($normalized));
    }

    private function requestData(Request $request, array $resource, bool $forInsert): array
    {
        $data = [];
        foreach ($resource['columns'] as $column) {
            $name = (string)$column['name'];
            if ($column['primary'] && ($column['auto_increment'] ?? false)) {
                continue;
            }

            if (!$request->has($name)) {
                continue;
            }

            $value = $request->input($name);
            if ($value === '' && (($column['nullable'] ?? true) || $forInsert)) {
                $value = null;
            }

            $data[$name] = $value;
        }

        return $data;
    }

    private function renderWizard(Request $request, array $errors = []): string
    {
        $schema = $this->inspector()->inspect();
        $appName = htmlspecialchars((string)$request->input('app_name', config('app.name', 'Curdder CRUD')), ENT_QUOTES);
        $path = htmlspecialchars((string)config('crudder.path', 'crudder'), ENT_QUOTES);
        $html = '<div style="max-width:1200px;margin:0 auto;padding:32px 20px 64px;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif">';
        $html .= '<div style="padding:24px;border-radius:24px;background:#0f172a;color:#e5e7eb;margin-bottom:24px">';
        $html .= '<div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8">Curdder Wizard</div>';
        $html .= '<h1 style="margin:8px 0 10px;font-size:32px">Database table selector</h1>';
        $html .= '<p style="margin:0;color:#cbd5e1">Open <strong>/' . $path . '</strong> to choose tables and joins, then generate CRUD inside this Laravel app.</p>';
        $html .= '</div>';

        foreach ($errors as $error) {
            $html .= '<div style="margin-bottom:16px;padding:14px 16px;border-radius:14px;background:#7f1d1d;color:#fee2e2">' . htmlspecialchars((string)$error, ENT_QUOTES) . '</div>';
        }

        $html .= '<form method="post" action="' . htmlspecialchars(route('crudder.generate'), ENT_QUOTES) . '">';
        $html .= csrf_field();
        $html .= '<div style="display:grid;gap:16px;margin-bottom:24px">';
        $html .= '<label style="display:grid;gap:8px"><span style="color:#475569;font-weight:600">App name</span><input name="app_name" value="' . $appName . '" style="padding:12px 14px;border:1px solid #cbd5e1;border-radius:12px"></label>';
        $html .= '</div>';
        $html .= '<div style="display:grid;gap:16px">';

        foreach ($schema as $name => $table) {
            $nameEsc = htmlspecialchars((string)$name, ENT_QUOTES);
            $label = htmlspecialchars((string)($table['name'] ?? $name), ENT_QUOTES);
            $html .= '<details style="border:1px solid #e2e8f0;border-radius:18px;background:#fff;padding:18px">';
            $html .= '<summary style="cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:16px;list-style:none">';
            $html .= '<span style="display:flex;align-items:center;gap:10px"><input type="checkbox" name="tables[]" value="' . $nameEsc . '"><strong style="font-size:18px">' . $label . '</strong></span>';
            $html .= '<span style="color:#64748b">' . htmlspecialchars((string)($table['primary_key'] ?? 'id'), ENT_QUOTES) . '</span>';
            $html .= '</summary>';
            $html .= '<div style="margin-top:16px;display:grid;gap:12px">';
            $html .= '<div style="color:#64748b">Columns: ' . htmlspecialchars(implode(', ', array_map(static fn (array $column): string => (string)$column['name'], $table['columns'] ?? [])), ENT_QUOTES) . '</div>';

            $foreignKeys = $table['foreign_keys'] ?? [];
            if ($foreignKeys !== []) {
                $html .= '<div style="display:grid;gap:8px">';
                $html .= '<div style="font-weight:600">Suggested joins</div>';
                foreach ($foreignKeys as $column => $fk) {
                    $joinRule = (string)$name . '.' . (string)$column . '=' . (string)$fk['table'] . '.' . (string)$fk['column'] . ':' . (string)($fk['label_column'] ?? $fk['column']);
                    $html .= '<label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="joins[]" value="' . htmlspecialchars($joinRule, ENT_QUOTES) . '"><span><code>' . htmlspecialchars($joinRule, ENT_QUOTES) . '</code></span></label>';
                }
                $html .= '</div>';
            }

            $html .= '</div></details>';
        }

        $html .= '</div>';
        $html .= '<div style="margin-top:24px;display:grid;gap:8px">';
        $html .= '<label style="display:grid;gap:8px"><span style="color:#475569;font-weight:600">Manual joins</span><textarea name="manual_joins" rows="6" style="padding:12px 14px;border:1px solid #cbd5e1;border-radius:12px"></textarea></label>';
        $html .= '<div style="color:#64748b">One join per line, for example <code>posts.user_id=users.id:name</code></div>';
        $html .= '</div>';
        $html .= '<div style="margin-top:24px"><button type="submit" style="padding:12px 18px;border:0;border-radius:999px;background:#f59e0b;color:#111827;font-weight:700;cursor:pointer">Generate CRUD config</button></div>';
        $html .= '</form></div>';

        return $html;
    }

    private function renderDashboard(array $config): string
    {
        $html = '<div style="max-width:1120px;margin:0 auto;padding:32px 20px 64px;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif">';
        $html .= '<div style="padding:24px;border-radius:24px;background:#0f172a;color:#e5e7eb;margin-bottom:24px">';
        $html .= '<h1 style="margin:0 0 8px;font-size:30px">' . htmlspecialchars((string)($config['app']['name'] ?? 'Curdder CRUD'), ENT_QUOTES) . '</h1>';
        $html .= '<p style="margin:0;color:#cbd5e1">Generated CRUD is ready. Choose a resource below.</p>';
        $html .= '</div>';
        $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">';

        foreach (($config['resources'] ?? []) as $name => $resource) {
            $html .= '<a href="' . htmlspecialchars(route('crudder.resource.show', ['resource' => $name]), ENT_QUOTES) . '" style="padding:20px;border:1px solid #e2e8f0;border-radius:20px;background:#fff;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:6px">';
            $html .= '<strong>' . htmlspecialchars((string)($resource['label'] ?? $name), ENT_QUOTES) . '</strong>';
            $html .= '<span style="color:#64748b">' . htmlspecialchars((string)$resource['table'], ENT_QUOTES) . '</span>';
            $html .= '</a>';
        }

        $html .= '</div></div>';

        return $html;
    }

    private function renderResourceIndex(string $resourceName, array $resource, array $rows, array $config): string
    {
        $primaryKey = (string)$resource['primary_key'];
        $columns = $this->visibleColumns($resource);

        $html = '<div style="max-width:1200px;margin:0 auto;padding:32px 20px 64px;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif">';
        $html .= '<div style="display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:24px">';
        $html .= '<div><h1 style="margin:0 0 6px;font-size:28px">' . htmlspecialchars((string)($resource['label'] ?? $resourceName), ENT_QUOTES) . '</h1><p style="margin:0;color:#64748b">' . htmlspecialchars((string)$resource['table'], ENT_QUOTES) . '</p></div>';
        $html .= '<a href="' . htmlspecialchars(route('crudder.resource.create', ['resource' => $resourceName]), ENT_QUOTES) . '" style="padding:12px 16px;border-radius:999px;background:#f59e0b;color:#111827;font-weight:700;text-decoration:none">New ' . htmlspecialchars((string)($resource['label'] ?? $resourceName), ENT_QUOTES) . '</a>';
        $html .= '</div>';
        $html .= '<div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:20px;background:#fff;padding:8px">';
        $html .= '<table style="width:100%;border-collapse:collapse">';
        $html .= '<thead><tr>';

        foreach ($columns as $column) {
            $html .= '<th style="text-align:left;padding:14px 12px;border-bottom:1px solid #e2e8f0;color:#64748b">' . htmlspecialchars((string)$column, ENT_QUOTES) . '</th>';
        }

        $html .= '<th style="text-align:left;padding:14px 12px;border-bottom:1px solid #e2e8f0;color:#64748b">Actions</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $column) {
                $html .= '<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9">' . htmlspecialchars($this->presentValue($resource, $column, $row), ENT_QUOTES) . '</td>';
            }
            $html .= '<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;white-space:nowrap">';
            $html .= '<a href="' . htmlspecialchars(route('crudder.resource.edit', ['resource' => $resourceName, 'id' => $row[$primaryKey]]), ENT_QUOTES) . '">Edit</a> ';
            $html .= '<form method="post" action="' . htmlspecialchars(route('crudder.resource.destroy', ['resource' => $resourceName, 'id' => $row[$primaryKey]]), ENT_QUOTES) . '" style="display:inline" onsubmit="return confirm(\'Delete this record?\')">';
            $html .= csrf_field();
            $html .= method_field('DELETE');
            $html .= '<button type="submit" style="border:0;background:none;color:#dc2626;cursor:pointer;padding:0">Delete</button>';
            $html .= '</form>';
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        $html .= '<p style="margin-top:18px"><a href="' . htmlspecialchars(route('crudder.index'), ENT_QUOTES) . '">Back to dashboard</a></p>';
        $html .= '</div>';

        return $html;
    }

    private function renderForm(string $resourceName, array $resource, ?array $row, string $action, mixed $id = null): string
    {
        $html = '<div style="max-width:960px;margin:0 auto;padding:32px 20px 64px;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif">';
        $html .= '<div style="margin-bottom:20px"><h1 style="margin:0 0 6px;font-size:28px">' . htmlspecialchars((string)($resource['label'] ?? $resourceName), ENT_QUOTES) . '</h1><p style="margin:0;color:#64748b">' . htmlspecialchars((string)$resource['table'], ENT_QUOTES) . '</p></div>';
        $html .= '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '" style="display:grid;gap:16px;padding:20px;border:1px solid #e2e8f0;border-radius:20px;background:#fff">';
        $html .= csrf_field();
        if ($id !== null) {
            $html .= method_field('PUT');
        }

        foreach ($resource['columns'] as $column) {
            $name = (string)$column['name'];
            if ($column['primary'] && ($column['auto_increment'] ?? false)) {
                continue;
            }

            $value = $row[$name] ?? '';
            $html .= '<label style="display:grid;gap:8px"><span style="font-weight:600;color:#475569">' . htmlspecialchars($name, ENT_QUOTES) . '</span>';
            if (isset($resource['foreign_keys'][$name])) {
                $fk = $resource['foreign_keys'][$name];
                $options = $this->database()->pluck($fk['table'], $fk['column'], $fk['label_column'] ?? $fk['column']);
                $html .= '<select name="' . htmlspecialchars($name, ENT_QUOTES) . '" style="padding:12px 14px;border:1px solid #cbd5e1;border-radius:12px">';
                $html .= '<option value="">Choose...</option>';
                foreach ($options as $option) {
                    $selected = (string)$option['value'] === (string)$value ? ' selected' : '';
                    $html .= '<option value="' . htmlspecialchars((string)$option['value'], ENT_QUOTES) . '"' . $selected . '>' . htmlspecialchars((string)$option['label'], ENT_QUOTES) . '</option>';
                }
                $html .= '</select>';
            } else {
                $inputType = $this->inputTypeForColumn((string)$column['type']);
                $html .= '<input type="' . htmlspecialchars($inputType, ENT_QUOTES) . '" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES) . '" style="padding:12px 14px;border:1px solid #cbd5e1;border-radius:12px">';
            }
            $html .= '</label>';
        }

        $html .= '<div style="display:flex;gap:12px;justify-content:flex-end">';
        $html .= '<button type="submit" style="padding:12px 16px;border:0;border-radius:999px;background:#f59e0b;color:#111827;font-weight:700;cursor:pointer">Save</button>';
        $html .= '</div></form>';
        $html .= '<p style="margin-top:18px"><a href="' . htmlspecialchars(route('crudder.resource.show', ['resource' => $resourceName]), ENT_QUOTES) . '">Back</a></p>';
        $html .= '</div>';

        return $html;
    }

    private function renderSuccess(array $config): string
    {
        $html = '<div style="max-width:960px;margin:0 auto;padding:32px 20px 64px;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif">';
        $html .= '<div style="padding:24px;border-radius:24px;background:#052e16;color:#dcfce7">';
        $html .= '<h1 style="margin:0 0 8px;font-size:30px">CRUD config generated</h1>';
        $html .= '<p style="margin:0 0 12px">Saved to <code>' . htmlspecialchars($this->generatedConfigPath(), ENT_QUOTES) . '</code></p>';
        $html .= '<div style="margin-bottom:16px">Resources: ' . htmlspecialchars(implode(', ', array_keys($config['resources'] ?? [])), ENT_QUOTES) . '</div>';
        $html .= '<div><a href="' . htmlspecialchars(route('crudder.index'), ENT_QUOTES) . '" style="display:inline-block;padding:12px 16px;border-radius:999px;background:#dcfce7;color:#052e16;font-weight:700;text-decoration:none">Open dashboard</a></div>';
        $html .= '</div></div>';

        return $html;
    }

    private function visibleColumns(array $resource): array
    {
        return array_values(array_map(
            static fn (array $column): string => (string)$column['name'],
            array_filter($resource['columns'], static fn (array $column): bool => !($column['primary'] && ($column['auto_increment'] ?? false)))
        ));
    }

    private function presentValue(array $resource, string $column, array $row): string
    {
        $value = $row[$column] ?? '';
        if (isset($resource['foreign_keys'][$column])) {
            $fk = $resource['foreign_keys'][$column];
            $related = $this->database()->find($fk['table'], $fk['column'], $value);
            if ($related) {
                return (string)($related[$fk['label_column'] ?? $fk['column']] ?? $value);
            }
        }

        return (string)$value;
    }

    private function inputTypeForColumn(string $type): string
    {
        $type = strtolower($type);
        if (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            return 'number';
        }
        if (str_contains($type, 'date') && !str_contains($type, 'time')) {
            return 'date';
        }
        if (str_contains($type, 'time')) {
            return 'datetime-local';
        }
        return 'text';
    }
}
