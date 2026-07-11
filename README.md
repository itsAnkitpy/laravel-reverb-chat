# laravel-reverb-chat

A deliberately minimal **multi-tenant realtime chat** built to watch a message travel end to end:

```
save to DB → event fires → job queued on Redis → Horizon worker → Reverb (WebSocket) → authorized private channel → other browser
```

No styling, no packages beyond the mechanism. The point is that every hop in that chain is visible — in the Network tab, in the Horizon dashboard, and in the code, which is commented like a walkthrough.

## What it demonstrates

- **Private channel authorization** — subscribing to `conversation.{id}` triggers the `/broadcasting/auth` handshake; the channel callback in `routes/channels.php` is the entire tenant wall.
- **Row-level multi-tenancy, enforced per entry point** — read (`/chat`), write (`POST /messages`), and subscribe are three separate doors, each checked against the user's `business_id`. A cross-tenant subscribe gets a **403**; a cross-tenant post gets a **404** (and doesn't leak which conversation ids exist).
- **Async broadcasting** — `ShouldBroadcast` queues the push on Redis; the HTTP response returns in ~60ms while a Horizon worker delivers the message (a demo `sleep(3)` in `MessageSent::broadcastWith` makes the gap visible on purpose).
- **`toOthers()`** — the sender's own socket is excluded via the `X-Socket-Id` header, wired by hand because plain `fetch` (unlike axios) doesn't send it.
- **XSS-safe rendering on both paths** — Blade `{{ }}` escaping for history, `textContent` for live messages.

## Stack

Laravel 11 · PHP 8.4 · MySQL · Redis + Horizon (queue) · Laravel Reverb (WebSocket server) · Laravel Echo + plain Blade/vanilla JS — no frontend framework, so the broadcasting mechanics stay visible.

## Run it

Prerequisites: PHP 8.4, Composer, Node 20+, MySQL, Redis.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
```

Set in `.env`:

```env
DB_CONNECTION=mysql          # plus your DB_DATABASE / credentials
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis

REVERB_APP_ID=any-id         # self-issued — any values work locally
REVERB_APP_KEY=any-key
REVERB_APP_SECRET=any-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Then:

```bash
php artisan migrate --seed
```

Four terminals:

```bash
npm run dev                # Vite
php artisan reverb:start   # WebSocket server on :8080
php artisan horizon        # queue worker (dashboard at /horizon)
php artisan serve          # app on :8000
```

## The demo

The seeder creates two tenants:

| User | Login | Business | Conversation |
|---|---|---|---|
| Agent A | `/login-as/1` | 1 | 1 |
| Agent B | `/login-as/2` | 1 | 1 |
| Agent C | `/login-as/3` | 2 | 2 |

(`/login-as/{id}` is a dev-only backdoor, hard-guarded to the `local` environment.)

1. Open two browsers (or one normal + one incognito): `/login-as/1` and `/login-as/2`.
2. Send a message from A — it appears instantly for A (local append) and ~3s later for B (the queued broadcast, delayed on purpose by the demo `sleep`).
3. Watch it move: the `POST /messages` in the Network tab, the job in `/horizon`, the WebSocket frame on the `:8080` connection.
4. Prove the tenant wall: as Agent C, run `window.Echo.private('conversation.1')` in the console → the `/broadcasting/auth` request returns **403**. Posting into conversation 1 as C returns **404**.

## Learning notes

The `PRD/` folder contains the module-by-module build plan, a live progress tracker, and plain-language write-ups of each module — including a post-build code review (`PRD/learning/review-findings.md`) that found and fixed a cross-tenant write hole, and documents the two gaps left open by design (missed-message catch-up, history pagination) with how they'd be solved in a real product.

## Deliberate non-goals

Styling, tests, presence/typing indicators, message catch-up on reconnect, pagination. This is a mechanism study, not a product.
