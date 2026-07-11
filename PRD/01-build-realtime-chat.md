# PRD 01 — Mini Real-time Chat (the build)

> Build it slowly, read every line. The goal is to *see* the flow, not to ship a product.

## Learning outcomes (the only reasons this exists)

By the end, from memory and out loud, you can:

1. Trace a chat message end to end: `save → event → broadcast → Reverb/Pusher → authorized channel → other browser`.
2. Explain the private-channel auth handshake (the `/broadcasting/auth` call and the `routes/channels.php` callback).
3. Show why one company cannot hear another's messages (the tenant gate). **This is the answer the founder cared about most.**
4. Explain why the broadcast runs on a queue.

If a module doesn't serve one of these, it's out of scope.

## Stack (pinned)

- **Laravel 11**, PHP 8.2+.
- **Laravel Reverb** as broadcast driver — first-party, self-hosted, no signup, one command locally. Echo API is identical to Pusher, so every concept transfers.
- Laravel Echo + `laravel-echo` + `pusher-js` on the frontend (Reverb speaks the Pusher protocol).
- SQLite (`database/database.sqlite`), one file, throwaway-friendly.
- Plain **Blade + vanilla JS**. Not Livewire — Livewire hides the broadcasting mechanism; here you want to see `Echo.private().listen()` with your own eyes.

### Alternate driver: Pusher instead of Reverb
Same code, config-only switch. **Do the core on Reverb (zero friction), then flip the driver to Pusher once** — because *Pusher is the tech named in the JD*. After the swap you can speak to the hosted model, connection limits, and why you'd pick hosted Pusher vs self-hosted Reverb vs raw Socket.io. Steps: create a free Pusher app, put keys in `.env` (`BROADCAST_CONNECTION=pusher`, `PUSHER_APP_*`), rebuild Vite. No code changes. ~10 min, high interview payoff.

---

## Module 0 — Setup (~15 min)

See `README.md` for the scaffolding commands (PRD-aside trick + `install:broadcasting`). Summary:
- Scaffold Laravel 11, `touch database/database.sqlite`, set `DB_CONNECTION=sqlite`.
- `php artisan install:broadcasting` → say yes to Reverb + Echo scaffolding.
- `npm install`, `npm run dev` (terminal 1), `php artisan reverb:start` (terminal 2).

**Checkpoint:** Reverb running, Vite serving. Nothing visible yet.

---

## Module 1 — Data model (~10 min)

Two tables plus users.

- `conversations`: `id`, `business_id`.
- `messages`: `id`, `conversation_id`, `user_id`, `body`, timestamps.
- `users`: add a `business_id` column.

Seed: 1 business, 1 conversation, 2 users (Agent A + Agent B) both in that business. Two users so you can open two windows and watch it live.

**Auth note:** private channels need a logged-in user, because the auth callback receives `$user`. For a throwaway, skip a login UI — add a dev-only route `GET /login-as/{id}` calling `Auth::loginUsingId($id)`. Open it once per browser window (Agent A in one, Agent B in the other).

### Alternate choice: Laravel Breeze
Real login screen in a minute, but drags in Tailwind + scaffolding you don't need. The `loginUsingId` hack keeps focus on broadcasting. **Pick the hack** unless you want the realism.

**Checkpoint:** you can log in as either agent; both share one conversation.

---

## Module 2 — Broadcast the message (the core, ~30 min)

This module teaches the whole thing. Three pieces.

### a) The event — `app/Events/MessageSent.php`
```php
class MessageSent implements ShouldBroadcast   // queued broadcast, NOT ...Now
{
    public function __construct(public Message $message) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('conversation.'.$this->message->conversation_id);
    }

    public function broadcastWith(): array      // the payload the client receives
    {
        return [
            'id'      => $this->message->id,
            'body'    => $this->message->body,
            'user_id' => $this->message->user_id,
        ];
    }
}
```
`ShouldBroadcast` (not `ShouldBroadcastNow`) = the broadcast is queued. That is deliberate — see Module 5.

