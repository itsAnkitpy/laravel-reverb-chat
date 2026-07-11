# Code-review findings (2026-07-11) — explained simply

A full read-through of the codebase after Module 5 surfaced three things worth keeping:
one real hole (**fixed the same day**, section 1) and two design gaps that are *fine for
this toy* but are exactly what a chat-company interviewer probes (sections 2 and 3 —
documented as lessons, deliberately not built).

---

## 1. FIXED — the tenant wall had an open door on the write side

**What was wrong.** Module 4 proved nobody can *listen* to another business's
conversation — the channel gate (the code in `routes/channels.php` that approves or
rejects each listener) 403s outsiders. But `POST /messages` only checked that the
conversation id *existed*, never that it *belonged to the sender's business*. So Agent C
(business 2) could post straight into conversation 1 (business 1), and his message would
save and be broadcast to Agents A and B.

Analogy: the meeting room had a receptionist checking IDs at the door for anyone coming
to **listen** — but the mail slot let **anyone** drop a note inside.

**The fix** (in `MessageController::store`): look the conversation up scoped to the
sender's own business. A conversation you don't own now behaves exactly like one that
doesn't exist — the request dies with a 404 before anything is saved:

```php
$conversation = Conversation::where('id', $validated['conversation_id'])
    ->where('business_id', $request->user()->business_id)
    ->firstOrFail();   // outsider => 404, nothing saved, nothing broadcast
```

(404 rather than 403 is a small bonus: it doesn't even reveal *which conversation ids
exist* to someone probing.)

**Proved on the wire** (same curl style as Module 4):

| Logged in as | POSTing into | Before fix | After fix |
|---|---|---|---|
| Agent C (business 2) | conversation 1 (business 1) | 200 — message landed | **404 — nothing saved** |
| Agent C (business 2) | conversation 2 (his own) | 200 | **200** (unchanged) |
| Agent A (business 1) | conversation 1 (his own) | 200 | **200** (unchanged) |

**The lesson: authorization must guard every door separately.** This app has three ways
in — read the page (`/chat`), listen live (the channel gate), and write
(`POST /messages`). Two of three were guarded; that is not a wall. Every route that
takes an id from the browser must re-check ownership itself, because the browser can
send any id it likes.

> **Interview line this earns:** *"I gated the subscribe handshake first and initially
> left the write path open — my own review caught it. The fix is to scope every lookup
> by tenant so a foreign resource 404s. The takeaway I keep: check isolation per entry
> point — read, write, and subscribe are three separate doors."*
> An answer with a found-and-fixed hole in it beats a build that was never wrong.

---

## 2. DOCUMENTED — messages can be silently missed (the catch-up problem)

**Not a bug in what we built — a gap in what we didn't.** There are two time windows
where a message can vanish for a viewer, and every real chat product has to close them:

- **The page-load gap.** `/chat` renders old messages from the database *first*, and
  only then does the browser's JavaScript connect and start listening. A message sent
  during that half-second is too new for the page render and too old for the live
  listener — it belongs to neither list, so it simply never appears.
- **The reconnect gap.** The WebSocket (the always-open pipe from Reverb to the
  browser) will drop in real life — laptop lid closed, wifi blip, phone switching
  towers. It reconnects automatically, but anything sent *during* the outage was
  broadcast to a pipe that wasn't there. Nobody goes back for it.

Analogy: broadcasts are a loudspeaker announcement. If you were out of the room —
even for two seconds — the announcement is gone. A real system also keeps **minutes
of the meeting** (the database) and lets you read what you missed when you walk back in.

**How real products resolve it — the catch-up fetch:**

