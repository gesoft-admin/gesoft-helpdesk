<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pagină de test — chat Gesoft</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; padding: 3rem 1.25rem 6rem;
            font: 16px/1.65 system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f4f7f6; color: #16201f;
        }
        main { max-width: 40rem; margin: 0 auto; }
        h1 { font-size: 1.6rem; line-height: 1.2; margin: 0 0 .5rem; }
        p.lede { color: #5b696c; margin: 0 0 2rem; }
        section { background: #fff; border: 1px solid #e2e8e7; border-radius: 10px; padding: 1.1rem 1.25rem; margin-bottom: 1rem; }
        h2 { font-size: 1rem; margin: 0 0 .5rem; }
        ol { margin: 0; padding-left: 1.2rem; }
        li { margin-bottom: .35rem; }
        code { background: #eef2f1; padding: .1em .35em; border-radius: 3px; font-size: .88em; }
        @media (prefers-color-scheme: dark) {
            body { background: #0f1516; color: #e3eae8; }
            p.lede { color: #93a3a3; }
            section { background: #141b1c; border-color: #26312f; }
            code { background: #1b2425; }
        }
    </style>
</head>
<body>
<main>
    <h1>Pagină de test pentru chatul Gesoft</h1>
    <p class="lede">Nu este un produs. Este harnașamentul pe care se verifică transportul, înainte ca bula să fie pusă pe un site adevărat.</p>

    <section>
        <h2>Cum se face proba</h2>
        <ol>
            <li>Apăsați bula din colțul din dreapta jos și scrieți un mesaj.</li>
            <li>În FreeScout, deschideți folderul <strong>Chats</strong>. Conversația apare acolo.</li>
            <li>Răspundeți din <strong>Chat Mode</strong>. Răspunsul ajunge în bulă după circa 15 secunde — întârzierea de Undo a FreeScout, nu a noastră.</li>
            <li>Reîncărcați pagina: conversația continuă în același tab. Un tab nou pornește o conversație nouă, iar după închiderea browserului nu rămâne nimic — tokenul stă în <code>sessionStorage</code>.</li>
            <li><strong>Încheie</strong>, din capul bulei, oprește conversația pentru vizitator; în FreeScout apare o linie care spune asta. Închiderea tabului apare ca „a părăsit chatul" după circa două minute fără revenire.</li>
        </ol>
    </section>

    <section>
        <h2>Ce demonstrează</h2>
        <p style="margin:0">Că vizitatorul nu are nevoie de cont, de email sau de sesiune, și că mesajele lui devin o conversație FreeScout obișnuită — cu Remote Support în același sidebar. Bula nu conține niciun token al nostru și nu atinge nicio rută de operator.</p>
    </section>
</main>

{{-- ?lang=en or ?lang=ro picks the bubble's language; without it the bubble
     follows this page, which is Romanian. --}}
<script src="{{ asset('modules/gesoftlivechat/js/widget.js') }}"
        data-title="Asistență Gesoft"
        data-lang="{{ in_array(request('lang'), ['ro', 'en'], true) ? request('lang') : '' }}"
        @if ($color !== '') data-color="{{ $color }}" @endif
        @if ($theme !== '') data-theme="{{ $theme }}" @endif
        @if ($sheet !== '') data-stylesheet="{{ $sheet }}" @endif></script>
</body>
</html>
