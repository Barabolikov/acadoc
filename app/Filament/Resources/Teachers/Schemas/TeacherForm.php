<?php

namespace App\Filament\Resources\Teachers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TeacherForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('models.PIB'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label(__('models.email'))
                    ->email()
                    ->required()
                    ->maxLength(255),

                TextInput::make('password')
                    ->label(__('models.password') ?? 'Пароль')
                    ->password()
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create'),

                Select::make('department_id')
                    ->label(__('models.department_id'))
                    ->relationship('department', 'name')
                    ->searchable()
                    ->preload(),

                Select::make('group_id')
                    ->label(__('models.group_id'))
                    ->relationship('group', 'name')
                    ->searchable()
                    ->preload(),
            ]);
    }
}
