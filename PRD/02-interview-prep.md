# PRD 02 — Interview Prep (the rest of the JD)

> The chat build rehearses real-time. This doc rehearses the JD's *heaviest* themes — scale, security depth, integrations — which the toy does not touch. Same format: each module is a small hands-on drill plus the one-sentence talking point it earns you.

**Reuse the chat repo.** These drills run against the same `realtime-chat` app so you're not building a second project. Seed the `messages` table big where noted.

Why this doc exists: a "millions of records" shop will grill a *senior backend* candidate hardest on query performance and production debugging — which is your own self-named weak domain. A green-checkmark chat demo will *feel* like prep while leaving that untouched. Don't let it.

---

## Module A — Scale & performance (~1–1.5 hr) — highest JD weight

Maps to JD: *"large-scale projects," "millions of records," "indexing, query optimization," "performance tuning," "debugging production issues independently."*

**Setup:** factory-seed `messages` to ~200k+ rows across a few conversations so `EXPLAIN` and pagination actually show something.
```php
Message::factory()->count(200000)->create();   // in a seeder or tinker
```

### A1 — N+1 queries → eager loading
*N+1 = one extra query per parent row instead of one batched query.* Render the message list looping `$message->user->name`. Turn on query logging (`DB::enableQueryLog()` in tinker, or install Laravel Telescope / Debugbar) and count queries — you'll see ~1 + N. Fix with `Message::with('user')->...`. Watch the count drop to 2.
- **Talking point:** *"N+1 is the first thing I look for on a slow index page — I eager-load with `with()` and confirm the query count in Telescope."*

### A2 — Indexing + `EXPLAIN`
Query `messages` by `conversation_id` ordered by `created_at`. Run `EXPLAIN` (SQLite: `EXPLAIN QUERY PLAN`; MySQL: `EXPLAIN`) before and after adding a **composite index** on `(conversation_id, created_at)`. Watch it go from a full scan to an index range scan.
- **Talking point:** *"For a chat table, the hot query is messages-by-conversation-newest-first, so the index is composite `(conversation_id, created_at)` — order matters because the leftmost column must match the filter."*

### A3 — Pagination on millions of rows
Compare `->paginate()` (offset) vs `->cursorPaginate()` (keyset). *Keyset/cursor pagination = "give me rows after this id/timestamp" instead of "skip 1,000,000 rows."* Offset degrades on deep pages because the DB scans and discards everything before the offset.
- **Talking point:** *"Offset pagination is fine for page 3, deadly for page 40,000 — it scans and throws away every prior row. On millions of records I use cursor/keyset pagination on an indexed column."*

### A4 — Debugging a slow query independently
Use `->toSql()` to see the generated SQL, `DB::listen()` to log every query + time, and read a slow-query log. Practice the loop: reproduce → measure → `EXPLAIN` → add index or rewrite → re-measure.
- **Talking point:** *"My loop for a slow endpoint is reproduce, measure with `DB::listen`, `EXPLAIN` the offender, fix the index or the N+1, re-measure — I don't guess."*

**Checkpoint:** you can narrate A1–A4 from memory, each tied to a number you actually saw (query count, EXPLAIN plan, timing).

---

## Module B — Security depth (~45 min)

Maps to JD: *"JWT, OAuth, CSRF, SQL injection, XSS prevention."* The chat build already handed you the **channel-auth handshake** (CSRF token + private-channel authorization) as a free talking point — reference it.

### B1 — XSS (you have a live one)
The chat's `appendMessage` via `innerHTML` is a stored-XSS hole: send `<img src=x onerror=alert(1)>` as a message body. Fix with `textContent`. Note Blade `{{ }}` auto-escapes but a JS DOM append does not.
- **Talking point:** *"User-generated content is escaped at the point it hits the DOM — Blade `{{ }}` does it server-side, but a JS `textContent` (never `innerHTML`) does it client-side. I demoed the hole in my own chat and closed it."*

### B2 — SQL injection
Show a `whereRaw("body LIKE '%$input%'")` (vulnerable) vs bound `where('body', 'like', "%{$input}%")` / `whereRaw('... ?', [$input])` (safe). Eloquent/query-builder uses PDO bindings by default.
- **Talking point:** *"Eloquent binds parameters by default; the only way you get SQLi in Laravel is by hand-rolling `whereRaw` with string interpolation — which I don't."*

### B3 — CSRF + channel auth (already built)
Point at the `/broadcasting/auth` call from the build: it carries the CSRF token and the session cookie; the server authorizes the private channel via the `channels.php` gate. That's CSRF + authorization in one flow.
- **Talking point:** *"Private-channel subscription isn't trust-the-client — it round-trips to `/broadcasting/auth`, CSRF-protected and session-authenticated, and my gate decides. I watched it 403 a cross-tenant attempt."*

### B4 — JWT vs session, OAuth (conceptual)
Chatway integrates Shopify/Wix → OAuth. Be able to sketch: session/cookie auth (what the widget dashboard uses) vs JWT (stateless, for APIs) and the OAuth authorization-code flow (redirect → consent → code → token exchange). *OAuth = "let this app act on the user's behalf on another service without sharing the password."*
- **Talking point:** *"Sessions for the first-party dashboard, OAuth authorization-code for connecting a merchant's Shopify/Wix store, tokens stored per-tenant."*

**Checkpoint:** each of B1–B4 is a sentence you can say cold, three of them tied to something in your own repo.

---

## Module C — Third-party integrations & webhooks (~45 min)

Maps to JD: *"Shopify, Wix, Stripe, Twilio integration experience."* Chatway is a chat widget that lives inside those platforms, so inbound webhooks are its lifeblood.

### C1 — Build one inbound webhook endpoint
`POST /webhooks/stripe` (pick any provider) that does the three things that separate senior from junior webhook handling:
1. **Verify the signature** — HMAC of the raw body against a shared secret (Stripe: `Stripe-Signature`; Shopify: `X-Shopify-Hmac-Sha256`). Reject if it doesn't match. *Never trust an unsigned webhook.*
2. **Idempotency** — the same event *will* be delivered more than once. *Idempotent = processing it twice has the same effect as once.* Dedupe on the provider's event id (store processed ids; skip if seen).
3. **Return 200 fast, process on the queue** — validate + dispatch a job, respond immediately. Ties straight to Module 5's queue learning: providers retry aggressively on slow/failed responses.

### C2 — Talking points to rehearse
- *"Three rules for a webhook: verify the signature on the raw body, dedupe on the event id for idempotency, and offload the work to a queue so I return 200 before the provider times out and retries."*
- *"Retries are a feature, not a bug — which is exactly why idempotency is non-negotiable."*
- *"For outbound (Twilio SMS, Stripe charges) I wrap calls with timeouts + retries and treat the provider as unreliable."*

**Checkpoint:** you can walk the three webhook rules and point at your endpoint doing each.

---

## Definition of done (prep track)

For every module A–C: the talking point comes out of your mouth from memory, and each is anchored to something you actually ran in the repo — not something you read. That anchoring is what makes it sound like experience instead of revision.
