<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // A "business" is just an id number here — no businesses table needed
        // for this toy. Both agents and the conversation share business 1.
        $businessId = 1;

        Conversation::create([
            'business_id' => $businessId,
        ]);

        // Two agents in the SAME business so you can open two browser windows
        // and watch a message travel between them. Factory sets password = "password".
        User::factory()->create([
            'name' => 'Agent A',
            'email' => 'agent-a@example.com',
            'business_id' => $businessId,
        ]);

        User::factory()->create([
            'name' => 'Agent B',
            'email' => 'agent-b@example.com',
            'business_id' => $businessId,
        ]);

        // --- Business 2 (Module 4: prove tenant isolation) ---
        // A SECOND tenant with its own conversation and its own agent. Agent C
        // has no business owning conversation 1, so subscribing to it must 403.
        $otherBusinessId = 2;

        Conversation::create([
            'business_id' => $otherBusinessId,
        ]);

        User::factory()->create([
            'name' => 'Agent C',
            'email' => 'agent-c@example.com',
            'business_id' => $otherBusinessId,
        ]);
    }
}
