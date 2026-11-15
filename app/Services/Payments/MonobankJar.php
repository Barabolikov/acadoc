<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Пожертви через банку monobank і особистий API monobank.
 *
 * Користувач отримує 6-значний код і вказує його в коментарі до переказу в банку.
 * Тариф визначає сума: від 10 грн — місяць «Преміум», від 100 грн — назавжди.
 * monobank надсилає кожну операцію банки на вебхук (або ми самі читаємо виписку банки),
 * ми знаходимо код у коментарі й надаємо «Преміум».
 */
class MonobankJar
{
    private const API = 'https://api.monobank.ua';

    /** Код гривні за ISO 4217 */
    private const UAH = 980;

    public static function enabled(): bool
    {
        return Payment::paymentsEnabled() && filled(config('services.monobank.jar_url'));
    }

    /** Чи налаштовано автоматичне зарахування (токен і банка) */
    public static function automatic(): bool
    {
        return filled(config('services.monobank.token')) && filled(config('services.monobank.jar_id'));
    }

    /** Посилання на банку з підставленими сумою тарифу й коментарем (якщо monobank їх підхопить) */
    public static function jarUrl(Payment $payment, string $plan = Payment::MONTH): string
    {
        $url = config('services.monobank.jar_url');

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['a' => Payment::priceUah($plan), 't' => $payment->code]);
    }

    /** Незавершена пожертва користувача з кодом (створюється, якщо її ще немає) */
    public static function pendingFor(User $user): Payment
    {
        return DB::transaction(function () use ($user) {
            $existing = $user->payments()->where('provider', Payment::MONOBANK)->where('status', Payment::PENDING)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            do {
                $code = (string) random_int(100000, 999999);
            } while (Payment::where('provider', Payment::MONOBANK)->where('code', $code)->exists());

            return $user->payments()->create(['provider' => Payment::MONOBANK, 'code' => $code, 'status' => Payment::PENDING]);
        });
    }

    /**
     * Обробка однієї операції з виписки (вебхук або перевірка вручну).
     *
     * @return Payment|null зарахована пожертва
     */
    public static function handleStatementItem(?string $account, array $item): ?Payment
    {
        $jarId = config('services.monobank.jar_id');
        if ($jarId && $account !== null && $account !== $jarId) {
            return null; // операція з іншого рахунку, не з банки
        }

        $amount = (int) ($item['amount'] ?? 0);
        $currency = (int) ($item['currencyCode'] ?? self::UAH);
        // Тариф визначає сума: від 100 грн — назавжди, від 10 грн — місяць
        $plan = Payment::planForUah($amount);
        if ($plan === null || $currency !== self::UAH || empty($item['id'])) {
            return null;
        }

        $text = ($item['comment'] ?? '').' '.($item['description'] ?? '');
        if (! preg_match_all('/(?<!\d)(\d{6})(?!\d)/', $text, $m)) {
            return null;
        }

        $payment = Payment::where('provider', Payment::MONOBANK)->where('status', Payment::PENDING)->whereIn('code', $m[1])->first();
        if (! $payment || Payment::where('provider', Payment::MONOBANK)->where('external_id', $item['id'])->exists()) {
            return null;
        }

        $payment->markPaid($item['id'], $amount, 'UAH', ['comment' => mb_substr($text, 0, 255)], $plan);
        Log::info('monobank: зараховано пожертву за «Преміум»', ['payment' => $payment->id, 'user' => $payment->user_id]);

        return $payment;
    }

    /**
     * Перевірка виписки банки за останні 3 дні (якщо вебхук не дійшов).
     * monobank дозволяє 1 запит виписки на 60 секунд.
     *
     * @return int|null скільки пожертв зараховано; null — запит не вдався
     */
    public static function syncStatement(): ?int
    {
        if (! self::automatic()) {
            return null;
        }

        try {
            $response = Http::withHeaders(['X-Token' => config('services.monobank.token')])->timeout(15)
                ->get(self::API.'/personal/statement/'.config('services.monobank.jar_id').'/'.now()->subDays(3)->getTimestamp());
        } catch (\Throwable $e) {
            Log::warning('monobank: виписка недоступна', ['error' => $e->getMessage()]);

            return null;
        }
        if (! $response->ok() || ! is_array($response->json())) {
            Log::warning('monobank: помилка виписки', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

            return null;
        }

        $count = 0;
        foreach ($response->json() as $item) {
            if (is_array($item) && self::handleStatementItem(null, $item)) {
                $count++;
            }
        }

        return $count;
    }

    /** Дані клієнта: рахунки й банки (для налаштування) */
    public static function clientInfo(): array
    {
        return Http::withHeaders(['X-Token' => config('services.monobank.token')])->timeout(15)
            ->get(self::API.'/personal/client-info')->throw()->json();
    }

    /** Реєструє адресу вебхука в monobank */
    public static function setWebhook(string $url): void
    {
        Http::withHeaders(['X-Token' => config('services.monobank.token')])->timeout(30)
            ->post(self::API.'/personal/webhook', ['webHookUrl' => $url])->throw();
    }
}
