<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payments\MonobankJar;
use App\Services\Payments\PayPal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сторінка «Преміум» і пожертви: monobank-банка та PayPal.
 */
class PremiumController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $pending = $user && MonobankJar::enabled()
            ? $user->payments()->where('provider', Payment::MONOBANK)->where('status', Payment::PENDING)->first()
            : null;

        return view('premium', [
            'user' => $user,
            'isPremium' => (bool) $user?->isPremium(),
            'premiumStatus' => $user?->premiumStatusText(),
            'isLifetime' => (bool) $user?->premium_lifetime,
            'isAdmin' => (bool) $user?->hasRole(\App\Models\User::ROLE_ADMIN),
            'price' => Payment::priceUah(Payment::MONTH),
            'lifetimePrice' => Payment::priceUah(Payment::LIFETIME),
            'monobank' => MonobankJar::enabled(),
            'monobankAuto' => MonobankJar::automatic(),
            'paypal' => PayPal::enabled(),
            'paypalPrice' => PayPal::price(Payment::MONTH),
            'paypalLifetimePrice' => PayPal::price(Payment::LIFETIME),
            'pending' => $pending,
            'jarUrl' => $pending ? MonobankJar::jarUrl($pending, Payment::MONTH) : null,
            'jarLifetimeUrl' => $pending ? MonobankJar::jarUrl($pending, Payment::LIFETIME) : null,
        ]);
    }

    /** Видає код для коментаря до переказу в банку */
    public function monobankStart(Request $request)
    {
        abort_unless(MonobankJar::enabled(), 404);

        MonobankJar::pendingFor($request->user());

        return redirect()->route('premium')->withFragment('pay');
    }

    /** «Я переказав — перевірити»: читаємо виписку банки (не частіше разу на хвилину) */
    public function monobankCheck(Request $request)
    {
        abort_unless(MonobankJar::enabled(), 404);
        $user = $request->user();
        $pending = $user->payments()->where('provider', Payment::MONOBANK)->where('status', Payment::PENDING)->first();

        if ($pending && MonobankJar::automatic() && Cache::add('monobank-statement-lock', true, 61)) {
            MonobankJar::syncStatement();
        }

        if ($pending?->fresh()->status === Payment::PAID) {
            return redirect()->route('premium')->with('status', self::thanks($user));
        }

        return redirect()->route('premium')->withFragment('pay')
            ->withErrors(['payment' => 'Пожертву з вашим кодом ще не знайдено. Зачекайте хвилину й перевірте ще раз; переконайтеся, що код вказано в коментарі, а сума — не менше '.Payment::priceUah().' грн.']);
    }

    /**
     * Вебхук особистого API monobank. GET — перевірка адреси під час реєстрації,
     * POST — нова операція. Секрет в адресі, бо monobank не підписує запити.
     */
    public function monobankWebhook(Request $request, string $secret)
    {
        $expected = (string) config('services.monobank.webhook_secret');
        abort_unless($expected !== '' && hash_equals($expected, $secret), 404);

        if ($request->isMethod('post') && $request->input('type') === 'StatementItem') {
            $item = $request->input('data.statementItem');
            if (is_array($item)) {
                MonobankJar::handleStatementItem($request->input('data.account'), $item);
            }
        }

        return response('ok');
    }

    private static function thanks(\App\Models\User $user): string
    {
        return 'Дякуємо за підтримку! Статус «Преміум» діє '.$user->fresh()->premiumStatusText().'.';
    }

    public function paypalStart(Request $request)
    {
        abort_unless(PayPal::enabled(), 404);

        try {
            $plan = $request->input('plan') === Payment::LIFETIME ? Payment::LIFETIME : Payment::MONTH;

            return redirect()->away(PayPal::createOrder($request->user(), $plan));
        } catch (\Throwable $e) {
            Log::error('PayPal: не вдалося створити замовлення', ['error' => $e->getMessage()]);

            return redirect()->route('premium')->withErrors(['payment' => 'Не вдалося з\'єднатися з PayPal. Спробуйте пізніше або скористайтеся monobank.']);
        }
    }

    public function paypalReturn(Request $request)
    {
        abort_unless(PayPal::enabled(), 404);
        $orderId = (string) $request->query('token');

        try {
            $ok = $orderId !== '' && PayPal::capture($request->user(), $orderId);
        } catch (\Throwable $e) {
            Log::error('PayPal: помилка списання', ['order' => $orderId, 'error' => $e->getMessage()]);
            $ok = false;
        }

        return $ok
            ? redirect()->route('premium')->with('status', self::thanks($request->user()))
            : redirect()->route('premium')->withErrors(['payment' => 'Оплату через PayPal не підтверджено. Якщо кошти списано, напишіть адміністратору @Barabolikov у Telegram.']);
    }
}
