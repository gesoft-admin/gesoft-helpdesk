#!/usr/bin/env python3
"""
End-to-end checks of the visitor endpoints against a running test instance.

    GLC_BASE=http://freescout-test.local \
    GLC_SQL="ssh test-vm 'cd /var/www/freescout && sudo -n mysql -N freescout'" \
    GLC_ARTISAN="ssh test-vm 'cd /var/www/freescout && sudo -n -u www-data php artisan'" \
    python3 Modules/GesoftLiveChat/Tests/e2e/visitor-api.py

Optional: GLC_AGENT_EMAIL and GLC_AGENT_PASSWORD also check what the agent's
chat endpoint reports about presence.

**Never point this at production.** It clears the application cache (the
start limit is per address, and a test run is one address), rewrites session
timestamps, runs the sweep, and closes the conversations it opened.

GLC_SQL is a shell command that reads one SQL statement on stdin and prints
rows tab-separated. GLC_ARTISAN is a shell command prefix that artisan
arguments are appended to.

Python standard library only, so it runs wherever the test is launched from.
"""

import hashlib
import json
from http.cookiejar import CookieJar
import os
import re
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ["GLC_BASE"].rstrip("/")
SQL = os.environ["GLC_SQL"]
ARTISAN = os.environ["GLC_ARTISAN"]
START_LIMIT = int(os.environ.get("GLC_START_LIMIT", "3"))
RUN = secrets.token_hex(3)

passed = 0
failed = 0
opened = []


def check(label, got, want):
    global passed, failed
    ok = got == want
    if ok:
        passed += 1
        print(f"  ok    {label:<66} {got!r}")
    else:
        failed += 1
        print(f"  FAIL  {label:<66} got: {got!r}  wanted: {want!r}")


