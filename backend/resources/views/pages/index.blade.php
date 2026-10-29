<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pages</title>
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
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        h1 {
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
        }

        ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        li {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
        }

        .empty {
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <main>
        <h1>Pages</h1>
        @if ($pages->isEmpty())
            <p class="empty">No pages published yet.</p>
        @else
            <ul>
                @foreach ($pages as $item)
                    <li><a href="{{ route('pages.show', $item->slug) }}">{{ $item->title }}</a></li>
                @endforeach
            </ul>
        @endif
    </main>
</body>
</html>
