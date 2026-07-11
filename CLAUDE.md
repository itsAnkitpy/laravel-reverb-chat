# CLAUDE.md — Realtime Chat (Chatway interview prep)

Read this first, then `PRD/progress.md` for live status. This file orients; `progress.md` is the source of truth for what's done.

## What this project is

A **throwaway learning build**, not a product. Purpose: let Ankit *watch a chat message travel* end to end (save → event → broadcast → Reverb → authorized channel → other browser) so real-time broadcasting stops being theory before a **Chatway senior-backend developer** interview. Delete after. Do **not** polish, add styling, tests, or scope beyond the PRD.

## Where the plan lives (read these, don't re-derive)

| File | What it is |
|---|---|
| `PRD/progress.md` | **Live status.** Module checklist, decisions log, gotchas. Update it at the end of each module. |
| `PRD/README.md` | Index: the two tracks, JD→module map, scaffolding commands, definition of done. |
| `PRD/01-build-realtime-chat.md` | The build spec — Modules 0–6. Forward-looking plan. |
| `PRD/02-interview-prep.md` | Companion prep — scale/perf, security, webhooks (Modules A–C). |
| `PRD/learning/module-N.md` | Plain-language "what we did and why" per module, for Ankit's learning. Write one after each build module. |

## Status (2026-07-10)

- ✅ **Module 0 done** — Laravel 11.54 + Reverb v1.10.2 + Echo fully wired, frontend builds, Reverb binds :8080. Details in `PRD/learning/module-0.md`.
- ✅ **Module 1 done** — DB switched to MySQL (`chatway`); `conversations` + `messages` tables, `business_id` on `users`, seeder (biz 1, conv 1, Agent A #1 + Agent B #2), local-only `GET /login-as/{id}`. Details in `PRD/learning/module-1.md`.
- ✅ **Module 2 done** — `MessageSent` (ShouldBroadcast) + `POST /messages` (save→broadcast→`toOthers`) + tenant gate in `routes/channels.php`. Queued broadcast verified landing in `jobs`. Details in `PRD/learning/module-2.md`.
- ✅ **Module 3 done** — `chat.blade.php` + `/chat` route + `chat()` method. Blade sends via `fetch` (X-Socket-Id + CSRF), receives via `Echo.private(...).listen('MessageSent')`, renders with `textContent` (XSS-safe). E2E verified server-side. Details in `PRD/learning/module-3.md`.
- ✅ **Module 4 done** — seeded business 2 (conv2 + Agent C #3, `business_id=2`). No new gate code — `channels.php` from M2 already is the wall. Proved on the wire: Agent A→`conversation.1`=**200**+token, Agent C→`conversation.1`=**403** (`AccessDeniedHttpException`). Details in `PRD/learning/module-4.md`.
- ✅ **Module 5 done** — broadcast now async on **Redis 8.8.0 + Horizon** (`QUEUE_CONNECTION=redis`, `REDIS_CLIENT=predis`). Demo `sleep(3)`+logs in `MessageSent::broadcastWith` (worker-side, remove later). Measured `POST /messages`=200 in 0.061s while the worker delivered ~3s later. Dashboard at `/horizon`. Details in `PRD/learning/module-5.md`.
- ✅ **2026-07-11 code review** — cross-tenant **write** hole in `POST /messages` found & fixed (store() now scopes the conversation to the sender's business → foreign id = 404; wire-verified C→conv1=404, C→conv2=200, A→conv1=200). Missed-messages gap + unbounded history query documented as lessons (not built) in `PRD/learning/review-findings.md`.
- ⬜ **Next → optional** — Pusher driver swap (~10 min, config-only, JD-named), then prep tracks A–C in `02-interview-prep.md`. Module 6 (typing) only if time.

Note: stack line below still says SQLite in the original pin — actual DB is now MySQL `chatway` (see decisions log 2026-07-10).

## Stack (pinned — do not change)

Laravel 11 · PHP 8.4.7 · SQLite (`database/database.sqlite`) · **Reverb** as broadcast driver (`BROADCAST_CONNECTION=reverb`, port 8080) · plain Blade + vanilla JS (**not** Livewire — we want the broadcasting mechanism visible).

## How to resume (from project root, two terminals)

```bash
# Redis must be up first (Module 5): brew services start redis   → redis-cli ping = PONG
#   (if brew errors on tap-trust: HOMEBREW_NO_REQUIRE_TAP_TRUST=1 brew services start redis)
npm run dev               # terminal 1 — Vite (frontend build/watch)
php artisan reverb:start  # terminal 2 — WebSocket server on :8080
php artisan horizon       # terminal 3 — drains queued broadcasts off Redis (Module 5; replaced the old queue:work)
```
> Horizon caches code at boot: after editing an event/job, `php artisan horizon:terminate` then start it again.

## Guardrails / gotchas (don't repeat)

- **Do NOT re-run `php artisan install:broadcasting`** — already done. Re-running re-scaffolds everything. (Its npm step also fails in a non-TTY shell; frontend deps were installed manually with `npm install --save-dev laravel-echo pusher-js`.)
- `laravel new` / `create-project` refuses a non-empty dir — `PRD/` lives at root, so any re-scaffold needs the PRD move-aside dance (see `PRD/README.md`).
- Stay strictly inside the current module's scope. Surface deviations as a question, not a surprise in the diff.
- **Modules 4 (tenant isolation) and 5 (Redis + Horizon) are do-these, not optional** — they map to named JD requirements. Pusher driver-swap is a later config-only step (Pusher is the JD-named tech).

## Working style (per Ankit)

Plan-first, KISS/DRY, module-by-module. Pair-programmer tone: push back, surface tradeoffs, no scope creep, no false facts (search when unsure about Laravel specifics). After finishing a build module: update `PRD/progress.md` and add `PRD/learning/module-N.md`.
