<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// THE TENANT GATE. Runs on every /broadcasting/auth call — i.e. every time a
// browser tries to subscribe to conversation.{id}. Returning true lets them on
// the channel; false => 403, they never hear a single message.
//
// The rule: you may listen ONLY if your business owns this conversation.
// This one comparison is the entire multi-tenant wall (see Module 4's live 403).
Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conversation = Conversation::find($id);

    // Defensive: unknown conversation => deny, don't crash on null->business_id.
    return $conversation !== null
        && (int) $user->business_id === (int) $conversation->business_id;
});
