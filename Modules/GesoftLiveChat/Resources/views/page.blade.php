{{--
    The chat as a page of its own, for a link: /chat.

    The bubble's script in page mode — open, filling the window, with no
    launcher to find. Everything the visitor sees is the bubble's; this page
    only gives it a ground to stand on.
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title !== '' ? $title : ($lang === 'en' ? 'Support' : 'Asistență') }}</title>
    <style>
        :root { color-scheme: light dark; }
        html, body { margin: 0; height: 100%; background: #e9efee; }
        noscript p { margin: 0; padding: 3rem 1.25rem; text-align: center; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #16201f; }
        @media (prefers-color-scheme: dark) {
            html, body { background: #0b1112; }
            noscript p { color: #e3eae8; }
        }
    </style>
</head>
<body>
<noscript><p>{{ $lang === 'en' ? 'The chat needs JavaScript switched on.' : 'Chatul are nevoie de JavaScript activat.' }}</p></noscript>
<script src="{{ asset('modules/gesoftlivechat/js/widget.js') }}?v={{ $version }}"
        data-display="page"
        data-lang="{{ $lang }}"
        @if ($title !== '') data-title="{{ $title }}" @endif></script>
</body>
</html>
