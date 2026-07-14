# Pusher swap — Explained simply (point the pipe at the cloud)

Goal: after reading this you can explain what changes — and, more importantly, what does *not* — when you swap the delivery pipe from self-hosted Reverb to hosted Pusher, and you can debug the classic "the console is empty but my message showed up" confusion.

---

## The one idea: swap the pipe, keep everything else

Reverb and Pusher both speak the **same protocol** (the Pusher protocol). Switching from your local Reverb server to Pusher's cloud is like changing which phone company carries the call — the phones, the conversation, and the rules for who's allowed on the line are untouched. **No application logic changes:** the `MessageSent` event, `POST /messages`, the `channels.php` tenant gate, and the `/broadcasting/auth` handshake are all identical before and after.

---

## What we did NOT have to touch (the nice surprises)

- **No `composer require`.** `pusher/pusher-php-server` (the server library that talks to Pusher's HTTP API) was **already installed** — Reverb pulls it in as a dependency. Confirmed in `composer.lock`.
- **No `config/broadcasting.php` edit.** Laravel ships a ready-made `'pusher'` connection block that reads `PUSHER_APP_*` from `.env`. It was there the whole time.

So the swap really is: create an account, paste keys, edit one small JS file, rebuild.

---

## The three layers that must ALL flip (this is the lesson)

A broadcast passes through **three processes**, and each caches its settings **at boot**. Flip one and forget the others and you get half-working, confusing behaviour.

1. **The web app (`.env`)** — `BROADCAST_CONNECTION=reverb` → `pusher`, plus the four `PUSHER_APP_*` credentials and two `VITE_PUSHER_*` browser copies. Then `php artisan config:clear`.
2. **The queue worker (Horizon)** — the broadcast is *queued*, so the actual publish to Pusher runs on the **Horizon worker, not the web request**. Horizon caches config at boot, so a worker started *before* the `.env` change is still publishing to Reverb. Fix: `php artisan horizon:terminate`, then start it again. (In our run Horizon was restarted *after* the swap, so this one was already correct.)
3. **The frontend (`echo.js` + a rebuild)** — change `broadcaster: 'reverb'` → `'pusher'`, use `VITE_PUSHER_APP_KEY` + `cluster`, drop the localhost `wsHost/wsPort` lines. Then **`npm run build`** — the compiled bundle in `public/build` hard-codes the old `localhost:8080` until you rebuild. `npm run dev` masks this by serving fresh source; the day you stop it you'd silently fall back to a Reverb server that isn't even running.

---

## The debugging beat: "empty console but the message delivered?"

The Pusher **Debug Console** said *Waiting for new events*, yet a message appeared on screen. That is **not** proof the swap worked — it's the classic trap:

- **The sender always sees its own message locally.** By design (Module 3), the page calls `appendMessage()` right after sending, and the broadcast uses `->toOthers()` (everyone *except* the sender). So the sender's window shows the message even if the broadcast never left the building.
- **Old messages render from the database** on page load — also not real-time.

The real test is the **other** window, and the real proof is on the wire:

- **DevTools → WS tab** shows a connection to `ws-ap2.pusher.com`, not `localhost:8080`.
- **Pusher Debug Console** shows an `API Message` row — channel `private-conversation.1`, event `App\Events\MessageSent`, your `{id, body, user_id}` payload.
- **Server side:** the broadcast job completes with **no errors** and `failed_jobs = 0`.

We fired a real broadcast from the CLI (`broadcast(new MessageSent($m))`) and watched exactly that: Horizon processed the job, published to Pusher with zero errors, and the event landed in the console.

---

## Account setup (so a fresh machine can reproduce)

- Sign up at pusher.com (free **Sandbox** plan). Create a **Channels** app (not **Beams** — that's push notifications, the wrong product).
- Pick the **cluster** nearest you — we used **`ap2`** (Asia Pacific, Mumbai). The client must use the *same* cluster.
- Copy `app_id`, `key`, `secret`, `cluster` from **App Keys** into `.env`. **Never commit them** — `.env` is gitignored; the secret is a real credential.
- Reverb no longer needs to run — Pusher's cloud is the switchboard. Flipping back is just `BROADCAST_CONNECTION=reverb` + reverting `echo.js` (and a rebuild).

---

## Why this matters in production (the interview answer)

> *"The swap is config-only because Reverb already speaks the Pusher protocol, so Laravel authorizes and publishes through the same `PusherBroadcaster` either way. That lets me choose hosted vs self-hosted per project: hosted **Pusher** for zero-ops and pay-per-connection; self-hosted **Reverb** when I want to own the infrastructure and avoid per-message pricing; raw **Socket.io** only on a non-Laravel stack. The application code doesn't care which one carries the call."*

---

## Glossary (one line each)

- **Driver / connection** — which switchboard brand carries broadcasts (`reverb` / `pusher` / `ably`); set by `BROADCAST_CONNECTION`.
- **Cluster** — which region Pusher hosts your app in (`ap2` = Mumbai); client and server must agree.
- **`pusher/pusher-php-server`** — the server library that publishes events to Pusher's HTTP API; already present via Reverb.
- **Local echo** — the sender's own message shown by `appendMessage()` on send, independent of any broadcast.
- **Boot-time config cache** — Horizon (and cached config) read settings once at startup; changing `.env` needs a restart to take effect.
- **Debug Console** — Pusher's dashboard view that shows every event published to the app; the proof the server→Pusher leg works.
