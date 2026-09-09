<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['fa','ar','he']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <meta name="color-scheme" content="light dark">
    <link rel="stylesheet" href="{{ asset('editor/design.css') }}">
    <link rel="stylesheet" href="{{ asset('editor/panel.css') }}">
</head>
<body>
    <div id="app" data-api="{{ url('/v1') }}"></div>
    <script src="{{ asset('editor/js/icons.js') }}"></script>
    <script src="{{ asset('editor/js/chart.js') }}"></script>
    <script src="{{ asset('editor/js/chart-ops.js') }}"></script>
    <script src="{{ asset('editor/js/editor.js') }}"></script>
    <script src="{{ asset('editor/js/inspector.js') }}"></script>
    <script src="{{ asset('editor/js/sites.js') }}"></script>
    <script src="{{ asset('editor/js/tickets.js') }}"></script>
    <script src="{{ asset('editor/js/modules.js') }}"></script>
    <script src="{{ asset('editor/js/team.js') }}"></script>
    <script src="{{ asset('editor/js/pricing.js') }}"></script>
    <script src="{{ asset('editor/js/i18n.js') }}"></script>
    <script src="{{ asset('editor/js/panel.js') }}"></script>
</body>
</html>
