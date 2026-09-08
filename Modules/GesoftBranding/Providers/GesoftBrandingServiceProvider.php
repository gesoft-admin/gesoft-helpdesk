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
 * Four filters in `resources/views/layouts/app.blade.php` decide what a visitor
 * sees before anything else does: the name in the title, the favicon, the
 * header logo and the footer. Because there is one layout for both the login
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
