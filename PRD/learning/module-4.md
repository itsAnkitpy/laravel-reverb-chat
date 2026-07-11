# Module 4 — Explained simply (prove tenant isolation)

Goal: after reading this you can explain, out loud, **why one company's agent cannot listen to another company's conversation** — and point at the exact line that stops them and the exact 403 it produces. This is your strongest interview card because multi-tenancy is your home turf.

---

## The surprise: there was no new code to write

The whole "wall" was already built back in Module 2. Module 4 didn't add a gate — it **seeded a second tenant and watched the existing gate reject a trespasser.** The wall is one function in `routes/channels.php`:

```php
Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conversation = Conversation::find($id);
    return $conversation !== null
        && (int) $user->business_id === (int) $conversation->business_id;
});
```

Read it in one sentence: *"You may listen to this conversation only if your business is the business that owns it."* That single comparison is the entire multi-tenant boundary. Return `true` → you join the channel. Return `false` → **403, you never hear a byte.**

Analogy: the channel is a private meeting room. This callback is the receptionist checking your company ID against the room's booking before buzzing you in. It runs on **every** subscribe attempt.

---

## What we set up (the two tenants)

The seeder now creates two separate "businesses" (a business here is just an id — no businesses table needed for the toy):

| Who | user id | business_id | Their conversation |
|---|---|---|---|
| Agent A | 1 | 1 | conversation 1 (business 1) |
| Agent B | 2 | 1 | conversation 1 (business 1) |
| **Agent C** | **3** | **2** | conversation 2 (business 2) |

Agent C is the outsider. Business 2 has no claim on conversation 1, so if Agent C tries to listen to `conversation.1`, the receptionist must turn them away.

---

## Where the handshake happens

When a browser calls `Echo.private('conversation.1')`, it doesn't connect straight to the chat. First it POSTs to **`/broadcasting/auth`** — the bouncer. Laravel runs the `channels.php` callback there and answers yes or no. Two things worth knowing:

- **This endpoint is plain HTTP, not the WebSocket server.** Reverb can be completely stopped and the auth check still fires. That's *why* we could prove the whole thing with `curl`, no browser and no Reverb needed.
- **The class that runs it is `PusherBroadcaster`** — even though we run Reverb. Reverb speaks the Pusher protocol, so Laravel authorizes through the same Pusher code path. (This is also why the later Pusher swap is config-only — same broadcaster underneath.)

---

## What we proved on the wire (the payoff)

We drove `/broadcasting/auth` twice — **same channel, same request, only the logged-in agent differs** — so the 403 clearly comes from *who you are*, not from anything else:

| Logged in as | Subscribing to | Result |
|---|---|---|
| **Agent A** (business 1) | `private-conversation.1` | **HTTP 200** + a signed auth token → allowed on |
| **Agent C** (business 2) | `private-conversation.1` | **HTTP 403** `AccessDeniedHttpException` → rejected |

The 403 stack trace points straight at `Broadcaster::verifyUserCanAccessChannel` (`Broadcaster.php:123`) — that's Laravel calling our callback, getting `false`, and refusing the subscription. The 200 case returns `{"auth":"<app-key>:<hmac-signature>"}` — the token the client would hand to Reverb to actually join.

**The A=200 / C=403 contrast is the entire lesson.** If both had 403'd, you'd only know the endpoint denies things. Because A got through and C didn't, you've proven the gate *discriminates by tenant*.

---

## See it yourself in a browser (watch it move)

1. Log in as Agent C: open `http://localhost:8000/login-as/3` → lands on `/chat`, which (correctly) shows **conversation 2**, Agent C's own tenant. The controller scopes `/chat` to your own business, so it never accidentally loads someone else's room.
2. Open DevTools → Console and deliberately trespass:
   ```js
   window.Echo.private('conversation.1')   // business 1's room — not yours
   ```
3. Watch DevTools → Network: the `POST /broadcasting/auth` for `private-conversation.1` comes back **403**. Agent C is refused before joining. Do the same from Agent A and it's 200.

No code is changed for this — the console call is the trespass, the 403 is the wall doing its job.

---

## The interview answer this earns you

> *"I built a small multi-tenant chat and watched the auth endpoint reject a cross-tenant subscribe with a 403. The gate is a row-level `business_id` check in the channel callback — for a chat widget that's the right isolation model: it's the simplest thing that works, it's enforced on every subscribe, and one comparison is the whole boundary. I'd escalate to schema-per-tenant or database-per-tenant only with a real driver — hard compliance/data-residency isolation, noisy-neighbor performance at scale, or per-tenant backup/restore needs — because that isolation buys real operational cost. Starting there without that justification is over-engineering."*

That last part — knowing *when* row-level stops being enough — is what separates a senior answer from a textbook one. Lean into it; it's your domain.

---

## Glossary (one line each)

- **Tenant / multi-tenancy** — many separate customers (businesses) sharing one app; the job is keeping their data invisible to each other.
- **Row-level isolation** — every tenant's rows live in the same tables, separated only by a `business_id` column and a check like this callback. Simplest model; the default starting point.
- **Channel callback** (`channels.php`) — the function that authorizes (or denies) a subscription; here it *is* the tenant wall.
- **`/broadcasting/auth`** — the HTTP handshake that runs the callback before a client can join a private channel; returns 200 + token or 403.
- **`AccessDeniedHttpException`** — the exception Laravel throws when the callback returns `false`; surfaces as HTTP 403.
- **`PusherBroadcaster`** — the broadcaster class Laravel uses for both Pusher and Reverb (Reverb speaks the Pusher protocol).
