<?php

namespace App\Filament\Resources\Groups\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class GroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Select::make('department_id')
                    ->label('Відділення / Кафедра')
                    ->relationship('department', 'name')
                    ->required()
                    ->searchable() // Додає зручний пошук всередині списку
                    ->preload(),   // Одразу підвантажує список при відкритті сторінки
            ]);
    }
}
