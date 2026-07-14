<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// ShouldBroadcast (NOT ShouldBroadcastNow) => the broadcast is QUEUED.
// The HTTP response returns immediately; a worker does the Reverb push later.
// That is the whole point of Module 5: the job now rides a REDIS queue, drained
// by Horizon (php artisan horizon), not the old `database` queue + queue:work.
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    // WHERE to shout it. Private = the bouncer in routes/channels.php must
    // approve every listener before they receive anything.
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('conversation.'.$this->message->conversation_id);
    }

    // WHAT the browser receives. We hand-pick the payload instead of dumping the
    // whole model — never broadcast columns the client shouldn't see.
    public function broadcastWith(): array
    {
        return [
            'id'      => $this->message->id,
            'body'    => $this->message->body,
            'user_id' => $this->message->user_id,
        ];
    }
}
