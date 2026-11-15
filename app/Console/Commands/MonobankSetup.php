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
            MonobankJar::setWebhook($url);
            $this->info("Вебхук зареєстровано: {$url}");
        }

        return self::SUCCESS;
    }
}
