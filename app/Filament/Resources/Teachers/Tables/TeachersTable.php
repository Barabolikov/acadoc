<?php

namespace App\Filament\Resources\Teachers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TeachersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('models.PIB'))
                    ->searchable(),

                TextColumn::make('email')
                    ->label('Email adress')
                    ->searchable(),

                TextColumn::make('department.name')
                    ->label(__('models.department_id'))
                    ->sortable()
                    ->placeholder('Не вказано'),

                // ДОДАНО КОЛОНКУ "ГРУПА"
                TextColumn::make('group.name')
                    ->label('Група')
                    ->sortable()
                    ->placeholder('Не вказано'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
