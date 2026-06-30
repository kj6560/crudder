@extends('curdder::layout')

@section('title', 'Curdder Wizard')

@section('content')
    <div class="hero">
        <h1>Database table selector</h1>
        <p>Pick the tables to generate, add joins visually, and create new tables inside your Laravel app.</p>
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

    <form method="post" action="{{ $generateUrl }}" class="form-grid">
        @csrf
        <div class="panel">
            <div class="field">
                <label for="app_name">App name</label>
                <input id="app_name" name="app_name" value="{{ old('app_name', $appName) }}">
            </div>
        </div>

        <div class="grid cards">
            @foreach($schema as $name => $table)
                <label class="card">
                    <div class="toolbar" style="align-items:flex-start">
                        <div>
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                                <input type="checkbox" name="tables[]" value="{{ $name }}" @checked(in_array($name, $selectedTables, true))>
                                <strong>{{ $table['name'] ?? $name }}</strong>
                            </div>
                            <div class="small">{{ $table['primary_key'] ?? 'id' }}</div>
                        </div>
                    </div>
                    <div class="small" style="margin-top:12px">
                        {{ implode(', ', array_map(static fn ($column) => $column['name'], $table['columns'] ?? [])) }}
                    </div>
                </label>
            @endforeach
        </div>

        <div class="panel">
            <div class="toolbar" style="margin-bottom: 14px">
                <div>
                    <h2 class="section-title">Join builder</h2>
                    <div class="small">Rows are draggable. The detected joins are already prefilled, and you can edit or add more.</div>
                </div>
                <button type="button" class="button ghost" id="add-join">Add join</button>
            </div>

            <div class="row-list" id="join-list">
                @foreach($joinRows as $row)
                    <div class="row-item join-row" draggable="true">
                        <div class="row-header">
                            <div class="row-handle">Drag to reorder</div>
                            <button type="button" class="button ghost remove-row">Remove</button>
                        </div>
                        <div class="row-columns join">
                            <div class="field">
                                <label>From table</label>
                                <select data-field="left_table">
                                    <option value="">Choose...</option>
                                    @foreach(array_keys($schema) as $tableName)
                                        <option value="{{ $tableName }}" @selected(($row['left_table'] ?? '') === $tableName)>{{ $tableName }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label>From column</label>
                                <select data-field="left_column"></select>
                            </div>
                            <div class="field">
                                <label>To table</label>
                                <select data-field="right_table">
                                    <option value="">Choose...</option>
                                    @foreach(array_keys($schema) as $tableName)
                                        <option value="{{ $tableName }}" @selected(($row['right_table'] ?? '') === $tableName)>{{ $tableName }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label>To column</label>
                                <select data-field="right_column"></select>
                            </div>
                            <div class="field">
                                <label>Label column</label>
                                <select data-field="label_column"></select>
                            </div>
                            <div class="field">
                                <label>&nbsp;</label>
                                <input type="hidden" data-field="row_marker" value="1">
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="suggestions" style="margin-top:16px">
                @foreach($schema as $leftTable => $table)
                    @foreach(($table['foreign_keys'] ?? []) as $leftColumn => $fk)
                        <button type="button"
                                class="chip suggested-join"
                                data-left-table="{{ $leftTable }}"
                                data-left-column="{{ $leftColumn }}"
                                data-right-table="{{ $fk['table'] }}"
                                data-right-column="{{ $fk['column'] }}"
                                data-label-column="{{ $fk['label_column'] ?? '' }}">
                            Suggested: {{ $leftTable }}.{{ $leftColumn }} -> {{ $fk['table'] }}.{{ $fk['column'] }}{{ !empty($fk['label_column']) ? ' (' . $fk['label_column'] . ')' : '' }}
                        </button>
                    @endforeach
                @endforeach
            </div>
        </div>

        <div class="panel">
            <button type="submit" class="button primary">Generate CRUD config</button>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        const crudderSchema = @json($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const joinList = document.getElementById('join-list');
        const addJoinButton = document.getElementById('add-join');

        function tableOptions(selected = '') {
            return Object.keys(crudderSchema).map(table => `<option value="${table}"${selected === table ? ' selected' : ''}>${table}</option>`).join('');
        }

        function columnOptions(table, selected = '', includeBlank = true) {
            const columns = (crudderSchema[table] && crudderSchema[table].columns) ? crudderSchema[table].columns : [];
            const options = columns.map(column => `<option value="${column.name}"${selected === column.name ? ' selected' : ''}>${column.name}</option>`).join('');
            return (includeBlank ? '<option value="">Choose...</option>' : '') + options;
        }

        function labelOptions(table, selected = '') {
            const columns = (crudderSchema[table] && crudderSchema[table].columns) ? crudderSchema[table].columns : [];
            const options = columns
                .filter(column => {
                    const name = String(column.name || '').toLowerCase();
                    const type = String(column.type || '').toLowerCase();
                    return !name.includes('id') && (type.includes('char') || type.includes('text') || type.includes('json'));
                })
                .map(column => `<option value="${column.name}"${selected === column.name ? ' selected' : ''}>${column.name}</option>`)
                .join('');
            return '<option value="">Auto</option>' + options;
        }

        function applyJoinSelects(row, data = {}) {
            const leftTable = row.querySelector('[data-field="left_table"]');
            const leftColumn = row.querySelector('[data-field="left_column"]');
            const rightTable = row.querySelector('[data-field="right_table"]');
            const rightColumn = row.querySelector('[data-field="right_column"]');
            const labelColumn = row.querySelector('[data-field="label_column"]');

            leftTable.value = data.left_table || leftTable.value || '';
            rightTable.value = data.right_table || rightTable.value || '';
            leftColumn.innerHTML = columnOptions(leftTable.value, data.left_column || '');
            rightColumn.innerHTML = columnOptions(rightTable.value, data.right_column || '');
            labelColumn.innerHTML = labelOptions(rightTable.value, data.label_column || '');
            leftColumn.value = data.left_column || leftColumn.value || '';
            rightColumn.value = data.right_column || rightColumn.value || '';
            labelColumn.value = data.label_column || labelColumn.value || '';
        }

        function joinTemplate(data = {}) {
            const row = document.createElement('div');
            row.className = 'row-item join-row';
            row.setAttribute('draggable', 'true');
            row.innerHTML = `
                <div class="row-header">
                    <div class="row-handle">Drag to reorder</div>
                    <button type="button" class="button ghost remove-row">Remove</button>
                </div>
                <div class="row-columns join">
                    <div class="field">
                        <label>From table</label>
                        <select data-field="left_table"><option value="">Choose...</option>${tableOptions(data.left_table || '')}</select>
                    </div>
                    <div class="field"><label>From column</label><select data-field="left_column"></select></div>
                    <div class="field">
                        <label>To table</label>
                        <select data-field="right_table"><option value="">Choose...</option>${tableOptions(data.right_table || '')}</select>
                    </div>
                    <div class="field"><label>To column</label><select data-field="right_column"></select></div>
                    <div class="field"><label>Label column</label><select data-field="label_column"></select></div>
                    <div class="field"><label>&nbsp;</label><input type="hidden" data-field="row_marker" value="1"></div>
                </div>
            `;
            applyJoinSelects(row, data);
            wireJoinRow(row);
            return row;
        }

        function wireJoinRow(row) {
            const leftTable = row.querySelector('[data-field="left_table"]');
            const rightTable = row.querySelector('[data-field="right_table"]');
            const removeButton = row.querySelector('.remove-row');

            const refresh = () => applyJoinSelects(row, {
                left_table: leftTable.value,
                left_column: row.querySelector('[data-field="left_column"]').value,
                right_table: rightTable.value,
                right_column: row.querySelector('[data-field="right_column"]').value,
                label_column: row.querySelector('[data-field="label_column"]').value,
            });

            leftTable.addEventListener('change', refresh);
            rightTable.addEventListener('change', refresh);
            removeButton.addEventListener('click', () => {
                row.remove();
                syncJoinNames();
            });
            refresh();
        }

        function syncJoinNames() {
            [...joinList.querySelectorAll('.join-row')].forEach((row, index) => {
                row.querySelectorAll('[data-field]').forEach((input) => {
                    const field = input.getAttribute('data-field');
                    if (!field || field === 'row_marker') {
                        return;
                    }
                    input.name = `join_${field}[${index}]`;
                });
            });
        }

        function enableJoinDragAndDrop() {
            let dragging = null;
            joinList.addEventListener('dragstart', (event) => {
                const row = event.target.closest('.join-row');
                if (!row) return;
                dragging = row;
                row.classList.add('dragging');
            });
            joinList.addEventListener('dragend', () => {
                if (dragging) dragging.classList.remove('dragging');
                dragging = null;
            });
            joinList.addEventListener('dragover', (event) => {
                event.preventDefault();
                const row = event.target.closest('.join-row');
                if (!row || !dragging || row === dragging) return;
                const box = row.getBoundingClientRect();
                const after = (event.clientY - box.top) > (box.height / 2);
                if (after) {
                    row.after(dragging);
                } else {
                    row.before(dragging);
                }
                syncJoinNames();
            });
        }

        function addJoinRow(data = {}) {
            joinList.appendChild(joinTemplate(data));
            syncJoinNames();
        }

        document.querySelectorAll('.suggested-join').forEach((button) => {
            button.addEventListener('click', () => {
                addJoinRow({
                    left_table: button.dataset.leftTable,
                    left_column: button.dataset.leftColumn,
                    right_table: button.dataset.rightTable,
                    right_column: button.dataset.rightColumn,
                    label_column: button.dataset.labelColumn,
                });
            });
        });

        addJoinButton?.addEventListener('click', () => addJoinRow());
        enableJoinDragAndDrop();

        [...joinList.querySelectorAll('.join-row')].forEach((row) => wireJoinRow(row));
        syncJoinNames();
    </script>
@endpush
