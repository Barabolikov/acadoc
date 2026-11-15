<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PremiumTest extends TestCase
{
    use RefreshDatabase;

    private const JAR = 'jar-account-id';

    private function student(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate(User::ROLE_STUDENT, 'web'));

        return $user;
    }

    private function enableMonobank(): void
    {
        config(['services.premium.payments_enabled' => true]);
        config(['services.monobank' => [
            'token' => 'mono-token', 'jar_id' => self::JAR,
            'jar_url' => 'https://send.monobank.ua/jar/TEST', 'webhook_secret' => 'hook-secret',
        ]]);
    }

    private function enablePayPal(): void
    {
        config(['services.premium.payments_enabled' => true]);
        config(['services.paypal' => ['mode' => 'sandbox', 'client_id' => 'id', 'secret' => 'secret', 'amount' => '1.00', 'currency' => 'USD']]);
    }

    private function webhook(string $code, int $amount = 1000, string $account = self::JAR, string $id = 'op-1')
    {
        return $this->postJson(route('payments.monobank.webhook', ['secret' => 'hook-secret']), [
            'type' => 'StatementItem',
            'data' => ['account' => $account, 'statementItem' => [
                'id' => $id, 'time' => time(), 'description' => 'Від: Студент', 'comment' => "Код {$code}, дякую!",
                'amount' => $amount, 'currencyCode' => 980,
            ]],
        ]);
    }

    public function test_home_page_has_premium_block(): void
    {
        config(['services.premium.payments_enabled' => true]);
        $this->get('/')->assertOk()
            ->assertSee('Символічна пожертва на розвиток проєкту')->assertSee('100 <span class="text-xl">грн</span>', false)
            ->assertSee('оплату хостингу та домену', false)
            ->assertSee(route('premium'));
    }

    public function test_premium_page_for_guest_and_without_payment_settings(): void
    {
        config(['services.monobank.jar_url' => null, 'services.paypal.client_id' => null]);

        $this->get(route('premium'))->assertOk()->assertSee('Увійдіть, щоб отримати «Преміум»');
        $this->actingAs($this->student())->get(route('premium'))->assertSee('Статус «Преміум» надається особисто адміністратором ресурсу');
    }

    public function test_payments_switched_off_hide_providers_and_show_telegram(): void
    {
        $this->enableMonobank();
        $this->enablePayPal();
        config(['services.premium.payments_enabled' => false]);
        $user = $this->student();

        $this->actingAs($user)->get(route('premium'))->assertOk()
            ->assertSee('Статус «Преміум» надається особисто адміністратором ресурсу')->assertSee('https://t.me/Barabolikov')->assertSee($user->email)
            ->assertDontSee('Отримати код і підтримати')->assertDontSee('грн');
        $this->actingAs($user)->post(route('premium.monobank'))->assertNotFound();
        $this->actingAs($user)->post(route('premium.paypal'), ['plan' => 'month'])->assertNotFound();
        foreach (['/', route('rules'), route('premium')] as $url) {
            $this->get($url)->assertOk()->assertSee('надається особисто адміністратором ресурсу')
                ->assertDontSee('monobank')->assertDontSee('PayPal')->assertDontSee('пожертв');
        }
    }

    public function test_monobank_webhook_grants_premium_by_code(): void
    {
        $this->enableMonobank();
        $user = $this->student();

        $this->actingAs($user)->post(route('premium.monobank'))->assertRedirect();
        $payment = $user->payments()->sole();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $payment->code);
        $this->get(route('premium'))->assertSee($payment->code)->assertSee('send.monobank.ua/jar/TEST?a=10&amp;t='.$payment->code, false);

        // Інша банка, замала сума — не зараховуємо
        $this->webhook($payment->code, 1000, 'other-account')->assertOk();
        $this->webhook($payment->code, 500)->assertOk();
        $this->assertFalse($user->fresh()->isPremium());

        $this->webhook($payment->code)->assertOk();
        $this->webhook($payment->code)->assertOk(); // повтор вебхука

        $this->assertTrue($user->fresh()->isPremium());
        $this->assertFalse($user->fresh()->hasDailyCheckLimit());
        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->assertSame(1000, $payment->fresh()->amount);
    }

    public function test_monobank_webhook_rejects_wrong_secret_and_answers_verification_get(): void
    {
        $this->enableMonobank();

        $this->get(route('payments.monobank.webhook', ['secret' => 'hook-secret']))->assertOk();
        $this->postJson(route('payments.monobank.webhook', ['secret' => 'wrong']), [])->assertNotFound();
    }

    public function test_manual_check_reads_jar_statement(): void
    {
        $this->enableMonobank();
        $user = $this->student();
        $this->actingAs($user)->post(route('premium.monobank'));
        $code = $user->payments()->sole()->code;

        Http::fake(['api.monobank.ua/personal/statement/*' => Http::response([
            ['id' => 'op-9', 'time' => time(), 'description' => 'Поповнення', 'comment' => $code, 'amount' => 1500, 'currencyCode' => 980],
        ])]);

        $this->post(route('premium.monobank.check'))->assertRedirect(route('premium'))->assertSessionHas('status');
        $this->assertTrue($user->fresh()->isPremium());
    }

    public function test_paypal_checkout_grants_premium(): void
    {
        $this->enablePayPal();
        $user = $this->student();
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            '*/v2/checkout/orders' => Http::response(['id' => 'ORDER1', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/approve']]]),
            '*/v2/checkout/orders/ORDER1/capture' => Http::response(['status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [
                ['id' => 'CAP1', 'status' => 'COMPLETED', 'amount' => ['value' => '1.00', 'currency_code' => 'USD']],
            ]]]]]),
        ]);

        $this->actingAs($user)->post(route('premium.paypal'))->assertRedirect('https://paypal.test/approve');
        $this->get(route('premium.paypal.return', ['token' => 'ORDER1']))->assertRedirect(route('premium'))->assertSessionHas('status');

        $this->assertTrue($user->fresh()->isPremium());
        $this->assertSame(100, $user->payments()->sole()->amount);
    }

    public function test_paypal_underpayment_and_foreign_order_are_rejected(): void
    {
        $this->enablePayPal();
        $user = $this->student();
        $user->payments()->create(['provider' => Payment::PAYPAL, 'external_id' => 'ORDER2']);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            '*/capture' => Http::response(['status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [
                ['id' => 'CAP2', 'status' => 'COMPLETED', 'amount' => ['value' => '0.10', 'currency_code' => 'USD']],
            ]]]]]),
        ]);

        $this->actingAs($user)->get(route('premium.paypal.return', ['token' => 'ORDER2']))->assertSessionHasErrors('payment');
        $this->actingAs($this->student())->get(route('premium.paypal.return', ['token' => 'ORDER2']))->assertSessionHasErrors('payment');
        $this->assertFalse($user->fresh()->isPremium());
    }

    public function test_premium_lasts_one_month_and_donation_extends_it(): void
    {
        $this->enableMonobank();
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
        $user = $this->student();

        $this->actingAs($user)->post(route('premium.monobank'));
        $this->webhook($user->payments()->sole()->code, 1000, self::JAR, 'op-a');
        $this->assertSame('2026-12-15', $user->fresh()->premium_until->toDateString());

        // Повторна пожертва, поки «Преміум» діє, — ще місяць від кінця періоду
        $this->post(route('premium.monobank'));
        $second = $user->payments()->where('status', Payment::PENDING)->sole();
        $this->actingAs($user->fresh())->get(route('premium'))->assertSee('Статус «Преміум» діє до 15.12.2026')->assertSee('Продовжити «Преміум» або оформити назавжди');
        $this->webhook($second->code, 1000, self::JAR, 'op-b');
        $this->assertSame('2027-01-15', $user->fresh()->premium_until->toDateString());

        // Строк минув — знову студент з лімітом
        $this->travelTo(now()->setDate(2027, 1, 16));
        $this->assertFalse($user->fresh()->isPremium());
        $this->assertTrue($user->fresh()->hasDailyCheckLimit());

        // Нова пожертва після закінчення — місяць від сьогодні
        $this->post(route('premium.monobank'));
        $this->webhook($user->payments()->where('status', Payment::PENDING)->sole()->code, 1000, self::JAR, 'op-c');
        $this->assertSame('2027-02-16', $user->fresh()->premium_until->toDateString());
    }

    public function test_100_uah_gives_lifetime_premium(): void
    {
        $this->enableMonobank();
        $user = $this->student();

        $this->actingAs($user)->post(route('premium.monobank'));
        $code = $user->payments()->sole()->code;
        $this->get(route('premium'))->assertSee('send.monobank.ua/jar/TEST?a=100&amp;t='.$code, false);

        $this->webhook($code, 10000);

        $user->refresh();
        $this->assertTrue($user->premium_lifetime);
        $this->assertSame(Payment::LIFETIME, $user->payments()->sole()->plan);
        $this->travelTo(now()->addYears(5));
        $this->assertTrue($user->fresh()->isPremium());
        $this->actingAs($user->fresh())->get(route('premium'))->assertSee('Статус «Преміум» діє назавжди')->assertDontSee('Оберіть спосіб пожертви');
    }

    public function test_paypal_lifetime_plan_requires_lifetime_amount(): void
    {
        $this->enablePayPal();
        config(['services.paypal.lifetime_amount' => '5.00']);
        $user = $this->student();
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            '*/v2/checkout/orders' => Http::response(['id' => 'ORDER3', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/approve']]]),
            '*/capture' => Http::response(['status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [
                ['id' => 'CAP3', 'status' => 'COMPLETED', 'amount' => ['value' => '1.00', 'currency_code' => 'USD']],
            ]]]]]),
        ]);

        $this->actingAs($user)->post(route('premium.paypal'), ['plan' => 'lifetime'])->assertRedirect('https://paypal.test/approve');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/checkout/orders')
            && $request['purchase_units'][0]['amount']['value'] === '5.00');

        // Сплачено лише 1.00 замість 5.00 — «Преміум» назавжди не надається
        $this->get(route('premium.paypal.return', ['token' => 'ORDER3']))->assertSessionHasErrors('payment');
        $this->assertFalse($user->fresh()->isPremium());
    }

    public function test_premium_can_upload_up_to_20_mb(): void
    {
        $big = fn () => UploadedFile::fake()->create('robota.docx', 8 * 1024, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->actingAs($this->student())
            ->post(route('norm-control.process'), ['document' => $big(), ...self::CAPTCHA])
            ->assertSessionHasErrors(['document' => 'Розмір файлу не повинен перевищувати 5 МБ. Стисніть рисунки в документі (Файл → Стиснути зображення) і спробуйте ще раз.']);

        $premium = $this->student();
        $premium->extendPremium();
        $errors = $this->actingAs($premium)
            ->post(route('norm-control.process'), ['document' => $big(), ...self::CAPTCHA])
            ->baseResponse->getSession()->get('errors')?->get('document') ?? [];
        foreach ($errors as $error) {
            $this->assertStringNotContainsString('не повинен перевищувати', $error);
        }

        $this->actingAs($premium)->get(route('norm-control.index'))->assertSee('Максимальний розмір файлу — 20 МБ');
    }
}
