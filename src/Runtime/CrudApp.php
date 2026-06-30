<?php

declare(strict_types=1);

namespace Curdder\Runtime;

use RuntimeException;

final class CrudApp
{
    private Database $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = Database::fromConfig($config['database'] ?? []);
    }

    public function handleWeb(): void
    {
        $resourceName = (string)($_GET['resource'] ?? '');
        if ($resourceName === '' || !isset($this->config['resources'][$resourceName])) {
            $this->renderDashboard();
            return;
        }

        $resource = $this->config['resources'][$resourceName];
        $action = (string)($_GET['action'] ?? 'index');

        if ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->store($resource);
            return;
        }

        if (in_array($action, ['update', 'delete'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->mutate($resource, $action);
            return;
        }

        $this->renderWebPage($resourceName, $resource, $action);
    }

    public function handleApi(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $resourceName = (string)($_GET['resource'] ?? '');
        if ($resourceName === '' || !isset($this->config['resources'][$resourceName])) {
            $this->json(['error' => 'Unknown resource'], 404);
            return;
        }

        $resource = $this->config['resources'][$resourceName];
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $id = $_GET['id'] ?? null;

        try {
            match ($method) {
                'GET' => $id !== null ? $this->json($this->db->find($resource['table'], $resource['primary_key'], $id) ?? [], 200) : $this->json($this->db->select($resource['table']), 200),
                'POST' => $this->json(['id' => $this->db->insert($resource['table'], $this->requestData($resource, true))], 201),
                'PUT', 'PATCH' => $this->json(['updated' => $this->db->update($resource['table'], $resource['primary_key'], $id, $this->requestData($resource, false))], 200),
                'DELETE' => $this->json(['deleted' => $this->db->delete($resource['table'], $resource['primary_key'], $id)], 200),
                default => $this->json(['error' => 'Method not allowed'], 405),
            };
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function renderDashboard(): void
    {
        $title = $this->config['app']['name'] ?? 'Curdder CRUD';
        $resources = $this->config['resources'] ?? [];

        echo $this->page($title, function (): string use ($resources) {
            $html = '<div class="hero"><h1>Generated CRUD</h1><p>Choose a resource to manage its records via web or API.</p></div>';
            $html .= '<div class="cards">';
            foreach ($resources as $name => $resource) {
                $html .= '<a class="card" href="?resource=' . htmlspecialchars((string)$name, ENT_QUOTES) . '">';
                $html .= '<strong>' . htmlspecialchars((string)($resource['label'] ?? $name), ENT_QUOTES) . '</strong>';
                $html .= '<span>' . htmlspecialchars((string)$resource['table'], ENT_QUOTES) . '</span>';
                $html .= '</a>';
            }
            $html .= '</div>';
            return $html;
        });
    }

    private function renderWebPage(string $resourceName, array $resource, string $action): void
    {
        $title = $resource['label'] ?? $resourceName;
        $primaryKey = (string)$resource['primary_key'];
        $table = (string)$resource['table'];

        if ($action === 'create') {
            echo $this->page($title . ' - Create', fn () => $this->formHtml($resourceName, $resource, 'store'));
            return;
        }

        if ($action === 'edit') {
            $id = $_GET['id'] ?? null;
            $row = $id !== null ? $this->db->find($table, $primaryKey, $id) : null;
            echo $this->page($title . ' - Edit', fn () => $this->formHtml($resourceName, $resource, 'update', $row));
            return;
        }

        if ($action === 'show') {
            $id = $_GET['id'] ?? null;
            $row = $id !== null ? $this->db->find($table, $primaryKey, $id) : null;
            echo $this->page($title . ' - Details', fn () => $this->showHtml($resourceName, $resource, $row));
            return;
        }

        $rows = $this->db->select($table);
        echo $this->page($title, fn () => $this->indexHtml($resourceName, $resource, $rows));
    }

    private function indexHtml(string $resourceName, array $resource, array $rows): string
    {
        $label = $resource['label'] ?? $resourceName;
        $primaryKey = (string)$resource['primary_key'];
        $columns = $this->visibleColumns($resource);
        $html = '<div class="toolbar">';
        $html .= '<div><h1>' . htmlspecialchars((string)$label, ENT_QUOTES) . '</h1><p>' . htmlspecialchars((string)$resource['table'], ENT_QUOTES) . '</p></div>';
        $html .= '<a class="button" href="?resource=' . urlencode($resourceName) . '&action=create">New ' . htmlspecialchars((string)$label, ENT_QUOTES) . '</a>';
        $html .= '</div>';
        $html .= '<div class="table-wrap"><table><thead><tr>';
        foreach ($columns as $column) {
            $html .= '<th>' . htmlspecialchars((string)$column, ENT_QUOTES) . '</th>';
        }
        $html .= '<th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $column) {
                $html .= '<td>' . htmlspecialchars($this->presentValue($resource, $column, $row), ENT_QUOTES) . '</td>';
            }
            $html .= '<td class="actions">';
            $html .= '<a href="?resource=' . urlencode($resourceName) . '&action=show&id=' . urlencode((string)$row[$primaryKey]) . '">View</a> ';
            $html .= '<a href="?resource=' . urlencode($resourceName) . '&action=edit&id=' . urlencode((string)$row[$primaryKey]) . '">Edit</a> ';
            $html .= '<form method="post" action="?resource=' . urlencode($resourceName) . '&action=delete&id=' . urlencode((string)$row[$primaryKey]) . '" onsubmit="return confirm(\'Delete this record?\')"><button type="submit">Delete</button></form>';
            $html .= '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    private function showHtml(string $resourceName, array $resource, ?array $row): string
    {
        if (!$row) {
            return '<p>Record not found.</p>';
        }

        $primaryKey = (string)$resource['primary_key'];
        $html = '<div class="toolbar"><div><h1>Details</h1><p>' . htmlspecialchars((string)$resource['table'], ENT_QUOTES) . '</p></div>';
        $html .= '<a class="button secondary" href="?resource=' . urlencode($resourceName) . '">Back</a></div>';
        $html .= '<div class="details">';
        foreach ($resource['columns'] as $column) {
            $name = (string)$column['name'];
            $html .= '<div class="detail"><span>' . htmlspecialchars($name, ENT_QUOTES) . '</span><strong>' . htmlspecialchars($this->presentValue($resource, $name, $row), ENT_QUOTES) . '</strong></div>';
        }
        $html .= '</div>';
        $html .= '<p><a href="?resource=' . urlencode($resourceName) . '&action=edit&id=' . urlencode((string)$row[$primaryKey]) . '">Edit this record</a></p>';
        return $html;
    }

    private function formHtml(string $resourceName, array $resource, string $submitAction, ?array $row = null): string
    {
        $primaryKey = (string)$resource['primary_key'];
        $action = '?resource=' . urlencode($resourceName) . '&action=' . urlencode($submitAction);
        if ($submitAction === 'update' && $row !== null) {
            $action .= '&id=' . urlencode((string)$row[$primaryKey]);
        }

        $html = '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '" class="form-grid">';
        foreach ($resource['columns'] as $column) {
            $name = (string)$column['name'];
            if ($column['primary'] && ($column['auto_increment'] ?? false)) {
                continue;
            }

            $value = $row[$name] ?? '';
            $html .= '<label><span>' . htmlspecialchars($name, ENT_QUOTES) . '</span>';
            if (isset($resource['foreign_keys'][$name])) {
                $fk = $resource['foreign_keys'][$name];
                $options = $this->db->pluck($fk['table'], $fk['column'], $fk['label_column'] ?? $fk['column']);
                $html .= '<select name="' . htmlspecialchars($name, ENT_QUOTES) . '">';
                $html .= '<option value="">Choose...</option>';
                foreach ($options as $option) {
                    $selected = (string)$option['value'] === (string)$value ? ' selected' : '';
                    $html .= '<option value="' . htmlspecialchars((string)$option['value'], ENT_QUOTES) . '"' . $selected . '>' . htmlspecialchars((string)$option['label'], ENT_QUOTES) . '</option>';
                }
                $html .= '</select>';
            } else {
                $inputType = $this->inputTypeForColumn((string)$column['type']);
                $html .= '<input type="' . htmlspecialchars($inputType, ENT_QUOTES) . '" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES) . '">';
            }
            $html .= '</label>';
        }
        $html .= '<div class="form-actions"><button type="submit">Save</button></div></form>';
        return $html;
    }

    private function mutate(array $resource, string $action): void
    {
        $table = (string)$resource['table'];
        $primaryKey = (string)$resource['primary_key'];
        $id = $_GET['id'] ?? null;
        if ($action === 'delete') {
            if ($id !== null) {
                $this->db->delete($table, $primaryKey, $id);
            }
            $this->redirect('?resource=' . urlencode($table));
            return;
        }

        $data = $this->requestData($resource, false);
        if ($id !== null) {
            $this->db->update($table, $primaryKey, $id, $data);
        }

        $this->redirect('?resource=' . urlencode($table));
    }

    private function store(array $resource): void
    {
        $this->db->insert((string)$resource['table'], $this->requestData($resource, true));
        $this->redirect('?resource=' . urlencode((string)$resource['table']));
    }

    private function requestData(array $resource, bool $forInsert): array
    {
        $data = [];
        foreach ($resource['columns'] as $column) {
            $name = (string)$column['name'];
            if ($column['primary'] && ($column['auto_increment'] ?? false)) {
                continue;
            }
            if (!array_key_exists($name, $_POST)) {
                continue;
            }
            $value = $_POST[$name];
            if ($value === '' && (($column['nullable'] ?? true) || $forInsert)) {
                $value = null;
            }
            $data[$name] = $value;
        }
        return $data;
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
            $related = $this->db->find($fk['table'], $fk['column'], $value);
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

    private function page(string $title, callable $content): string
    {
        $body = $content();
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$this->escape($title)}</title>
  <style>
    :root { --bg: #0f172a; --panel: #111827; --card: #1f2937; --accent: #f59e0b; --text: #e5e7eb; --muted: #94a3b8; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: radial-gradient(circle at top, #1e293b 0, var(--bg) 45%); color: var(--text); min-height: 100vh; }
    a { color: inherit; text-decoration: none; }
    .page { max-width: 1120px; margin: 0 auto; padding: 32px 20px 64px; }
    .hero, .toolbar, .details, .form-grid, .table-wrap, .cards { margin-bottom: 24px; }
    .hero { padding: 24px; border: 1px solid rgba(255,255,255,.08); border-radius: 24px; background: rgba(15,23,42,.72); backdrop-filter: blur(16px); }
    .hero h1, .toolbar h1 { margin: 0 0 8px; font-size: 2rem; }
    .hero p, .toolbar p { margin: 0; color: var(--muted); }
    .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
    .card, .detail, .table-wrap, form { border: 1px solid rgba(255,255,255,.08); border-radius: 20px; background: rgba(17,24,39,.86); }
    .card { padding: 20px; display: flex; flex-direction: column; gap: 6px; min-height: 108px; transition: transform .2s ease, border-color .2s ease; }
    .card:hover { transform: translateY(-2px); border-color: rgba(245,158,11,.6); }
    .card span, .muted { color: var(--muted); }
    .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
    .button, button { background: linear-gradient(135deg, var(--accent), #fb7185); color: #111827; border: 0; padding: 12px 16px; border-radius: 999px; font-weight: 700; cursor: pointer; }
    .button.secondary { background: rgba(148,163,184,.18); color: var(--text); }
    .table-wrap { overflow-x: auto; padding: 8px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 14px 12px; border-bottom: 1px solid rgba(255,255,255,.06); text-align: left; vertical-align: top; }
    th { color: var(--muted); font-weight: 600; font-size: .9rem; }
    td.actions { white-space: nowrap; }
    td.actions a, td.actions button { margin-right: 8px; }
    .details { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
    .detail { padding: 16px; display: grid; gap: 8px; }
    .detail span { color: var(--muted); font-size: .9rem; }
    .form-grid { display: grid; gap: 16px; padding: 20px; }
    .form-grid label { display: grid; gap: 8px; }
    .form-grid input, .form-grid select, .form-grid textarea { width: 100%; padding: 12px 14px; border-radius: 14px; border: 1px solid rgba(255,255,255,.08); background: rgba(15,23,42,.8); color: var(--text); }
    .form-actions { display: flex; justify-content: flex-end; }
    @media (max-width: 720px) { .toolbar { flex-direction: column; align-items: flex-start; } .page { padding: 20px 16px 48px; } }
  </style>
</head>
<body>
  <main class="page">
    {$body}
  </main>
</body>
</html>
HTML;
    }

    private function redirect(string $location): void
    {
        header('Location: ' . $location);
        exit;
    }

    private function json(array $payload, int $status): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
