<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    // $fillable = which columns you're allowed to mass-assign via create([...]).
    // Guards against a request setting columns you didn't intend to expose.
    protected $fillable = ['business_id'];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
