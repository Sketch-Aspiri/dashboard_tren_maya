<?php

namespace App\Http\Requests\Auth;

use App\Services\TwoFactorAuthenticationService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates a one-time password submitted against either a pending
 * enrollment secret (setup) or the user's confirmed secret (challenge).
 *
 * Mirrors the rate-limiting pattern already used by Breeze's LoginRequest
 * for this project's auth-adjacent forms.
 */
class TwoFactorCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'one_time_password' => ['required', 'string', 'size:6'],
        ];
    }

    /**
     * Verify the submitted code against the given secret, applying rate
     * limiting per user/IP.
     *
     * @throws ValidationException
     */
    public function verifyAgainstSecret(TwoFactorAuthenticationService $service, string $secret): void
    {
        $this->ensureIsNotRateLimited();

        if (! $service->verify($secret, $this->string('one_time_password')->value())) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'one_time_password' => __('The verification code is incorrect.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'one_time_password' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->user()?->email).'|two-factor|'.$this->ip());
    }
}
