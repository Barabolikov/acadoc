<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Пожертва за статус «Преміум».
 */
class Payment extends Model
{
    public const MONOBANK = 'monobank';

    public const PAYPAL = 'paypal';

    public const PENDING = 'pending';

    public const PAID = 'paid';

    /** Тарифи: місяць або назавжди */
    public const MONTH = 'month';

    public const LIFETIME = 'lifetime';

    protected $fillable = ['provider', 'plan', 'code', 'external_id', 'amount', 'currency', 'status', 'paid_at', 'meta'];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Сума пожертви за «Преміум» у гривнях: місяць (за замовчуванням) або назавжди */
    public static function priceUah(string $plan = self::MONTH): int
    {
        return $plan === self::LIFETIME
            ? (int) config('services.premium.lifetime_price_uah', 100)
            : (int) config('services.premium.price_uah', 10);
    }

    /** Тариф за сумою переказу в гривні (копійки); null — сума замала */
    public static function planForUah(int $kopecks): ?string
    {
        return match (true) {
            $kopecks >= self::priceUah(self::LIFETIME) * 100 => self::LIFETIME,
            $kopecks >= self::priceUah(self::MONTH) * 100 => self::MONTH,
            default => null,
        };
    }

    public static function planLabel(string $plan): string
    {
        return $plan === self::LIFETIME ? 'назавжди' : 'на місяць';
    }

    /** Сума для відображення: «10,00 UAH» */
    public function formattedAmount(): string
    {
        return $this->amount === null ? '—' : number_format($this->amount / 100, 2, ',', ' ').' '.$this->currency;
    }

    /**
     * Позначає пожертву оплаченою й надає «Преміум»: місяць (продовжує чинний) або назавжди.
     * Повторний виклик (повторний вебхук) нічого не змінює.
     *
     * @return bool true — якщо саме цей виклик зарахував оплату
     */
    public function markPaid(?string $externalId, int $amount, string $currency, array $meta = [], ?string $plan = null): bool
    {
        return DB::transaction(function () use ($externalId, $amount, $currency, $meta, $plan) {
            $payment = self::whereKey($this->id)->lockForUpdate()->first();
            if ($payment->status === self::PAID) {
                return false;
            }

            $payment->update([
                'external_id' => $externalId ?? $payment->external_id,
                'amount' => $amount,
                'currency' => $currency,
                'status' => self::PAID,
                'plan' => $plan ?? $payment->plan,
                'paid_at' => now(),
                'meta' => array_merge($payment->meta ?? [], $meta),
            ]);
            $user = $payment->user()->lockForUpdate()->first();
            $payment->plan === self::LIFETIME ? $user->grantLifetimePremium() : $user->extendPremium();
            $this->refresh();

            return true;
        });
    }
}
