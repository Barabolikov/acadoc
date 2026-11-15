<?php

namespace App\Console\Commands;

use App\Services\Payments\MonobankJar;
use Illuminate\Console\Command;

/**
 * Налаштування пожертв через monobank: показує банки з особистого API
 * та реєструє вебхук для автоматичного надання «Преміум».
 */
class MonobankSetup extends Command
{
    protected $signature = 'monobank:setup {--webhook : Зареєструвати вебхук на APP_URL}';

    protected $description = 'Показати банки monobank (MONOBANK_JAR_ID) і зареєструвати вебхук';

    public function handle(): int
    {
        if (! config('services.monobank.token')) {
            $this->error('Вкажіть MONOBANK_TOKEN у .env (токен: https://api.monobank.ua/).');

            return self::FAILURE;
        }

        $info = MonobankJar::clientInfo();
        $this->info('Власник: '.($info['name'] ?? '—'));

        $jars = $info['jars'] ?? [];
        if (! $jars) {
            $this->warn('Банок не знайдено. Створіть банку в застосунку monobank і запустіть команду ще раз.');
        } else {
            $this->table(['MONOBANK_JAR_ID', 'Назва', 'Посилання (MONOBANK_JAR_URL)', 'Баланс, грн'], array_map(fn ($jar) => [
                $jar['id'],
                $jar['title'] ?? '',
                'https://send.monobank.ua/'.(str_starts_with($jar['sendId'] ?? '', 'jar/') ? $jar['sendId'] : 'jar/'.($jar['sendId'] ?? '')),
                number_format(($jar['balance'] ?? 0) / 100, 2, ',', ' '),
            ], $jars));
        }

        if ($this->option('webhook')) {
            $secret = config('services.monobank.webhook_secret');
            if (! $secret) {
                $this->error('Вкажіть MONOBANK_WEBHOOK_SECRET у .env (довгий випадковий рядок).');

                return self::FAILURE;
            }
            $url = route('payments.monobank.webhook', ['secret' => $secret]);
            $shown = str_replace($secret, '***', $url);
            $this->line("Адреса вебхука: {$shown}");

            $host = parse_url($url, PHP_URL_HOST);
            if (! str_starts_with($url, 'https://') || in_array($host, ['localhost', '127.0.0.1'], true)) {
                $this->error('APP_URL у .env має бути публічною HTTPS-адресою сайту (напр. https://acadoc.example.ua), а не '.config('app.url').'.');
                $this->line('Після зміни: php artisan config:clear (або config:cache) і запустіть команду ще раз.');

                return self::FAILURE;
            }

            try {
                $check = \Illuminate\Support\Facades\Http::timeout(15)->get($url);
                if (! $check->successful()) {
                    $this->error("Сайт відповідає на адресу вебхука кодом {$check->status()}, а monobank потрібен 200.");
                    $this->line('Перевірте: php artisan route:clear, чи оновлено код на сервері, чи веб-сервер відкриває Laravel (public/) для цього домену.');

                    return self::FAILURE;
                }
            } catch (\Throwable $e) {
                $this->warn('Не вдалося відкрити адресу з самого сервера ('.$e->getMessage().'). Пробуємо зареєструвати все одно…');
            }

            try {
                MonobankJar::setWebhook($url);
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $this->error('monobank відхилив вебхук: '.$e->response->body());
                $this->line('monobank робить GET-запит на цю адресу і чекає 200 протягом 5 с. Відкрийте адресу в браузері (з вашим секретом замість ***) — має з’явитися «ok». Потрібен дійсний SSL-сертифікат.');

                return self::FAILURE;
            }
            $this->info("Вебхук зареєстровано: {$shown}");
        }

        return self::SUCCESS;
    }
}