1. The browser always remembers the **id of the last message it has** (say #42).
2. On every connect *and* reconnect, it asks the server: *"give me everything in this
   conversation after #42"* — a plain HTTP endpoint like
   `GET /messages?conversation_id=1&after=42`. The database is the source of truth;
   the WebSocket is only the fast path.
3. Anything the fetch returns gets appended, then live listening carries on.
4. Because a message can now arrive **twice** (once from the catch-up fetch, once live),
   the client de-duplicates: before appending, skip any message whose id it already has.
   This is why our `broadcastWith` payload carrying the message `id` matters — the id is
   what makes "have I seen this?" answerable.

**How it would wire into this toy** (~an hour, if ever wanted): add the `after` query to
`MessageController`, call it from `chat.blade.php` right after `Echo.private(...)` is
subscribed, and again on the connection's `reconnected`/`connected` event (pusher-js,
which Echo wraps, fires these). Track `lastId` in a variable; dedupe in
`appendMessage`. Deliberately **not built** — the lesson is the shape, not the code.

> **Interview line this earns:** *"Broadcast is fire-and-forget — it only reaches
> sockets connected at that instant. So I'd never treat the WebSocket as the source of
> truth: the DB is truth, the socket is the fast path, and the client reconciles with a
> catch-up fetch keyed on the last message id, deduping by id. That closes both the
> page-load gap and the reconnect gap with one mechanism."*

---

## 3. DOCUMENTED — the history query is unbounded (will die at prep-track-A scale)

**What's there today:** `chat()` runs
`$conversation->messages()->orderBy('id')->get()` — fetch **every** message the
conversation has ever had, render each as a list item. Ten rows: fine. The 200,000 rows
prep track A plans to seed: the page tries to build a 200,000-item list and effectively
hangs. **First thing to change when track A starts.**

**How it's resolved — load the newest slice, page backwards on demand:**

- **Initial load:** newest 50, then flip them into reading order:
  `messages()->latest('id')->limit(50)->get()->reverse()`.
  (`latest('id')` = order newest-first by id.)
- **"Load older" / scrolling up:** ask for the 50 *before the oldest id currently on
  screen*: `->where('id', '<', $oldestSeenId)->latest('id')->limit(50)`. This is
  **keyset pagination** (also called cursor pagination) — "give me rows before this
  bookmark." The alternative, **offset pagination** ("skip 150,000 rows, then give me
  50"), makes the database *walk past* everything it skips, so page 3,000 is brutally
  slow. Keyset jumps straight to the bookmark via the index and stays fast at any
  depth. For an ever-growing feed like chat history, keyset is the right answer;
  offset is fine only for small, shallow lists.

**The index nugget hiding in our own migration** (a classic scale-interview question):
`messages.conversation_id` **is** indexed even though we never wrote `->index()` —
MySQL's InnoDB engine auto-creates an index for every foreign key (the database rule
linking a message to its conversation, from `foreignId()->constrained()`).
**PostgreSQL does not do this** — port this schema to Postgres and every
"messages for conversation X" query silently becomes a full-table scan until you add
the index yourself. Knowing that difference out loud is senior-signal.

> **Interview line this earns:** *"Chat history is an unbounded, ever-growing list, so
> the page never loads it whole — newest slice first, keyset pagination backwards from
> the oldest visible id, never offset, because offset cost grows with depth. And I
> check the tenant/FK columns are actually indexed per engine — MySQL auto-indexes
> foreign keys, Postgres doesn't."*

---

## Glossary (one line each)

- **Write path / read path / subscribe path** — the three ways into this app: sending a message, loading the page, and joining the live channel. Each needs its own ownership check.
- **Scoped lookup** — querying "the row with this id **that belongs to this tenant**" instead of just "the row with this id."
- **Catch-up fetch** — on (re)connect, asking the server over plain HTTP for every message after the last one you have, so nothing missed during a gap stays missing.
- **De-duplication (dedupe)** — skipping a message you already displayed, using its id; needed once a message can arrive by two routes (fetch + live).
- **WebSocket** — the always-open pipe Reverb pushes through; fast, but only reaches browsers connected at that exact moment.
- **Keyset / cursor pagination** — "next 50 before/after this id"; uses the index, stays fast at any depth.
- **Offset pagination** — "skip N rows, then give me 50"; the database pays for every skipped row, so deep pages get slow.
- **Foreign key (FK)** — the database rule tying `messages.conversation_id` to a real conversation row; MySQL auto-indexes it, PostgreSQL does not.
