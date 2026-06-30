@extends('curdder::layout')

@section('title', 'Curdder Wizard')

@section('content')
    <style>
        .shell {
            max-width: 1640px;
        }
        .wizard-shell {
            display: grid;
            gap: 18px;
        }
        .wizard-hero {
            display: grid;
            gap: 12px;
        }
        .wizard-grid {
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            gap: 16px;
            align-items: start;
        }
        .workspace-panel {
            min-height: 84vh;
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 24px;
            background:
                radial-gradient(circle at top left, rgba(245, 158, 11, 0.08), transparent 28%),
                linear-gradient(180deg, #fff, #f8fafc);
        }
        .workspace-toolbar {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .selected-table-strip {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding: 0 12px 12px;
            scrollbar-gutter: stable both-edges;
        }
        .selected-table-card {
            flex: 0 0 260px;
            border: 1px solid #dbe3ee;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
            padding: 14px;
            cursor: pointer;
        }
        .selected-table-card.active {
            border-color: rgba(245, 158, 11, 0.85);
            box-shadow: 0 14px 28px rgba(245, 158, 11, 0.12);
            background: #fffdf7;
        }
        .selected-table-card strong {
            display: block;
            margin-bottom: 4px;
        }
        .selected-table-card .meta {
            font-size: .88rem;
            color: var(--muted);
        }
        .workspace-canvas {
            position: relative;
            min-height: 70vh;
            border-radius: 20px;
            margin: 0 12px 12px;
            overflow: auto;
            background:
                linear-gradient(transparent 95%, rgba(148, 163, 184, 0.16) 96%),
                linear-gradient(90deg, transparent 95%, rgba(148, 163, 184, 0.16) 96%);
            background-size: 56px 56px;
            scrollbar-gutter: stable both-edges;
        }
        .workspace-stage {
            position: relative;
            min-width: 2200px;
            min-height: 860px;
        }
        .workspace-stage::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(transparent 95%, rgba(148, 163, 184, 0.16) 96%),
                linear-gradient(90deg, transparent 95%, rgba(148, 163, 184, 0.16) 96%);
            background-size: 56px 56px;
            opacity: .22;
        }
        .workspace-empty {
            position: absolute;
            inset: 24px;
            display: grid;
            place-items: center;
            text-align: center;
            color: var(--muted);
            border: 1px dashed rgba(100, 116, 139, 0.25);
            border-radius: 20px;
            pointer-events: none;
            background: rgba(255, 255, 255, 0.36);
        }
        .workspace-svg {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            overflow: visible;
            pointer-events: none;
            z-index: 1;
        }
        .workspace-card {
            position: absolute;
            width: 300px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
            overflow: hidden;
            z-index: 2;
        }
        .workspace-card.active {
            outline: 2px solid rgba(245, 158, 11, 0.45);
            box-shadow: 0 18px 42px rgba(245, 158, 11, 0.14);
        }
        .card-head {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: center;
            padding: 14px 14px 12px;
            border-bottom: 1px solid #eef2f7;
            background: linear-gradient(180deg, #fff, #f8fafc);
            cursor: grab;
            user-select: none;
        }
        .card-title {
            display: grid;
            gap: 2px;
        }
        .card-title strong {
            font-size: 1rem;
        }
        .card-title span {
            font-size: .84rem;
            color: var(--muted);
        }
        .card-body {
            display: grid;
            gap: 12px;
            padding: 12px 14px 14px;
        }
        .column-list {
            display: grid;
            gap: 8px;
        }
        .column-pill {
            width: 100%;
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 10px;
            align-items: center;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            padding: 10px 12px;
            text-align: left;
            cursor: pointer;
        }
        .column-pill:hover {
            border-color: rgba(245, 158, 11, 0.55);
            box-shadow: 0 8px 18px rgba(245, 158, 11, 0.08);
        }
        .column-pill.active {
            border-color: rgba(245, 158, 11, 0.9);
            background: #fff7ed;
        }
        .column-pill .column-anchor {
            width: 12px;
            height: 12px;
            border-radius: 999px;
            border: 2px solid #f59e0b;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        }
        .column-pill.active .column-anchor {
            background: #f59e0b;
        }
        .column-pill small {
            color: var(--muted);
        }
        .relations-panel {
            display: grid;
            gap: 10px;
            padding-top: 4px;
            border-top: 1px solid #eef2f7;
        }
        .workspace-settings-inline {
            display: grid;
            gap: 12px;
            padding: 16px 18px 18px;
            border-top: 1px solid #eef2f7;
            background: linear-gradient(180deg, #fff, #f8fafc);
        }
        .settings-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .settings-row {
            display: grid;
            gap: 8px;
        }
        .settings-row label {
            font-size: 12px;
            color: #475569;
        }
        .settings-row select,
        .settings-row input {
            width: 100%;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            padding: 10px 12px;
            background: #fff;
        }
        .selected-relations {
            display: grid;
            gap: 10px;
        }
        .selected-relations .relation-row {
            margin: 0;
        }
        .relations-head {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: center;
        }
        .relations-list {
            display: grid;
            gap: 10px;
        }
        .relation-row {
            display: grid;
            gap: 10px;
            padding: 12px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            background: #fbfdff;
        }
        .relation-row .mini-grid {
            display: grid;
            gap: 8px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .relation-row .mini-grid.three {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        .relation-row label {
            font-size: 12px;
            color: #475569;
        }
        .relation-row select,
        .relation-row input {
            width: 100%;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            padding: 10px 12px;
            background: #fff;
        }
        .palette-list {
            display: grid;
            gap: 10px;
            max-height: 82vh;
            overflow: auto;
            padding-right: 4px;
        }
        .palette-card {
            padding: 14px;
            border-radius: 18px;
            border: 1px solid #dbe3ee;
            background: #fff;
            cursor: grab;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
        .palette-card strong {
            display: block;
            margin-bottom: 4px;
        }
        .palette-card .meta {
            color: var(--muted);
            font-size: .9rem;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            background: #fff7ed;
            color: #9a3412;
            font-size: .86rem;
            border: 1px solid #fed7aa;
        }
        .line-help {
            display: grid;
            gap: 8px;
            color: var(--muted);
            font-size: .92rem;
        }
        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .icon-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            cursor: pointer;
        }
        @media (max-width: 1180px) {
            .wizard-grid {
                grid-template-columns: 1fr;
            }
            .palette-list {
                max-height: none;
            }
            .settings-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 720px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
            .selected-table-card {
                flex-basis: 220px;
            }
        }
    </style>

    <div class="wizard-shell">
        <div class="hero wizard-hero">
            <h1>Database relation builder</h1>
            <p>Drag tables into the workspace, connect columns with straight arrows, and generate CRUD config plus Laravel models inside this app.</p>
            <div class="hero-actions">
                <a class="button primary" href="{{ $createTableUrl }}">Create Table</a>
                <a class="button secondary" href="{{ route('crudder.index') }}">Refresh schema</a>
            </div>
        </div>

        @if(session('status'))
            <div class="notice">{{ session('status') }}</div>
        @endif

        @foreach($errors as $error)
            <div class="error">{{ $error }}</div>
        @endforeach

        <form method="post" action="{{ $generateUrl }}" id="crudder-form" class="form-grid">
            @csrf
            <input type="hidden" name="graph_state" id="graph_state" value="">

            <div class="panel">
                <div class="workspace-toolbar">
                    <div class="field" style="min-width:260px;flex:1">
                        <label for="app_name">App name</label>
                        <input id="app_name" name="app_name" value="{{ old('app_name', $appName) }}">
                    </div>
                    <div class="action-row">
                        <button type="button" class="button ghost" id="center-workspace">Center layout</button>
                        <button type="submit" class="button primary">Generate config and models</button>
                    </div>
                </div>
            </div>

            <div class="wizard-grid">
                <aside class="panel">
                    <div class="toolbar" style="margin-bottom:12px">
                        <div>
                            <h2 class="section-title">Tables</h2>
                            <div class="small">Drag a table into the workspace.</div>
                        </div>
                    </div>
                    <div class="palette-list" id="table-palette">
                        @foreach($schema as $name => $table)
                            <div class="palette-card" draggable="true" data-table="{{ $name }}">
                                <strong>{{ $table['name'] ?? $name }}</strong>
                                <div class="meta">{{ count($table['columns'] ?? []) }} columns</div>
                                <div class="meta">{{ implode(', ', array_map(static fn ($column) => $column['name'], $table['columns'] ?? [])) }}</div>
                            </div>
                        @endforeach
                    </div>
                </aside>

                <section class="workspace-panel panel">
                    <div class="workspace-toolbar" style="padding:18px 18px 0">
                        <div>
                            <h2 class="section-title">Workspace</h2>
                            <div class="small">Click one column, then another to create a relation. Each relation can also be edited inside the table card.</div>
                        </div>
                        <span class="badge" id="selection-badge">No column selected</span>
                    </div>
                    <div class="selected-table-strip" id="selected-table-strip"></div>
                    <div class="workspace-canvas" id="workspace-canvas">
                        <div class="workspace-stage" id="workspace-stage">
                            <svg class="workspace-svg" id="workspace-svg" aria-hidden="true">
                                <defs>
                                    <marker id="crudder-arrow" viewBox="0 0 10 10" refX="8.5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                        <path d="M 0 0 L 10 5 L 0 10 z" fill="currentColor"></path>
                                    </marker>
                                </defs>
                            </svg>
                            <div class="workspace-empty" id="workspace-empty">
                                <div>
                                    <h3 style="margin:0 0 8px">Drop tables here</h3>
                                    <div>Drag tables from the left or drop them on this area.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="workspace-settings-inline">
                        <div class="toolbar">
                            <div>
                                <h2 class="section-title">Relation settings</h2>
                                <div class="small">These settings stay inside the main workspace box and update the selected table or relation.</div>
                            </div>
                            <div class="action-row">
                                <button type="button" class="button ghost" id="clear-selection">Clear selection</button>
                            </div>
                        </div>
                        <div class="settings-grid">
                            <div class="settings-row">
                                <label for="default_relation_type">Default relation type</label>
                                <select id="default_relation_type">
                                    @foreach($relationTypes as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="settings-row">
                                <label>Selected source</label>
                                <input type="text" id="selection-text" value="None" readonly>
                            </div>
                            <div class="settings-row">
                                <label>Selected table</label>
                                <input type="text" id="active-table-text" value="None" readonly>
                            </div>
                            <div class="settings-row">
                                <label>Workspace status</label>
                                <input type="text" id="workspace-status" value="Drop tables to begin" readonly>
                            </div>
                        </div>
                        <div id="relation-settings-body"></div>
                        <div class="selected-relations" id="selected-relations"></div>
                    </div>
                </section>
            </div>

            <div class="panel">
                <h2 class="section-title">How it works</h2>
                <div class="line-help">
                    <div>1. Drag tables into the canvas.</div>
                    <div>2. Click a source column, then a target column.</div>
                    <div>3. Edit the relation row inside the table card or the relation panel inside the box.</div>
                    <div>4. Generate the config and models in the applied Laravel project.</div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        const crudderSchema = @json($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const initialGraph = @json($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const relationTypes = @json($relationTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        const canvas = document.getElementById('workspace-canvas');
        const stage = document.getElementById('workspace-stage');
        const svg = document.getElementById('workspace-svg');
        const palette = document.getElementById('table-palette');
        const graphInput = document.getElementById('graph_state');
        const selectionBadge = document.getElementById('selection-badge');
        const selectionText = document.getElementById('selection-text');
        const activeTableText = document.getElementById('active-table-text');
        const workspaceStatus = document.getElementById('workspace-status');
        const relationTypeSelect = document.getElementById('default_relation_type');
        const clearSelectionButton = document.getElementById('clear-selection');
        const centerButton = document.getElementById('center-workspace');
        const emptyState = document.getElementById('workspace-empty');
        const selectedTableStrip = document.getElementById('selected-table-strip');
        const selectedRelations = document.getElementById('selected-relations');
        const relationSettingsBody = document.getElementById('relation-settings-body');

        const state = normalizeGraph(initialGraph || {});
        let selectedEndpoint = null;
        let activeTable = tableNames()[0] || null;
        let dragging = null;
        let dragOffset = { x: 0, y: 0 };
        let relationCounter = state.meta?.nextRelationId || 1;

        function normalizeGraph(graph) {
            const tables = {};
            const sourceTables = graph.tables || {};
            Object.keys(sourceTables).forEach((name, index) => {
                const table = sourceTables[name] || {};
                tables[name] = {
                    x: Number(table.x ?? (80 + (index % 3) * 320)),
                    y: Number(table.y ?? (60 + Math.floor(index / 3) * 260)),
                    order: Number(table.order ?? index),
                };
            });

            const relations = Array.isArray(graph.relations) ? graph.relations.map((relation, index) => ({
                id: relation.id || `relation-${index + 1}`,
                type: relation.type || 'belongsTo',
                from_table: relation.from_table || '',
                from_column: relation.from_column || '',
                to_table: relation.to_table || '',
                to_column: relation.to_column || '',
                label_column: relation.label_column || '',
            })).filter(relation => relation.from_table && relation.from_column && relation.to_table && relation.to_column) : [];

            const nextOrder = Math.max(0, ...Object.values(tables).map(table => Number(table.order || 0))) + 1;
            const nextRelationId = relations.reduce((max, relation) => {
                const match = String(relation.id || '').match(/(\d+)$/);
                const value = match ? Number(match[1]) : 0;
                return Math.max(max, value);
            }, 0) + 1;

            return {
                tables,
                relations,
                meta: {
                    nextOrder,
                    nextRelationId,
                },
            };
        }

        function tableNames() {
            return Object.keys(crudderSchema);
        }

        function columnsFor(tableName) {
            const table = crudderSchema[tableName] || {};
            return Array.isArray(table.columns) ? table.columns : [];
        }

        function columnsForSelect(tableName, selected = '', includeBlank = true) {
            const options = columnsFor(tableName).map((column) => {
                const value = String(column.name || '');
                const isSelected = value === selected ? ' selected' : '';
                return `<option value="${value}"${isSelected}>${value}</option>`;
            }).join('');

            return (includeBlank ? '<option value="">Choose...</option>' : '') + options;
        }

        function labelColumnsForSelect(tableName, selected = '') {
            const options = columnsFor(tableName)
                .filter((column) => {
                    const name = String(column.name || '').toLowerCase();
                    const type = String(column.type || '').toLowerCase();
                    return !name.includes('id') && (type.includes('char') || type.includes('text') || type.includes('json'));
                })
                .map((column) => {
                    const value = String(column.name || '');
                    const isSelected = value === selected ? ' selected' : '';
                    return `<option value="${value}"${isSelected}>${value}</option>`;
                }).join('');

            return '<option value="">Auto</option>' + options;
        }

        function renderPalette() {
            palette.querySelectorAll('[draggable="true"]').forEach((card) => {
                card.addEventListener('dragstart', (event) => {
                    event.dataTransfer.setData('text/plain', card.dataset.table || '');
                    event.dataTransfer.effectAllowed = 'copy';
                });
            });
        }

        function normalizePosition(tableName, x, y) {
            const maxX = Math.max(20, stage.clientWidth - 340);
            const maxY = Math.max(20, stage.clientHeight - 120);
            return {
                x: Math.max(20, Math.min(Number(x || 0), maxX)),
                y: Math.max(20, Math.min(Number(y || 0), maxY)),
            };
        }

        function tableOrder() {
            return Object.entries(state.tables).sort((a, b) => {
                const left = Number(a[1].order || 0);
                const right = Number(b[1].order || 0);
                return left - right;
            }).map(([name]) => name);
        }

        function makeCard(tableName) {
            const table = crudderSchema[tableName] || {};
            const card = document.createElement('div');
            card.className = 'workspace-card';
            card.dataset.table = tableName;
            card.style.left = `${state.tables[tableName].x}px`;
            card.style.top = `${state.tables[tableName].y}px`;
            card.style.zIndex = String(50 + Number(state.tables[tableName].order || 0));

            const relations = state.relations.filter((relation) => relation.from_table === tableName);
            card.innerHTML = `
                <div class="card-head" data-drag-handle>
                    <div class="card-title">
                        <strong>${tableName}</strong>
                        <span>${columnsFor(tableName).length} columns</span>
                    </div>
                    <div class="action-row">
                        <button type="button" class="icon-button remove-table" title="Remove table">Remove</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="column-list"></div>
                    <div class="relations-panel">
                        <div class="relations-head">
                            <strong style="font-size:.95rem">Relations</strong>
                            <button type="button" class="button ghost add-relation" style="padding:8px 12px">Add relation</button>
                        </div>
                        <div class="relations-list"></div>
                    </div>
                </div>
            `;

            const columnList = card.querySelector('.column-list');
            columnsFor(tableName).forEach((column) => {
                const columnName = String(column.name || '');
                const pill = document.createElement('button');
                pill.type = 'button';
                pill.className = 'column-pill';
                pill.dataset.column = columnName;
                pill.innerHTML = `<span>${columnName}</span><span class="column-anchor" aria-hidden="true"></span><small>${String(column.type || '')}</small>`;
                if (selectedEndpoint && selectedEndpoint.table === tableName && selectedEndpoint.column === columnName) {
                    pill.classList.add('active');
                }
                pill.addEventListener('click', () => handleColumnClick(tableName, columnName));
                columnList.appendChild(pill);
            });

            const relationList = card.querySelector('.relations-list');
            relations.length ? relations.forEach((relation) => relationList.appendChild(makeRelationRow(relation))) : relationList.appendChild(makeEmptyRelationHint(tableName));

            card.querySelector('.remove-table').addEventListener('click', () => removeTable(tableName));
            card.querySelector('.add-relation').addEventListener('click', () => addInlineRelation(tableName));
            card.querySelector('[data-drag-handle]').addEventListener('pointerdown', (event) => startDrag(event, tableName, card));
            card.addEventListener('click', (event) => {
                if (event.target.closest('button, select, input, textarea, label')) {
                    return;
                }
                setActiveTable(tableName);
            });

            return card;
        }

        function makeEmptyRelationHint(tableName) {
            const empty = document.createElement('div');
            empty.className = 'small';
            empty.textContent = `No outgoing relations yet for ${tableName}.`;
            return empty;
        }

        function makeRelationRow(relation) {
            const row = document.createElement('div');
            row.className = 'relation-row';
            row.dataset.relationId = relation.id;
            row.innerHTML = `
                <div class="mini-grid three">
                    <label>
                        <span>Type</span>
                        <select data-field="type">
                            ${Object.entries(relationTypes).map(([value, label]) => `<option value="${value}"${relation.type === value ? ' selected' : ''}>${label}</option>`).join('')}
                        </select>
                    </label>
                    <label>
                        <span>Source column</span>
                        <select data-field="from_column">${columnsForSelect(relation.from_table, relation.from_column, false)}</select>
                    </label>
                    <label>
                        <span>Target table</span>
                        <select data-field="to_table">
                            <option value="">Choose...</option>
                            ${tableNames().map((tableName) => `<option value="${tableName}"${relation.to_table === tableName ? ' selected' : ''}>${tableName}</option>`).join('')}
                        </select>
                    </label>
                </div>
                <div class="mini-grid three">
                    <label>
                        <span>Target column</span>
                        <select data-field="to_column">${columnsForSelect(relation.to_table, relation.to_column)}</select>
                    </label>
                    <label>
                        <span>Label column</span>
                        <select data-field="label_column">${labelColumnsForSelect(relation.to_table, relation.label_column)}</select>
                    </label>
                    <div style="display:flex;align-items:end">
                        <button type="button" class="icon-button remove-relation" style="width:100%">Remove</button>
                    </div>
                </div>
            `;

            const type = row.querySelector('[data-field="type"]');
            const fromColumn = row.querySelector('[data-field="from_column"]');
            const toTable = row.querySelector('[data-field="to_table"]');
            const toColumn = row.querySelector('[data-field="to_column"]');
            const labelColumn = row.querySelector('[data-field="label_column"]');

            const refreshTargets = () => {
                const currentToTable = toTable.value || relation.to_table || '';
                toColumn.innerHTML = columnsForSelect(currentToTable, relation.to_column);
                labelColumn.innerHTML = labelColumnsForSelect(currentToTable, relation.label_column);
            };

            type.addEventListener('change', () => updateRelation(relation.id, { type: type.value }));
            fromColumn.addEventListener('change', () => updateRelation(relation.id, { from_column: fromColumn.value }));
            toTable.addEventListener('change', () => {
                updateRelation(relation.id, { to_table: toTable.value, to_column: '', label_column: '' });
                refreshTargets();
            });
            toColumn.addEventListener('change', () => updateRelation(relation.id, { to_column: toColumn.value }));
            labelColumn.addEventListener('change', () => updateRelation(relation.id, { label_column: labelColumn.value }));
            row.querySelector('.remove-relation').addEventListener('click', () => removeRelation(relation.id));

            refreshTargets();
            return row;
        }

        function renderWorkspace() {
            canvas.querySelectorAll('.workspace-card').forEach((node) => node.remove());

            const ordered = tableOrder();
            ordered.forEach((tableName) => {
                const card = makeCard(tableName);
                canvas.appendChild(card);
            });

            emptyState.style.display = ordered.length ? 'none' : 'grid';
            activeTable = activeTable && state.tables[activeTable] ? activeTable : (ordered[0] || null);
            renderSelectedTableStrip();
            renderRelationSettings();
            renderSelectedRelations();
            renderWorkspaceStatus();
            syncSelectionUi();
            drawConnections();
        }

        function syncSelectionUi() {
            if (!selectedEndpoint) {
                selectionBadge.textContent = 'No column selected';
                selectionText.value = 'None';
                document.querySelectorAll('.column-pill.active').forEach((node) => node.classList.remove('active'));
                return;
            }

            selectionBadge.textContent = `${selectedEndpoint.table}.${selectedEndpoint.column}`;
            selectionText.value = `Source: ${selectedEndpoint.table}.${selectedEndpoint.column}`;

            document.querySelectorAll('.column-pill').forEach((node) => {
                const isActive = node.closest('.workspace-card')?.dataset.table === selectedEndpoint.table && node.dataset.column === selectedEndpoint.column;
                node.classList.toggle('active', isActive);
            });
        }

        function setActiveTable(tableName) {
            if (!state.tables[tableName]) {
                return;
            }

            activeTable = tableName;
            renderSelectedTableStrip();
            renderRelationSettings();
            renderSelectedRelations();
            renderWorkspaceStatus();
            syncSelectionUi();
        }

        function renderSelectedTableStrip() {
            if (!selectedTableStrip) {
                return;
            }

            const ordered = tableOrder();
            selectedTableStrip.innerHTML = '';

            if (!ordered.length) {
                const empty = document.createElement('div');
                empty.className = 'small';
                empty.style.padding = '6px 2px';
                empty.textContent = 'Dragged tables will appear here.';
                selectedTableStrip.appendChild(empty);
                return;
            }

            ordered.forEach((tableName) => {
                const table = crudderSchema[tableName] || {};
                const card = document.createElement('button');
                card.type = 'button';
                card.className = 'selected-table-card';
                if (activeTable === tableName) {
                    card.classList.add('active');
                }
                card.innerHTML = `
                    <strong>${tableName}</strong>
                    <div class="meta">${columnsFor(tableName).length} columns</div>
                    <div class="meta">${columnsFor(tableName).map((column) => String(column.name || '')).join(', ')}</div>
                `;
                card.addEventListener('click', () => setActiveTable(tableName));
                selectedTableStrip.appendChild(card);
            });
        }

        function renderRelationSettings() {
            if (!relationSettingsBody) {
                return;
            }

            if (!activeTable || !state.tables[activeTable]) {
                relationSettingsBody.innerHTML = '<div class="small" style="padding:4px 0 0">Drag a table into the workspace to configure its relations here.</div>';
                return;
            }

            const tableOptions = tableNames().map((name) => `<option value="${name}">${name}</option>`).join('');
            const columnOptions = columnsForSelect(activeTable, '', false);
            relationSettingsBody.innerHTML = `
                <div class="toolbar" style="margin-top:14px">
                    <div>
                        <div class="small">Quick relation builder for <strong>${activeTable}</strong></div>
                        <div class="small">Pick the relation type and target table, then add it from here.</div>
                    </div>
                    <button type="button" class="button primary" id="add-active-relation">Add relation</button>
                </div>
                <div class="settings-grid" style="margin-top:12px">
                    <div class="settings-row">
                        <label>Relation type</label>
                        <select id="active-relation-type">
                            ${Object.entries(relationTypes).map(([value, label]) => `<option value="${value}">${label}</option>`).join('')}
                        </select>
                    </div>
                    <div class="settings-row">
                        <label>Source column</label>
                        <select id="active-from-column">${columnOptions}</select>
                    </div>
                    <div class="settings-row">
                        <label>Target table</label>
                        <select id="active-to-table">${tableOptions}</select>
                    </div>
                    <div class="settings-row">
                        <label>Target column</label>
                        <select id="active-to-column"></select>
                    </div>
                </div>
                <div class="settings-grid" style="margin-top:12px">
                    <div class="settings-row">
                        <label>Label column</label>
                        <select id="active-label-column"></select>
                    </div>
                    <div class="settings-row">
                        <label>Selected table</label>
                        <input type="text" value="${activeTable}" readonly>
                    </div>
                    <div class="settings-row">
                        <label>Selection</label>
                        <input type="text" value="${selectedEndpoint ? `${selectedEndpoint.table}.${selectedEndpoint.column}` : 'None'}" readonly>
                    </div>
                    <div class="settings-row">
                        <label>Tip</label>
                        <input type="text" value="Drag cards or click columns to link them" readonly>
                    </div>
                </div>
            `;

            const activeRelationType = document.getElementById('active-relation-type');
            const activeFromColumn = document.getElementById('active-from-column');
            const activeToTable = document.getElementById('active-to-table');
            const activeToColumn = document.getElementById('active-to-column');
            const activeLabelColumn = document.getElementById('active-label-column');
            const addActiveRelation = document.getElementById('add-active-relation');

            const refreshTargets = () => {
                const targetTable = activeToTable.value || tableNames().find((name) => name !== activeTable) || activeTable;
                activeToColumn.innerHTML = columnsForSelect(targetTable, '', false);
                activeLabelColumn.innerHTML = labelColumnsForSelect(targetTable, '');
                if (!activeToTable.value && targetTable) {
                    activeToTable.value = targetTable;
                }
            };

            activeToTable.addEventListener('change', refreshTargets);
            refreshTargets();

            addActiveRelation.addEventListener('click', () => {
                addRelation({
                    type: activeRelationType.value,
                    from_table: activeTable,
                    from_column: activeFromColumn.value || columnsFor(activeTable)[0]?.name || '',
                    to_table: activeToTable.value || tableNames().find((name) => name !== activeTable) || activeTable,
                    to_column: activeToColumn.value || columnsFor(activeToTable.value || activeTable)[0]?.name || 'id',
                    label_column: activeLabelColumn.value || suggestLabelColumn(activeToTable.value || activeTable),
                });
            });
        }

        function renderSelectedRelations() {
            if (!selectedRelations) {
                return;
            }

            if (!activeTable || !state.tables[activeTable]) {
                selectedRelations.innerHTML = '';
                return;
            }

            const rows = state.relations.filter((relation) => relation.from_table === activeTable);
            if (rows.length === 0) {
                selectedRelations.innerHTML = '<div class="small">No relations defined for the active table yet.</div>';
                return;
            }

            selectedRelations.innerHTML = '<h3 style="margin:4px 0 0;font-size:1rem">Active table relations</h3>';
            rows.forEach((relation) => {
                selectedRelations.appendChild(makeRelationRow(relation));
            });
        }

        function renderWorkspaceStatus() {
            if (!workspaceStatus) {
                return;
            }

            const count = Object.keys(state.tables).length;
            workspaceStatus.value = count ? `${count} table(s) in the board` : 'Drop tables to begin';
            if (activeTableText) {
                activeTableText.value = activeTable || 'None';
            }
        }

        function syncGraphInput() {
            graphInput.value = JSON.stringify({
                tables: state.tables,
                relations: state.relations,
                meta: state.meta,
            });
        }

        function addTable(tableName, x, y) {
            if (!crudderSchema[tableName] || state.tables[tableName]) {
                return;
            }

            const position = normalizePosition(tableName, x, y);
            state.tables[tableName] = {
                x: position.x,
                y: position.y,
                order: state.meta.nextOrder++,
            };

            renderWorkspace();
            syncGraphInput();
        }

        function removeTable(tableName) {
            delete state.tables[tableName];
            state.relations = state.relations.filter((relation) => relation.from_table !== tableName && relation.to_table !== tableName);
            if (selectedEndpoint && selectedEndpoint.table === tableName) {
                selectedEndpoint = null;
            }
            if (activeTable === tableName) {
                activeTable = tableNames().find((name) => state.tables[name]) || null;
            }
            renderWorkspace();
            syncGraphInput();
        }

        function addRelation(payload) {
            const relation = {
                id: `relation-${relationCounter++}`,
                type: payload.type || relationTypeSelect.value || 'belongsTo',
                from_table: payload.from_table || '',
                from_column: payload.from_column || '',
                to_table: payload.to_table || '',
                to_column: payload.to_column || '',
                label_column: payload.label_column || '',
            };

            if (!relation.from_table || !relation.from_column || !relation.to_table || !relation.to_column) {
                return;
            }

            state.relations.push(relation);
            state.meta.nextRelationId = relationCounter;
            renderWorkspace();
            syncGraphInput();
        }

        function addInlineRelation(tableName) {
            const sourceColumns = columnsFor(tableName);
            const targetTables = tableNames().filter((name) => name !== tableName);
            const targetTable = targetTables[0] || tableName;
            const targetColumns = columnsFor(targetTable);

            addRelation({
                type: relationTypeSelect.value || 'belongsTo',
                from_table: tableName,
                from_column: sourceColumns[0] ? String(sourceColumns[0].name || '') : '',
                to_table: targetTable,
                to_column: targetColumns[0] ? String(targetColumns[0].name || '') : '',
                label_column: suggestLabelColumn(targetTable),
            });
        }

        function updateRelation(relationId, patch) {
            const relation = state.relations.find((item) => item.id === relationId);
            if (!relation) {
                return;
            }

            Object.assign(relation, patch);
            renderWorkspace();
            syncGraphInput();
        }

        function removeRelation(relationId) {
            state.relations = state.relations.filter((relation) => relation.id !== relationId);
            renderWorkspace();
            syncGraphInput();
        }

        function suggestLabelColumn(tableName) {
            const columns = columnsFor(tableName);
            const preferred = columns.find((column) => {
                const name = String(column.name || '').toLowerCase();
                const type = String(column.type || '').toLowerCase();
                return !name.includes('id') && (type.includes('char') || type.includes('text') || type.includes('json'));
            });

            return preferred ? String(preferred.name || '') : '';
        }

        function handleColumnClick(tableName, columnName) {
            if (!selectedEndpoint) {
                selectedEndpoint = { table: tableName, column: columnName };
                syncSelectionUi();
                return;
            }

            if (selectedEndpoint.table === tableName && selectedEndpoint.column === columnName) {
                selectedEndpoint = null;
                syncSelectionUi();
                return;
            }

            addRelation({
                type: relationTypeSelect.value || 'belongsTo',
                from_table: selectedEndpoint.table,
                from_column: selectedEndpoint.column,
                to_table: tableName,
                to_column: columnName,
                label_column: suggestLabelColumn(tableName),
            });

            selectedEndpoint = null;
            syncSelectionUi();
        }

        function startDrag(event, tableName, card) {
            if (event.button !== 0) {
                return;
            }

            if (event.target.closest('button, select, input, textarea, label')) {
                return;
            }

            event.preventDefault();
            dragging = { tableName, card };
            const rect = card.getBoundingClientRect();
            dragOffset = {
                x: event.clientX - rect.left,
                y: event.clientY - rect.top,
            };

            card.style.zIndex = '999';
            card.setPointerCapture(event.pointerId);
        }

        function moveDrag(event) {
            if (!dragging) {
                return;
            }

            const stageRect = stage.getBoundingClientRect();
            const x = event.clientX - stageRect.left + canvas.scrollLeft - dragOffset.x;
            const y = event.clientY - stageRect.top + canvas.scrollTop - dragOffset.y;
            const position = normalizePosition(dragging.tableName, x, y);

            state.tables[dragging.tableName].x = position.x;
            state.tables[dragging.tableName].y = position.y;
            dragging.card.style.left = `${position.x}px`;
            dragging.card.style.top = `${position.y}px`;
            drawConnections();
            syncGraphInput();
        }

        function stopDrag(event) {
            if (!dragging) {
                return;
            }

            dragging.card.style.zIndex = String(50 + Number(state.tables[dragging.tableName].order || 0));
            dragging = null;
            syncGraphInput();
            event?.preventDefault?.();
        }

        function anchorForColumn(tableName, columnName) {
            const card = stage.querySelector(`.workspace-card[data-table="${CSS.escape(tableName)}"]`);
            if (!card) {
                return null;
            }

            const anchor = card.querySelector(`.column-pill[data-column="${CSS.escape(columnName)}"] .column-anchor`);
            if (!anchor) {
                return null;
            }

            const canvasRect = stage.getBoundingClientRect();
            const buttonRect = anchor.getBoundingClientRect();
            return {
                x: buttonRect.left - canvasRect.left + canvas.scrollLeft + (buttonRect.width / 2),
                y: buttonRect.top - canvasRect.top + canvas.scrollTop + (buttonRect.height / 2),
            };
        }

        function relationColor(type) {
            return {
                belongsTo: '#0f172a',
                hasOne: '#c2410c',
                hasMany: '#7c3aed',
                belongsToMany: '#0369a1',
            }[type] || '#475569';
        }

        function drawConnections() {
            svg.querySelectorAll('[data-relation-line]').forEach((node) => node.remove());

            state.relations.forEach((relation) => {
                const from = anchorForColumn(relation.from_table, relation.from_column);
                const to = anchorForColumn(relation.to_table, relation.to_column);
                if (!from || !to) {
                    return;
                }

                const startX = from.x + 18;
                const endX = to.x - 18;
                const midX = startX + ((endX - startX) / 2);
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.dataset.relationLine = relation.id;
                path.setAttribute('d', `M ${startX} ${from.y} L ${midX} ${from.y} L ${midX} ${to.y} L ${endX} ${to.y}`);
                path.setAttribute('fill', 'none');
                path.setAttribute('stroke', relationColor(relation.type));
                path.setAttribute('stroke-width', '3');
                path.setAttribute('stroke-linecap', 'round');
                path.setAttribute('stroke-linejoin', 'round');
                path.setAttribute('marker-end', 'url(#crudder-arrow)');
                path.style.color = relationColor(relation.type);
                svg.appendChild(path);

                const shadow = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                shadow.dataset.relationLine = relation.id;
                shadow.setAttribute('d', `M ${startX} ${from.y} L ${midX} ${from.y} L ${midX} ${to.y} L ${endX} ${to.y}`);
                shadow.setAttribute('fill', 'none');
                shadow.setAttribute('stroke', 'rgba(15, 23, 42, 0.08)');
                shadow.setAttribute('stroke-width', '8');
                shadow.setAttribute('stroke-linecap', 'round');
                shadow.setAttribute('stroke-linejoin', 'round');
                svg.insertBefore(shadow, path);

                const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                label.dataset.relationLine = relation.id;
                label.setAttribute('x', String(midX));
                label.setAttribute('y', String(Math.min(from.y, to.y) - 8));
                label.setAttribute('fill', relationColor(relation.type));
                label.setAttribute('font-size', '11');
                label.setAttribute('font-weight', '700');
                label.setAttribute('text-anchor', 'middle');
                label.textContent = relationTypes[relation.type] || relation.type;
                svg.appendChild(label);
            });

            svg.setAttribute('viewBox', `0 0 ${stage.scrollWidth} ${stage.scrollHeight}`);
        }

        function centerTables() {
            const ordered = tableOrder();
            const columns = 3;
            ordered.forEach((tableName, index) => {
                const row = Math.floor(index / columns);
                const col = index % columns;
                state.tables[tableName].x = 40 + (col * 320);
                state.tables[tableName].y = 40 + (row * 260);
            });
            renderWorkspace();
            syncGraphInput();
        }

        canvas.addEventListener('dragover', (event) => {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        });

        canvas.addEventListener('drop', (event) => {
            event.preventDefault();
            const tableName = event.dataTransfer.getData('text/plain');
            if (!tableName) {
                return;
            }

            const canvasRect = stage.getBoundingClientRect();
            addTable(
                tableName,
                event.clientX - canvasRect.left + canvas.scrollLeft - 140,
                event.clientY - canvasRect.top + canvas.scrollTop - 50
            );
        });

        document.addEventListener('pointermove', moveDrag);
        document.addEventListener('pointerup', stopDrag);
        document.addEventListener('pointercancel', stopDrag);
        window.addEventListener('resize', () => {
            drawConnections();
        });

        relationTypeSelect.addEventListener('change', syncSelectionUi);
        clearSelectionButton.addEventListener('click', () => {
            selectedEndpoint = null;
            syncSelectionUi();
        });
        centerButton.addEventListener('click', centerTables);

        palette.querySelectorAll('[draggable="true"]').forEach((card) => {
            card.addEventListener('dragstart', (event) => {
                event.dataTransfer.setData('text/plain', card.dataset.table || '');
                event.dataTransfer.effectAllowed = 'copy';
            });
        });

        syncGraphInput();
        renderWorkspace();

        if (Object.keys(state.tables).length === 0) {
            selectionText.value = 'Drop a table to begin';
        }
    </script>
@endpush
