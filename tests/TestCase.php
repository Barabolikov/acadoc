<?php

namespace Tests;

use App\Rules\Turnstile;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /** Токен капчі, який тести додають до форм */
    protected const CAPTCHA = [Turnstile::FIELD => 'test-token'];

    protected function setUp(): void
    {
        parent::setUp();

        // Капча Cloudflare Turnstile: у тестах відповідь сервісу підмінено на «успішно»
        config(['services.turnstile.secret_key' => 'test-secret', 'services.turnstile.site_key' => 'test-site']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
    }
}
