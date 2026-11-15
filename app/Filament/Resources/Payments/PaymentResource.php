<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** Пожертви за «Преміум» — лише перегляд і ручне підтвердження */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $modelLabel = 'пожертва';

    protected static ?string $pluralModelLabel = 'Пожертви';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
        ];
    }
}
