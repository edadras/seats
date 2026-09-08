{{--
    The shell every hosted page is rendered into.

    Server-rendered on purpose: an event site has to be indexable and fast on a phone on venue
    Wi-Fi, and a blank page that fetches JSON is neither (ADR-0003).
--}}
<!doctype html>
<html lang="{{ $site->locale }}" dir="{{ in_array($site->locale, ['fa','ar','he']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:type" content="website">
    @if ($canonical)
        <link rel="canonical" href="{{ $canonical }}">
        <meta property="og:url" content="{{ $canonical }}">
    @endif
    <link rel="stylesheet" href="{{ asset('site/css/site.css') }}">
    <link rel="stylesheet" href="{{ asset('site/css/themes/'.$site->theme_key.'.css') }}">
    {{-- Brand overrides last, so an organiser's colour wins over the theme's default. --}}
    <style>
        :root {
            --accent: {{ $brand['accent'] }};
            --font-heading: {{ $brand['heading_family'] }};
            --font-body: {{ $brand['body_family'] }};
            --radius: {{ $brand['radius_value'] }};
        }
    </style>
    @stack('head')
</head>
<body class="theme-{{ $site->theme_key }}">
<a class="skip" href="#main">Skip to content</a>

<header class="masthead">
    <div class="shell masthead__inner">
        <a class="brand" href="/">
            @if ($brand['logo_url'])
                <img class="brand__logo" src="{{ $brand['logo_url'] }}" alt="{{ $site->name }}">
            @else
                <span class="brand__name">{{ $site->name }}</span>
            @endif
        </a>

        @if (count($headerMenu))
            <nav class="nav" aria-label="Main">
                @foreach ($headerMenu as $item)
                    <a class="nav__link" href="{{ $item['href'] }}"
                       @if ($item['new_tab']) target="_blank" rel="noopener" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
        @endif
    </div>
</header>

<main id="main">
    @yield('content')
</main>

<footer class="footer">
    <div class="shell footer__inner">
        <div>
            <p class="footer__name">{{ $site->name }}</p>
            @if ($brand['tagline'])
                <p class="footer__tagline">{{ $brand['tagline'] }}</p>
            @endif
        </div>

        @if (count($footerMenu))
            <nav class="nav nav--footer" aria-label="Footer">
                @foreach ($footerMenu as $item)
                    <a class="nav__link" href="{{ $item['href'] }}"
                       @if ($item['new_tab']) target="_blank" rel="noopener" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
        @endif
    </div>
</footer>

@stack('scripts')
</body>
</html>
