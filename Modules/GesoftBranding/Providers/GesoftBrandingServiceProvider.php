<?php

namespace Modules\GesoftBranding\Providers;

use Illuminate\Support\ServiceProvider;

// Module alias, as recommended by the FreeScout module guide.
if (!defined('GESOFT_BRANDING_MODULE')) {
    define('GESOFT_BRANDING_MODULE', 'gesoftbranding');
}

/**
 * Branding, entirely through published extension points.
 *
 * The filters in `resources/views/layouts/app.blade.php` decide what a visitor
 * sees before anything else does: the name in the title, the favicon, the
 * header logo, the theme colour, the stylesheets and the footer, plus the login
 * page's banner. Because there is one layout for both the login
 * screen and the application, filtering them covers the whole interface — so
 * this module patches no core file, and an upstream merge has nothing of ours
 * to collide with.
 */
class GesoftBrandingServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    public function boot()
    {
        $this->registerConfig();
        $this->hooks();
    }

    public function register()
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../Resources/lang');
    }

    public function hooks()
    {
        \Eventy::addFilter('layout.title.name', function ($name) {
            return $this->setting('brand_name') ?: $name;
        });

        \Eventy::addFilter('layout.header_logo', function ($url) {
            return $this->asset($this->setting('brand_logo')) ?: $url;
        });

        \Eventy::addFilter('layout.favicon', function ($url) {
            return $this->asset($this->setting('brand_favicon')) ?: $url;
        });

        // The login page has a banner of its own, FreeScout's wordmark, and it
        // is the first thing anyone sees. Without a banner of its own an
        // instance shows its logo there instead.
        \Eventy::addFilter('login.banner', function ($url) {
            return $this->asset($this->setting('brand_banner') ?: $this->setting('brand_logo')) ?: $url;
        });

        \Eventy::addFilter('layout.theme_color', function ($color) {
            $brand = $this->setting('brand_color');

            return preg_match('/^#[0-9a-f]{6}$/i', $brand) ? $brand : $color;
        });

        // Colours and shapes beyond a logo: one stylesheet of the operator's.
        // Last of all, at a priority no module uses, so it overrides core and
        // every module's own stylesheet, which are added at the default 20.
        \Eventy::addFilter('stylesheets', function ($styles) {
            $sheet = $this->stylesheet();
            if ($sheet !== '') {
                $styles[] = $sheet;
            }

            return $styles;
        }, 1000);

        // Replacing the footer rather than appending to it, because that is the
        // shape of the hook: core renders its own line only when this filter
        // returns nothing. So the upstream copyright is reproduced here — it is
        // required to stay, and dropping it is the one thing a rebrand must not
        // do.
        \Eventy::addFilter('footer.text', function ($text) {
            return $this->footer() ?: $text;
        });
    }

    /**
     * Read a branding value.
     *
     * Trimmed, and an empty string is treated as "not set" so that a blank line
     * in `.env` falls back to the default instead of blanking the interface.
     */
    protected function setting($key)
    {
        return trim((string) config('gesoftbranding.'.$key));
    }

    /**
     * Turn a configured asset into a URL.
     *
     * An absolute URL is used as it stands — a brand asset may well live on a
     * CDN. Anything else is a path under `public/`, which is where an operator
     * drops files that must not be committed.
     */
    protected function asset($value)
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('~^(https?:)?//~i', $value)) {
            return $value;
        }

        return asset(ltrim($value, '/'));
    }

    /**
     * The operator's stylesheet as core's minifier wants it, or an empty string.
     *
     * Only a file under `public/`, because the minifier reads and combines the
     * files itself, and it has to exist: a stylesheet it cannot read throws, and
     * the layout catches that by dropping every stylesheet on the page, core's
     * included. A missing brand file must cost the brand, not the interface.
     */
    protected function stylesheet()
    {
        $path = $this->setting('brand_stylesheet');
        if ($path === '' || preg_match('~^(https?:)?//~i', $path) || strpos($path, '..') !== false) {
            return '';
        }

        $path = '/'.ltrim($path, '/');

        return is_file(public_path(ltrim($path, '/'))) ? $path : '';
    }

    /**
     * The footer line: upstream's copyright, this instance's name, and the
     * offer of source.
     */
    protected function footer()
    {
        $name = htmlspecialchars($this->setting('brand_name'), ENT_QUOTES, 'UTF-8');
        $brand_url = $this->setting('brand_url');
        $upstream_url = htmlspecialchars((string) config('app.freescout_url'), ENT_QUOTES, 'UTF-8');
        $upstream_name = htmlspecialchars((string) config('app.name', 'FreeScout'), ENT_QUOTES, 'UTF-8');

        if ($brand_url !== '') {
            $name = '<a href="'.htmlspecialchars($brand_url, ENT_QUOTES, 'UTF-8').'">'.$name.'</a>';
        }

        $parts = [
            '&copy; 2018-'.date('Y').' <a href="'.$upstream_url.'" target="_blank" rel="noopener">'.$upstream_name.'</a>',
            $name,
        ];

        if (config('gesoftbranding.source_link') && $this->setting('source_url') !== '') {
            $source = htmlspecialchars($this->setting('source_url'), ENT_QUOTES, 'UTF-8');
            $parts[] = '<a href="'.$source.'" target="_blank" rel="noopener">'.__('Source code').'</a> (AGPL-3.0)';
        }

        return implode(' &middot; ', $parts);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'gesoftbranding');
    }
}
