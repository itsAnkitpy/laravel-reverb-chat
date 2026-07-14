<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- laravel-echo reads this automatically and sends it as X-CSRF-TOKEN on
         the /broadcasting/auth handshake. Also used by our fetch() below. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Chat · {{ auth()->user()->name }}</title>
    {{-- Loads the compiled JS chain (app.js → bootstrap.js → echo.js), which
         sets window.Echo, pointed at the broadcast driver (Pusher). --}}
    @vite(['resources/js/app.js'])
    <style>
        :root{
            --bg:#eef1f6; --panel:#ffffff; --line:#e3e7ee;
            --own:#2f50e6; --own-ink:#ffffff;
            --other:#eef0f4; --other-ink:#1f2430;
            --muted:#8a93a3; --ink:#1f2430;
        }
        *{box-sizing:border-box}
        html,body{height:100%}
        body{
            margin:0; background:var(--bg); color:var(--ink);
            font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            -webkit-font-smoothing:antialiased;
            display:flex; justify-content:center;
        }
        /* phone-width app shell, centered */
        .app{
            width:100%; max-width:600px; height:100dvh; background:var(--panel);
            display:flex; flex-direction:column; border-left:1px solid var(--line); border-right:1px solid var(--line);
        }

        /* header */
        header{
            padding:12px 16px; border-bottom:1px solid var(--line); display:flex; align-items:center; gap:12px;
            background:var(--panel); position:sticky; top:0; z-index:2;
        }
        header .h-avatar{
            width:38px;height:38px;border-radius:50%;flex:0 0 auto;
            background:var(--own);color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;
        }
        header .h-title{font-weight:650;font-size:15px}
        header .h-sub{font-size:12px;color:var(--muted)}

        /* messages scroll area */
        .messages{flex:1 1 auto; overflow-y:auto; padding:18px 14px; display:flex; flex-direction:column; gap:12px}

        .empty{margin:auto;color:var(--muted);font-size:14px;text-align:center}

        .row{display:flex; align-items:flex-end; gap:8px; max-width:100%}
        .row--own{flex-direction:row-reverse}

        .avatar{
            width:30px;height:30px;border-radius:50%;flex:0 0 auto;
            display:flex;align-items:center;justify-content:center;
            font-size:12px;font-weight:700;
            background:#dfe3ec;color:#4a5468;
        }
        .row--own .avatar{background:var(--own);color:#fff}

        .bubble{
            max-width:74%; padding:9px 13px; border-radius:16px;
            background:var(--other); color:var(--other-ink);
            border-bottom-left-radius:5px;
            word-wrap:break-word; overflow-wrap:anywhere;
        }
        .row--own .bubble{
            background:var(--own); color:var(--own-ink);
            border-bottom-left-radius:16px; border-bottom-right-radius:5px;
        }
        .bubble .text{white-space:pre-wrap}
        .bubble .time{font-size:11px; margin-top:4px; color:var(--muted); text-align:right}
        .row--own .bubble .time{color:rgba(255,255,255,.75)}

        /* composer */
        form{
            display:flex; gap:10px; align-items:center;
            padding:10px 12px; border-top:1px solid var(--line); background:var(--panel);
        }
        #body{
            flex:1 1 auto; border:1px solid var(--line); background:#f6f7fa;
            border-radius:22px; padding:11px 16px; font-size:15px; outline:none;
        }
        #body:focus{border-color:var(--own); background:#fff}
        .send{
            flex:0 0 auto; width:44px; height:44px; border-radius:50%; border:none;
            background:var(--own); color:#fff; cursor:pointer;
            display:flex; align-items:center; justify-content:center;
        }
        .send:disabled{opacity:.5; cursor:default}
        .send svg{width:20px;height:20px}
    </style>
