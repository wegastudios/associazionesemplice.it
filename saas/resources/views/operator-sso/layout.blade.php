<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') - Associazione Semplice</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f5f6f8; color: #1f2937; margin: 0; }
        main { max-width: 28rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 4px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        button { font: inherit; padding: .6rem 1.1rem; border: 0; border-radius: .5rem; background: #1d4ed8; color: #fff; cursor: pointer; }
        a { color: #1d4ed8; }
        .actions { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin-top: 1.25rem; }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
