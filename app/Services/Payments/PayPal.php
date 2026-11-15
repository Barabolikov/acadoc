<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayPal Checkout (Orders API v2): створення замовлення → оплата на сайті PayPal →
 * повернення на /premium/paypal/return → списання (capture) і надання «Преміум».
 */
class PayPal
{
    public static function enabled(): bool
    {
        return filled(config('services.paypal.client_id')) && filled(config('services.paypal.secret'));
    }

    private static function api(): string
    {
        return config('services.paypal.mode') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private static function token(): string
    {
        return Http::asForm()->timeout(20)
            ->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.secret'))
            ->post(self::api().'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->throw()->json('access_token');
    }

    /** Сума тарифу в PayPal: «1.00» */
    public static function amount(string $plan): string
    {
        return (string) config($plan === Payment::LIFETIME ? 'services.paypal.lifetime_amount' : 'services.paypal.amount');
    }

    /** Сума, як її показуємо: «1.00 USD» */
    public static function price(string $plan = Payment::MONTH): string
    {
        return self::amount($plan).' '.config('services.paypal.currency');
    }

    /**
     * Створює замовлення і повертає посилання, куди перенаправити користувача для оплати.
     */
    public static function createOrder(User $user, string $plan = Payment::MONTH): string
    {
        $payment = $user->payments()->create(['provider' => Payment::PAYPAL, 'plan' => $plan, 'status' => Payment::PENDING]);

        $order = Http::withToken(self::token())->timeout(20)
            ->post(self::api().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => 'premium',
                    'custom_id' => (string) $payment->id,
                    'description' => 'Acadoc: пожертва на розвиток проєкту («Преміум» '.Payment::planLabel($plan).')',
                    'amount' => ['currency_code' => config('services.paypal.currency'), 'value' => self::amount($plan)],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => 'Acadoc',
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                    'return_url' => route('premium.paypal.return'),
                    'cancel_url' => route('premium'),
                ]]],
            ])->throw()->json();

        $payment->update(['external_id' => $order['id']]);

        foreach ($order['links'] ?? [] as $link) {
            if (in_array($link['rel'], ['payer-action', 'approve'], true)) {
                return $link['href'];
            }
        }

        throw new \RuntimeException('PayPal не повернув посилання на оплату');
    }

    /**
     * Списує кошти за замовленням після повернення користувача з PayPal.
     *
     * @return bool true — оплата пройшла й «Преміум» надано
     */
    public static function capture(User $user, string $orderId): bool
    {
        $payment = $user->payments()->where('provider', Payment::PAYPAL)->where('external_id', $orderId)->first();
        if (! $payment) {
            return false;
        }
        if ($payment->status === Payment::PAID) {
            return true;
        }

        $response = Http::withToken(self::token())->timeout(30)
            ->withBody('{}', 'application/json')
            ->post(self::api()."/v2/checkout/orders/{$orderId}/capture");

        $data = $response->json() ?? [];
        // Замовлення вже списане раніше (повторне повернення) — беремо його стан
        if ($response->status() === 422 && str_contains($response->body(), 'ORDER_ALREADY_CAPTURED')) {
            $data = Http::withToken(self::token())->timeout(20)->get(self::api()."/v2/checkout/orders/{$orderId}")->json() ?? [];
        }

        $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? null;
        $value = $capture['amount']['value'] ?? '0';
        $currency = $capture['amount']['currency_code'] ?? '';
        if (($data['status'] ?? null) !== 'COMPLETED' || ($capture['status'] ?? null) !== 'COMPLETED'
            || $currency !== config('services.paypal.currency')
            || round((float) $value * 100) < round((float) self::amount($payment->plan) * 100)) {
            Log::warning('PayPal: оплату не підтверджено', ['order' => $orderId, 'status' => $data['status'] ?? $response->status()]);

            return false;
        }

        $payment->markPaid($orderId, (int) round(((float) $value) * 100), $currency, ['capture_id' => $capture['id'] ?? null]);

        return true;
    }
}
