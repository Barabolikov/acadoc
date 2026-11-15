<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Перевірка капчі Cloudflare Turnstile на сервері.
 * Віджет додає до форми приховане поле «cf-turnstile-response» з токеном.
 */
class Turnstile implements ValidationRule
{
    public const FIELD = 'cf-turnstile-response';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secret = config('services.turnstile.secret_key');
        if (! $secret) {
            Log::error('Turnstile: не задано TURNSTILE_SECRET_KEY у .env');
            $fail('Перевірку «Я не робот» не налаштовано. Зверніться до адміністратора.');

            return;
        }

        if (! is_string($value) || $value === '') {
            $fail('Підтвердіть, що ви не робот.');

            return;
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::VERIFY_URL, [
                'secret' => $secret,
                'response' => $value,
                'remoteip' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Turnstile: сервіс перевірки недоступний', ['error' => $e->getMessage()]);
            $fail('Не вдалося перевірити «Я не робот». Спробуйте ще раз.');

            return;
        }

        if ($response->json('success') !== true) {
            $fail('Перевірку «Я не робот» не пройдено. Спробуйте ще раз.');
        }
    }
}
