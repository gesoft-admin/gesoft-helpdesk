<?php
/**
 * Branding filters, checked without booting Laravel.
 *
 * Run it directly:  php Modules/GesoftBranding/Tests/branding-filters.php
 *
 * Not a PHPUnit case on purpose. What is worth checking here is pure: given a
 * configuration, what do the four filters return. Booting the framework to ask
 * that would test the framework. The Laravel pieces the provider touches are
 * stubbed below, so a failure here is a failure in our code.
 */
namespace Illuminate\Support { class ServiceProvider { public function __construct($app = null) {} } }

namespace {
    $CONFIG = [];
    function config($key, $default = null) { global $CONFIG; return array_key_exists($key, $CONFIG) ? $CONFIG[$key] : $default; }
    function asset($path) { return 'https://helpdesk.test/'.$path; }
    function public_path($path = '') { return __DIR__.'/fixtures/public/'.$path; }
    function __($s, $r = []) { foreach ($r as $k => $v) { $s = str_replace(':'.$k, $v, $s); } return $s; }
    function env($k, $d = null) { return $d; }

    class EventyStub {
        public $filters = [];
        public $actions = [];
        public function addFilter($name, $cb, $prio = 20, $args = 1) { $this->filters[$name] = $cb; }
        public function addAction($name, $cb, $prio = 20, $args = 1) { $this->actions[$name] = $cb; }
        public function apply($name, ...$args) { return isset($this->filters[$name]) ? call_user_func_array($this->filters[$name], $args) : $args[0]; }
        public function fire($name, ...$args) { if (isset($this->actions[$name])) { call_user_func_array($this->actions[$name], $args); } }
    }
    class Eventy { public static $stub; public static function __callStatic($m, $a) { return call_user_func_array([self::$stub, $m], $a); } }
    Eventy::$stub = new EventyStub();

    require __DIR__.'/../Providers/GesoftBrandingServiceProvider.php';

    $defaults = require __DIR__.'/../Config/config.php';
    $pass = 0; $fail = 0;
    function check($label, $got, $want_substr) {
        global $pass, $fail;
        $ok = is_bool($want_substr) ? ($got === $want_substr) : (strpos((string) $got, $want_substr) !== false);
        if ($ok) { $pass++; printf("  ok    %-52s %s\n", $label, is_bool($got) ? var_export($got, true) : substr((string) $got, 0, 60)); }
        else { $fail++; printf("  FAIL  %-52s got: %s\n        wanted to contain: %s\n", $label, substr((string) $got, 0, 90), $want_substr); }
    }

    function boot(array $overrides = []) {
        global $CONFIG, $defaults;
        $CONFIG = [];
        foreach ($defaults as $k => $v) { $CONFIG['gesoftbranding.'.$k] = $v; }
        foreach ($overrides as $k => $v) { $CONFIG['gesoftbranding.'.$k] = $v; }
        $CONFIG['app.name'] = 'FreeScout';
        $CONFIG['app.freescout_url'] = 'https://freescout.net';
        Eventy::$stub = new EventyStub();
        $p = new Modules\GesoftBranding\Providers\GesoftBrandingServiceProvider(null);
        $p->hooks();
        return Eventy::$stub;
    }

    echo "--- defaults: an unbranded public build ---\n";
    $e = boot();
    check('title falls back to the neutral name', $e->apply('layout.title.name', 'FreeScout'), 'Helpdesk');
    check('logo resolves to the placeholder', $e->apply('layout.header_logo', '/img/logo-brand.svg'), 'brand/default-logo.svg');
    check('favicon resolves to the placeholder', $e->apply('layout.favicon', '/favicon.ico'), 'brand/default-favicon.svg');
    $footer = $e->apply('footer.text', '');
    check('footer keeps upstream copyright', $footer, 'freescout.net');
    check('footer offers the source', $footer, 'Source code');
    check('footer names the licence', $footer, 'AGPL-3.0');

    echo "\n--- branded instance ---\n";
    $e = boot(['brand_name' => 'Gesoft Support', 'brand_logo' => '/brand/logo.svg', 'brand_url' => 'https://support.example.com']);
    check('title takes the brand name', $e->apply('layout.title.name', 'FreeScout'), 'Gesoft Support');
    check('logo takes the operator path', $e->apply('layout.header_logo', '/x'), 'https://helpdesk.test/brand/logo.svg');
    $footer = $e->apply('footer.text', '');
    check('footer links the brand', $footer, 'href="https://support.example.com"');
    check('footer still keeps upstream', $footer, 'FreeScout');

