<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->title }}</title>
    <style>
        :root {
            --surface: #0f172a;
            --background: #020617;
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --accent: #2dd4bf;
            --border: #1e293b;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            line-height: 1.6;
        }

        main {
            max-width: 720px;
            margin: 0 auto;
            padding: 3rem 1.5rem 5rem;
        }

        a {
            color: var(--accent);
        }

        h1, h2, h3 {
            line-height: 1.3;
        }

        h1 {
            font-size: 1.75rem;
            margin-bottom: 0.25rem;
        }

        .meta {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-bottom: 2rem;
        }

        .content {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 1.5rem 2rem;
        }

        .content pre {
            overflow-x: auto;
        }

        code {
            background: var(--border);
            padding: 0.1rem 0.35rem;
            border-radius: 0.25rem;
        }

        .back {
            display: inline-block;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <main>
        <a class="back" href="{{ route('pages.index') }}">&larr; All pages</a>
        <h1>{{ $page->title }}</h1>
        <div class="meta">Last updated {{ $page->updated_at->toFormattedDateString() }}</div>
        <article class="content">
            {!! $html !!}
        </article>
    </main>
</body>
</html>
