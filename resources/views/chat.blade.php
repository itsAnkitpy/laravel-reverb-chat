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
         sets window.Echo, pointed at Reverb. --}}
    @vite(['resources/js/app.js'])
</head>
<body>
    <h2>Conversation #{{ $conversation->id }}</h2>
    <p>You are <strong>{{ auth()->user()->name }}</strong>
       (user #{{ auth()->id() }}, business #{{ auth()->user()->business_id }})</p>

    <ul id="messages">
        {{-- Existing messages, rendered server-side. Blade's {{ }} auto-escapes,
             so this is XSS-safe on the initial load. --}}
        @foreach ($messages as $message)
            <li>#{{ $message->user_id }}: {{ $message->body }}</li>
        @endforeach
    </ul>

    <form id="send-form">
        <input id="body" autocomplete="off" placeholder="Type a message and hit Send…" size="40">
        <button type="submit">Send</button>
    </form>

    <script>
        const conversationId = {{ $conversation->id }};
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        function appendMessage(payload) {
            const li = document.createElement('li');
            // textContent, NOT innerHTML. A body like "<img src=x onerror=alert(1)>"
            // shows as literal text instead of executing. This is the XSS defense.
            li.textContent = '#' + payload.user_id + ': ' + payload.body;
            document.getElementById('messages').appendChild(li);
        }

        document.addEventListener('DOMContentLoaded', () => {
            // (1) RECEIVE — subscribe to this conversation's PRIVATE channel.
            // Subscribing fires the /broadcasting/auth handshake (the bouncer),
            // then any MessageSent event on the channel lands here.
            window.Echo.private('conversation.' + conversationId)
                .listen('MessageSent', (payload) => appendMessage(payload));

            // (2) SEND — POST the message, no page reload.
            document.getElementById('send-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const input = document.getElementById('body');
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
                } else {
                    console.error('Send failed:', response.status, await response.text());
                }
            });
        });
    </script>
</body>
</html>
