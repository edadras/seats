{{--
    The platform's own console.

    A page of its own, not a screen inside the organiser panel. They share a stylesheet and nothing
    else: no route, no permission, no JavaScript. An operator's console that lived inside a
    customer's panel would be one bug away from being reachable by a customer.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['fa','ar','he']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} · Console</title>
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="{{ asset('editor/design.css') }}">
    <link rel="stylesheet" href="{{ asset('editor/panel.css') }}">
</head>
<body>
    <div id="console" data-api="{{ url('/v1') }}"></div>

    <script src="{{ asset('editor/js/icons.js') }}"></script>
    <script src="{{ asset('editor/js/console.js') }}"></script>
</body>
</html>
