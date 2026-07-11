<?php

use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Send a message. auth => must be logged in (login-as handles that in dev).
// Lives in the web group, so it's CSRF-protected — the frontend (Module 3)
// will send the CSRF token with its fetch() call.
Route::post('/messages', [MessageController::class, 'store'])->middleware('auth');

// The chat page itself. Loads existing messages for the caller's conversation
// and renders the Blade view that talks to Echo.
Route::get('/chat', [MessageController::class, 'chat'])->middleware('auth');

// Dev-only backdoor login: no password screen, just log in as a user by id.
// Private broadcast channels need an authenticated $user, so we need SOME way
// to be logged in — this is the throwaway shortcut. Guarded to local so it can
// never be a login-bypass hole in production.
Route::get('/login-as/{id}', function (int $id) {
    abort_unless(app()->environment('local'), 403);

    Auth::loginUsingId($id);

    return redirect('/chat');
});
