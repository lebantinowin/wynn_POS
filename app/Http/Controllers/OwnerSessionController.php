<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class OwnerSessionController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if ((int) Auth::id() === User::OWNER_ID) {
            return redirect()->route('workspace', ['section' => 'dashboard']);
        }

        return view('auth.login', ['ownerConfigured' => User::query()->whereKey(User::OWNER_ID)->exists()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $emailInput = $request->input('email');
        $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
        $request->merge(['email' => $email]);
        $ipKey = 'owner-login-ip:'.hash('sha256', (string) $request->ip());
        $accountKey = 'owner-login:'.hash('sha256', $email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($ipKey, 30) || RateLimiter::tooManyAttempts($accountKey, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many sign-in attempts. Please wait one minute and try again.'])->status(429);
        }
        RateLimiter::hit($ipKey, 60);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
        ]);
        $credentials['id'] = User::OWNER_ID;

        if (! Auth::attempt($credentials)) {
            RateLimiter::hit($accountKey, 60);
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        RateLimiter::clear($accountKey);
        $request->session()->regenerate();

        return redirect()->intended(route('workspace', ['section' => 'dashboard']));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
