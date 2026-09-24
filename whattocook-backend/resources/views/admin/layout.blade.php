<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') · WhatToCook</title>
    <style>
        :root { --ink: #173d2b; --green: #23864d; --green-dark: #17683a; --cream: #f7f8f2; --card: #ffffff; --line: #dbe4dc; --muted: #637267; --danger: #b42318; --warning: #955d00; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--cream); color: #18291f; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .topbar { background: var(--ink); color: #fff; min-height: 64px; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px max(20px, calc((100vw - 1160px) / 2)); }
        .brand { color: #fff; text-decoration: none; font-size: 18px; font-weight: 800; letter-spacing: -.02em; }
        .brand small { color: #bcd4c3; font-size: 12px; font-weight: 500; margin-left: 8px; }
        .topbar-nav { display: flex; align-items: center; gap: 10px; margin-left: auto; margin-right: 12px; }
        .topbar-nav a { color: #d9eee0; text-decoration: none; font-size: 14px; font-weight: 600; }
        .topbar-actions { display: flex; gap: 12px; align-items: center; font-size: 14px; }
        .admin-name { color: #d9eee0; }
        .container { width: min(1160px, calc(100% - 32px)); margin: 32px auto 56px; }
        .page-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 24px; }
        h1 { margin: 0; font-size: clamp(24px, 3vw, 32px); letter-spacing: -.035em; }
        h2 { margin: 0 0 16px; font-size: 18px; }
        p { line-height: 1.55; }
        .subheading { margin: 6px 0 0; color: var(--muted); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 5px 18px rgba(23, 61, 43, .05); padding: 24px; }
        .flash { border-radius: 10px; padding: 12px 14px; margin-bottom: 18px; }
        .flash.success { background: #e8f7ed; color: #145c31; border: 1px solid #b7e4c3; }
        .flash.error { background: #fff0ef; color: #8d2017; border: 1px solid #f4c6c1; }
        .button, button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 38px; border: 1px solid transparent; border-radius: 8px; padding: 8px 14px; background: var(--green); color: #fff; font: inherit; font-size: 14px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .button:hover, button:hover { background: var(--green-dark); }
        .button.secondary, button.secondary { background: #fff; color: var(--ink); border-color: #b8c9bc; }
        .button.secondary:hover, button.secondary:hover { background: #edf4ee; }
        .button.danger, button.danger { background: #fff; color: var(--danger); border-color: #efb9b2; }
        .button.danger:hover, button.danger:hover { background: #fff0ef; }
        .plain-button { border: 0; padding: 0; min-height: auto; background: transparent; color: #d9eee0; font-weight: 600; }
        .plain-button:hover { background: transparent; color: #fff; }
        .field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .field-grid.four { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .field { min-width: 0; }
        .field.full { grid-column: 1 / -1; }
        label { display: block; color: #33473a; font-size: 13px; font-weight: 700; margin-bottom: 6px; }
        input, select, textarea { width: 100%; border: 1px solid #b8c9bc; border-radius: 8px; padding: 9px 10px; color: #18291f; background: #fff; font: inherit; font-size: 14px; }
        textarea { min-height: 105px; resize: vertical; }
        input:focus, select:focus, textarea:focus { outline: 3px solid rgba(35, 134, 77, .16); border-color: var(--green); }
        .help { color: var(--muted); font-size: 12px; margin: 5px 0 0; }
        .error-text { color: var(--danger); font-size: 12px; margin: 5px 0 0; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 24px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 700px; }
        th, td { text-align: left; padding: 13px 12px; border-bottom: 1px solid var(--line); font-size: 14px; vertical-align: middle; }
        th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        tr:last-child td { border-bottom: 0; }
        .row-actions { display: flex; gap: 8px; justify-content: flex-end; }
        .row-actions form { display: inline; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 999px; color: #245039; background: #e8f4eb; font-size: 12px; font-weight: 700; }
        .empty { color: var(--muted); text-align: center; padding: 34px; }
        .pagination { display: flex; justify-content: space-between; gap: 12px; margin-top: 18px; color: var(--muted); font-size: 14px; }
        .pagination a { color: var(--green-dark); font-weight: 700; text-decoration: none; }
        @media (max-width: 720px) { .container { width: min(100% - 24px, 1160px); margin-top: 20px; } .topbar { padding: 12px; } .admin-name { display: none; } .page-heading { display: block; } .page-heading .button { margin-top: 16px; } .card { padding: 16px; } .field-grid, .field-grid.four { grid-template-columns: 1fr; } }
    </style>
    @stack('head')
</head>
<body>
    <header class="topbar">
        <a class="brand" href="{{ route('admin.recipes.index') }}">WhatToCook <small>Recipe Admin</small></a>
        <nav class="topbar-nav" aria-label="Admin navigation">
            <a href="{{ route('admin.recipes.index') }}">Recipes</a>
            <a href="{{ route('admin.meal-plans.index') }}">Meal plans</a>
        </nav>
        <div class="topbar-actions">
            <span class="admin-name">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button class="plain-button" type="submit">Log out</button>
            </form>
        </div>
    </header>

    <main class="container">
        @if (session('success'))
            <div class="flash success" role="status">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="flash error" role="alert">
                <strong>Please correct the highlighted fields.</strong>
            </div>
        @endif

        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
