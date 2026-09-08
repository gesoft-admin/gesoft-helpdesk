<?php

/*
| Included by the module loader as the module boots — this is what `files` in
| module.json names, and it is the only thing that ever loads this module's
| routes. The service provider does not: nwidart/laravel-modules loads route
| files from here, so a module without this file registers its views, hooks and
| migrations perfectly well and then has no routes at all. The panel renders,
| and `route('gesoftremotesupport.start')` throws "Route not defined" while
| drawing it.
|
| The cache guard is the stub's and is load-bearing: `php artisan route:cache`
| compiles every route into one file, and requiring this one afterwards would
| register them a second time on top of the cached set.
*/

if (!app()->routesAreCached()) {
    require __DIR__.'/Http/routes.php';
}
