{{--
    The platform's own console.

    A page of its own, not a screen inside the organiser panel. They share a stylesheet and nothing
    else: no route, no permission, no JavaScript. An operator's console that lived inside a
    customer's panel would be one bug away from being reachable by a customer.

    Its catalogue is rendered into the page rather than fetched from `/v1/i18n`, which is public and
    serves every panel visitor: there is no reason for an organiser's browser to download the words
    of a screen they may not open. The language is whatever LocaleResolver settled on, so `?lang=xx`
    switches it and the session remembers the choice.
--}}
@php
    $locale = app()->getLocale();
    $catalogue = [
        'locale' => $locale,
        'dir' => \App\Support\Locale\Locales::direction($locale),
        'icu' => \App\Support\Locale\Locales::icu($locale),
        'locales' => \App\Support\Locale\Locales::menu(),
        'messages' => [
            'console' => require lang_path($locale.'/console.php'),
            // Role names are the panel's, and there is one set of them. A second copy here would
            // be a second translation of the same six words.
            'team' => ['roles' => (require lang_path($locale.'/team.php'))['roles']],
        ],
    ];
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $catalogue['dir'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} · {{ __('console.brand') }}</title>
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="{{ asset('editor/design.css') }}">
    <link rel="stylesheet" href="{{ asset('editor/panel.css') }}">
</head>
<body>
    <div id="console"
         data-api="{{ url('/v1') }}"
         data-i18n="{{ json_encode($catalogue, JSON_UNESCAPED_UNICODE) }}"></div>

    <script src="{{ asset('editor/js/icons.js') }}"></script>
    <script src="{{ asset('editor/js/console.js') }}"></script>
</body>
</html>
