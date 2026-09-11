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
    {{-- `viewport-fit=cover` so the page reaches into a phone's rounded corners; every sticky
         thing below is padded with `env(safe-area-inset-*)` so nothing lands under the notch. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ $brand['tokens']['surface'] ?? '#ffffff' }}">
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
    {{--
        A venue's site, installable.

        The manifest, the tile and the worker are all served from the root of this host and all
        three are drawn from the site's own record — so what somebody keeps on their home screen is
        the venue's name in the venue's colour, not this platform's.
    --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/app-icon-192.png" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="/app-icon-180.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $site->name }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
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

        {{--
            One header, folded.

            A phone gets a button and a panel; anything wider gets the same links laid out in a row.
            One copy in the template, folded by the stylesheet — two copies of a menu is two menus,
            and the second one is always the one nobody remembers to change.

            The button is rendered hidden and revealed by the script, which is the right way round:
            with no JavaScript the links are simply *there*, as a short column under the name of the
            venue. A menu that cannot be opened is a header with no navigation in it at all.
        --}}
        <div class="menu">
            <button class="menu__button" type="button" data-menu hidden
                    aria-expanded="false" aria-controls="site-menu"
                    aria-label="{{ __('site.mainNav') }}">
                <svg class="menu__open" width="22" height="22" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16"/>
                </svg>
                <svg class="menu__shut" width="22" height="22" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                    <path d="m6 6 12 12M18 6 6 18"/>
                </svg>
            </button>

            <div class="menu__panel" id="site-menu">
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

                {{-- A site published in one language has nothing to switch between, and a control
                     that offers one choice is furniture that asks a question with one answer. --}}
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
        </div>
    </div>
</header>

<main id="main">
    {{-- A night being rehearsed says so, on every page of the path.
         Said once here rather than in each template: the one thing a rehearsal must never do is
         look like a real sale, and a banner that three pages remember and the fourth forgets is
         exactly how somebody ends up believing they have bought a ticket. --}}
    @if ($rehearsal ?? false)
        <div class="shell section section--tight">
            <p class="notice notice--rehearsal">
                <strong>{{ __('site.rehearsal.title') }}</strong>
                {{ __('site.rehearsal.body') }}
            </p>
        </div>
    @endif

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

        @if (count($footerMenu) || \App\Domain\Sites\Measurement::measures($site))
            <nav class="nav nav--footer" aria-label="{{ __('site.footerNav') }}">
                @foreach ($footerMenu as $item)
                    <a class="nav__link" href="{{ $item['href'] }}"
                       @if ($item['new_tab']) target="_blank" rel="noopener" @endif>{{ $item['label'] }}</a>
                @endforeach

                {{-- A way back to a question somebody has already answered. Only where there is a
                     question: a site that measures nothing has nothing to change your mind about. --}}
                @if (\App\Domain\Sites\Measurement::measures($site))
                    <a class="nav__link" href="#" data-consent-reopen>{{ __('site.cookies.change') }}</a>
                @endif
            </nav>
        @endif
    </div>
</footer>

{{-- Measurement, and the question that comes before it. Renders nothing at all on a site that
     measures nothing, which is most of them. --}}
@include('site.partials.measurement')

{{--
    Offered, never insisted on.

    Rendered hidden and shown only when the browser has said an install is actually possible — and
    then only once: somebody who said no has said no, and a shop that asks again every visit is a
    shop people stop visiting.
--}}
<div class="install" data-install hidden>
    <div class="shell install__inner">
        <p class="install__text">
            <strong>{{ __('site.app.install') }}</strong>
            <span class="install__hint">{{ __('site.app.installHint') }}</span>
        </p>
        <div class="install__acts">
            <button class="button button--quiet" type="button" data-install-no>{{ __('site.app.notNow') }}</button>
            <button class="button button--primary" type="button" data-install-yes>{{ __('site.app.install') }}</button>
        </div>
    </div>
</div>

{{--
    The site, kept on a phone.

    Two small jobs and nothing else. The worker is registered because the one thing a ticket shop
    owes a buyer is that the ticket opens at the door; the button is offered only when the browser
    itself says an install is possible, so nobody is told to do something their browser cannot.

    Deliberately not behind the consent bar: a service worker stores this site's own pages on the
    visitor's own device and tells nobody anything. It is function, not measurement.
--}}
<script>
    ( function () {
        /*
         * The header, folded.
         *
         * The button exists in the markup but is hidden, and this reveals it — so a visitor with
         * no JavaScript gets the links themselves rather than a button that does nothing. The
         * stylesheet decides whether the panel is a dropdown or a row; all this owns is the one
         * piece of state a stylesheet cannot hold, which is whether the thing is open.
         */
        var fold = document.querySelector( '[data-menu]' );
        var panel = fold && document.getElementById( 'site-menu' );

        if ( fold && panel ) {
            fold.hidden = false;
            document.querySelector( '.menu' ).classList.add( 'menu--folds' );

            fold.addEventListener( 'click', function () {
                fold.setAttribute( 'aria-expanded',
                    'true' === fold.getAttribute( 'aria-expanded' ) ? 'false' : 'true' );
            } );

            // Escape closes it, and puts the cursor back on the button that opened it.
            panel.addEventListener( 'keydown', function ( event ) {
                if ( 'Escape' === event.key ) {
                    fold.setAttribute( 'aria-expanded', 'false' );
                    fold.focus();
                }
            } );

            document.addEventListener( 'click', function ( event ) {
                if ( ! event.target.closest( '.menu' ) ) {
                    fold.setAttribute( 'aria-expanded', 'false' );
                }
            } );
        }

        if ( 'serviceWorker' in navigator ) {
            window.addEventListener( 'load', function () {
                navigator.serviceWorker.register( '/sw.js', { scope: '/' } )
                    // A worker that will not install is a site that works exactly as it did
                    // before, so there is nothing here to tell anybody about.
                    .catch( function () {} );
            } );
        }

        var bar = document.querySelector( '[data-install]' );
        var offer = bar && bar.querySelector( '[data-install-yes]' );
        var KEY = 'seatmap-install-asked';
        var prompt = null;

        if ( ! bar || ! offer ) {
            return;
        }

        function asked() {
            try {
                return '1' === window.localStorage.getItem( KEY );
            } catch ( error ) {
                // Storage turned off. Treated as "not asked", so the offer comes back each visit —
                // mildly annoying, and the only honest answer when there is nowhere to write it.
                return false;
            }
        }

        function remember() {
            try {
                window.localStorage.setItem( KEY, '1' );
            } catch ( error ) {}
        }

        window.addEventListener( 'beforeinstallprompt', function ( event ) {
            // Held rather than let through: the browser's own bar appears at whatever moment it
            // likes, and a prompt over a seat somebody is choosing is a prompt that gets dismissed.
            event.preventDefault();
            prompt = event;

            if ( ! asked() ) {
                bar.hidden = false;
            }
        } );

        offer.addEventListener( 'click', function () {
            if ( ! prompt ) {
                return;
            }

            bar.hidden = true;
            remember();
            prompt.prompt();
            prompt = null;
        } );

        bar.querySelector( '[data-install-no]' ).addEventListener( 'click', function () {
            bar.hidden = true;
            remember();
        } );

        // Installed from the browser's own menu, or already installed. Either way there is nothing
        // left to offer.
        window.addEventListener( 'appinstalled', function () {
            bar.hidden = true;
            remember();
        } );
    }() );
</script>

@stack('scripts')
</body>
</html>
