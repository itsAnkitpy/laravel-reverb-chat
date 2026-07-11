<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        // --- Module 5 demo ONLY (remove after the lesson) ---
        // This method runs inside the QUEUE WORKER (Horizon), not the HTTP
        // request. The log line + 3s sleep make the async gap physical: the
        // POST /messages response is already back in your browser while this
        // job is still sleeping here; the message reaches the other window ~3s
        // later, the moment this returns and the Reverb push fires.
        Log::info('[Module 5] worker START broadcasting message #'.$this->message->id.' at '.now()->format('H:i:s.v'));
        sleep(3);
        Log::info('[Module 5] worker DONE  broadcasting message #'.$this->message->id.' at '.now()->format('H:i:s.v'));
        // --- end demo ---

        return [
            'id'      => $this->message->id,
            'body'    => $this->message->body,
            'user_id' => $this->message->user_id,
        ];
    }
}
