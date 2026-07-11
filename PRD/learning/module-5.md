# Module 5 — Explained simply (make the broadcast async: Redis + Horizon)

Goal: after reading this you can explain **why the broadcast runs on a queue**, what Redis and Horizon each do, and point at the moment the HTTP response leaves *before* the message is delivered. This is the JD's named stack — *"Redis queues, Horizon, event broadcasting."*

---

## The one idea

Sending a chat message does two things of very different speed:

1. **Save the row** — fast, must happen now, the user is waiting on the response.
2. **Push the broadcast out to every listener** — slower, can fail, can be retried, nobody's HTTP request should wait on it.

So we **split them**: the request does step 1 and returns instantly; step 2 is handed to a **background worker**. The mechanism that carries "do this later" work is a **queue**. That's the whole module.

Analogy: you drop a letter in the mailbox (`POST /messages` returns the moment the letter is in the box) and walk away. The postal worker (the queue worker) delivers it on their own schedule. You didn't stand at the mailbox until it arrived.

---

## What each piece is

- **Redis** — an in-memory data store, here used as the **queue backend**: the list of "jobs to do later" lives in Redis instead of the MySQL `jobs` table we used before. It's fast because it's in memory.
- **The queue** — the pipe. `broadcast(new MessageSent(...))` doesn't push to Reverb directly; because the event is `ShouldBroadcast` (not `...Now`), Laravel wraps it in a **job** and drops that job onto the Redis queue.
- **Horizon** — a **worker manager + dashboard** for Redis queues. `php artisan horizon` boots workers that pull jobs off Redis and run them, and gives you `/horizon` to watch throughput, runtime, and failures. It replaces the plain `php artisan queue:work` from earlier modules.

Chain now: `POST /messages` → save row → **push job to Redis** → *(response returns here)* → Horizon worker pulls job → runs `broadcastWith()` → pushes to Reverb → other browser.

---

## Where the "later" actually happens (the code)

The delay we watch is inside the event's `broadcastWith()` — and that method runs **on the worker, not in the request**:

```php
public function broadcastWith(): array
{
    Log::info('[Module 5] worker START broadcasting message #'.$this->message->id...);
    sleep(3);                       // demo only — pretend the push is slow
    Log::info('[Module 5] worker DONE  broadcasting message #'.$this->message->id...);
    return ['id' => ..., 'body' => ..., 'user_id' => ...];
}
```

The `sleep(3)` is a fake "this is slow work." Because it lives in `broadcastWith()`, it's paid by the Horizon worker — the browser already has its `POST` response back. **Remove this block after the lesson**; it's teaching scaffolding, not real code.

---

## What we measured (the proof)

One `POST /messages`, timed, with the worker logging when it started and finished the broadcast:

```
POST /messages   : HTTP 200  time_total=0.061s      ← request done in 61 ms
worker START broadcasting message #1 at 12:10:34.442
worker DONE  broadcasting message #1 at 12:10:37.367 ← ~3s of worker time, separately
```

Read it: the HTTP response came back in **61 milliseconds**, while the actual Reverb push happened on the worker and took **~3 seconds**. The request never waited for the broadcast. That gap *is* the reason broadcasts are queued.

---

## See it yourself in a browser (watch it move)

Terminals up: `npm run dev`, `php artisan reverb:start`, and now **`php artisan horizon`** (not `queue:work`). Redis running (`redis-cli ping` → `PONG`).

1. Open Agent A and Agent B on `/chat` (two windows).
2. Send a message from A. Notice the input clears and your own line appears **instantly** (that's the fast `POST` response).
3. It shows up in **B about 3 seconds later** — that lag is the queued broadcast being processed by the Horizon worker (the `sleep(3)`).
4. Open **`http://localhost:8000/horizon`** → *Recent Jobs*. You'll see `App\Events\MessageSent` (via `BroadcastEvent`) run, with its ~3s runtime and a green completed status. That dashboard is the production-grade view Chatway would watch.

Turn the worker off (`php artisan horizon:terminate`) and send another message: it saves and shows locally but **never reaches B** — the job sits in Redis. Start Horizon again → it drains and B updates. That's "queued" made physical.

---

## Why this matters in production (the interview answer)

> *"Broadcasting is queued so the HTTP request stays fast and the slow/failable part — the actual push to the WebSocket layer — happens on a worker. The queue backend is Redis; Horizon manages the worker pool and gives retries, backpressure, and a dashboard. If the broadcast fails, the request already succeeded and the job retries independently. That decoupling is why you never do `ShouldBroadcastNow` on a hot path."*

Bonus tradeoffs to have ready:
- **`ShouldBroadcast` vs `ShouldBroadcastNow`** — queued (worker) vs inline (blocks the request). We use the queued one on purpose.
- **Redis vs the `database` queue** — Redis is in-memory and much faster under load; `database` works with zero infra but is the wrong tool at scale. JD names Redis, so we rehearsed Redis.
- **predis vs phpredis** — we used predis (pure PHP, zero setup) locally; production uses the phpredis C-extension for throughput. Same code above it.

---

## Setup notes (so a fresh machine can reproduce)

- Install + run Redis: `brew install redis && brew services start redis` → `redis-cli ping` = `PONG`.
  (If brew errors about an untrusted tap, prefix the command with `HOMEBREW_NO_REQUIRE_TAP_TRUST=1` — one-command scope, doesn't trust the tap globally.)
- `composer require predis/predis laravel/horizon`, then `php artisan horizon:install`.
- `.env`: `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=predis`. Then `php artisan config:clear`.
- Run `php artisan horizon` (replaces `queue:work`). After editing any event/job, restart it — Horizon caches code at boot.

---

## Glossary (one line each)

- **Queue** — a list of "do this later" jobs; decouples slow work from the request.
- **Redis** — in-memory store used here as the fast queue backend.
- **Horizon** — Laravel's worker manager + dashboard (`/horizon`) for Redis queues; replaces `queue:work`.
- **`ShouldBroadcast`** — marks an event to be broadcast *via the queue* (vs `ShouldBroadcastNow` = inline).
- **`broadcastWith()`** — builds the payload; runs on the **worker**, which is why our `sleep(3)` delays delivery, not the request.
- **predis / phpredis** — two ways PHP talks to Redis: pure-PHP package (easy) vs C-extension (fast).
- **Worker** — the process that pulls jobs off the queue and runs them; here, a Horizon-managed worker.
