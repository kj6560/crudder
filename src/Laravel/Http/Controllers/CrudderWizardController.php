<?php

declare(strict_types=1);

namespace Curdder\Laravel\Http\Controllers;

use Curdder\Generator\ConfigGenerator;
use Curdder\Runtime\Database;
use Curdder\Schema\DatabaseSchemaInspector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class CrudderWizardController extends Controller
{
    public function index(Request $request)
    {
        $status = (string)session('status', '');
        $config = $this->loadGeneratedConfig();
        if ($config === null || empty($config['resources'])) {
            return response()->view('curdder::wizard', $this->graphWizardViewData($request));
        }

        return response()->make($this->renderDashboard($config, $status !== '' ? $status : null));
    }

    public function generate(Request $request)
    {
        $inspector = $this->inspector();
        $graph = $this->parseGraphState((string)$request->input('graph_state', ''));
        $selectedTables = array_keys($graph['tables'] ?? []);
        if ($selectedTables === []) {
            return response()->view('curdder::wizard', $this->graphWizardViewData($request, ['Please drag at least one table into the workspace.']), 422);
        }

        $selectedSchema = $inspector->inspect($selectedTables);
        $generator = new ConfigGenerator($inspector);
        $config = $generator->makeConfig(
            schema: $selectedSchema,
            mode: 'laravel',
            database: $this->databaseConfig(),
            spec: ['name' => (string)$request->input('app_name', config('app.name', 'Curdder CRUD'))],
            joins: $this->graphRelationsToJoinRules($graph['relations'] ?? []),
            graph: $graph
        );

        $generator->writeConfigFile($this->generatedConfigPath(), $config);
        $models = $this->writeModels($selectedSchema, $graph['relations'] ?? []);

        return response()->make($this->renderSuccess($config, $models));
    }

    public function createTableForm(Request $request)
    {
        return response()->view('curdder::table-create', $this->tableCreateViewData($request));
    }

    public function storeTable(Request $request)
    {
        $tableName = trim((string)$request->input('table_name', ''));
        $rows = $this->columnRowsFromRequest($request);
        $errors = [];

        if ($tableName === '') {
            $errors[] = 'Table name is required.';
        } elseif (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tableName)) {
            $errors[] = 'Table name may only contain letters, numbers, and underscores.';
        }

        $columns = [];
        foreach ($rows as $row) {
            if (($row['name'] ?? '') === '' && ($row['type'] ?? '') === '') {
                continue;
            }

            $columnError = $this->validateColumnRow($row);
            if ($columnError !== null) {
                $errors[] = $columnError;
                continue;
            }

            $columns[] = $row;
        }

        if ($columns === []) {
            $errors[] = 'Add at least one column.';
        }

        if (Schema::hasTable($tableName)) {
            $errors[] = "Table {$tableName} already exists.";
        }

        if ($errors !== []) {
            return response()->view('curdder::table-create', $this->tableCreateViewData($request, $errors));
        }

        try {
            Schema::create($tableName, function (Blueprint $table) use ($columns): void {
                $primaryDefined = false;
                foreach ($columns as $definition) {
                    if (!empty($definition['primary'])) {
                        $primaryDefined = true;
                        break;
                    }
                }

                if (!$primaryDefined) {
                    $table->id();
                }

                foreach ($columns as $definition) {
                    $this->applyColumnDefinition($table, $definition);
                }
            });
        } catch (\Throwable $e) {
            return response()->view('curdder::table-create', $this->tableCreateViewData($request, [$e->getMessage()]));
        }

        return redirect()->route('crudder.index')->with('status', "Table {$tableName} created successfully.");
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

    private function buildJoinRules(Request $request): array
    {
        $leftTables = (array)$request->input('join_left_table', []);
        $leftColumns = (array)$request->input('join_left_column', []);
        $rightTables = (array)$request->input('join_right_table', []);
        $rightColumns = (array)$request->input('join_right_column', []);
        $labelColumns = (array)$request->input('join_label_column', []);

        $rules = [];
        $count = max(count($leftTables), count($leftColumns), count($rightTables), count($rightColumns), count($labelColumns));
        for ($i = 0; $i < $count; $i++) {
            $leftTable = trim((string)($leftTables[$i] ?? ''));
            $leftColumn = trim((string)($leftColumns[$i] ?? ''));
            $rightTable = trim((string)($rightTables[$i] ?? ''));
            $rightColumn = trim((string)($rightColumns[$i] ?? ''));
            $labelColumn = trim((string)($labelColumns[$i] ?? ''));

            if ($leftTable === '' || $leftColumn === '' || $rightTable === '' || $rightColumn === '') {
                continue;
            }

            $rule = $leftTable . '.' . $leftColumn . '=' . $rightTable . '.' . $rightColumn;
            if ($labelColumn !== '') {
                $rule .= ':' . $labelColumn;
            }

            $rules[] = $rule;
        }

        return $rules;
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

    private function graphWizardViewData(Request $request, array $errors = []): array
    {
        $schema = $this->inspector()->inspect();
        $graph = $this->parseGraphState((string)$request->input('graph_state', ''));

        return [
            'errors' => $errors,
            'schema' => $schema,
            'graph' => $graph,
            'relationTypes' => $this->relationTypes(),
            'appName' => (string)$request->input('app_name', config('app.name', 'Curdder CRUD')),
            'path' => (string)config('crudder.path', 'crudder'),
            'createTableUrl' => route('crudder.tables.create'),
            'generateUrl' => route('crudder.generate'),
            'modelsUrl' => route('crudder.index'),
        ];
    }

    private function relationTypes(): array
    {
        return [
            'belongsTo' => 'Belongs To',
            'hasOne' => 'Has One',
            'hasMany' => 'Has Many',
            'belongsToMany' => 'Belongs To Many',
        ];
    }

    private function parseGraphState(string $value): array
    {
        if ($value === '') {
            return [
                'tables' => [],
                'relations' => [],
            ];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [
                'tables' => [],
                'relations' => [],
            ];
        }

        $tables = [];
        foreach ((array)($decoded['tables'] ?? []) as $name => $table) {
            if (is_string($name) && is_array($table)) {
                $tables[$name] = [
                    'x' => (int)($table['x'] ?? 0),
                    'y' => (int)($table['y'] ?? 0),
                    'order' => (int)($table['order'] ?? 0),
                ];
            } elseif (is_array($table) && isset($table['name'])) {
                $tableName = (string)$table['name'];
                $tables[$tableName] = [
                    'x' => (int)($table['x'] ?? 0),
                    'y' => (int)($table['y'] ?? 0),
                    'order' => (int)($table['order'] ?? 0),
                ];
            }
        }

        $relations = [];
        foreach ((array)($decoded['relations'] ?? []) as $relation) {
            if (!is_array($relation)) {
                continue;
            }

            $fromTable = (string)($relation['from_table'] ?? '');
            $fromColumn = (string)($relation['from_column'] ?? '');
            $toTable = (string)($relation['to_table'] ?? '');
            $toColumn = (string)($relation['to_column'] ?? '');
            $type = (string)($relation['type'] ?? 'belongsTo');
            $labelColumn = (string)($relation['label_column'] ?? '');
            if ($fromTable === '' || $fromColumn === '' || $toTable === '' || $toColumn === '') {
                continue;
            }

            $relations[] = [
                'type' => in_array($type, array_keys($this->relationTypes()), true) ? $type : 'belongsTo',
                'from_table' => $fromTable,
                'from_column' => $fromColumn,
                'to_table' => $toTable,
                'to_column' => $toColumn,
                'label_column' => $labelColumn,
            ];
        }

        return [
            'tables' => $tables,
            'relations' => $relations,
        ];
    }

    private function graphRelationsToJoinRules(array $relations): array
    {
        $rules = [];
        foreach ($relations as $relation) {
            if (!is_array($relation)) {
                continue;
            }

            $fromTable = (string)($relation['from_table'] ?? '');
            $fromColumn = (string)($relation['from_column'] ?? '');
            $toTable = (string)($relation['to_table'] ?? '');
            $toColumn = (string)($relation['to_column'] ?? '');
            $labelColumn = (string)($relation['label_column'] ?? '');
            if ($fromTable === '' || $fromColumn === '' || $toTable === '' || $toColumn === '') {
                continue;
            }

            $rule = $fromTable . '.' . $fromColumn . '=' . $toTable . '.' . $toColumn;
            if ($labelColumn !== '') {
                $rule .= ':' . $labelColumn;
            }
            $rules[] = $rule;
        }

        return $rules;
    }

    private function writeModels(array $schema, array $relations): array
    {
        $modelsDir = base_path('app/Models');
        if (!is_dir($modelsDir) && !mkdir($modelsDir, 0777, true) && !is_dir($modelsDir)) {
            throw new RuntimeException("Unable to create models directory: {$modelsDir}");
        }

        $selectedTables = array_keys($schema);
        $written = [];
        foreach ($selectedTables as $tableName) {
            $className = $this->modelClassName($tableName);
            $path = $modelsDir . '/' . $className . '.php';
            if (is_file($path)) {
                $written[] = ['table' => $tableName, 'model' => $className, 'path' => $path, 'created' => false];
                continue;
            }

            $tableRelations = $this->relationsForTable($tableName, $relations);
            $content = $this->modelStub($tableName, $schema[$tableName], $tableRelations);
            file_put_contents($path, $content);
            $written[] = ['table' => $tableName, 'model' => $className, 'path' => $path, 'created' => true];
        }

        return $written;
    }

    private function relationsForTable(string $tableName, array $relations): array
    {
        $items = [];
        foreach ($relations as $relation) {
            if (!is_array($relation)) {
                continue;
            }

            if (($relation['from_table'] ?? '') === $tableName) {
                $items[] = $relation;
            }
        }

        return $items;
    }

    private function modelStub(string $tableName, array $table, array $relations): string
    {
        $className = $this->modelClassName($tableName);
        $fillable = [];
        foreach (($table['columns'] ?? []) as $column) {
            if (!empty($column['primary']) && !empty($column['auto_increment'])) {
                continue;
            }
            $fillable[] = (string)($column['name'] ?? '');
        }

        $methods = [];
        foreach ($relations as $relation) {
            $methods[] = $this->relationMethodStub($relation);
        }

        $fillableValues = array_values(array_filter($fillable));
        $fillableLine = $fillableValues !== [] ? "['" . implode("', '", $fillableValues) . "']" : '[]';
        $methodsBlock = implode("\n\n", array_filter($methods));

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class {$className} extends Model
{
    use HasFactory;

    protected \$table = '{$tableName}';

    protected \$fillable = {$fillableLine};

{$methodsBlock}
}
PHP;
    }

    private function relationMethodStub(array $relation): string
    {
        $type = (string)($relation['type'] ?? 'belongsTo');
        $fromTable = (string)($relation['from_table'] ?? '');
        $fromColumn = (string)($relation['from_column'] ?? '');
        $toTable = (string)($relation['to_table'] ?? '');
        $toColumn = (string)($relation['to_column'] ?? 'id');

        if ($fromTable === '' || $fromColumn === '' || $toTable === '') {
            return '';
        }

        $targetModel = $this->modelClassName($toTable);
        $methodName = $this->relationMethodName($type, $toTable);

        return match ($type) {
            'hasOne' => <<<PHP
    public function {$methodName}(): HasOne
    {
        return \$this->hasOne({$targetModel}::class, '{$toColumn}', '{$fromColumn}');
    }
PHP,
            'hasMany' => <<<PHP
    public function {$methodName}(): HasMany
    {
        return \$this->hasMany({$targetModel}::class, '{$toColumn}', '{$fromColumn}');
    }
PHP,
            'belongsToMany' => <<<PHP
    public function {$methodName}(): BelongsToMany
    {
        return \$this->belongsToMany({$targetModel}::class);
    }
PHP,
            default => <<<PHP
    public function {$methodName}(): BelongsTo
    {
        return \$this->belongsTo({$targetModel}::class, '{$fromColumn}', '{$toColumn}');
    }
PHP,
        };
    }

    private function relationMethodName(string $type, string $targetTable): string
    {
        return match ($type) {
            'hasMany', 'belongsToMany' => $this->pluralStudly($targetTable),
            default => $this->singularStudly($targetTable),
        };
    }

    private function modelClassName(string $tableName): string
    {
        return $this->studly($this->singularize($tableName));
    }

    private function studly(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);
        return str_replace(' ', '', $value);
    }

    private function singularize(string $value): string
    {
        if (str_ends_with($value, 'ies')) {
            return substr($value, 0, -3) . 'y';
        }

        if (str_ends_with($value, 'ses')) {
            return substr($value, 0, -2);
        }

        if (str_ends_with($value, 's') && !str_ends_with($value, 'ss')) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    private function singularStudly(string $value): string
    {
        return $this->studly($this->singularize($value));
    }

    private function pluralStudly(string $value): string
    {
        $value = $this->singularize($value);
        if (str_ends_with($value, 'y')) {
            $value = substr($value, 0, -1) . 'ies';
        } elseif (!str_ends_with($value, 's')) {
            $value .= 's';
        }

        return $this->studly($value);
    }

    private function tableCreateViewData(Request $request, array $errors = []): array
    {
        return [
            'errors' => $errors,
            'schema' => $this->inspector()->inspect(),
            'tableName' => (string)$request->input('table_name', ''),
            'columnRows' => $this->columnRowsFromRequest($request) ?: $this->defaultColumnRows(),
            'createTableUrl' => route('crudder.tables.store'),
            'backUrl' => route('crudder.index'),
        ];
    }

    private function joinRowsFromRequest(Request $request): array
    {
        $leftTables = (array)$request->input('join_left_table', []);
        $leftColumns = (array)$request->input('join_left_column', []);
        $rightTables = (array)$request->input('join_right_table', []);
        $rightColumns = (array)$request->input('join_right_column', []);
        $labelColumns = (array)$request->input('join_label_column', []);

        $rows = [];
        $count = max(count($leftTables), count($leftColumns), count($rightTables), count($rightColumns), count($labelColumns));
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'left_table' => (string)($leftTables[$i] ?? ''),
                'left_column' => (string)($leftColumns[$i] ?? ''),
                'right_table' => (string)($rightTables[$i] ?? ''),
                'right_column' => (string)($rightColumns[$i] ?? ''),
                'label_column' => (string)($labelColumns[$i] ?? ''),
            ];
        }

        return array_values(array_filter($rows, static fn (array $row): bool => array_filter($row, static fn (string $value): bool => $value !== '') !== []));
    }

    private function suggestJoinRows(array $schema): array
    {
        $rows = [];
        foreach ($schema as $leftTable => $table) {
            foreach (($table['foreign_keys'] ?? []) as $leftColumn => $fk) {
                $rows[] = [
                    'left_table' => (string)$leftTable,
                    'left_column' => (string)$leftColumn,
                    'right_table' => (string)($fk['table'] ?? ''),
                    'right_column' => (string)($fk['column'] ?? 'id'),
                    'label_column' => (string)($fk['label_column'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    private function columnRowsFromRequest(Request $request): array
    {
        $rows = (array)$request->input('columns', []);
        if ($rows === []) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized[] = [
                'name' => trim((string)($row['name'] ?? '')),
                'type' => trim((string)($row['type'] ?? 'string')),
                'length' => trim((string)($row['length'] ?? '255')),
                'precision' => trim((string)($row['precision'] ?? '')),
                'scale' => trim((string)($row['scale'] ?? '')),
                'default' => trim((string)($row['default'] ?? '')),
                'nullable' => !empty($row['nullable']),
                'primary' => !empty($row['primary']),
                'unique' => !empty($row['unique']),
                'auto_increment' => !empty($row['auto_increment']),
            ];
        }

        return $normalized;
    }

    private function defaultColumnRows(): array
    {
        return [
            [
                'name' => '',
                'type' => 'string',
                'length' => '255',
                'precision' => '',
                'scale' => '',
                'default' => '',
                'nullable' => false,
                'primary' => false,
                'unique' => false,
                'auto_increment' => false,
            ],
        ];
    }

    private function validateColumnRow(array $row): ?string
    {
        $name = trim((string)($row['name'] ?? ''));
        $type = trim((string)($row['type'] ?? ''));

        if ($name === '') {
            return 'Each column needs a name.';
        }

        if ($type === '') {
            return "Column {$name} needs a type.";
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            return "Column {$name} contains invalid characters.";
        }

        return null;
    }

    private function applyColumnDefinition(Blueprint $table, array $definition): void
    {
        $name = (string)$definition['name'];
        $type = strtolower((string)$definition['type']);
        $nullable = !empty($definition['nullable']);
        $primary = !empty($definition['primary']);
        $unique = !empty($definition['unique']);
        $autoIncrement = !empty($definition['auto_increment']);
        $length = (int)($definition['length'] ?? 255);
        $precision = (int)($definition['precision'] ?? 8);
        $scale = (int)($definition['scale'] ?? 2);
        $default = $definition['default'] ?? null;

        if ($primary && $autoIncrement && in_array($type, ['integer', 'biginteger', 'bigint', 'id'], true)) {
            $table->id($name);
            return;
        }

        $column = match ($type) {
            'string' => $table->string($name, $length > 0 ? $length : 255),
            'text' => $table->text($name),
            'integer' => $table->integer($name),
            'biginteger', 'bigint' => $table->bigInteger($name),
            'boolean' => $table->boolean($name),
            'date' => $table->date($name),
            'datetime' => $table->dateTime($name),
            'decimal' => $table->decimal($name, $precision > 0 ? $precision : 8, $scale >= 0 ? $scale : 2),
            'json' => $table->json($name),
            default => $table->string($name, $length > 0 ? $length : 255),
        };

        if ($nullable) {
            $column->nullable();
        }

        if ($default !== null && $default !== '') {
            $column->default($this->normalizeDefaultValue($type, $default));
        }

        if ($unique) {
            $column->unique();
        }

        if ($primary) {
            $column->primary();
        }
    }

    private function normalizeDefaultValue(string $type, mixed $value): mixed
    {
        return match ($type) {
            'integer', 'biginteger', 'bigint' => is_numeric($value) ? (int)$value : $value,
            'decimal' => is_numeric($value) ? (float)$value : $value,
            'boolean' => in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true),
            default => $value,
        };
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
        $tableNames = array_keys($schema);
        $schemaJson = htmlspecialchars(json_encode($this->schemaForClient($schema), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', ENT_QUOTES);
        $appName = htmlspecialchars((string)$request->input('app_name', config('app.name', 'Curdder CRUD')), ENT_QUOTES);
        $path = htmlspecialchars((string)config('crudder.path', 'crudder'), ENT_QUOTES);
        $selectedTables = array_values(array_filter((array)$request->input('tables', []), static fn ($value): bool => is_string($value) && $value !== ''));
        $joinRows = $this->joinRowsFromRequest($request);
        if ($joinRows === []) {
            $joinRows[] = [
                'left_table' => '',
                'left_column' => '',
                'right_table' => '',
                'right_column' => '',
                'label_column' => '',
            ];
        }

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
            $checked = in_array((string)$name, $selectedTables, true) ? ' checked' : '';
            $html .= '<details style="border:1px solid #e2e8f0;border-radius:18px;background:#fff;padding:18px">';
            $html .= '<summary style="cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:16px;list-style:none">';
            $html .= '<span style="display:flex;align-items:center;gap:10px"><input type="checkbox" name="tables[]" value="' . $nameEsc . '"' . $checked . '><strong style="font-size:18px">' . $label . '</strong></span>';
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
        $html .= '<div style="margin-top:28px;padding:20px;border:1px solid #e2e8f0;border-radius:20px;background:#f8fafc">';
        $html .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px">';
        $html .= '<div><h2 style="margin:0 0 6px;font-size:22px">Join builder</h2><p style="margin:0;color:#64748b">Pick tables and columns visually. Each row becomes one foreign key rule.</p></div>';
        $html .= '<button type="button" id="crudder-add-join" style="padding:10px 14px;border:0;border-radius:999px;background:#0f172a;color:#fff;font-weight:700;cursor:pointer">Add join</button>';
        $html .= '</div>';
        $html .= '<div id="crudder-join-rows" style="display:grid;gap:12px">';
        foreach ($joinRows as $index => $row) {
            $html .= $this->renderJoinRow($index, $row, $schema);
        }
        $html .= '</div>';
        $html .= '<div style="margin-top:14px;color:#64748b">Tip: click one of the suggested joins below to add it instantly.</div>';
        $html .= '<div id="crudder-suggestions" style="display:grid;gap:10px;margin-top:12px">';
        foreach ($schema as $leftTable => $table) {
            foreach (($table['foreign_keys'] ?? []) as $leftColumn => $fk) {
                $suggestion = [
                    'left_table' => (string)$leftTable,
                    'left_column' => (string)$leftColumn,
                    'right_table' => (string)$fk['table'],
                    'right_column' => (string)$fk['column'],
                    'label_column' => (string)($fk['label_column'] ?? ''),
                ];
                $suggestionJson = htmlspecialchars(json_encode($suggestion, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', ENT_QUOTES);
                $suggestionText = htmlspecialchars($leftTable . '.' . $leftColumn . ' -> ' . $fk['table'] . '.' . $fk['column'] . ' (' . ($fk['label_column'] ?? $fk['column']) . ')', ENT_QUOTES);
                $html .= '<button type="button" class="crudder-suggestion" data-join="' . $suggestionJson . '" style="text-align:left;padding:12px 14px;border:1px solid #cbd5e1;border-radius:14px;background:#fff;cursor:pointer">' . $suggestionText . '</button>';
            }
        }
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div style="margin-top:24px"><button type="submit" style="padding:12px 18px;border:0;border-radius:999px;background:#f59e0b;color:#111827;font-weight:700;cursor:pointer">Generate CRUD config</button></div>';
        $html .= '</form>';
        $html .= '<template id="crudder-join-template">' . $this->renderJoinRow('__INDEX__', ['left_table' => '', 'left_column' => '', 'right_table' => '', 'right_column' => '', 'label_column' => ''], $schema, true) . '</template>';
        $html .= '<script>';
        $html .= 'window.__CRUDDER_SCHEMA = ' . $schemaJson . ';';
        $html .= '(function(){';
        $html .= 'const schema = window.__CRUDDER_SCHEMA || {};';
        $html .= 'const rows = document.getElementById("crudder-join-rows");';
        $html .= 'const template = document.getElementById("crudder-join-template");';
        $html .= 'const addButton = document.getElementById("crudder-add-join");';
        $html .= 'let index = rows ? rows.children.length : 0;';
        $html .= 'function optionsForTables(selected){ return Object.keys(schema).map(name => `<option value="${name}"${selected===name?" selected":""}>${name}</option>`).join(""); }';
        $html .= 'function optionsForColumns(table, selected){ const cols = (schema[table] && schema[table].columns) ? schema[table].columns : []; return cols.map(col => `<option value="${col.name}"${selected===col.name?" selected":""}>${col.name}</option>`).join(""); }';
        $html .= 'function refreshRow(row){ const leftTable=row.querySelector("[data-role=left_table]"); const rightTable=row.querySelector("[data-role=right_table]"); const leftColumn=row.querySelector("[data-role=left_column]"); const rightColumn=row.querySelector("[data-role=right_column]"); const labelColumn=row.querySelector("[data-role=label_column]"); const updateColumns=(table, target, selected)=>{ target.innerHTML=`<option value="">Choose...</option>` + optionsForColumns(table, selected); }; if (leftTable && leftColumn) updateColumns(leftTable.value, leftColumn, leftColumn.getAttribute("data-selected") || ""); if (rightTable && rightColumn) updateColumns(rightTable.value, rightColumn, rightColumn.getAttribute("data-selected") || ""); if (rightTable && labelColumn){ const cols=(schema[rightTable.value] && schema[rightTable.value].columns)?schema[rightTable.value].columns:[]; labelColumn.innerHTML = `<option value="">Auto</option>` + cols.filter(c => c.name !== rightColumn.value).map(c => `<option value="${c.name}"${labelColumn.getAttribute("data-selected")==c.name?" selected":""}>${c.name}</option>`).join(""); } }';
        $html .= 'function wireRow(row){ row.querySelectorAll("[data-role=left_table],[data-role=right_table]").forEach(select => select.addEventListener("change", () => refreshRow(row))); row.querySelector("[data-action=remove]").addEventListener("click", () => row.remove()); refreshRow(row); }';
        $html .= 'function createRow(prefill){ const html = template.innerHTML.replaceAll("__INDEX__", String(index)); rows.insertAdjacentHTML("beforeend", html); const row = rows.lastElementChild; if (prefill){ row.querySelector("[data-role=left_table]").value = prefill.left_table || ""; row.querySelector("[data-role=left_column]").setAttribute("data-selected", prefill.left_column || ""); row.querySelector("[data-role=right_table]").value = prefill.right_table || ""; row.querySelector("[data-role=right_column]").setAttribute("data-selected", prefill.right_column || ""); row.querySelector("[data-role=label_column]").setAttribute("data-selected", prefill.label_column || ""); } wireRow(row); index++; }';
        $html .= 'if (addButton) addButton.addEventListener("click", () => createRow({}));';
        $html .= 'document.querySelectorAll(".crudder-suggestion").forEach(btn => btn.addEventListener("click", () => createRow(JSON.parse(btn.dataset.join))));';
        $html .= 'rows.querySelectorAll("[data-join-row]").forEach(wireRow);';
        $html .= '})();';
        $html .= '</script>';
        $html .= '</div>';

        return $html;
    }

    private function renderJoinRow(int|string $index, array $row, array $schema, bool $template = false): string
    {
        $tables = array_keys($schema);
        $leftTable = (string)($row['left_table'] ?? '');
        $leftColumn = (string)($row['left_column'] ?? '');
        $rightTable = (string)($row['right_table'] ?? '');
        $rightColumn = (string)($row['right_column'] ?? '');
        $labelColumn = (string)($row['label_column'] ?? '');
        $indexAttr = htmlspecialchars((string)$index, ENT_QUOTES);
        $leftOptions = $this->tableOptions($tables, $leftTable);
        $rightOptions = $this->tableOptions($tables, $rightTable);
        $leftColumnOptions = $this->columnOptions($schema[$leftTable]['columns'] ?? [], $leftColumn);
        $rightColumnOptions = $this->columnOptions($schema[$rightTable]['columns'] ?? [], $rightColumn);
        $labelColumnOptions = $this->labelColumnOptions($schema[$rightTable]['columns'] ?? [], $labelColumn);
        $style = 'display:grid;grid-template-columns:1.4fr 1fr 1.4fr 1fr 1fr auto;gap:10px;align-items:end;padding:14px;border:1px solid #cbd5e1;border-radius:16px;background:#fff';

        $html = '<div data-join-row style="' . $style . '">';
        $html .= $this->joinSelect('join_left_table[' . $indexAttr . ']', $leftOptions, 'From table', 'left_table', $template);
        $html .= $this->joinSelect('join_left_column[' . $indexAttr . ']', $leftColumnOptions, 'From column', 'left_column', $template);
        $html .= $this->joinSelect('join_right_table[' . $indexAttr . ']', $rightOptions, 'To table', 'right_table', $template);
        $html .= $this->joinSelect('join_right_column[' . $indexAttr . ']', $rightColumnOptions, 'To column', 'right_column', $template);
        $html .= $this->joinSelect('join_label_column[' . $indexAttr . ']', $labelColumnOptions, 'Label column', 'label_column', $template, true);
        $html .= '<button type="button" data-action="remove" style="padding:11px 14px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;cursor:pointer">Remove</button>';
        $html .= '</div>';

        return $html;
    }

    private function joinSelect(string $name, string $options, string $label, string $role, bool $template, bool $allowAuto = false): string
    {
        $attrs = $template ? ' data-selected=""' : '';
        $html = '<label style="display:grid;gap:6px;font-size:13px;color:#475569"><span>' . htmlspecialchars($label, ENT_QUOTES) . '</span>';
        $html .= '<select name="' . htmlspecialchars($name, ENT_QUOTES) . '" data-role="' . htmlspecialchars($role, ENT_QUOTES) . '"' . $attrs . ' style="padding:11px 12px;border:1px solid #cbd5e1;border-radius:12px;background:#fff">';
        $html .= '<option value="">' . ($allowAuto ? 'Auto' : 'Choose...') . '</option>';
        $html .= $options;
        $html .= '</select></label>';

        return $html;
    }

    private function tableOptions(array $tables, string $selected): string
    {
        $html = '';
        foreach ($tables as $table) {
            $value = htmlspecialchars((string)$table, ENT_QUOTES);
            $isSelected = (string)$table === $selected ? ' selected' : '';
            $html .= '<option value="' . $value . '"' . $isSelected . '>' . $value . '</option>';
        }

        return $html;
    }

    private function columnOptions(array $columns, string $selected): string
    {
        $html = '';
        foreach ($columns as $column) {
            $value = (string)($column['name'] ?? '');
            $valueEsc = htmlspecialchars($value, ENT_QUOTES);
            $isSelected = $value === $selected ? ' selected' : '';
            $html .= '<option value="' . $valueEsc . '"' . $isSelected . '>' . $valueEsc . '</option>';
        }

        return $html;
    }

    private function labelColumnOptions(array $columns, string $selected): string
    {
        $html = '';
        foreach ($columns as $column) {
            $value = (string)($column['name'] ?? '');
            $type = strtolower((string)($column['type'] ?? ''));
            $name = strtolower($value);
            if (($column['primary'] ?? false) || str_contains($name, 'id') || (!str_contains($type, 'char') && !str_contains($type, 'text') && !str_contains($type, 'json'))) {
                continue;
            }

            $valueEsc = htmlspecialchars($value, ENT_QUOTES);
            $isSelected = $value === $selected ? ' selected' : '';
            $html .= '<option value="' . $valueEsc . '"' . $isSelected . '>' . $valueEsc . '</option>';
        }

        return $html;
    }

    private function schemaForClient(array $schema): array
    {
        $client = [];
        foreach ($schema as $tableName => $table) {
            $client[$tableName] = [
                'columns' => array_values(array_map(
                    static fn (array $column): array => [
                        'name' => (string)($column['name'] ?? ''),
                        'type' => (string)($column['type'] ?? ''),
                    ],
                    $table['columns'] ?? []
                )),
            ];
        }

        return $client;
    }

    private function renderDashboard(array $config, ?string $status = null): string
    {
        $html = '<div style="max-width:1120px;margin:0 auto;padding:32px 20px 64px;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif">';
        if ($status) {
            $html .= '<div style="margin-bottom:16px;padding:14px 16px;border-radius:14px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46">' . htmlspecialchars($status, ENT_QUOTES) . '</div>';
        }
        $html .= '<div style="padding:24px;border-radius:24px;background:#0f172a;color:#e5e7eb;margin-bottom:24px">';
        $html .= '<h1 style="margin:0 0 8px;font-size:30px">' . htmlspecialchars((string)($config['app']['name'] ?? 'Curdder CRUD'), ENT_QUOTES) . '</h1>';
        $html .= '<p style="margin:0;color:#cbd5e1">Generated CRUD is ready. Choose a resource below.</p>';
        $html .= '<div style="margin-top:16px;display:flex;gap:12px;flex-wrap:wrap">';
        $html .= '<a href="' . htmlspecialchars(route('crudder.tables.create'), ENT_QUOTES) . '" style="display:inline-flex;align-items:center;justify-content:center;padding:12px 16px;border-radius:999px;background:#f59e0b;color:#111827;font-weight:700;text-decoration:none">Create Table</a>';
        $html .= '<a href="' . htmlspecialchars(route('crudder.index'), ENT_QUOTES) . '" style="display:inline-flex;align-items:center;justify-content:center;padding:12px 16px;border-radius:999px;background:rgba(255,255,255,.1);color:#fff;font-weight:700;text-decoration:none;border:1px solid rgba(255,255,255,.16)">Back to wizard</a>';
        $html .= '</div>';
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

    private function renderSuccess(array $config, array $models): string
    {
        $html = '<div style="max-width:960px;margin:0 auto;padding:32px 20px 64px;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif">';
        $html .= '<div style="padding:24px;border-radius:24px;background:#052e16;color:#dcfce7">';
        $html .= '<h1 style="margin:0 0 8px;font-size:30px">CRUD config generated</h1>';
        $html .= '<p style="margin:0 0 12px">Saved to <code>' . htmlspecialchars($this->generatedConfigPath(), ENT_QUOTES) . '</code></p>';
        $html .= '<div style="margin-bottom:16px">Resources: ' . htmlspecialchars(implode(', ', array_keys($config['resources'] ?? [])), ENT_QUOTES) . '</div>';
        if ($models !== []) {
            $html .= '<div style="margin-bottom:16px;display:grid;gap:8px">';
            $html .= '<div style="font-weight:700">Models</div>';
            foreach ($models as $model) {
                $status = !empty($model['created']) ? 'Created' : 'Skipped';
                $html .= '<div style="padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.08);color:#dcfce7">';
                $html .= htmlspecialchars($status . ': ' . ($model['model'] ?? '') . ' from ' . ($model['table'] ?? ''), ENT_QUOTES);
                $html .= '</div>';
            }
        }
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
