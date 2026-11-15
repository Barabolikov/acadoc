<?php

namespace App\Filament\Auth;

use App\Rules\Turnstile;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Schema;

/**
 * Вхід в адмін-панель із капчею Cloudflare Turnstile.
 */
class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
                ViewField::make('captcha')
                    ->view('filament.turnstile')
                    ->hiddenLabel()
                    ->required()
                    ->rules([new Turnstile])
                    ->validationMessages(['required' => 'Підтвердіть, що ви не робот.']),
            ]);
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            return parent::authenticate();
        } finally {
            // Токен капчі одноразовий: після невдалої спроби скидаємо віджет, щоб отримати новий
            if (! Filament::auth()->check()) {
                $this->data['captcha'] = null;
                $this->dispatch('turnstile-reset');
            }
        }
    }
}
