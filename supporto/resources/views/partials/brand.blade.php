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
        /* Valori di docs/brand/tokens.css (unica fonte): non inventare colori o raggi */
        --bg:#f5f6fa; --card:#ffffff; --ink:#181a2e; --muted:#5d6078; --line:#e2e5ee; --surface-2:#e8e9fb;
        --teal:#5f8579; --head:#f5f6fa; --brand:#1f218c; --brand-2:#1f218c; --brand-ink:#ffffff; --link:#1f218c; --focus:#181a2e;
        --yellow:#efca5c; --yellow-soft:#fcf3d3; --warn-ink:#6b4f00;
        --danger:#b42318; --danger-soft:#fdecea; --ok:#3d6256; --ok-soft:#e6efeb; --code-bg:#e8e9fb;
        --r-control:12px; --r-card:16px; --r-sheet:24px; --tap:44px; --max:64rem;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg:#11122a; --card:#1b1d3d; --ink:#eceefa; --muted:#a9acc6; --line:#2f3260; --surface-2:#26285a;
            --teal:#86ab9d; --head:#11122a; --brand:#a6a9ff; --brand-2:#a6a9ff; --brand-ink:#11122a; --link:#a6a9ff; --focus:#eceefa;
            --yellow:#efca5c; --yellow-soft:#3d3410; --warn-ink:#efca5c;
            --danger:#ff8a80; --danger-soft:#3b1b1b; --ok:#a8cfc0; --ok-soft:#1f3a31; --code-bg:#26285a;
        }
    }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--bg); color:var(--ink); -webkit-font-smoothing:antialiased; font:500 16.5px/1.65 "Montserrat", system-ui, -apple-system, "Segoe UI", sans-serif; }
    a { color:var(--link); }
    :focus-visible { outline:3px solid var(--focus); outline-offset:2px; border-radius:4px; }
    header.site { position:sticky; top:0; z-index:20; background:color-mix(in srgb, var(--bg) 88%, transparent); backdrop-filter:saturate(1.4) blur(10px); color:var(--ink); border-bottom:1px solid var(--line); }
    .bar { max-width:var(--max); margin:0 auto; padding:8px 20px; min-height:66px; display:flex; gap:20px; align-items:center; flex-wrap:wrap; }
    .bar .logo { display:flex; align-items:center; gap:10px; color:var(--ink); text-decoration:none; }
    .bar nav { display:flex; gap:20px; flex:1; flex-wrap:wrap; }
    .bar a, .bar .who { color:var(--muted); text-decoration:none; font-weight:600; font-size:.95rem; }
    .bar .logo picture, .bar .logo img { display:block; }
    .login picture, .login img { display:inline-block; }
    .bar nav a { padding:8px 12px; border-radius:10px; } .bar nav { gap:6px; }\n    .bar nav a:hover, .bar nav a[aria-current="page"] { color:var(--ink); background:var(--surface-2); }
    .bar .who { opacity:.9; font-size:.95rem; }
    .bar .place { font-weight:700; color:var(--ink); }
    .bar .account { margin-left:auto; }
    .menu { max-width:var(--max); margin:0 auto; padding:10px 20px 0; display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .menu a { padding:8px 12px; border-radius:var(--r-control); color:var(--muted); text-decoration:none; font-weight:600; font-size:.95rem; }
    .menu a:hover, .menu a[aria-current="page"] { color:var(--ink); background:var(--surface-2); }
    .menu form { margin:0; }
    .errorpage { max-width:34rem; margin:48px auto; text-align:center; }
    .errorpage .actions { justify-content:center; margin-top:18px; }
    .bar button, .bar button.secondary { background:transparent; color:var(--brand); border:1px solid var(--brand); }
    .bar button:hover { background:var(--surface-2); }
    main { max-width:var(--max); margin:0 auto; padding:32px 20px 56px; }
    h1 { font-size:clamp(1.8rem, 4vw, 2.4rem); font-weight:700; line-height:1.15; letter-spacing:-.02em; margin:0 0 18px; }
    h2 { font-size:1.2rem; font-weight:700; letter-spacing:-.01em; margin:26px 0 8px; }
    .card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:22px; margin-bottom:20px; }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:left; padding:9px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
    th { font-size:.8rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); font-weight:600; }
    .tablewrap { overflow-x:auto; }
    .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:.85rem; font-weight:600; border:1px solid transparent; }
    .b-active { background:var(--ok-soft); color:var(--ok); } .b-grace { background:var(--yellow-soft); color:var(--warn-ink); }
    .b-expired { background:var(--surface-2); color:var(--muted); } .b-suspended { background:var(--yellow-soft); color:var(--warn-ink); }
    .b-revoked { background:var(--danger-soft); color:var(--danger); } .b-pending { background:var(--surface-2); color:var(--link); }
    label { display:block; font-weight:500; margin:12px 0 4px; }
    input, select, textarea { width:100%; padding:13px 14px; border:1.5px solid var(--line); border-radius:12px; background:var(--bg); color:var(--ink); font:inherit; }\n    input:focus, select:focus, textarea:focus { border-color:var(--brand); outline:none; box-shadow:0 0 0 4px color-mix(in srgb, var(--brand) 20%, transparent); }
    input[readonly] { background:var(--surface-2); color:var(--muted); cursor:not-allowed; border-style:dashed; }
    input[type=checkbox], input[type=radio] { width:auto; margin-right:6px; }
    .row { display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); }
    .hint { color:var(--muted); font-size:.92rem; margin:4px 0 0; }
    button, .btn { display:inline-flex; align-items:center; justify-content:center; padding:12px 22px; border:1px solid var(--brand); border-radius:12px; background:var(--brand); color:var(--brand-ink); font:inherit; font-weight:700; cursor:pointer; text-decoration:none; }
    button.secondary, .btn.secondary { background:transparent; color:var(--link); border-color:var(--teal); }
    button.danger { background:var(--danger); border-color:var(--danger); color:var(--brand-ink); }
    button.danger.outline { background:transparent; color:var(--danger); }
    .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
    .flash { padding:12px 16px; border-radius:12px; margin-bottom:20px; font-weight:600; border:1px solid color-mix(in srgb, var(--brand) 35%, transparent); color:var(--brand-2); background:color-mix(in srgb, var(--brand) 10%, var(--card)); }
    .errors { padding:12px 16px; border-radius:12px; margin-bottom:16px; border:1px solid var(--danger); color:var(--danger); background:var(--card); }
    .key { font:600 1.05rem ui-monospace, SFMono-Regular, Menlo, monospace; word-break:break-all; padding:12px; border:2px solid var(--teal); border-left:8px solid var(--yellow); border-radius:var(--r-control); margin:8px 0; user-select:all; background:var(--yellow-soft); }
    dl { display:grid; grid-template-columns:max-content 1fr; gap:6px 18px; margin:0; }
    dt { color:var(--muted); } dd { margin:0; word-break:break-word; }
    .chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
    .muted { color:var(--muted); }
    .filters { display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); align-items:end; }
    .pager { display:flex; justify-content:space-between; margin-top:12px; }
    .checks { display:grid; gap:4px 18px; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); }
    .checks label { font-weight:400; margin:2px 0; }
    .login { max-width:420px; margin:28px auto; text-align:center; }
    .login form { text-align:left; }
    footer.site { max-width:var(--max); margin:0 auto; padding:28px 20px 40px; border-top:1px solid var(--line); color:var(--muted); font-size:.9rem; }
    code, pre { font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:.92em; }
    code { background:var(--code-bg); padding:1px 5px; border-radius:4px; }
    /* Pagine di documentazione */
    .prose { max-width:880px; }
    .prose h1 { font-size:1.9rem; } .prose h2 { font-size:1.35rem; margin-top:34px; padding-top:6px; border-top:1px solid var(--line); }
    .prose h3 { font-size:1.1rem; margin:24px 0 6px; }
    .prose pre { background:var(--code-bg); border:1px solid var(--line); border-radius:var(--r-control); padding:14px; overflow-x:auto; line-height:1.45; }
    .prose table th, .prose table td { border:1px solid var(--line); padding:8px 10px; }
    .prose table th { background:var(--surface-2); }
    .prose h2:first-child { border-top:0; margin-top:0; }
    .prose li { margin:4px 0; }
    .prose pre code { background:none; padding:0; }
    .prose table { display:block; overflow-x:auto; }
    .prose blockquote { margin:14px 0; padding:2px 16px; border-left:5px solid var(--yellow); background:var(--yellow-soft); border-radius:0 var(--r-control) var(--r-control) 0; }
    .docs-layout { display:grid; gap:28px; grid-template-columns:230px 1fr; align-items:start; }
    .docs-nav { position:sticky; top:12px; }
    .docs-nav ul { list-style:none; margin:0; padding:0; }
    .docs-nav a { display:block; padding:6px 10px; border-radius:var(--r-control); text-decoration:none; color:var(--ink); }
    .docs-nav a[aria-current="page"] { background:var(--yellow-soft); border:1px solid var(--line); border-left:5px solid var(--yellow); font-weight:600; color:var(--link); }
    @media (max-width:640px) {
        .bar { gap:4px 12px; padding:8px 16px; }
        .bar nav { flex:1 1 100%; flex-wrap:nowrap; overflow-x:auto; gap:4px; scrollbar-width:none; }
        .bar nav a, .bar nav button { flex:none; white-space:nowrap; display:inline-flex; align-items:center; min-height:44px; padding:0 8px; }
        main a:not(.btn) { padding-block:2px; }
        main li { margin-bottom:6px; }
        .btn, button { min-height:44px; }
    }
    @media (max-width:800px) { .docs-layout { grid-template-columns:1fr; } .docs-nav { position:static; } dl { grid-template-columns:1fr; } dt { margin-top:8px; } }
    @media (max-width:640px) { dl { grid-template-columns:1fr; } dt { margin-top:8px; } }
</style>
