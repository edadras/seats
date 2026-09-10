{{--
    The shell every hosted page is rendered into.

    Server-rendered on purpose: an event site has to be indexable and fast on a phone on venue
    Wi-Fi, and a blank page that fetches JSON is neither (ADR-0003). Nothing on this page needs
    JavaScript to work — including the language menu, which is a <details>.
--}}
<!doctype html>
{{-- Direction comes from the locale this request actually resolved to, not from the site's own
     setting and not from a list of language codes copied into a template. A Persian buyer reading
     a German venue's site gets Persian text and a right-to-left page (ADR-0005 §3). --}}
@php($locale = app()->getLocale())
{{-- Only the languages this site is actually published in.

     The switcher used to offer all six the platform speaks, whatever the organiser had written —
     so a visitor could choose Italian and be handed a Persian page with English furniture. --}}
@php($languages = collect(\App\Support\Locale\Locales::menu())
    ->whereIn('code', $site->publishedLocales())
    ->values()
    ->all())
<html lang="{{ $locale }}" dir="{{ \App\Support\Locale\Locales::direction($locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $site->name }}">
    @if ($description)
        <meta property="og:description" content="{{ $description }}">
    @endif
    @if (! empty($image))
        {{-- What a link to this page looks like when it is pasted into a message. --}}
        <meta property="og:image" content="{{ $image }}">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta name="twitter:card" content="summary">
    @endif
    @if ($canonical)
        <link rel="canonical" href="{{ $canonical }}">
        <meta property="og:url" content="{{ $canonical }}">
    @endif
    @stack('meta')
    @if (! empty($jsonld))
        {{-- Structured data, so a search result can show the date and the price rather than a
             line of prose. JSON_HEX_TAG matters: without it a description containing "</script>"
             would close this element early, and everything after it would be markup somebody
             typed into a form. --}}
        <script type="application/ld+json">@json($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
    @endif
    <link rel="stylesheet" href="{{ asset('site/css/site.css') }}">
    <link rel="stylesheet" href="{{ asset('site/css/themes/'.$brand['base_key'].'.css') }}">
    {{-- Tokens last, so a custom theme and then the organiser's own brand win over the theme file.
         Every value in here came from a closed table in ThemeTokens; none of it is a string an
         organiser typed, because a font-family somebody typed is an injection into a stylesheet. --}}
    <style>{!! $brand['token_css'] !!}</style>
    @if ($brand['css'] !== '')
        {{-- The organiser's own stylesheet. Sanitised on the way in (App\Domain\Sites\ThemeCss):
             no angle brackets, so it cannot close this element; no @import; no url() that is not
             plainly a picture or a page. --}}
        <style>{!! $brand['css'] !!}</style>
    @endif
    @stack('head')
</head>
<body class="theme-{{ $brand['base_key'] }}">
<a class="skip" href="#main">{{ __('site.skipToContent') }}</a>

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
            <nav class="nav" aria-label="{{ __('site.mainNav') }}">
                @foreach ($headerMenu as $item)
                    <a class="nav__link" href="{{ $item['href'] }}"
                       @if ($item['new_tab']) target="_blank" rel="noopener" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
        @endif

        @if ($site->offersSignIn())
            <a class="nav__link nav__link--account" href="/account">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" aria-hidden="true">
                    <path d="M4 20a8 8 0 0 1 16 0"/><circle cx="12" cy="8" r="4"/>
                </svg>
                {{ __('site.account.link') }}
            </a>
        @endif

        {{-- A site published in one language has nothing to switch between, and a control that
             offers one choice is furniture that asks a question with one answer. --}}
        @if (count($languages) > 1)
        <details class="langs">
            <summary class="langs__button" aria-label="{{ __('site.language') }}">
                <svg class="langs__globe" width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15 0 18M12 3c-2.5 2.7-2.5 15 0 18"/>
                </svg>
                {{ collect($languages)->firstWhere('code', $locale)['native'] ?? $locale }}
                <svg class="langs__caret" width="12" height="12" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m5 9 7 7 7-7"/></svg>
            </summary>

            <div class="langs__menu">
                @foreach ($languages as $option)
                    @if ($option['code'] === $locale)
                        <span class="langs__current" aria-current="true">{{ $option['native'] }}</span>
                    @else
                        {{-- Keeps the visitor where they are: same path, same query, one parameter more. --}}
                        <a class="langs__link" hreflang="{{ $option['code'] }}"
                           href="{{ request()->fullUrlWithQuery(['lang' => $option['code']]) }}">{{ $option['native'] }}</a>
                    @endif
                @endforeach
            </div>
        </details>
        @endif
    </div>
</header>

<main id="main">
    {{-- Something the last request needs to say — a hold that expired while the buyer was away.
         Rendered here so it is said once, wherever they were sent. --}}
    @if (session('seatmap_message'))
        <div class="shell section section--tight">
            <p class="notice">{{ session('seatmap_message') }}</p>
        </div>
    @endif

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
            <nav class="nav nav--footer" aria-label="{{ __('site.footerNav') }}">
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
