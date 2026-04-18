<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\TextInput::make('name')
                    ->label('ПІБ')
                    ->required()
                    ->maxLength(255),

                \Filament\Forms\Components\TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255),

                \Filament\Forms\Components\TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->dehydrated(fn ($state) => filled($state)) // Зберігає пароль тільки якщо його змінили
                    ->required(fn (string $context): bool => $context === 'create'), // Вимагає пароль тільки при створенні нового користувача

                \Filament\Forms\Components\Select::make('roles')
                    ->label('Роль у системі')
                    ->relationship('roles', 'name')
                    ->multiple() // Дозволяє вибрати кілька ролей
                    ->preload(),

                \Filament\Forms\Components\Select::make('department_id')
                    ->label('Кафедра (Для співробітників)')
                    ->relationship('department', 'name')
                    ->searchable()
                    ->preload(),

                \Filament\Forms\Components\Select::make('group_id')
                    ->label('Група (Для студентів)')
                    ->relationship('group', 'name')
                    ->searchable()
                    ->preload(),
            ]);
    }
}
