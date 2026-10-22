<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * GitHub OAuth is the only way into the dashboard.
 */
class GithubLoginController extends Controller
{
    /**
     * Send the browser to GitHub to authorize the dashboard.
     */
    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver('github')->with(['allow_signup' => 'false'])->redirect();
    }

    /**
     * Sign in the GitHub account, but only when it is allow-listed.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return $this->deny('GitHub sign-in was cancelled.');
        }

        try {
            $account = Socialite::driver('github')->user();
        } catch (InvalidStateException|ClientException) {
            return $this->deny('GitHub sign-in expired. Please try again.');
        }

        $allowedLogins = array_map(strtolower(...), config('services.github.allowed_logins'));

        if (! in_array(strtolower((string) $account->getNickname()), $allowedLogins, true)) {
            return $this->deny('This GitHub account is not allowed to use the dashboard.');
        }

        // Name and email are only taken on first sign-in, so the alert address set in settings sticks.
        $user = User::firstOrCreate(['github_id' => $account->getId()], [
            'name' => $account->getName() ?? $account->getNickname(),
            'email' => $account->getEmail() ?? "{$account->getId()}+{$account->getNickname()}@users.noreply.github.com",
        ]);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Sign out and invalidate the session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function deny(string $message): RedirectResponse
    {
        return to_route('login')->withErrors(['github' => $message]);
    }
}
