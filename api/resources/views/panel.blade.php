<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['fa','ar','he']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('editor/panel.css') }}">
</head>
<body>
    <div id="app" data-api="{{ url('/v1') }}"></div>
    <script src="{{ asset('editor/js/geometry.js') }}"></script>
    <script src="{{ asset('editor/js/editor.js') }}"></script>
    <script src="{{ asset('editor/js/panel.js') }}"></script>
</body>
</html>
