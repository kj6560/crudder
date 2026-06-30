@extends('curdder::layout')

@section('title', 'Create Table')

@section('content')
    <div class="hero">
        <h1>Create a new table</h1>
        <p>Define the table and its columns from a form, then let the package create it in your Laravel database.</p>
        <div class="hero-actions">
            <a class="button primary" href="{{ $backUrl }}">Back to wizard</a>
        </div>
    </div>

    @foreach($errors as $error)
        <div class="error">{{ $error }}</div>
    @endforeach

    <form method="post" action="{{ $createTableUrl }}" class="form-grid">
        @csrf
        <div class="panel">
            <div class="field">
                <label for="table_name">Table name</label>
                <input id="table_name" name="table_name" value="{{ old('table_name', $tableName) }}" placeholder="products">
            </div>
        </div>

        <div class="panel">
            <div class="toolbar" style="margin-bottom: 14px">
                <div>
                    <h2 class="section-title">Columns</h2>
                    <div class="small">Rows can be reordered by drag and drop. If you do not choose a primary key, an `id` column is added automatically.</div>
                </div>
                <button type="button" class="button ghost" id="add-column">Add column</button>
            </div>

            <div class="row-list" id="column-list"></div>
        </div>

        <div class="panel">
            <button type="submit" class="button primary">Create table</button>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        const columnList = document.getElementById('column-list');
        const addColumnButton = document.getElementById('add-column');
        const initialColumns = @json($columnRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const typeOptions = ['string', 'text', 'integer', 'biginteger', 'boolean', 'date', 'datetime', 'decimal', 'json'];

        function columnTemplate(data = {}) {
            const row = document.createElement('div');
            row.className = 'row-item column-row';
            row.setAttribute('draggable', 'true');
            row.innerHTML = `
                <div class="row-header">
                    <div class="row-handle">Drag to reorder</div>
                    <button type="button" class="button ghost remove-row">Remove</button>
                </div>
                <div class="row-columns table">
                    <div class="field">
                        <label>Column name</label>
                        <input data-field="name" value="${escapeHtml(data.name || '')}" placeholder="title">
                    </div>
                    <div class="field">
                        <label>Type</label>
                        <select data-field="type">${typeOptions.map(type => `<option value="${type}"${(data.type || 'string') === type ? ' selected' : ''}>${type}</option>`).join('')}</select>
                    </div>
                    <div class="field">
                        <label>Length</label>
                        <input data-field="length" value="${escapeHtml(data.length || '255')}" placeholder="255">
                    </div>
                    <div class="field">
                        <label>Precision</label>
                        <input data-field="precision" value="${escapeHtml(data.precision || '')}" placeholder="8">
                    </div>
                    <div class="field">
                        <label>Scale</label>
                        <input data-field="scale" value="${escapeHtml(data.scale || '')}" placeholder="2">
                    </div>
                    <div class="field">
                        <label>Default</label>
                        <input data-field="default" value="${escapeHtml(data.default || '')}" placeholder="">
                    </div>
                    <div class="field" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                        <label class="checkbox"><input type="checkbox" data-field="nullable"${data.nullable ? ' checked' : ''}> Nullable</label>
                        <label class="checkbox"><input type="checkbox" data-field="primary"${data.primary ? ' checked' : ''}> Primary</label>
                        <label class="checkbox"><input type="checkbox" data-field="unique"${data.unique ? ' checked' : ''}> Unique</label>
                        <label class="checkbox"><input type="checkbox" data-field="auto_increment"${data.auto_increment ? ' checked' : ''}> Auto inc</label>
                    </div>
                </div>
            `;
            wireColumnRow(row);
            return row;
        }

        function wireColumnRow(row) {
            row.querySelector('.remove-row').addEventListener('click', () => {
                row.remove();
                syncColumnNames();
            });
        }

        function syncColumnNames() {
            [...columnList.querySelectorAll('.column-row')].forEach((row, index) => {
                row.querySelectorAll('[data-field]').forEach((input) => {
                    const field = input.getAttribute('data-field');
                    const type = input.getAttribute('type');
                    const base = `columns[${index}][${field}]`;
                    if (type === 'checkbox') {
                        input.name = base;
                        input.value = '1';
                    } else {
                        input.name = base;
                    }
                });
            });
        }

        function enableColumnDragAndDrop() {
            let dragging = null;
            columnList.addEventListener('dragstart', (event) => {
                const row = event.target.closest('.column-row');
                if (!row) return;
                dragging = row;
                row.classList.add('dragging');
            });
            columnList.addEventListener('dragend', () => {
                if (dragging) dragging.classList.remove('dragging');
                dragging = null;
            });
            columnList.addEventListener('dragover', (event) => {
                event.preventDefault();
                const row = event.target.closest('.column-row');
                if (!row || !dragging || row === dragging) return;
                const box = row.getBoundingClientRect();
                const after = (event.clientY - box.top) > (box.height / 2);
                if (after) {
                    row.after(dragging);
                } else {
                    row.before(dragging);
                }
                syncColumnNames();
            });
        }

        function escapeHtml(value) {
            return String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function addColumnRow(data = {}) {
            columnList.appendChild(columnTemplate(data));
            syncColumnNames();
        }

        addColumnButton?.addEventListener('click', () => addColumnRow());
        enableColumnDragAndDrop();

        if (initialColumns.length) {
            initialColumns.forEach((column) => addColumnRow(column));
        } else {
            addColumnRow();
        }
    </script>
@endpush
