# Module 0 — Explained simply (what we set up and why)

Goal of this doc: after reading it you understand *what each piece we installed actually does*, in plain language, before you write a single line of chat code. Nothing here is code you need to write — it's already done. This is the mental model.

---

## The one idea behind the whole project

A normal web page works like **posting letters**. Your browser sends a request ("give me the page"), the server mails back a reply, and the conversation is over. If a new message arrives on the server a second later, your browser has no idea — it would have to *ask again* (refresh).

Real-time chat needs the opposite: an **open phone line** that stays connected, so the moment a message lands on the server, the server can speak up and your browser hears it instantly — no asking, no refresh. That open phone line is called a **WebSocket**.

Everything in Module 0 is us installing the parts that make that open phone line work.

---

## The cast of characters (and what each one is)

Think of a small radio station:

| Piece | Plain-language role | Real name |
|---|---|---|
| **The station / switchboard** | A separate program that holds every open phone line and relays announcements to the right listeners. Runs alongside Laravel as its own process. | **Reverb** (a WebSocket server) |
| **The announcement** | "A new message was just saved!" — the server shouting an event out to whoever's listening. | **Broadcasting** an **event** |
| **The frequency / private channel** | A named radio frequency. Only authorized people are allowed to tune in. | A **channel** (e.g. `conversation.1`) |
| **The receiver in the browser** | A little radio in each browser tab, tuned to a channel, that reacts the instant it hears an announcement. | **Laravel Echo** |
| **The bouncer** | Checks your ID before letting you tune into a private frequency. | `routes/channels.php` |

The beautiful part: Reverb, Pusher, and Ably are three interchangeable "brands" of switchboard that all speak the **same protocol**. We picked Reverb because it's free, runs locally with one command, and needs no signup — but the browser code is identical if we later switch to Pusher (the tool the job posting names). That's why learning on Reverb transfers directly.

---

## What actually happened, step by step

### 1. We created a fresh Laravel 11 app
`composer create-project` downloaded Laravel and all its building blocks into `realtime-chat/`. This is "scaffolding" — the empty house with plumbing and wiring, no furniture yet.

### 2. The database is a single SQLite file
Instead of running a database server (MySQL/Postgres), SQLite stores everything in one file: `database/database.sqlite`. Zero setup, perfect for a throwaway. Laravel 11 already defaults to this. It even ran the starter **migrations** (migrations = version-controlled instructions that build your tables), creating the `users`, `cache`, and `jobs` tables. (That `jobs` table matters later in Module 5 — it's where queued work waits its turn.)

### 3. We turned on broadcasting + installed Reverb
The command `php artisan install:broadcasting` is a helper that wires up the whole real-time system at once. It:

- **Installed Reverb** (the switchboard program) into the project.
- **Generated app keys** in `.env` — think of these as the station's address and secret password so only our app can talk to our switchboard.
- **Created the bouncer file** `routes/channels.php` (empty for now — we write the ID-check rule in Module 2).
- **Set up the browser receiver** by creating `resources/js/echo.js` and making sure it loads on every page.

### 4. We installed the browser-side libraries
Two JavaScript packages, `laravel-echo` (the receiver) and `pusher-js` (the low-level radio hardware Echo uses to speak the WebSocket protocol). These now live in `package.json`.

> **Small hiccup, for your awareness:** the installer normally runs `npm install` for you at the end, but in this automated shell that step needs a real terminal ("TTY") and errored out. Reverb itself installed fine; I just ran the npm part by hand. **On your own terminal this won't happen** — and importantly, don't run `install:broadcasting` again, or it'll try to re-do everything.

### 5. We proved it all works
- `npm run build` compiled the JavaScript chain (`app.js` → `bootstrap.js` → `echo.js` → the two libraries) with no errors. That proves the receiver is correctly wired.
- Starting `php artisan reverb:start` showed the switchboard listening on port **8080**. That proves the station turns on.

---

## The files worth knowing (and what each is for)

| File / setting | What it is, plainly |
|---|---|
| `database/database.sqlite` | The entire database, one file. |
| `.env` → `REVERB_*` keys | The switchboard's address + secret keys, used by the **server**. |
| `.env` → `VITE_REVERB_*` keys | The *same* values, but exposed to the **browser**. Vite (the tool that builds your frontend) only hands variables starting with `VITE_` to browser code — a safety line so server secrets don't leak into JavaScript. |
| `config/reverb.php` | Reverb's own settings. |
| `routes/channels.php` | The **bouncer** — rules for who may join which private channel. Empty now; filled in Module 2c. This one file is your tenant-isolation gate later. |
| `resources/js/echo.js` | Sets up the browser **receiver**, pointed at Reverb (`broadcaster: 'reverb'`). |
| `resources/js/bootstrap.js` | Contains `import './echo';` so the receiver loads on every page. |
| `package.json` | Now lists `laravel-echo` + `pusher-js`. |

---

## Why you run TWO terminals (and "nothing is visible yet")

Two of our pieces are **long-running programs** — they don't finish, they keep running and watching:

- **Terminal 1 — `npm run dev`**: Vite. Watches your JS/CSS and rebuilds instantly as you edit, and serves it to the browser.
- **Terminal 2 — `php artisan reverb:start`**: the switchboard, holding open phone lines.

Each needs its own terminal because each runs forever until you stop it. (Later, Module 5 adds a *third* terminal for the queue worker.)

And the Module 0 checkpoint is deliberately "**nothing visible yet**." That's correct — we built the *plumbing* (the open phone line, the switchboard, the receiver, the bouncer). There's no chat page, no message table, no UI. Those are Modules 1–3. If you opened the app now you'd just see Laravel's default welcome page. That's success for Module 0.

---

## The mental model to carry into Module 1

```
Browser A ──(open phone line / WebSocket)── Reverb ──(open phone line)── Browser B
     ▲                                         ▲
     │ Echo receiver, tuned to channel         │ Laravel tells Reverb "announce this event"
     │                                         │ but only after the bouncer (channels.php)
     └── bouncer checks ID at /broadcasting/auth   let the browser tune in
```

Right now the phone lines and switchboard exist, but no one is saying anything and there are no rules on the bouncer's clipboard. **Module 1** gives us something to talk *about* (the `messages` and `conversations` tables and two agents to talk as). **Module 2** makes Laravel actually shout the announcement and writes the bouncer's rule. **Module 3** puts a receiver in a real page so you *see* it land.

---

## Glossary (one line each)

- **WebSocket** — an always-open two-way connection between browser and server; the "open phone line."
- **Broadcasting** — the server pushing an event out to listeners over that connection.
- **Event** — a small object ("MessageSent") describing something that happened, with a payload.
- **Channel** — a named stream listeners subscribe to; **private** channels require authorization.
- **Reverb** — Laravel's first-party WebSocket server (the switchboard). Speaks the Pusher protocol.
- **Echo** — the browser-side library that subscribes to channels and reacts to events (the receiver).
- **Driver** — which switchboard brand you use (reverb / pusher / ably); swappable, same code.
- **Vite** — the tool that compiles and serves your JavaScript/CSS during development.
- **Migration** — code that builds/changes database tables in a repeatable, version-controlled way.
- **`/broadcasting/auth`** — the URL the browser calls to ask "am I allowed on this private channel?"; the bouncer answers.
