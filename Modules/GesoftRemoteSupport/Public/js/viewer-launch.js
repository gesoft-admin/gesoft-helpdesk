// Gesoft, 2026-11-08. AGPL-3.0-only. Loaded only with the viewer feature enabled.
(function ($) {
    'use strict';
    $(function () {
        var panel = document.getElementById('gesoft-rs-panel');
        if (!panel) return;
        var button = panel.querySelector('.gesoft-rs-web');
        if (!button) return;
        function refresh() {
            var id = panel.querySelector('.gesoft-rs-remote-id').textContent.trim();
            var disabled = panel.querySelector('.gesoft-rs-close').disabled || !/^[0-9]{1,20}$/.test(id);
            if (button.disabled !== disabled) button.disabled = disabled;
        }
        new MutationObserver(refresh).observe(panel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
        refresh();
        button.addEventListener('click', function () {
            if (button.disabled) return;
            var form = document.createElement('form');
            form.method = 'POST'; form.action = button.dataset.url; form.target = '_blank';
            var csrf = document.createElement('input');
            csrf.type = 'hidden'; csrf.name = '_token';
            csrf.value = document.querySelector('meta[name="csrf-token"]').content;
            form.appendChild(csrf); document.body.appendChild(form); form.submit(); form.remove();
        });
    });
})(jQuery);
