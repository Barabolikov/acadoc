<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('ПІБ')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255),

                TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->dehydrated(fn ($state) => filled($state)) // Зберігає пароль тільки якщо його змінили
                    ->required(fn (string $context): bool => $context === 'create'), // Вимагає пароль тільки при створенні нового користувача

                Select::make('roles')
                    ->label('Роль у системі')
                    ->relationship('roles', 'name')
                    ->multiple() // Дозволяє вибрати кілька ролей
                    ->preload()
                    // Ролі змінює лише адміністратор (інакше будь-хто з правом Update:User міг би підвищити собі роль)
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::ROLE_ADMIN))
                    ->helperText(fn (?User $record): ?string => $record?->requested_role === 'teacher' && ! $record->hasRole(User::ROLE_TEACHER)
                        ? 'Під час реєстрації користувач обрав «Викладач» — заявка очікує підтвердження.'
                        : null),

                Select::make('department_id')
                    ->label('Кафедра (Для співробітників)')
                    ->relationship('department', 'name')
                    ->searchable()
                    ->preload(),

                Select::make('group_id')
                    ->label('Група (Для студентів)')
                    ->relationship('group', 'name')
                    ->searchable()
                    ->preload(),
            ]);
    }
}
