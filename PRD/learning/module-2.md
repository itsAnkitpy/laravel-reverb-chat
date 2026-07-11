# Module 2 — Explained simply (the core: save, then shout)

Goal: after reading this you can trace what happens the instant someone sends a message, and name the job of each of the three files we wrote. This is *the* module that teaches the whole real-time flow — every other module just wraps around it.

---

## The one idea: save first, then announce

When Agent A sends "hello", two things must happen, in this exact order:

1. **Save** the message to the database. Now it's a permanent fact.
2. **Announce** it — shout an event onto a private channel so Agent B's browser hears it.

The order is non-negotiable. If we announced first and the save then failed, Agent B would see a message that exists nowhere. Save-then-announce means the announcement is always about something real. Interview line: *"persist, then broadcast — never the reverse."*

---

## The three files, and each one's job

### a) The event — `app/Events/MessageSent.php`
An **event** is a small object that says "this happened" and carries a bit of data. Because it implements `ShouldBroadcast`, Laravel knows to push it out over WebSockets. Two methods do the work:

- **`broadcastOn()`** → returns `PrivateChannel('conversation.1')` — *where* to shout. "Private" means the bouncer must approve every listener.
- **`broadcastWith()`** → the exact payload the browser receives (`id`, `body`, `user_id`). We hand-pick it instead of dumping the whole database row — you never broadcast columns the client shouldn't see.

The important word is **`ShouldBroadcast`, not `ShouldBroadcastNow`**. That one choice makes the broadcast **queued** (handed to a background worker) instead of inline. You saw the proof: sending a message added a row to the `jobs` table. Why that matters is below.

### b) The controller — `MessageController@store` (`POST /messages`)
In order:

1. **Validate** — `body` required; `conversation_id` must `exists` in the DB. Bad input never reaches the save.
2. **Save** — `Message::create(...)`. The message becomes a fact.
3. **Broadcast** — `broadcast(new MessageSent($message))->toOthers();`

**`->toOthers()`** = "send to everyone on the channel *except me*." The sender's own window already shows the message (it typed it), so skipping the sender avoids a duplicate. (There's a catch with raw `fetch` — see the gotcha below.)

### c) The channel gate — `routes/channels.php`
This is the **bouncer**. Every time a browser tries to subscribe to `conversation.1`, Laravel runs this closure at the `/broadcasting/auth` endpoint:

```
conversation.{id}  →  is your business_id the same as this conversation's business_id?
                      yes → allowed on the channel
                      no  → 403, you hear nothing
```

This single comparison is the entire multi-tenant wall. It's also your JD **"Gates / authorization / Policies"** talking point — a channel authorization callback *is* an authorization gate. Module 4 makes it fail on purpose so you watch the 403 live.

---

## Why the broadcast is queued (the `jobs` row you saw)

`broadcast()` on a `ShouldBroadcast` event does **not** call Reverb right then. It drops a job in the queue and returns. A separate **worker** picks it up later and does the Reverb push. Why bother?

- **Fast response.** `POST /messages` returns the instant the DB save finishes. The user never waits on the WebSocket network hop.
- **Resilience.** If the Reverb push fails, the queue can retry it. Inline, a Reverb hiccup would fail the user's whole request.

Right now `QUEUE_CONNECTION=database`, so the job waits in the `jobs` table — and with **no worker running, nothing actually gets delivered yet.** That's expected. In Module 3 we start `php artisan queue:work` (a third terminal) so the queued broadcast fires and the message reaches the other browser. Module 5 upgrades that worker to **Redis + Horizon** — the JD's named stack — and adds a deliberate delay so you *see* the async gap.

The demo you ran, in one line: `messages 0→1` (saved instantly) and `jobs 0→1` (broadcast deferred). That is the concept made visible.

---

## The `toOthers()` + raw `fetch` gotcha (Module 3 preview)

`->toOthers()` identifies "me" by the sender's WebSocket **socket id**, which the browser sends in an `X-Socket-Id` header. Laravel Echo adds that header automatically — **but only for axios**. Module 3 uses plain `fetch`, which does *not* add it. So we set it by hand:

```js
headers: { 'X-Socket-Id': window.Echo.socketId() }
```

Forget it, and the sender's own window double-shows the message. Watching that bug appear and fixing it is part of the Module 3 lesson.

---

## Why `.listen('MessageSent')` will work in Module 3

We didn't set a custom broadcast name, so the event goes out under its class name, `App\Events\MessageSent`. Laravel Echo assumes events live in the `App.Events` namespace, so on the client `.listen('MessageSent', …)` resolves to that same event. (If we had renamed it with `broadcastAs()`, we'd listen with a leading dot — `.listen('.custom-name')`. We didn't, so no dot.)

---

## Mental model into Module 3

Everything server-side is now in place: **save it, queue the announcement, guard the channel.** What's missing is a **page with a receiver** — a Blade view that (1) sends via `fetch('/messages')` and (2) tunes in with `Echo.private('conversation.1').listen('MessageSent', …)`. Module 3 builds that, we start a queue worker so the broadcast actually fires, and you finally watch a message cross two browser windows with the Network tab open.

---

## Glossary (one line each)

- **Event (broadcastable)** — an object describing something that happened; implementing `ShouldBroadcast` makes Laravel push it over WebSockets.
- **`ShouldBroadcast` vs `ShouldBroadcastNow`** — queued (handed to a worker) vs inline (sent during the request).
- **`broadcastOn()`** — which channel the event is shouted on.
- **`broadcastWith()`** — the exact payload the client receives.
- **`->toOthers()`** — broadcast to everyone on the channel except the sender.
- **Channel authorization callback** — the closure in `channels.php` that approves or denies a subscriber; an authorization "gate."
- **`/broadcasting/auth`** — the endpoint the browser hits to ask permission to join a private channel; the bouncer answers.
- **Queue / job / worker** — deferred work stored (in `jobs`) and run later by a worker process.
- **Socket id** — a unique id for one browser's WebSocket connection; used by `toOthers()` to identify "me."
