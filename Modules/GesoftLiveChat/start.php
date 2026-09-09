<?php

/*
| Included by the module loader as the module boots — this is what `files` in
| module.json names, and it is the only thing that ever loads this module's
| routes. The service provider does not.
|
| The cache guard matters: `php artisan route:cache` compiles every route into
| one file, and requiring this one afterwards would register them twice.
*/

if (!app()->routesAreCached()) {
    require __DIR__.'/Http/routes.php';
}
