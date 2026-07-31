<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Register');
    }

    /**
     * Handle an incoming registration request.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'] ?? explode('@', $validated['email'])[0],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        // `user_type` is intentionally not mass-assignable (see User::$fillable) —
        // set explicitly here from the validated, allow-listed value. Admin can
        // never reach this: RegisterRequest restricts user_type to
        // UserType::registrableValues(), which excludes Admin.
        $user->user_type = UserType::from($validated['user_type']);
        $user->save();

        event(new Registered($user));

        Auth::login($user);

        return to_route('dashboard');
    }
}
