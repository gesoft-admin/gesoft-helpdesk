// Gesoft, 2026-11-08. AGPL-3.0-only.
const configuration = JSON.parse(document.getElementById('gesoft-viewer-config').textContent);
window.__RD__ = configuration;
// This first integration is attended. Do not reuse a credential left by an
// earlier viewer session on a shared operator workstation.
try { localStorage.removeItem('cortendesk.rdpw.' + configuration.peerId); } catch (_) {}
// The upstream viewer treats any dropped connection as a network blip and keeps
// reconnecting; the gateway refuses each attempt once the support session has
// ended. Track its workers so the page can stop them, and ask the helpdesk
// whether the session is still the one this viewer was opened for.
const lifecycle = JSON.parse(document.getElementById('gesoft-viewer-lifecycle').textContent);
const workers = new Set();
let ended = false;
const NativeWorker = window.Worker;
window.Worker = class extends NativeWorker {
    constructor(...args) {
        super(...args);
        if (ended) { this.terminate(); return; }
        workers.add(this);
    }
};
function end(message) {
    if (ended) return;
    ended = true;
    for (const worker of workers) worker.terminate();
    workers.clear();
    const notice = document.createElement('div');
    notice.className = 'gesoft-viewer-ended';
    notice.setAttribute('role', 'status');
    const text = document.createElement('p');
    text.textContent = message;
    const hint = document.createElement('p');
    hint.textContent = 'Puteți închide această fereastră.';
    notice.append(text, hint);
    document.body.append(notice);
}
async function checkSession() {
    if (ended) return;
    if (Date.now() / 1000 >= lifecycle.expires) {
        end('Accesul din browser a expirat. Redeschideți-l din tichet, dacă sesiunea este încă activă.');
        return;
    }
    let response;
    try {
        response = await fetch(lifecycle.statusUrl, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    } catch (_) {
        return; // the helpdesk is unreachable, which is not evidence the session ended
    }
    if (response.status === 401 || response.status === 403 || response.status === 404 || response.redirected) {
        end('Nu mai sunteți autentificat în helpdesk sau nu mai aveți acces la acest tichet.');
        return;
    }
    if (!response.ok) return;
    let reply;
    try { reply = await response.json(); } catch (_) { return; }
    const state = reply && reply.state;
    if (state && (!state.is_active || state.session_ref !== lifecycle.session)) {
        end('Sesiunea de asistență a fost închisă din helpdesk.');
    }
}
setInterval(() => { if (!document.hidden) checkSession(); }, 3000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) checkSession(); });
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