    echo "\n--- edge cases ---\n";
    $e = boot(['brand_logo' => 'https://cdn.example.com/logo.svg']);
    check('absolute URL passes through', $e->apply('layout.header_logo', '/x'), 'https://cdn.example.com/logo.svg');
    $e = boot(['brand_logo' => '//cdn.example.com/logo.svg']);
    check('protocol-relative URL passes through', $e->apply('layout.header_logo', '/x'), '//cdn.example.com/logo.svg');
    $e = boot(['brand_name' => '   ', 'brand_logo' => '']);
    check('blank name falls back to core value', $e->apply('layout.title.name', 'FreeScout'), 'FreeScout');
    check('blank logo falls back to core value', $e->apply('layout.header_logo', '/img/logo-brand.svg'), '/img/logo-brand.svg');
    $e = boot(['source_link' => false]);
    check('source link can be turned off', strpos($e->apply('footer.text', ''), 'Source code') === false, true);
    $e = boot(['brand_name' => 'A & B <script>']);
    check('brand name is escaped in the footer', $e->apply('footer.text', ''), 'A &amp; B &lt;script&gt;');

    echo "\n--- login banner, theme colour, stylesheet ---\n";
    $e = boot();
    check('login banner falls back to the logo', $e->apply('login.banner', '/img/banner.png'), 'brand/default-logo.svg');
    check('theme colour stays core\'s when unset', $e->apply('layout.theme_color', '#ffffff'), '#ffffff');
    check('no stylesheet is added when unset', count($e->apply('stylesheets', ['/css/style.css'])), 1);
    $e = boot(['brand_banner' => '/brand/banner.svg', 'brand_color' => '#1F6FEB', 'brand_stylesheet' => '/brand.css']);
    check('login banner takes its own file', $e->apply('login.banner', '/img/banner.png'), 'https://helpdesk.test/brand/banner.svg');
    check('theme colour takes the brand colour', $e->apply('layout.theme_color', '#ffffff'), '#1F6FEB');
    $styles = $e->apply('stylesheets', ['/css/style.css']);
    check('the stylesheet comes after core\'s', end($styles), '/brand.css');
    $e = boot(['brand_stylesheet' => '/brand/missing.css']);
    check('a stylesheet that is not there is not added', count($e->apply('stylesheets', ['/css/style.css'])), 1);
    $e = boot(['brand_stylesheet' => 'https://cdn.example.com/brand.css']);
    check('a remote stylesheet is not added', count($e->apply('stylesheets', ['/css/style.css'])), 1);
    $e = boot(['brand_stylesheet' => '/brand/../../.env']);
    check('a path out of public is not added', count($e->apply('stylesheets', ['/css/style.css'])), 1);
    $e = boot(['brand_color' => 'red; background:url(x)']);
    check('a colour that is not #rrggbb is ignored', $e->apply('layout.theme_color', '#ffffff'), '#ffffff');

    echo "\n--- the frame around a customer reply ---\n";
    $e = boot();
    check('off by default: no header', $e->apply('reply_email.header', '') === '', true);
    check('off by default: subject untouched', $e->apply('email.reply_to_customer.subject', 'Re: X', (object) ['number' => 7]), 'Re: X');
    $e = boot(['mail_layout' => true, 'brand_color' => '#1F6FEB', 'mail_logo' => '/brand/mail.png', 'mail_name' => 'Example <Support>', 'mail_subject_tag' => 'EX']);
    $header = $e->apply('reply_email.header', '');
    check('the bar takes the brand colour', $header, 'background:#1F6FEB');
    check('the logo is an absolute URL', $header, 'src="https://helpdesk.test/brand/mail.png"');
    check('its alt text is the name, escaped', $header, 'alt="Example &lt;Support&gt;"');
    $e->fire('reply_email.before_signature', null, null, null, (object) ['number' => 42]);
    $footer = $e->apply('reply_email.footer', '');
    check('the footer names the request', $footer, 'request #42');
    check('and closes every table the header opened', substr_count($header.$footer, '<table') === substr_count($header.$footer, '</table>'), true);
    check('the subject carries the tag and number', $e->apply('email.reply_to_customer.subject', 'Re: X', (object) ['number' => 42]), '[EX #42] Re: X');
    $e->apply('reply_email.header', '');
    check('a new mail does not inherit the last one\'s request', $e->apply('reply_email.footer', ''), 'Reply to this email');
    $e = boot(['mail_layout' => true, 'brand_color' => 'red;x:y', 'brand_name' => 'Plain']);
    $header = $e->apply('reply_email.header', '');
    check('a colour that is not #rrggbb is not written', strpos($header, 'red;x:y') === false, true);
    check('without a logo the name is printed', $header, '>Plain</td>');

    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail ? 1 : 0);
}