def http(method, path, body=None, headers=None, raw=None, content_type=None):
    url = BASE + "/gesoft-live-chat/" + path
    h = {"User-Agent": "Mozilla/5.0 (gesoft-live-chat-e2e)"}
    data = None
    if raw is not None:
        data = raw.encode()
        h["Content-Type"] = content_type or "text/plain"
    elif body is not None:
        data = json.dumps(body).encode()
        h["Content-Type"] = "application/json"
    h.update(headers or {})
    req = urllib.request.Request(url, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            return r.status, {k.lower(): v for k, v in r.headers.items()}, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, {k.lower(): v for k, v in e.headers.items()}, e.read().decode()


def body(resp):
    try:
        return json.loads(resp[2])
    except ValueError:
        return {}


def sql(statement):
    out = subprocess.run(SQL, shell=True, input=statement, capture_output=True, text=True, timeout=60)
    if out.returncode:
        raise RuntimeError(out.stderr.strip())
    return [line.split("\t") for line in out.stdout.strip().splitlines() if line]


def one(statement):
    rows = sql(statement)
    return rows[0][0] if rows else None


def artisan(args):
    subprocess.run(ARTISAN + " " + args, shell=True, capture_output=True, text=True, timeout=180, check=True)


def sha(token):
    return hashlib.sha256(token.encode()).hexdigest()


def start(name, email, message, headers=None, clear=True):
    if clear:
        artisan("cache:clear")
    r = http("POST", "start", {"name": name, "email": email, "message": message}, headers=headers)
    b = body(r)
    token = b.get("token")
    if token:
        conv = one(f"select conversation_id from gesoft_live_chat_sessions where token_hash='{sha(token)}'")
        if conv:
            opened.append(conv)
        return r, b, token, conv
    return r, b, None, None


def poll(token, since=0, headers=None):
    return body(http("GET", f"poll?token={urllib.parse.quote(token)}&since={since}", headers=headers))


def send(token, message):
    r = http("POST", "send", {"token": token, "message": message})
    return r[0], body(r)


def session_col(token, column):
    return one(f"select {column} from gesoft_live_chat_sessions where token_hash='{sha(token)}'")


def lines(conv, action):
    return one(f"select count(*) from threads where conversation_id={conv} and type=4 and action_type={action}")


def close(conv):
    sql(f"update conversations set status=3, closed_at=now() where id={conv}")


print("GesoftLiveChat — visitor endpoints, end to end\n")

# ------------------------------------------------------------------ the token
r, b, t1, c1 = start(f"E2E vizitator {RUN}", f"e2e-{RUN}@gesoft.test", f"Primul mesaj {RUN}")
check("start answers 200", r[0], 200)
check("the token is 64 hex characters", bool(re.fullmatch(r"[0-9a-f]{64}", t1 or "")), True)
check("only its hash is stored", one(f"select count(*) from gesoft_live_chat_sessions where token_hash='{t1}'"), "0")
check("the customer channel id is not the token",
      one(f"select count(*) from customer_channel where channel_id='{t1}' or channel_id like '%{(t1 or '')[:16]}%'"), "0")
p = poll(t1)
check("poll with the token returns the first message", [m["body"] for m in p.get("messages", [])], [f"Primul mesaj {RUN}"])
check("  and says the chat is open", p.get("closed"), False)
code, s = send(t1, f"Al doilea {RUN}")
check("send with the token works", code, 200)
check("a 32-character token of the old design opens nothing", poll(secrets.token_hex(16)).get("closed"), True)
check("a random 64-character token opens nothing", poll(secrets.token_hex(32)).get("closed"), True)

# ------------------------------------------- the takeover reproduced this morning
victim_email = f"e2e-victima-{RUN}@gesoft.test"
_, _, tv, cv = start(f"E2E victima {RUN}", victim_email, f"Mesaj privat {RUN}: codul este 1234")
_, _, ta, ca = start(f"E2E altcineva {RUN}", victim_email, f"salut {RUN}")
check("the same email typed elsewhere opens a separate conversation", ca != cv and ca is not None, True)
check("the victim is not thrown out of their chat", poll(tv).get("closed"), False)
check("the other visitor sees only their own messages",
      [m["body"] for m in poll(ta).get("messages", [])], [f"salut {RUN}"])
close(ca)
p = poll(ta)
check("once their chat closes, their token says closed", p.get("closed"), True)
check("  and leads nowhere near the victim's conversation",
      any("Mesaj privat" in m["body"] for m in p.get("messages", [])), False)
code, s = send(ta, f"scris cu tokenul altcuiva {RUN}")
check("  and cannot write", (code, s.get("closed")), (404, True))
check("the victim's conversation holds only the victim's message",
      one(f"select count(*) from threads where conversation_id={cv} and type=1"), "1")

# -------------------------------------------------------- closed means closed
close(c1)
p = poll(t1)
check("a closed conversation answers closed", p.get("closed"), True)
check("  and still hands over what is left to read",
      [m["body"] for m in p.get("messages", [])], [f"Primul mesaj {RUN}", f"Al doilea {RUN}"])
code, s = send(t1, "după închidere")
check("  but takes no more messages", (code, s.get("closed")), (404, True))
check("  and the closed conversation was not reopened", one(f"select status from conversations where id={c1}"), "3")
check("  and nothing internal was ever in the poll",
      sorted({m["from"] for m in poll(t1).get("messages", [])}), ["visitor"])

# ----------------------------------------------------------- presence writes
_, _, tp, cp = start(f"E2E prezenta {RUN}", f"e2e-p-{RUN}@gesoft.test", f"Prezenta {RUN}")
before = session_col(tp, "last_seen_at")
poll(tp)
check("a poll straight after start does not write", session_col(tp, "last_seen_at"), before)
sql(f"update gesoft_live_chat_sessions set last_seen_at = date_sub(last_seen_at, interval 40 second) where token_hash='{sha(tp)}'")
aged = session_col(tp, "last_seen_at")
poll(tp)
check("a poll after 30 s does", session_col(tp, "last_seen_at") != aged, True)

r = http("POST", "leave", raw=json.dumps({"token": tp}))
check("the goodbye beacon is accepted as text/plain", r[0], 204)
check("  and recorded", session_col(tp, "left_at") is not None and session_col(tp, "left_at") != "NULL", True)
poll(tp)
check("a poll right after (a reload) clears it", session_col(tp, "left_at"), "NULL")
check("  and writes nothing into the conversation", lines(cp, 101), "0")

sql(f"update gesoft_live_chat_sessions set last_seen_at = date_sub(last_seen_at, interval 1 hour), "
    f"left_at = date_sub(now(), interval 1 hour) where token_hash='{sha(tp)}'")
artisan("gesoftlivechat:sweep-chats")
check("the sweep writes one 'left' line for a visitor who went away", lines(cp, 101), "1")
artisan("gesoftlivechat:sweep-chats")
check("  and does not write it twice", lines(cp, 101), "1")
check("  and the conversation stays open", one(f"select status from conversations where id={cp}") in ("1", "2"), True)
poll(tp)
check("the visitor coming back writes a 'came back' line", lines(cp, 102), "1")

# ------------------------------------------------------------------ ending it
_, _, te, ce = start(f"E2E incheiere {RUN}", f"e2e-e-{RUN}@gesoft.test", f"Incheiere {RUN}")
r = http("POST", "end", {"token": te})
check("end answers 200", r[0], 200)
check("  writes one 'ended' line", lines(ce, 100), "1")
check("  leaves the conversation for the operator to close", one(f"select status from conversations where id={ce}") in ("1", "2"), True)
check("  and the token opens nothing afterwards", poll(te).get("closed"), True)
check("  not even to write", send(te, "după încheiere")[0], 404)
http("POST", "end", {"token": te})
check("ending twice writes no second line", lines(ce, 100), "1")
check("end with a made-up token looks the same", http("POST", "end", {"token": secrets.token_hex(32)})[0], 200)

# ------------------------------------------------------------ what gets in
_, _, tx, cx = start(f"E2E markup {RUN}", f"e2e-x-{RUN}@gesoft.test", f"Markup {RUN}")
send(tx, "<script>alert(1)</script>")
check("markup is stored escaped",
      one(f"select count(*) from threads where conversation_id={cx} and body like '%&lt;script&gt;%'"), "1")
check("  never raw", one(f"select count(*) from threads where conversation_id={cx} and body like '%<script>%'"), "0")

r = http("GET", f"poll?token={tx}&since=0", headers={"Origin": "http://evil.example"})
check("an origin that is not configured gets no CORS header", r[1].get("access-control-allow-origin"), None)

# ------------------------------------------------------------ the start limit
artisan("cache:clear")
codes = []
for i in range(START_LIMIT + 1):
    rr, bb, tt, cc = start(f"E2E limita {RUN} {i}", f"e2e-l-{RUN}-{i}@gesoft.test", f"Limita {i}", clear=False)
    codes.append(rr[0])
check(f"{START_LIMIT} starts are allowed, the next one is refused", codes, [200] * START_LIMIT + [429])
rr = http("POST", "start", {"name": "x", "email": f"e2e-l2-{RUN}@gesoft.test", "message": "x"},
          headers={"X-Forwarded-For": "203.0.113.9"})
check("  and a forged X-Forwarded-For does not get round it", rr[0], 429)
check("  polling an open chat is not affected by it", poll(tx).get("closed"), False)
artisan("cache:clear")

# ------------------------------------------------------------ the agent's view
if os.environ.get("GLC_AGENT_EMAIL"):
    jar = CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    ua = {"User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36"}
    page = opener.open(urllib.request.Request(BASE + "/login", headers=ua), timeout=20).read().decode()
    csrf = re.search(r'name="_token" value="([^"]+)"', page).group(1)
    form = urllib.parse.urlencode({"_token": csrf, "email": os.environ["GLC_AGENT_EMAIL"],
                                   "password": os.environ["GLC_AGENT_PASSWORD"]}).encode()
    opener.open(urllib.request.Request(BASE + "/login", data=form, headers=ua), timeout=20).read()
    chats = json.loads(opener.open(urllib.request.Request(BASE + "/gesoft-live-chat/agent/chats", headers=ua), timeout=20).read())
    presence = chats.get("presence", {})
    check("the agent endpoint reports a present visitor as here", presence.get(str(cx)), "here")
    check("  an ended chat as ended", presence.get(str(ce)), "ended")
    check("  a closed chat not at all", str(c1) in presence, False)

# -------------------------------------------------------------------- tidy up
for conv in set(opened):
    close(conv)

print(f"\n{passed} passed, {failed} failed  (run {RUN}, {len(set(opened))} test conversations closed)")
sys.exit(1 if failed else 0)
