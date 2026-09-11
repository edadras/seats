{{--
    No signal.

    Standalone on purpose: no stylesheet, no script, no font. This page exists precisely when the
    network does not, and a page that says "you are offline" by fetching two files to say it is a
    page that shows a browser error instead. Everything it needs is in the bytes.

    The venue's own colours are inlined from the site's brand, so the one page somebody sees when
    things have gone wrong is still recognisably theirs.
--}}
@php($locale = app()->getLocale())
@php($tokens = $brand['tokens'] ?? [])
<!doctype html>
<html lang="{{ $locale }}" dir="{{ \App\Support\Locale\Locales::direction($locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>{{ $title }}</title>
    <style>
        :root {
            color-scheme: light;
            --surface: {{ $tokens['surface'] ?? '#ffffff' }};
            --text: {{ $tokens['text'] ?? '#171a21' }};
            --muted: {{ $tokens['text_muted'] ?? '#5f6878' }};
            --accent: {{ $tokens['accent'] ?? '#4a4fdc' }};
            --on-accent: {{ $tokens['on_accent'] ?? '#ffffff' }};
            --border: {{ $tokens['border'] ?? '#e3e6ec' }};
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-block-size: 100dvh;
            display: grid;
            place-items: center;
            padding: max(1.5rem, env(safe-area-inset-top)) 1.5rem max(1.5rem, env(safe-area-inset-bottom));
            font: 1rem/1.6 system-ui, sans-serif;
            color: var(--text);
            background: var(--surface);
        }

        main { max-inline-size: 26rem; text-align: center; }

        .mark {
            inline-size: 3.5rem;
            block-size: 3.5rem;
            margin: 0 auto 1.25rem;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: color-mix(in srgb, var(--accent) 12%, var(--surface));
            color: var(--accent);
        }

        h1 { margin: 0 0 0.5rem; font-size: 1.5rem; line-height: 1.2; }
        p { margin: 0 0 1.5rem; color: var(--muted); }

        .acts { display: flex; flex-wrap: wrap; gap: 0.6rem; justify-content: center; }

        a, button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-block-size: 2.75rem;
            padding-inline: 1.2rem;
            border-radius: 10px;
            border: 1px solid var(--border);
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            color: inherit;
            background: transparent;
            cursor: pointer;
        }

        .primary { border-color: var(--accent); background: var(--accent); color: var(--on-accent); }
    </style>
</head>
<body>
<main>
    <div class="mark" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M2 5.5 22 19.5" stroke-linecap="round"/>
            <path d="M5 12.5a10 10 0 0 1 4-2.3M2 9a15 15 0 0 1 5-3M22 9a15 15 0 0 0-9.5-3.4"/>
            <path d="M8.5 16a5 5 0 0 1 7 0"/><circle cx="12" cy="19.5" r="1"/>
        </svg>
    </div>

    <h1>{{ __('site.app.offlineTitle') }}</h1>
    <p>{{ __('site.app.offlineBody') }}</p>

    <div class="acts">
        {{-- Reloading is the only thing that can help, so it is the loud button. --}}
        <button class="primary" type="button" onclick="location.reload()">{{ __('site.app.retry') }}</button>
        <a href="/">{{ __('site.app.home') }}</a>
    </div>
</main>
</body>
</html>
