# Module 1 — Explained simply (the data model, and why it's shaped this way)

Goal: after reading this you understand what the three tables are — and, the real lesson, *where the wall between companies lives*. That wall is one column, `business_id`, and it's the thing the Chatway founder cared most about.

---

## The one idea: `business_id` is the wall

Chatway is **one app used by thousands of separate companies**. Company X's agents must never see Company Y's chats. That separation is called **tenant isolation** — a "tenant" is one company renting space in the shared app, like flats in one building.

We enforce it the cheapest way that works: a single column, `business_id`, stamped on every row that must stay private. Same number = same company = allowed to see each other. Different number = walled off.

This is **row-level isolation** — everyone shares the same tables, and a column decides who sees what. For a chat widget it's the right call. The heavier options (a separate schema or a separate database per company) buy stronger isolation at real operational cost, and you'd only reach for them with a specific reason. That contrast is a strong interview beat: name row-level as the sensible default, and know exactly when you'd escalate.

---

## The three tables (plain language)

| Table | Holds | The column that matters |
|---|---|---|
| `users` (already existed) | your agents | we **added** `business_id` — which company each agent works for |
| `conversations` | one chat thread | `business_id` — which company owns the thread |
| `messages` | one line of text | `conversation_id` + `user_id` — who said it, in which thread |

Notice **messages have no `business_id` of their own**. They don't need one: a message belongs to a conversation, and the conversation already carries the wall. Follow the chain — `message → conversation → business_id`. Interview phrasing: *"the tenant key lives on the conversation; messages inherit isolation through their parent."*

There's deliberately **no `businesses` table**. For this toy a "business" is just the number `1`. A real businesses table would be scope we don't need to teach the lesson. (KISS.)

```
business_id = 1
   │
   ├── users:  Agent A (#1),  Agent B (#2)     ← both stamped business_id = 1
   │
   └── conversations: #1  (business_id = 1)
            │
            └── messages: (none yet — Modules 2/3 create these live)
```

---

## Why `business_id` was a *separate* migration

We did **not** reopen the original `create_users_table` file to add the column. We wrote a new migration, `add_business_id_to_users_table`.

The rule: **once a migration has run in production, you never edit it.** Teammates and other environments have already applied the old version; changing the file means their database and the code disagree. You add a forward-only change instead. Here it's throwaway and either approach works — but the *habit* is the interview answer.

Two small details in that migration:
- **`nullable()`** — so adding the column to a table that might already hold rows can't fail.
- **`index()`** — because every tenant-scoped query will filter `WHERE business_id = ?`, and an index is what keeps that fast at millions of rows. Direct nod to the JD's *"indexing / query optimization"* line.

---

## The `login-as` shortcut (and why it exists)

Real-time **private channels need a logged-in user**, because the authorization check (built in Module 2) receives that `$user` and inspects their `business_id`. Building a real login screen would drag in scaffolding we'd only delete.

So: a dev-only route, `GET /login-as/{id}`, that logs you straight in as a user by id — no password. You'll open `/login-as/1` in one browser window (become Agent A) and `/login-as/2` in another (Agent B), then watch a message cross between them.

It's guarded with `abort_unless(app()->environment('local'), 403)`. A password-free login route is a gaping hole anywhere but your laptop, so it refuses to run outside `local`. Worth saying out loud in an interview — it shows the security reflex.

---

## What we actually ran

- `php artisan migrate` — created `conversations` + `messages` and added `users.business_id`. (Refresh phpMyAdmin: you'll see 2 new tables, and `users` now has a `business_id` column.)
- `php artisan db:seed` — inserted business 1's conversation and both agents.
- Verified: Agent A **#1** and Agent B **#2**, both `business_id = 1`, sharing conversation **#1**. Zero messages yet — those get born live in Module 3.

---

## The mental model into Module 2

We now have something to talk *about* (a conversation) and two identities to talk *as* (the agents). Module 2 is the core: Laravel will **save** a message, then **shout** it as an event onto the private channel `conversation.1`, and the **bouncer** in `routes/channels.php` will use exactly the `business_id` column we just created to decide who is allowed to hear it.

That bouncer rule — `user.business_id === conversation.business_id` — is the payoff this whole "boring" module was quietly setting up.

---

## Glossary (one line each)

- **Tenant / tenant isolation** — one company in a shared app; keeping each company's data walled off.
- **Row-level isolation** — shared tables, a column (`business_id`) decides visibility. The simplest model that works; the default.
- **Migration** — versioned code that builds/changes tables. Forward-only once it has shipped.
- **Seeder** — a script that inserts starter rows.
- **Eloquent model** — a PHP class (`Message`, `Conversation`) you use instead of writing SQL by hand.
- **`$fillable`** — the whitelist of columns `create([...])` is allowed to set (mass-assignment protection).
- **Foreign key (FK)** — a column pointing at another table's row (`messages.conversation_id → conversations.id`), enforced by the database.
- **`loginUsingId`** — log a user in by their id, skipping the password. Dev convenience only.