</head>
<body>
    <div class="app">
        <header>
            <div class="h-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
            <div>
                <div class="h-title">Conversation #{{ $conversation->id }}</div>
                <div class="h-sub">You are {{ auth()->user()->name }} · user #{{ auth()->id() }} · business #{{ auth()->user()->business_id }}</div>
            </div>
        </header>

        <div class="messages" id="messages">
            @forelse ($messages as $message)
                @php $own = $message->user_id === auth()->id(); @endphp
                {{-- Existing messages, rendered server-side. Blade's {{ }} auto-escapes,
                     so this is XSS-safe on the initial load. --}}
                <div class="row {{ $own ? 'row--own' : 'row--other' }}" data-id="{{ $message->id }}">
                    <div class="avatar">{{ $message->user_id }}</div>
                    <div class="bubble">
                        <div class="text">{{ $message->body }}</div>
                        <div class="time">{{ $message->created_at->format('H:i') }}</div>
                    </div>
                </div>
            @empty
                <div class="empty" id="empty">No messages yet — say hi 👋</div>
            @endforelse
        </div>

        <form id="send-form">
            <input id="body" autocomplete="off" placeholder="Type a message…">
            <button type="submit" class="send" aria-label="Send">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3.4 20.4l17.45-7.48a1 1 0 000-1.84L3.4 3.6a.993.993 0 00-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"/></svg>
            </button>
        </form>
    </div>

    <script>
        const conversationId = {{ $conversation->id }};
        const currentUserId  = {{ auth()->id() }};
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const messagesEl = document.getElementById('messages');

        function formatTime(date) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
        }

        function scrollToBottom() {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        // Build a message bubble. `time` is optional — live messages have no
        // server timestamp in the payload, so we stamp them with the client clock.
        function appendMessage(payload, time) {
            document.getElementById('empty')?.remove();

            const own = payload.user_id === currentUserId;

            const row = document.createElement('div');
            row.className = 'row ' + (own ? 'row--own' : 'row--other');
            if (payload.id) row.dataset.id = payload.id;

            const avatar = document.createElement('div');
            avatar.className = 'avatar';
            avatar.textContent = payload.user_id;

            const bubble = document.createElement('div');
            bubble.className = 'bubble';

            const text = document.createElement('div');
            text.className = 'text';
            // textContent, NOT innerHTML. A body like "<img src=x onerror=alert(1)>"
            // shows as literal text instead of executing. This is the XSS defense.
            text.textContent = payload.body;

            const stamp = document.createElement('div');
            stamp.className = 'time';
            stamp.textContent = time || formatTime(new Date());

            bubble.appendChild(text);
            bubble.appendChild(stamp);
            row.appendChild(avatar);
            row.appendChild(bubble);
            messagesEl.appendChild(row);
            scrollToBottom();
        }

        document.addEventListener('DOMContentLoaded', () => {
            scrollToBottom();

            // (1) RECEIVE — subscribe to this conversation's PRIVATE channel.
            // Subscribing fires the /broadcasting/auth handshake (the bouncer),
            // then any MessageSent event on the channel lands here.
            window.Echo.private('conversation.' + conversationId)
                .listen('MessageSent', (payload) => appendMessage(payload));

            // (2) SEND — POST the message, no page reload.
            const form  = document.getElementById('send-form');
            const input = document.getElementById('body');

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const body = input.value.trim();
                if (!body) return;

                const response = await fetch('/messages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        // Tells the server which socket is "me" so ->toOthers()
                        // can skip it. Raw fetch (unlike axios) won't add this.
                        'X-Socket-Id': window.Echo.socketId(),
                    },
                    body: JSON.stringify({ conversation_id: conversationId, body: body }),
                });

                if (response.ok) {
                    // Show my own message locally — the server used ->toOthers(),
                    // so this broadcast will NOT echo back to me.
                    appendMessage(await response.json());
                    input.value = '';
                    input.focus();
                } else {
                    console.error('Send failed:', response.status, await response.text());
                }
            });
        });
    </script>
</body>
</html>
