# PRD Index — Chatway Interview Prep (Real-time Chat Learning Build)

> Throwaway learning repo. The point is to *watch a message travel* so real-time stops being theory before the Chatway senior-backend interview, and to have a narratable answer for the rest of the JD too. Not a product. No polish. Delete after.

Stack pinned: **Laravel 11, PHP 8.2+.** Reverb (Pusher-protocol, local, zero signup) as the broadcast driver.

---

## The two tracks

This prep is split because the chat toy only rehearses one slice of the job description. Build both.

| Doc | Track | What it rehearses | Time |
|---|---|---|---|
| `01-build-realtime-chat.md` | **Real-time build** | Save → event → broadcast → Reverb/Pusher → authorized channel → other browser. Tenant isolation. Queued broadcast. | ~2–2.5 hr |
| `02-interview-prep.md` | **The rest of the JD** | Scale/perf (millions of rows), security depth, third-party webhooks/integrations. | ~2–3 hr |

The real-time build is the confidence artifact — a live thing you can narrate. The prep track covers the JD's *heaviest* themes (scale, perf) which the toy does not touch. Do not let a working chat demo fool you into thinking the interview is covered.

---

## The JD in one glance → where each requirement is covered

Chatway = bootstrapped, profitable SaaS chat widget, fully remote, "millions of records," 5–6yr eng tenure.

| JD requirement | Covered in |
|---|---|
| Event broadcasting, Pusher, Laravel Echo, Socket.io | Build — Modules 2, 3 |
| Private-channel auth / tenant isolation | Build — Modules 2c, 4 |
| Redis queues, Horizon | Build — Module 5 |
| MySQL/PostgreSQL, indexing, query optimization, "millions of records", performance tuning | Prep — Module A |
| Eloquent, Middleware, Service Providers, Policies, Gates | Build (Gates/channel auth) + Prep A (Eloquent perf) |
| Security: JWT, OAuth, CSRF, SQL-injection, XSS | Prep — Module B (channel-auth handshake in Build is a free CSRF/authz talking point) |
| Third-party APIs: Shopify, Wix, Stripe, Twilio | Prep — Module C |
| Debugging production issues independently | Prep — A (slow-query debugging), C (webhook failures) |

---

## Module 0 scaffolding — read this before you run anything

The PRD folder already exists inside `realtime-chat/`, so `laravel new realtime-chat` will refuse the non-empty directory. Scaffold with PRD moved aside, then moved back:

```bash
# from /Users/apple/myprojects
mv realtime-chat/PRD ./PRD-hold
rmdir realtime-chat

composer create-project "laravel/laravel:^11.0" realtime-chat
# (or: laravel new realtime-chat  — same result if you have the installer)

mv ./PRD-hold realtime-chat/PRD        # PRD lives at the repo root, as always
cd realtime-chat
```

Then the real Module 0 (see `01-build-realtime-chat.md`):
```bash
# SQLite: correct filename is database/database.sqlite (NOT sqlite.database)
touch database/database.sqlite
# .env: DB_CONNECTION=sqlite  (comment out the other DB_* lines)

php artisan install:broadcasting      # interactive; say YES to Reverb, YES to Node/Echo scaffolding
npm install
npm run dev                           # terminal 1 (Vite)
php artisan reverb:start              # terminal 2 (WebSocket server)
```
Checkpoint: Reverb is running, Vite is serving. Nothing visible yet.

---

## Definition of done

- **Build:** two browser windows; a message typed in one appears in the other with no refresh. You can open DevTools → Network and point at the `POST /messages`, the `/broadcasting/auth` handshake, and the WebSocket frame, and narrate each. Bonus (Module 4): you watched a cross-tenant subscribe get refused with **403**.
- **Prep:** for each module you can say the one-sentence talking point out loud, from memory, tied to something you actually ran.

## Out of scope (KISS guardrails — do not build)

Styling beyond raw HTML · message edit/delete/read-receipts · real registration/password-reset/roles UI · file uploads/emoji/attachments · tests/deployment/Docker/CI · refactoring for "cleanliness." It is throwaway.
