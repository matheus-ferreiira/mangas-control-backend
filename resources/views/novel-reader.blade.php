<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>{{ $chapter['title'] }} · {{ $novel['title'] }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; background: #f7f2e9; color: #29241e; font: 20px/1.85 Georgia, serif; }
        main, header, footer { max-width: 720px; margin: auto; padding: 24px; }
        header, footer { font: 15px/1.6 system-ui, sans-serif; }
        h1 { font-size: 1.6em; line-height: 1.3; }
        p { white-space: pre-wrap; overflow-wrap: anywhere; }
        nav { display: flex; justify-content: space-between; gap: 24px; margin-top: 20px; }
        a { color: #8a4b09; } small { display: block; }
        @media (prefers-color-scheme: dark) { body { background: #16181c; color: #e0ddd5; } a { color: #edba6c; } }
    </style>
</head>
<body>
    <header>
        <strong translate="no" class="notranslate">{{ $novel['title'] }}</strong>
        <p lang="pt-BR">Para traduzir, abra o menu do navegador e escolha “Traduzir” → Português. Este link expira em 12 horas. O progresso deve ser marcado no Neon Curator.</p>
        <nav aria-label="Chapter navigation">
            @if ($previousUrl)<a rel="noreferrer" href="{{ $previousUrl }}">← Previous chapter</a>@endif
            @if ($nextUrl)<a rel="noreferrer" href="{{ $nextUrl }}">Next chapter →</a>@endif
        </nav>
    </header>
    <main>
        <h1>{{ $chapter['title'] }}</h1>
        @foreach ($chapter['paragraphs'] as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    </main>
    <footer>
        <nav aria-label="Next chapter">
            @if ($previousUrl)<a rel="noreferrer" href="{{ $previousUrl }}">← Previous chapter</a>@endif
            @if ($nextUrl)<a rel="noreferrer" href="{{ $nextUrl }}">Next chapter →</a>@endif
        </nav>
    </footer>
</body>
</html>
