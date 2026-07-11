<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    // Render the chat page. Scope the conversation to the caller's business —
    // an agent only ever loads their own tenant's conversation.
    public function chat(Request $request)
    {
        $conversation = Conversation::where('business_id', $request->user()->business_id)
            ->firstOrFail();

        $messages = $conversation->messages()->orderBy('id')->get();

        return view('chat', compact('conversation', 'messages'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'body'            => ['required', 'string', 'max:2000'],
        ]);

        // TENANT CHECK ON THE WRITE PATH. The channel gate (Module 4) only
        // guards LISTENING — nothing here stopped an outsider from POSTing
        // into another business's conversation. So: look the conversation up
        // scoped to the sender's business. A conversation you don't own
        // behaves exactly like one that doesn't exist => 404 (which also
        // avoids leaking which conversation ids are real). Never trust the
        // id the browser sent — the browser lies.
        $conversation = Conversation::where('id', $validated['conversation_id'])
            ->where('business_id', $request->user()->business_id)
            ->firstOrFail();

        // 1) SAVE FIRST. The message must exist as a fact before anyone is told.
        //    (If we broadcast first and the save failed, listeners would see a
        //    message that doesn't exist in the DB.)
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id'         => $request->user()->id,
            'body'            => $validated['body'],
        ]);

        // 2) THEN broadcast. ->toOthers() skips the SENDER's own socket, so their
        //    window isn't double-updated (they append their own message locally).
        broadcast(new MessageSent($message))->toOthers();

        return response()->json([
            'id'      => $message->id,
            'body'    => $message->body,
            'user_id' => $message->user_id,
        ]);
    }
}
