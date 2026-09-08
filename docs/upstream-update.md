# Keeping up with upstream

FreeScout releases often, including security fixes. This fork has to follow them
deliberately rather than automatically: an in-place updater that overwrites the
tree would take our modules and our `.gitignore` with it.

## The workflow

    git fetch upstream --tags

    # read what changed before merging anything
    #   https://github.com/freescout-help-desk/freescout/releases

    git checkout main
    git merge <new tag>          # e.g. 1.8.240

    # resolve conflicts — there should be almost none: this fork touches
    # .gitignore and adds directories upstream does not have

    php artisan config:clear
    php artisan migrate --force

    # tests
    vendor/bin/phpunit                       # FreeScout's own
    php artisan module:list                  # our modules load
    # exercise a conversation view, a login, and the remote-support panel

    # staging first, production after

## A trap in upstream's own test workflows

`.github/workflows/test.yml` and `test-pgsql.yml` run on pushes to `master` and
are written for that line, where `vendor/` is *not* committed. Dispatched
against anything derived from `dist` — which is what this fork is — they fail at
`composer install`:

    Could not scan for classes inside ".../vendor/rap2hpoutre/laravel-log-viewer/src/controllers"
    which does not appear to be a file nor a folder

This was measured, not assumed: the same workflow fails identically on this
fork's untouched `dist` branch. It is a property of running a master-oriented
workflow on a dist tree, not a sign that anything here is broken. Run those
suites against a master-derived checkout, or read them as upstream's own signal
rather than ours.

What does run on every push here is `gesoft-secret-scan.yml`, which is this
fork's own.

Tag what you deploy, and record the tag with the deployment. That is what makes
"which source is this instance running" answerable, which the AGPL source link
in the footer implicitly promises.

## Why merges should stay small

The project rule is to prefer modules and configuration hooks over core
modifications. Every core file this fork edits is a file that can conflict on
every upstream release, so the cost of a core patch is paid again at each
update, not once. Today the fork edits exactly one file outside its own
directories — `.gitignore` — and everything else lives in `Modules/` and
`public/brand/`, which upstream never touches.

When something cannot be done through a hook, the honest options in order are:
ask upstream for a hook, carry the smallest possible patch and record it in
`CHANGES-GESOFT.md`, or do without.

## FreeScout's own updater

FreeScout can update itself from the interface. On a fork that is a hazard: it
fetches upstream's release and writes it over the tree, which is precisely the
control this workflow exists to keep.

Before this fork is deployed anywhere, the built-in updater must be disabled or
restricted, and updates must come from git. That change belongs to the
deployment, not to this repository, and it has not been made on any running
system yet.
