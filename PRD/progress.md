# Progress Tracker — Realtime Chat (Chatway prep)

Living checklist. Updated at the end of each module. For the *why* behind each step, see `learning/module-N.md`.

**Legend:** ✅ done · 🟡 in progress · ⬜ not started · ⏭️ skipped/optional

**Current position:** All JD-named build modules done **and the Pusher driver swap is complete (2026-07-14)** — the live broadcast driver is now hosted **Pusher** (cluster `ap2`), verified on the wire. Reverb stays wired; the swap is config-only. 2026-07-11 code review: cross-tenant **write** hole found & fixed, two design gaps documented as interview lessons — see `learning/review-findings.md`. Next → optional (prep tracks A–C, Module 6 typing).

---

## Environment snapshot (captured 2026-07-08)

| Thing | Value |
|---|---|
| Laravel | 11.54.0 |
| PHP | 8.4.7 |
| Composer | 2.2.23 |
| Node / npm | v23.3.0 / 10.9.0 |
| DB | MySQL → `chatway` @ 127.0.0.1:3306 (switched from SQLite 2026-07-10) |
| Broadcast driver | **Pusher** (hosted, cluster `ap2`) — `BROADCAST_CONNECTION=pusher`, swapped 2026-07-14. Reverb v1.10.2 still wired; flip the env + `echo.js` back to self-host. |
| Reverb port | 8080 (only when the Reverb driver is active) |
| Queue (M5) | Redis 8.8.0 @ 127.0.0.1:6379, drained by **Horizon** (`QUEUE_CONNECTION=redis`, `REDIS_CLIENT=predis`) |

---

## Build track (`01-build-realtime-chat.md`)

