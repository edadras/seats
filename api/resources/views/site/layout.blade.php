{{--
    The shell every hosted page is rendered into.

    Server-rendered on purpose: an event site has to be indexable and fast on a phone on venue
    Wi-Fi, and a blank page that fetches JSON is neither (ADR-0003).
--}}
<!doctype html>
{{-- Direction comes from the locale this request actually resolved to, not from the site's own
     setting and not from a list of language codes copied into a template. A Persian buyer reading
     a German venue's site gets Persian text and a right-to-left page (ADR-0005 §3). --}}
@php($locale = app()->getLocale())
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
    @if ($canonical)
        <link rel="canonical" href="{{ $canonical }}">
        <meta property="og:url" content="{{ $canonical }}">
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

        <nav class="langs" aria-label="{{ __('site.language') }}">
            @foreach (\App\Support\Locale\Locales::menu() as $option)
                @if ($option['code'] === $locale)
                    <span class="langs__current" aria-current="true">{{ $option['native'] }}</span>
                @else
                    {{-- Keeps the visitor where they are: same path, same query, one parameter more. --}}
                    <a class="langs__link" hreflang="{{ $option['code'] }}"
                       href="{{ request()->fullUrlWithQuery(['lang' => $option['code']]) }}">{{ $option['native'] }}</a>
                @endif
            @endforeach
        </nav>

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
