# Demo & Transitions — run it yourself, and how the moving parts evolved (M0→M5)

A one-page guide to (1) start the app from cold, (2) understand the two things people confuse — the **delivery pipe** vs the **do-it-later queue** — and (3) the plain-language story of what each module added.

---

## Part 1 — Run it yourself (from cold)

You need **one background service + four terminals**, all from the project root `/Users/apple/myprojects/realtime-chat`.

```bash
# 0) Redis — the queue backend (start once; it stays up as a background service)
brew services start redis
redis-cli ping                 # must print: PONG
#   if brew complains about an untrusted tap, use this one-off instead:
#   HOMEBREW_NO_REQUIRE_TAP_TRUST=1 brew services start redis
#   (redis-cli lives at /opt/homebrew/opt/redis/bin/redis-cli if not on PATH)

# 1) The web app (HTTP)                      → http://localhost:8000
php artisan serve

# 2) Vite — builds/serves the frontend JS (Echo, pusher-js)
npm run dev

# 3) Reverb — the WebSocket server that pushes messages to browsers (port 8080)
php artisan reverb:start

# 4) Horizon — the worker that drains queued broadcasts off Redis
php artisan horizon
```

**Then, in the browser:**
1. Normal window → `http://localhost:8000/login-as/1` → you're **Agent A** (business 1), lands on `/chat`.
2. Incognito window (separate session) → `http://localhost:8000/login-as/2` → **Agent B** (business 1), also `/chat`.
3. Type in A, hit Send → it appears in **B with no refresh**. Flip it.
4. Open DevTools → Network to watch it move: `POST /messages` (your save), `POST /broadcasting/auth` (the one-time channel handshake), and the **WS** frame carrying the event.
5. **Horizon dashboard:** `http://localhost:8000/horizon` → *Recent Jobs* → watch the `MessageSent` broadcast job run (~3s while the demo `sleep(3)` is in place).

**Two extra demos worth doing:**
- **Tenant isolation (M4):** `http://localhost:8000/login-as/3` = **Agent C** (business 2). In the console run `window.Echo.private('conversation.1')` — that's business 1's room. Watch `POST /broadcasting/auth` return **403**. Agent A doing the same gets **200**.
- **The queue made visible (M5):** stop Horizon (`php artisan horizon:terminate`), send a message → it saves and shows in your own window but **never reaches the other** (the job waits in Redis). Start Horizon again → it drains and the message appears.

> Health checks if something's off: `redis-cli ping` (PONG), `php artisan horizon:status` (running), Reverb log shows a client connect when `/chat` loads.

---

## Part 2 — The bit everyone confuses: pipe vs queue

There are **two separate systems** here. Keeping them apart is a senior-level clarity point.

**A) The delivery pipe = Reverb (the WebSocket server).** This is what actually shoves the message down an open connection into the other browser. It has been here since **Module 0** and never changed. Analogy: the **phone line** that carries the call.

**B) The "do it later" mechanism = a queue + a worker.** Because pushing a broadcast is slower and can fail, we don't do it inside the web request — we drop a **job** on a **queue**, and a **worker** picks it up and does the push (into pipe A). Analogy: an **outbox + a courier**. You drop the letter (job) in the outbox (queue); the courier (worker) delivers it later.

The pipe (A) stayed constant. The queue-and-worker (B) is what **transitioned**:

| Stage | Queue backend (the outbox) | Worker (the courier) | When |
|---|---|---|---|
| First working version | **`database`** — jobs stored in a MySQL `jobs` table | **`php artisan queue:work`** | Modules 2–4 |
| Now | **Redis** — jobs stored in memory (fast) | **`php artisan horizon`** (manages workers + gives the `/horizon` dashboard) | Module 5 |

So the sentence "we went from `queue:work` to Redis/Horizon" really means: **we swapped the outbox (MySQL → Redis) and upgraded the courier (`queue:work` → Horizon).** Reverb — the pipe — was never part of that swap. That's the JD's exact stack: *"Redis queues, Horizon, event broadcasting."*

Why bother? Keep the HTTP response instant (Module 5 measured **61ms**) and hand the slow, retry-able delivery to the worker. If delivery fails, the request already succeeded and the job retries on its own.

---

## Part 3 — What each module added (plain language)

| Module | What we added | The observable "watch it move" | Interview line it earns |
|---|---|---|---|
| **0 — Setup** | Laravel + Reverb (WebSocket server) + Echo (browser client) wired together. | Reverb binds port 8080; frontend builds. Nothing visible yet. | "Reverb is a self-hosted WebSocket server that speaks the Pusher protocol." |
| **1 — Data model** | `conversations`, `messages`, and a `business_id` column on `users`. A seeder (Agent A + B in business 1). A dev-only `/login-as/{id}` shortcut. | Log in as either agent; both share conversation 1. | "`business_id` is the row-level tenant key — added via a separate additive migration, never by editing a shipped one." |
| **2 — Broadcast the message** | The `MessageSent` event, the `POST /messages` controller (**save first, then broadcast**), and the **tenant gate** in `channels.php`. | The broadcast lands as a **queued job** (in the `jobs` table back then). | "Save is a fact before anyone's told; the broadcast is queued, not inline." |
| **3 — Frontend & payoff** | The `/chat` page: send via `fetch`, receive via `Echo.private().listen()`, render safely with `textContent`. | A message crosses **two browser windows with no refresh.** You can name all three network calls. | "Stored XSS is stopped at the render boundary — `textContent`/`{{ }}`, never `innerHTML` with user data." |
| **4 — Tenant isolation** | A **second** business (conversation 2 + Agent C). **No new code** — the M2 gate already was the wall. | Agent C subscribing to business 1's channel gets a **403**; Agent A gets **200**. Same request, only the tenant differs. | "A row-level `business_id` check in the channel callback rejected a cross-tenant subscribe — and here's when I'd escalate to schema-per-tenant." |
| **5 — Async on Redis + Horizon** | Swapped the queue from MySQL `database` → **Redis**, and the worker from `queue:work` → **Horizon**. | `POST` returns in ~60ms; the message arrives ~3s later, delivered by the Horizon worker. Watch the job in `/horizon`. | "Broadcasting is queued so the request stays fast; Horizon over Redis manages workers, retries, backpressure." |

**The whole journey in one breath:** a message is **saved** (M1–M2), **broadcast as a queued job** (M2), **delivered over a WebSocket to another browser** (M0 Reverb + M3 frontend), **only to agents whose business owns the conversation** (M2 gate, proven in M4), with the delivery **done off the request on Redis by Horizon** (M5).

**Next (a later session):** the **Pusher driver swap** — point the delivery pipe at hosted Pusher instead of self-hosted Reverb. Config + a ~5-line Echo edit, no application-logic changes. See `PRD/progress.md` → Next action, and the handoff.

---

### Quick reference — what each command is for

- `brew services start redis` — the in-memory store that holds the queue of "broadcasts to send."
- `php artisan serve` — the web app (handles `/chat`, `/messages`, `/broadcasting/auth`).
- `npm run dev` — compiles the frontend JS (Echo + pusher-js).
- `php artisan reverb:start` — the WebSocket server that delivers messages to browsers.
- `php artisan horizon` — the worker that takes broadcast jobs off Redis and fires them (replaced `queue:work`); dashboard at `/horizon`.