| Module | What | Status | Notes |
|---|---|---|---|
| 0 | Setup: scaffold + Reverb + Echo | ✅ | 2026-07-08. All wired, build compiles, Reverb binds :8080. See `learning/module-0.md`. |
| 1 | Data model: conversations, messages, `business_id`, seed, `login-as` route | ✅ | 2026-07-10. 3 migrations, 2 models, seeder (biz 1, conv 1, Agent A #1 + Agent B #2), local-only `login-as`. See `learning/module-1.md`. |
| 2 | Broadcast the message (event + controller + channel gate) | ✅ | 2026-07-10. `MessageSent` (ShouldBroadcast), `POST /messages` (save→broadcast→`toOthers`), tenant gate in `channels.php`. Verified queued job landed in `jobs`. See `learning/module-2.md`. |
| 3 | Frontend + payoff (Blade, fetch, Echo listen, watch Network tab) | ✅ | 2026-07-10. `chat.blade.php` + `/chat` route + `chat()` method. E2E verified server-side (login → /chat → POST → queue → Reverb, no failures). `textContent` XSS-safe. See `learning/module-3.md`. |
| 4 | Prove tenant isolation (403 on cross-tenant subscribe) | ✅ | 2026-07-10. Seeded business 2 (conv2 + Agent C #3). No gate code needed — `channels.php` from M2 already is the wall. Proved on the wire: Agent A→`conversation.1`=**200**+token, Agent C→`conversation.1`=**403**. See `learning/module-4.md`. |
| 5 | Async broadcast on Redis + Horizon | ✅ | 2026-07-10. Redis 8.8.0 (brew) + Horizon + predis. `QUEUE_CONNECTION=redis`. Demo `sleep(3)`+logs in `MessageSent::broadcastWith` (worker-side, remove later). Measured: `POST /messages`=**HTTP 200 in 0.061s**, worker START→DONE ~3s apart. Dashboard at `/horizon`. See `learning/module-5.md`. |
| 6 | Typing indicator (presence + whisper) | ⏭️ | Only if flying. |
| — | Pusher driver swap (config-only) | ✅ | 2026-07-14. `BROADCAST_CONNECTION=pusher` + `PUSHER_*` (cluster `ap2`) in `.env`; `echo.js` → `broadcaster:'pusher'` + cluster; `npm run build`. **No** composer/config edits (pusher-php-server already transitive via Reverb; the `pusher` block ships in `broadcasting.php`). Verified: broadcast published, 0 errors, WS to `ws-ap2.pusher.com`. See `learning/pusher-swap.md`. |

## Prep track (`02-interview-prep.md`)

| Module | What | Status | Notes |
|---|---|---|---|
| A | Scale & performance (N+1, indexing, pagination, slow-query debug) | ⬜ | Highest JD weight. Seed messages to 200k+. |
| B | Security depth (XSS, SQLi, CSRF/channel-auth, JWT/OAuth) | ⬜ | XSS + channel-auth reuse the build. |
| C | Webhooks & integrations (signature, idempotency, queue) | ⬜ | Shopify/Wix/Stripe/Twilio talking points. |

---

## Decisions log

- **2026-07-14 — Pusher driver swap executed (JD-named tech).** Flipped the live broadcast driver from self-hosted Reverb to hosted **Pusher** (Channels app, cluster `ap2`). Config-only: `BROADCAST_CONNECTION=pusher` + `PUSHER_APP_*`/`VITE_PUSHER_*` in `.env`, and `resources/js/echo.js` → `broadcaster:'pusher'` with `cluster`, dropping the localhost `wsHost/wsPort` lines. **No** `composer require` (pusher-php-server already installed transitively by Reverb) and **no** `config/broadcasting.php` edit (the `pusher` connection ships with Laravel). Lesson — the swap has **three layers that each cache config at boot**: the web app (`.env` + `config:clear`), the **Horizon worker** (it publishes the broadcast, so restart it after the env change), and the **frontend** (`echo.js` + `npm run build` — the compiled `public/build` bundle hard-codes `localhost:8080` until rebuilt; `npm run dev` masks it). Debug beat: an empty Pusher Debug Console while a message "delivered" was the sender's **local echo** (`->toOthers()` skips the sender) + DB-rendered history, not a real round-trip — proved the real path by firing `broadcast(new MessageSent($m))`, watching Horizon publish with 0 errors and the event land in the console. Details + interview framing in `learning/pusher-swap.md`.
- **2026-07-11 — Cross-tenant WRITE hole fixed in `POST /messages`.** Review found the Module 4 wall only guarded *listening*: `store()` validated `exists:conversations,id` but never checked ownership, so Agent C (business 2) could post into conversation 1 and have it broadcast to business 1's agents. Fix: scoped lookup in `MessageController::store` (`where business_id = sender's`) + `firstOrFail` → foreign conversation now 404s before anything saves (404 also avoids leaking which ids exist). Proved on the wire: C→conv1 = **404**, C→conv2 = **200**, A→conv1 = **200**. Lesson: check tenant isolation per entry point — read, write, and subscribe are three separate doors. Details + interview framing in `learning/review-findings.md` §1.
- **2026-07-11 — Findings 3 & 4 documented, deliberately NOT built.** (a) Missed-messages gap: broadcast only reaches sockets connected at that instant — page-load and reconnect windows silently drop messages; real fix is a catch-up fetch keyed on last message id + dedupe by id. (b) Unbounded history query: `chat()` does `->get()` on all messages — will hang the page at prep-track-A's 200k seed; fix is newest-50 + keyset (not offset) pagination. Both are interview material, written up in `learning/review-findings.md` §2–3.
- **2026-07-10 — predis over phpredis (Redis client).** phpredis is the faster C-extension and the production standard, but installing it on macOS needs PECL + compilation — friction not worth it for a throwaway. predis is a pure-PHP `composer require`, works with Horizon. Interview framing: "local used predis for zero setup; prod uses the phpredis extension for throughput." Set `REDIS_CLIENT=predis`.
- **2026-07-10 — Module 5 on Redis + Horizon (executed).** Redis 8.8.0 via brew, `laravel/horizon` installed + `horizon:install`, `QUEUE_CONNECTION=redis`. `php artisan horizon` replaces `queue:work`. Demo delay lives in `MessageSent::broadcastWith` (runs on the worker, not the request).
- **2026-07-10 — MySQL over SQLite.** Ankit wanted to watch rows land in phpMyAdmin, and MySQL is a JD-named engine so it doubles as rehearsal. DB `chatway` @ 127.0.0.1:3306, root/no-password (local only). Broadcasting flow is engine-agnostic — zero code impact.
- **2026-07-10 — `business_id` added via separate additive migration**, not by editing `create_users_table`. Teaches the prod rule (never edit a shipped migration). Column is `nullable()` + `index()`ed (indexing nod to the JD).
- **2026-07-08 — Laravel 11 (not 13).** Throwaway learner; broadcasting flow is identical across 11/12/13, so no reason to chase latest. Pinned `laravel/laravel:^11.0`.
- **2026-07-08 — Reverb over Pusher for the build.** Zero-friction local WebSocket server, identical Echo API. Pusher swap kept as a later config-only step since it's the JD-named tech.
- **2026-07-08 — Module 5 on Redis + Horizon, not `database` queue.** JD lists "Redis queues, Horizon" explicitly; rehearse the real tools.
- **2026-07-08 — Modules 4 & 5 promoted from "optional" to do-these.** Both map to named JD requirements.

## Gotchas hit (so we don't repeat)

- **`install:broadcasting` npm step failed** with `TTY mode requires /dev/tty` (non-interactive shell). Reverb itself installed fine; completed frontend with `npm install --save-dev laravel-echo pusher-js`. On a real terminal this won't happen — **do not re-run `install:broadcasting`.**
- **`laravel new` refuses a non-empty dir.** Because `PRD/` already existed, scaffolding needs the PRD move-aside dance (see `README.md`).
- **`/broadcasting/auth` is pure Laravel HTTP, not Reverb.** The private-channel handshake never touches the WebSocket server — Reverb can be down and the 403/200 still fires. Useful for headless verification (curl the endpoint with a session cookie + `X-CSRF-TOKEN`; without the token you get 419, not 403).
- **The broadcaster class under Reverb is `PusherBroadcaster`.** Reverb speaks the Pusher protocol, so Laravel authorizes through the same class — visible in the 403 stack trace (`Broadcaster::verifyUserCanAccessChannel`). Not a bug; it's why the Pusher driver-swap is config-only.
- **`brew install redis` blocked by a tap-trust error** from an unrelated `mongodb/brew` tap (Homebrew evaluates all taps on load). Worked around with `HOMEBREW_NO_REQUIRE_TAP_TRUST=1` inline **for that one command only** — not persisted, mongodb tap not globally trusted. Same flag needed on `brew services ...redis`.
- **Horizon caches PHP code at boot.** Editing `MessageSent` after starting Horizon has no effect until you restart it: `php artisan horizon:terminate` then `php artisan horizon` again. (In prod a supervisor auto-restarts on terminate; here we run it by hand.)
- **`sleep(3)` + logs in `MessageSent::broadcastWith` are DEMO-ONLY.** They run on the worker to make the async gap visible. Remove before you'd ever call this code "clean" (it's a throwaway, so fine to leave for manual replay).
- **Pusher swap "empty console but it delivered" is a false positive.** The sender always sees its own message (`->toOthers()` skips the sender; the page appends locally) and `/chat` renders DB history on load — neither is a real-time round-trip. Verify the actual pipe on the wire: DevTools **WS** tab should read `ws-ap2.pusher.com` (not `localhost:8080`), and the Pusher **Debug Console** should show an `API Message` on `private-conversation.1`.
- **A driver swap only takes after every layer reboots.** `.env` change needs `config:clear`; **Horizon caches config at boot** so it must be restarted (it publishes the broadcast); and the frontend needs `npm run build` because `public/build` bakes in the old `localhost:8080` — `npm run dev` hides a stale build until you stop it.

## Next action

All JD-named **build** modules (0–5) **and the Pusher driver swap** are done. Remaining, in priority order:

1. **Prep track A — scale & performance** (`02-interview-prep.md`) — highest JD weight (N+1, indexing, pagination, slow-query debug). Seed messages to 200k+.
2. **Prep track B — security depth**, **C — webhooks/integrations**.
3. **Module 6 — typing indicator** (presence + whisper) — only if flying.
4. **Cleanup** — remove the demo `sleep(3)` + logs from `MessageSent::broadcastWith` before calling any of this "clean."
