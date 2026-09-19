<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Get help') · {{ config('app.name') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; color: #111827; background: #f6f7f9; }
        main { max-width: 46rem; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
        h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
        .lede { color: #6b7280; margin: 0 0 1.5rem; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: .9rem; padding: 1.25rem; margin-bottom: 1.25rem; }
        label { display: block; font-size: .85rem; font-weight: 600; margin: .9rem 0 .35rem; }
        input[type=text], textarea, select { width: 100%; font: inherit; padding: .6rem .75rem; border: 1px solid #d1d5db; border-radius: .6rem; background: #fff; }
        button { font: inherit; font-weight: 600; background: #29376a; color: #fff; border: 0; border-radius: .6rem; padding: .6rem 1.1rem; cursor: pointer; margin-top: 1rem; }
        .row { display: flex; justify-content: space-between; gap: 1rem; padding: .65rem 0; border-top: 1px solid #f1f2f4; text-decoration: none; color: inherit; }
        .row:first-child { border-top: 0; }
        .muted { color: #6b7280; font-size: .85rem; }
        .message { border-radius: .7rem; padding: .85rem 1rem; border: 1px solid #eceef1; margin-bottom: .75rem; white-space: pre-wrap; }
        .team { background: #eef0f8; border-color: #dfe3f1; }
        .status { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: .6rem; padding: .6rem .9rem; margin-bottom: 1rem; }
        .error { color: #b91c1c; font-size: .85rem; margin-top: .35rem; }
        a { color: #29376a; }
    </style>
</head>
<body>
<main>
    @if (session('gadya-connect.status'))
        <p class="status">{{ session('gadya-connect.status') }}</p>
    @endif
    @yield('content')
</main>
</body>
</html>
