{{--
    Shell for the password-reset pages reset emails open on a phone.
    Self-contained (inline CSS, no CDN, no JS) and styled like the apps:
    light page, white card, teal accent — see CLAUDE.md's Theme section.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        :root {
            --bg: #F6F7F9;
            --card: #FFFFFF;
            --border: #E4E7EC;
            --text: #101828;
            --muted: #5B6475;
            --accent: #0F766E;
            --error: #D92D20;
            --success: #12B76A;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            line-height: 1.5;
        }

        header {
            background: linear-gradient(135deg, #0E8F83, #0F766E 55%, #134E4A);
            color: #fff;
            padding: 32px 20px 56px;
            text-align: center;
        }

        header h1 { margin: 0 0 4px; font-size: 1.35rem; }
        header p { margin: 0; opacity: .85; font-size: .95rem; }

        main {
            max-width: 420px;
            margin: -36px auto 40px;
            padding: 0 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 24px 20px;
            box-shadow: 0 8px 24px rgba(16, 24, 40, .08);
        }

        label { display: block; font-weight: 600; font-size: .9rem; margin: 14px 0 6px; }

        input[type=password] {
            width: 100%;
            padding: 12px 14px;
            font-size: 1rem;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            color: var(--text);
        }

        input[type=password]:focus { outline: 2px solid var(--accent); border-color: transparent; }

        button, a.button {
            display: block;
            text-align: center;
            text-decoration: none;
            width: 100%;
            margin-top: 22px;
            padding: 14px;
            font-size: 1rem;
            font-weight: 600;
            color: #fff;
            background: var(--accent);
            border: 0;
            border-radius: 12px;
            cursor: pointer;
        }

        .muted { color: var(--muted); font-size: .9rem; }
        .email { font-weight: 600; word-break: break-all; }
        .errors { color: var(--error); font-size: .9rem; margin: 12px 0 0; padding-left: 18px; }
        .icon { font-size: 2.5rem; text-align: center; margin-bottom: 8px; }
        .center { text-align: center; }
    </style>
</head>
<body>
    <header>
        <h1>{{ config('app.name') }}</h1>
        <p>@yield('subtitle')</p>
    </header>
    <main>
        <div class="card">
            @yield('content')
        </div>
    </main>
</body>
</html>
