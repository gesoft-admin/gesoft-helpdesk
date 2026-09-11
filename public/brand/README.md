# Branding assets

The two `default-*` files here are placeholders and are the only brand assets
this repository ships. They exist so that a build which configures nothing still
renders something neutral.

To brand an instance, put your own files in this directory and point the
environment at them:

    HELPDESK_BRAND_NAME=Your Helpdesk
    HELPDESK_BRAND_LOGO=/brand/logo.svg
    HELPDESK_BRAND_FAVICON=/brand/favicon.svg
    HELPDESK_BRAND_URL=https://support.example.com
    HELPDESK_BRAND_BANNER=/brand/banner.svg
    HELPDESK_BRAND_STYLESHEET=/brand/brand.css
    HELPDESK_BRAND_COLOR=#1f6feb

The stylesheet loads after core's and every module's. The chat module's
operator colours are CSS properties (`--gesoft-chat-accent` and the others at
the top of its `operator.css`) that it can set.

Everything in this directory except the defaults and this file is ignored by
git, so an operator's marks stay out of the public repository. That is
deliberate: the software is AGPL-3.0, the brand is not — see `TRADEMARKS.md`.

An absolute URL works too, if the assets are served from elsewhere.
