<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Curdder')</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --card: #ffffff;
            --line: #dbe3ee;
            --accent: #f59e0b;
            --accent-2: #0f172a;
            --text: #0f172a;
            --muted: #64748b;
            --success: #052e16;
            --danger: #7f1d1d;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background: radial-gradient(circle at top, #e2e8f0 0, #f8fafc 26%, #f8fafc 100%);
        }
        a { color: inherit; }
        .shell { max-width: 1240px; margin: 0 auto; padding: 28px 20px 64px; }
        .hero {
            border-radius: 28px;
            background: linear-gradient(135deg, var(--bg), #1e293b);
            color: #e2e8f0;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 22px 60px rgba(15, 23, 42, .12);
        }
        .hero h1 { margin: 0 0 8px; font-size: 2rem; }
        .hero p { margin: 0; color: #cbd5e1; }
        .hero-actions { margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 999px;
            padding: 12px 16px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .button.primary { background: var(--accent); color: #111827; }
        .button.secondary { background: rgba(255,255,255,.1); color: #fff; border: 1px solid rgba(255,255,255,.16); }
        .button.ghost { background: #fff; color: var(--text); border: 1px solid var(--line); }
        .panel, .card, .notice, .error {
            border-radius: 22px;
            background: var(--card);
            border: 1px solid var(--line);
            box-shadow: 0 14px 40px rgba(15, 23, 42, .06);
        }
        .panel { padding: 20px; }
        .notice {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #065f46;
            padding: 14px 16px;
            margin-bottom: 16px;
        }
        .error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
            padding: 14px 16px;
            margin-bottom: 16px;
        }
        .grid { display: grid; gap: 16px; }
        .grid.cards { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .card { padding: 18px; }
        .muted { color: var(--muted); }
        .toolbar { display: flex; justify-content: space-between; gap: 16px; align-items: center; flex-wrap: wrap; }
        .toolbar h2, .toolbar h3 { margin: 0; }
        .form-grid { display: grid; gap: 18px; }
        .field { display: grid; gap: 8px; }
        .field label { font-weight: 600; color: #475569; }
        .field input, .field select, .field textarea {
            width: 100%;
            border-radius: 14px;
            border: 1px solid var(--line);
            padding: 12px 14px;
            background: #fff;
            color: var(--text);
        }
        .row-list { display: grid; gap: 12px; }
        .row-item {
            display: grid;
            gap: 12px;
            padding: 14px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: #fff;
        }
        .row-item.dragging { opacity: .55; }
        .row-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .row-handle { cursor: grab; user-select: none; color: var(--muted); font-weight: 700; }
        .row-columns { display: grid; gap: 12px; grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .row-columns.join { grid-template-columns: 1.15fr 1fr 1.15fr 1fr 1fr auto; }
        .row-columns.table { grid-template-columns: 1.2fr 1fr .8fr .8fr .8fr .9fr .9fr auto; }
        .checkbox { display: flex; align-items: center; gap: 8px; color: #334155; }
        .column-card { padding: 18px; border: 1px solid var(--line); border-radius: 20px; background: #fff; }
        .suggestions { display: grid; gap: 10px; }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            cursor: pointer;
            text-align: left;
        }
        .section-title { margin: 0 0 8px; font-size: 1.35rem; }
        .small { font-size: .92rem; color: var(--muted); }
        .list { display: grid; gap: 12px; }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            padding: 12px 10px;
            border-bottom: 1px solid #eef2f7;
            text-align: left;
            vertical-align: top;
        }
        .table th { color: var(--muted); font-size: .92rem; }
        .table-wrap { overflow-x: auto; }
        @media (max-width: 980px) {
            .row-columns.join, .row-columns.table, .row-columns { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 680px) {
            .shell { padding: 18px 14px 48px; }
            .hero { padding: 22px; }
            .row-columns.join, .row-columns.table, .row-columns { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<main class="shell">
    @yield('content')
</main>
@stack('scripts')
</body>
</html>
