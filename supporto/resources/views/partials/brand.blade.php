<link rel="icon" type="image/svg+xml" href="{{ route('brand', 'favicon.svg') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ route('brand', 'favicon-32.png') }}">
<link rel="shortcut icon" href="{{ route('brand', 'favicon.ico') }}">
<link rel="apple-touch-icon" href="{{ route('brand', 'apple-touch-icon.png') }}">
<link rel="preload" href="{{ route('brand', 'Montserrat-Medium.ttf') }}" as="font" type="font/ttf" crossorigin>
<style>
    /* Associazione Semplice: carattere Montserrat (licenza OFL, ospitato qui), colori del logo: indaco #1F218C, salvia #5F8579, giallo #EFCA5C */
    @font-face { font-family: "Montserrat"; font-weight: 500; font-style: normal; font-display: swap; src: url("{{ route('brand', 'Montserrat-Medium.ttf') }}") format("truetype"); }
    @font-face { font-family: "Montserrat"; font-weight: 600; font-style: normal; font-display: swap; src: url("{{ route('brand', 'Montserrat-SemiBold.ttf') }}") format("truetype"); }
    @font-face { font-family: "Montserrat"; font-weight: 700; font-style: normal; font-display: swap; src: url("{{ route('brand', 'Montserrat-Bold.ttf') }}") format("truetype"); }
    :root {
        color-scheme: light dark;
        --bg:#f6f8f6; --card:#fff; --ink:#14172e; --muted:#566069; --line:#dbe3de; --surface-2:#edf2ef;
        --teal:#5f8579; --head:#fff; --brand:#1f218c; --brand-2:#15176b; --brand-ink:#fff; --link:#1f218c; --focus:#14172e;
        --yellow:#efca5c; --yellow-soft:#fbf3d4;
        --danger:#b3261e; --ok:#2f7a5f; --code-bg:#edf2ef;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg:#0f1311; --card:#161c1a; --ink:#eef2f0; --muted:#a2b0aa; --line:#27322e; --surface-2:#1c2421;
            --teal:#86ab9d; --head:#161c1a; --brand:#a6a9ff; --brand-2:#c4c6ff; --brand-ink:#0c0d2b; --link:#a6a9ff; --focus:#eef2f0;
            --yellow:#efca5c; --yellow-soft:#3a3013;
            --danger:#ff8a80; --ok:#6fd1a6; --code-bg:#1c2421;
        }
    }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--bg); color:var(--ink); font:500 16px/1.6 "Montserrat", system-ui, -apple-system, "Segoe UI", sans-serif; }
    a { color:var(--link); }
    :focus-visible { outline:3px solid var(--focus); outline-offset:2px; border-radius:4px; }
    header.site { background:var(--head); color:var(--ink); border-bottom:4px solid var(--yellow); }
    .bar { max-width:1180px; margin:0 auto; padding:10px 16px; display:flex; gap:20px; align-items:center; flex-wrap:wrap; }
    .bar .logo { display:flex; align-items:center; gap:10px; color:var(--ink); text-decoration:none; }
    .bar nav { display:flex; gap:20px; flex:1; flex-wrap:wrap; }
    .bar a, .bar .who { color:var(--brand); text-decoration:none; font-weight:600; }
    .bar .logo picture, .bar .logo img { display:block; }
    .login picture, .login img { display:inline-block; }
    .bar nav a:hover, .bar nav a[aria-current="page"] { text-decoration:underline; text-decoration-color:var(--yellow); text-underline-offset:8px; text-decoration-thickness:3px; }
    .bar .who { opacity:.9; font-size:.95rem; }
    .bar button, .bar button.secondary { background:transparent; color:var(--brand); border:1px solid var(--brand); }
    .bar button:hover { background:var(--surface-2); }
    main { max-width:1180px; margin:0 auto; padding:24px 16px 56px; }
    h1 { font-size:1.45rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; margin:0 0 16px; }
    h2 { font-size:1.15rem; font-weight:600; margin:26px 0 8px; }
    .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:18px; margin-bottom:16px; }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:left; padding:9px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
    th { font-size:.8rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); font-weight:600; }
    .tablewrap { overflow-x:auto; }
    .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:.85rem; font-weight:600; border:1px solid transparent; }
    .b-active { background:#d7f0df; color:#14532d; } .b-grace { background:#fdecc8; color:#6b4500; }
    .b-expired { background:#e4e7eb; color:#323a44; } .b-suspended { background:#ffe0c2; color:#7a3a00; }
    .b-revoked { background:#f9d4d2; color:#7d1a17; } .b-pending { background:#d6e6fb; color:#133e73; }
    label { display:block; font-weight:500; margin:12px 0 4px; }
    input, select, textarea { width:100%; padding:9px 10px; border:1px solid var(--line); border-radius:6px; background:var(--card); color:var(--ink); font:inherit; }
    input[readonly] { background:var(--surface-2); color:var(--muted); cursor:not-allowed; border-style:dashed; }
    input[type=checkbox], input[type=radio] { width:auto; margin-right:6px; }
    .row { display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); }
    .hint { color:var(--muted); font-size:.92rem; margin:4px 0 0; }
    button, .btn { display:inline-block; padding:9px 16px; border:1px solid var(--brand); border-radius:6px; background:var(--brand); color:var(--brand-ink); font:inherit; font-weight:700; cursor:pointer; text-decoration:none; }
    button.secondary, .btn.secondary { background:transparent; color:var(--link); border-color:var(--teal); }
    button.danger { background:var(--danger); border-color:var(--danger); color:#fff; }
    button.danger.outline { background:transparent; color:var(--danger); }
    .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
    .flash { padding:12px 14px; border-radius:8px; margin-bottom:16px; border:1px solid var(--ok); color:var(--ok); background:var(--card); }
    .errors { padding:12px 14px; border-radius:8px; margin-bottom:16px; border:1px solid var(--danger); color:var(--danger); background:var(--card); }
    .key { font:600 1.05rem ui-monospace, SFMono-Regular, Menlo, monospace; word-break:break-all; padding:12px; border:2px solid var(--teal); border-left:8px solid var(--yellow); border-radius:8px; margin:8px 0; user-select:all; background:var(--yellow-soft); }
    dl { display:grid; grid-template-columns:max-content 1fr; gap:6px 18px; margin:0; }
    dt { color:var(--muted); } dd { margin:0; word-break:break-word; }
    .chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
    .muted { color:var(--muted); }
    .filters { display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); align-items:end; }
    .pager { display:flex; justify-content:space-between; margin-top:12px; }
    .checks { display:grid; gap:4px 18px; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); }
    .checks label { font-weight:400; margin:2px 0; }
    .login { max-width:420px; margin:28px auto; text-align:center; border-top:5px solid var(--yellow); }
    .login form { text-align:left; }
    footer.site { max-width:1180px; margin:0 auto; padding:0 16px 32px; color:var(--muted); font-size:.9rem; }
    code, pre { font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:.92em; }
    code { background:var(--code-bg); padding:1px 5px; border-radius:4px; }
    /* Pagine di documentazione */
    .prose { max-width:880px; }
    .prose h1 { font-size:1.9rem; } .prose h2 { font-size:1.35rem; margin-top:34px; padding-top:6px; border-top:1px solid var(--line); }
    .prose h3 { font-size:1.1rem; margin:24px 0 6px; }
    .prose pre { background:var(--code-bg); border:1px solid var(--line); border-radius:8px; padding:14px; overflow-x:auto; line-height:1.45; }
    .prose pre code { background:none; padding:0; }
    .prose table { display:block; overflow-x:auto; }
    .prose blockquote { margin:14px 0; padding:2px 16px; border-left:5px solid var(--yellow); background:var(--yellow-soft); border-radius:0 8px 8px 0; }
    .docs-layout { display:grid; gap:28px; grid-template-columns:230px 1fr; align-items:start; }
    .docs-nav { position:sticky; top:12px; }
    .docs-nav ul { list-style:none; margin:0; padding:0; }
    .docs-nav a { display:block; padding:6px 10px; border-radius:6px; text-decoration:none; color:var(--ink); }
    .docs-nav a[aria-current="page"] { background:var(--yellow-soft); border:1px solid var(--line); border-left:5px solid var(--yellow); font-weight:600; color:var(--link); }
    @media (max-width:800px) { .docs-layout { grid-template-columns:1fr; } .docs-nav { position:static; } dl { grid-template-columns:1fr; } dt { margin-top:8px; } }
    @media (max-width:640px) { dl { grid-template-columns:1fr; } dt { margin-top:8px; } }
</style>
