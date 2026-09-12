{{--
    The chat as a panel inside another application's page: /chat/embed.

    Deliberately thinner than the /chat page. There is no source link, because
    this page is never the only thing a person sees of this helpdesk -- they are
    inside an application of ours -- and no ground colour of its own beyond what
    keeps the frame from flashing white before the bubble paints.

    `data-embed` carries the one origin this page may be framed by and may talk
    to. The header already told the browser the same thing in `frame-ancestors`;
    this is so the script can address its messages rather than broadcast them.
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title !== '' ? $title : ($lang === 'en' ? 'Support' : 'Asistență') }}</title>
    <style>
        :root { color-scheme: {{ $theme !== '' ? $theme : 'light dark' }}; }
        html, body { margin: 0; height: 100%; background: {{ $color !== '' ? '#eef1f4' : '#e9efee' }}; }
        noscript p { margin: 0; padding: 2rem 1.25rem; text-align: center; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #16201f; }
        @if ($theme !== 'light')
        @if ($theme === '') @media (prefers-color-scheme: dark) { @endif
            html, body { background: #0b1112; }
            noscript p { color: #e3eae8; }
        @if ($theme === '') } @endif
        @endif
    </style>
</head>
<body>
<noscript><p>{{ $lang === 'en' ? 'The chat needs JavaScript switched on.' : 'Chatul are nevoie de JavaScript activat.' }}</p></noscript>
<script src="{{ asset('modules/gesoftlivechat/js/widget.js') }}?v={{ $version }}"
        data-display="embed"
        data-embed="{{ $origin }}"
        data-lang="{{ $lang }}"
        @if ($title !== '') data-title="{{ $title }}" @endif
        @if ($color !== '') data-color="{{ $color }}" @endif
        @if ($theme !== '') data-theme="{{ $theme }}" @endif
        @if ($sheet !== '') data-stylesheet="{{ $sheet }}" @endif></script>
</body>
</html>
