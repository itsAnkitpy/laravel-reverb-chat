# Module 3 — Explained simply (the payoff: watch a message travel)

Goal: after reading this you can point at the **three network calls** a single message makes and name each one, and you understand the two jobs the chat page does — send and receive. This is the "definition of done" for the core (Modules 0–3).

---

## The page has exactly two jobs

Everything in `chat.blade.php` reduces to two things:

1. **RECEIVE** — tune a radio to this conversation's private channel and react when a message arrives:
   ```js
   window.Echo.private('conversation.1')
       .listen('MessageSent', (payload) => appendMessage(payload));
   ```
2. **SEND** — POST the typed message, no page reload:
   ```js
   fetch('/messages', { method: 'POST', headers: {…}, body: JSON.stringify({…}) })
   ```

That's the whole app. Two browser windows each do both, and Reverb relays between them.

---

## The three network calls (THE lesson — watch these in DevTools → Network)

Open two windows (Agent A and Agent B), DevTools open, and send one message. In order you'll see:

1. **`POST /messages`** — your send. The controller saves the row and queues the broadcast. Returns instantly with the saved message as JSON.
2. **`POST /broadcasting/auth`** — the **handshake**, fired *once* when the page first subscribes (not per message). This is the bouncer: Laravel runs the `channels.php` callback and answers "yes, you may join `conversation.1`" (or 403). Look for it right after the page loads.
3. **The WebSocket frame** — in the **WS** tab, click the `ws://localhost:8080` connection → Messages. When the worker delivers the broadcast, you'll see the `MessageSent` event frame arrive carrying your `{id, body, user_id}` payload. *That* frame is the message landing in the other browser.

If you can point at these three and say what each does, the core learning outcome is met.

---

## The CSRF handshake, explained

Two places send a Cross-Site Request Forgery token (a per-session secret that proves the request came from your own page, not a malicious site):

- **`/broadcasting/auth`** — `laravel-echo` automatically reads `<meta name="csrf-token">` from the page and attaches it. That's the only reason we put the meta tag in `<head>`. (We verified this works — the auth call authorized cleanly.)
- **`POST /messages`** — raw `fetch` sends nothing automatically, so we set the header ourselves: `'X-CSRF-TOKEN': csrfToken`. Forget it and you'd get a **419** ("page expired") response.

---

## The `X-Socket-Id` detail (why the sender doesn't see a duplicate)

The server broadcasts with `->toOthers()` — "everyone on the channel except me." It identifies "me" by the sender's WebSocket **socket id**, sent in the `X-Socket-Id` header. Echo adds this automatically **on axios**, but **not on raw `fetch`**, so we add it by hand:

```js
'X-Socket-Id': window.Echo.socketId()
```

That's why the sender shows their own message by calling `appendMessage()` *locally* (the broadcast is deliberately skipping them). If you ever see your own message appear **twice**, this header is missing or empty — that's the bug the PRD wanted you to witness.

---

## The XSS defense (a free JD security talking point)

Incoming message bodies are rendered with **`textContent`, not `innerHTML`**:

```js
li.textContent = '#' + payload.user_id + ': ' + payload.body;
```

If someone sends the literal text `<img src=x onerror=alert(1)>`, `textContent` shows it as harmless text; `innerHTML` would *execute* it — a stored-XSS hole (the malicious string is saved, then runs in every viewer's browser). The server-rendered existing messages use Blade `{{ }}`, which auto-escapes for the same reason. Interview line: *"stored XSS is prevented at the render boundary — escape on output, `textContent`/`{{ }}`, never `innerHTML` with user data."*

---

## Why the queue worker must be running

Broadcasts are **queued** (Module 2). So a message won't reach the other browser until a worker processes the job:

```bash
php artisan queue:work    # 3rd terminal
```

Great thing to witness once: **stop** the worker, send a message → it saves and shows locally but never reaches the other window (the job sits in `jobs`). **Start** the worker → it flushes and the message appears. That is "queued broadcast" made physical. Module 5 replaces this worker with Horizon-on-Redis.

---

## What we verified (server-side, before any browser)

An automated end-to-end run confirmed the whole chain:

- `login-as/1` → 302 → `/chat` renders (message list, form, csrf meta, `Echo.private` all present).
- `POST /messages` with the CSRF token → **200**, returned `{"id":1,"body":"…","user_id":1}` (CSRF authorized).
- Queue state `jobs: 1, messages: 1` (saved + broadcast queued).
- `queue:work` processed `App\Events\MessageSent` → **DONE**, `failed_jobs: 0` (broadcast reached the running Reverb).

The only thing a script can't do is *see* it — that part is yours.

---

## Your manual test (the actual payoff)

With three terminals up (`npm run dev`, `php artisan reverb:start`, `php artisan queue:work`):

1. Window 1 (normal): open `http://localhost:8000/login-as/1` → lands on `/chat` as Agent A.
2. Window 2 (incognito, so it's a separate session): open `http://localhost:8000/login-as/2` → `/chat` as Agent B.
3. DevTools → Network in both. Watch for `/broadcasting/auth` on load.
4. Type in Agent A → hit Send. It appears in **Agent B with no refresh**. Flip it.
5. In the WS tab, find the `MessageSent` frame carrying the payload.

> Note: if assets look stale, restart `npm run dev` (the verification deleted `public/hot`; restarting Vite recreates it). Without `npm run dev`, the built manifest is used and still works.

---

## Glossary (one line each)

- **`Echo.private(channel).listen(event, cb)`** — subscribe to a private channel and run `cb` each time `event` arrives.
- **`/broadcasting/auth`** — the one-time handshake where the bouncer approves your subscription.
- **CSRF token** — a per-session secret proving a request came from your own page; sent via meta tag (Echo) and `X-CSRF-TOKEN` header (fetch).
- **`X-Socket-Id`** — identifies the sender's socket so `toOthers()` can skip it; must be set manually with raw `fetch`.
- **`textContent` vs `innerHTML`** — render as text (safe) vs render as HTML (executes scripts — XSS risk).
- **WS frame** — a single message over the open WebSocket; where you literally see the event arrive.
- **`queue:work`** — the worker process that drains queued jobs (including broadcasts) so they actually fire.
