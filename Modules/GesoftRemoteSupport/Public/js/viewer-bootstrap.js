// Gesoft, 2026-11-08. AGPL-3.0-only.
const configuration = JSON.parse(document.getElementById('gesoft-viewer-config').textContent);
window.__RD__ = configuration;
// This first integration is attended. Do not reuse a credential left by an
// earlier viewer session on a shared operator workstation.
try { localStorage.removeItem('cortendesk.rdpw.' + configuration.peerId); } catch (_) {}
await import(new URL('../viewer/app.js', import.meta.url).href);
// Additional modes need their own policy and are outside this first integration.
for (const id of ['rd-btn-files', 'rd-btn-terminal', 'rd-btn-camera', 'rd-save-row']) {
    const element = document.getElementById(id);
    if (element) element.hidden = true;
}
const savePassword = document.getElementById('rd-save-pw');
if (savePassword) { savePassword.checked = false; savePassword.disabled = true; }
const password = document.getElementById('rd-password');
if (password) { password.value = ''; password.disabled = true; password.closest('.rd-input').hidden = true; }
const help = document.querySelector('.rd-help');
if (help) help.textContent = 'Solicită conectarea, apoi așteaptă aprobarea clientului pe calculatorul său.';
const connect = document.getElementById('rd-connect');
if (connect) { connect.title = 'Solicită conectarea'; connect.setAttribute('aria-label', connect.title); }