### b) The controller — `store` behind `POST /messages`
- Validate `body` and `conversation_id`.
- **Save to the DB first.** Persist, then broadcast. Non-negotiable — the message exists as a fact before anyone is told about it. (If broadcast came first and the save failed, listeners would see a message that doesn't exist.)
- Then `broadcast(new MessageSent($message))->toOthers();` so the sender's own window isn't double-updated.

> **Gotcha — `toOthers()` with raw `fetch`.** `->toOthers()` needs the sender's socket id in an `X-Socket-Id` header. Echo sets this automatically on **axios**, but **not on raw `fetch`**. Since Module 3 uses `fetch`, you must add the header yourself or the sender's window *will* double-update:
> ```js
> headers: { 'X-Socket-Id': window.Echo.socketId(), ... }
> ```
> Watching this bug appear and fixing it is itself part of the lesson.

### c) The channel gate — `routes/channels.php`
```php
Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conversation = Conversation::find($id);
    // allow only if this agent belongs to the business that owns the conversation
    return (int) $user->business_id === (int) $conversation->business_id;
});
```
This callback is your **tenant gate** and a **Gate/authorization** talking point for the JD. It runs on every `/broadcasting/auth` call.

**Checkpoint:** you can explain what each of the three does, and *why the save comes before the broadcast*.

---

## Module 3 — Frontend and the payoff (~20 min)

- One Blade view: a `<ul>` of messages, a text input, a send button. No CSS beyond raw.
- Send with `fetch('/messages', { method: 'POST', headers: { 'X-Socket-Id': window.Echo.socketId(), ... }, body: ... })`. No page reload.
- Listen:
```js
Echo.private(`conversation.${conversationId}`)
    .listen('MessageSent', (e) => appendMessage(e));
```
- Open two windows (Agent A, Agent B). Type in one → appears in the other, no refresh.

> **Do `appendMessage` XSS-safe** — use `textContent`, not `innerHTML`, for `e.body`. `innerHTML` here is a real stored-XSS hole (an attacker types `<img onerror>` as a message). This is a free, concrete security talking point — see `02-interview-prep.md` Module B.

**Watch it happen (the actual lesson):** DevTools → Network. Send a message and watch, in order:
1. `POST /messages` (your save + queued broadcast dispatch)
2. `/broadcasting/auth` (the private-channel handshake — happens once, on subscribe)
3. the WebSocket frame in the **WS** tab carrying your event

**Checkpoint (definition of done for the core):** message appears in the other window without refresh, and you can point at each network call and name it. **Modules 0–3 are the whole win.** Stop here if time is short.

---

## Module 4 — Prove tenant isolation (~20 min) — DO THIS, not optional

The PRD it came from marked this optional. It is your **strongest card**: multi-tenancy is your deep domain, and it's the exact concern the founder probed.

Plan:
- Add a 2nd business, a 2nd conversation, and a 3rd user (Agent C) in business 2.
- Log in as Agent C, try to subscribe to business 1's channel (`conversation.1`).
- Watch `/broadcasting/auth` return **403** — the callback's `business_id` check fails. Agent C never gets on the channel.

The senior answer this earns you: *"I built a small one and watched the auth endpoint reject a cross-tenant subscription with a 403. The gate is a row-level `business_id` check in the channel callback — which is the right isolation model for a chat widget. Here's the point where I'd escalate to schema-per-tenant."* That last clause is what separates you — lean into it, it's home turf.

**Checkpoint:** you watched a 403 refuse a cross-tenant subscribe, and can explain the callback that caused it.

---

## Module 5 — Make the broadcast async (~15–20 min) — DO THIS on Redis + Horizon

The source PRD marked this optional and used the `database` queue. For *this* JD it's core, and it should rehearse the **named** tools: the JD literally lists *"Redis queues, Horizon, event broadcasting."*

### Recommended: Redis + Horizon (rehearses the JD's stack)
```bash
# Redis running locally (brew install redis && brew services start redis)
composer require laravel/horizon
php artisan horizon:install
# .env
QUEUE_CONNECTION=redis
php artisan horizon                    # terminal 3 — the worker + dashboard
```
Add a `sleep(3)` or a `Log::info(...)` in the broadcast path. Observe: `POST /messages` returns **instantly** while the message shows up a beat later, delivered by the worker. Open the Horizon dashboard (`/horizon`) and watch the job run.

Learning link: that worker is doing what **Horizon over Redis** manages in production — which is what Chatway runs. You now understand *why* broadcasting is queued (keep the HTTP response fast; retries/backpressure on failures), not just *that* it is.

### Alternate choice: `database` queue (zero infra)
No Redis to install. `QUEUE_CONNECTION=database`, `php artisan queue:table && migrate`, `php artisan queue:work`. Teaches the same *concept* but rehearses the wrong tool. **Use only if you can't run Redis locally.**

**Checkpoint:** `POST` returns before the message appears; you can name what the worker did and why the broadcast wasn't inline.

---

## Module 6 — Typing indicator (~10 min, only if flying)

Swap to a presence channel with `Echo.join()`, use `whisper('typing')` / `listenForWhisper('typing')`. Teaches presence channels and whispers (client-to-client, never touches your DB) in one small step.

---

## Time budget

- Core (Modules 0–3): ~1–1.5 hr. Closes the main gap.
- Core + 4 (isolation) + 5 (Redis/Horizon): ~2–2.5 hr. **Recommended** — 4 and 5 map straight onto named JD requirements.
- Everything incl. Pusher swap + Module 6: ~3 hr.
