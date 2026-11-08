<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Remote Support') }} — #{{ $conversation->number }}</title>
    <link rel="stylesheet" href="{{ $assets }}/app.css">
    <link rel="stylesheet" href="{{ Module::getPublicPath(GESOFT_REMOTE_SUPPORT_MODULE) }}/css/viewer.css">
</head>
<body>
    <div class="gesoft-viewer-help">{{ __('Browser viewer') }} · #{{ $conversation->number }} · {{ __('Closing this viewer keeps the support session available for the desktop client.') }} <a href="{{ $assets }}/source.tar.gz">{{ __('Source code') }}</a></div>
    <div id="rd-root"><div id="rd-toolbar"></div><div id="rd-viewport"><canvas id="rd-canvas"></canvas><div id="rd-overlay"></div></div></div>
    <script id="gesoft-viewer-config" type="application/json">{!! json_encode($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <script type="module" src="{{ Module::getPublicPath(GESOFT_REMOTE_SUPPORT_MODULE) }}/js/viewer-bootstrap.js"></script>
</body>
</html>
