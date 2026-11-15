<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Створено')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('user.name')->label('Користувач')->searchable()
                    ->description(fn (Payment $record): ?string => $record->user?->email),
                TextColumn::make('provider')->label('Спосіб')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Payment::MONOBANK ? 'monobank' : 'PayPal')
                    ->color(fn (string $state): string => $state === Payment::MONOBANK ? 'gray' : 'info'),
                TextColumn::make('plan')->label('Тариф')->badge()
                    ->formatStateUsing(fn (string $state): string => Payment::planLabel($state))
                    ->color(fn (string $state): string => $state === Payment::LIFETIME ? 'success' : 'warning'),
                TextColumn::make('code')->label('Код')->searchable()->placeholder('—'),
                TextColumn::make('amount')->label('Сума')
                    ->state(fn (Payment $record): string => $record->formattedAmount()),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Payment::PAID ? 'Оплачено' : 'Очікує')
                    ->color(fn (string $state): string => $state === Payment::PAID ? 'success' : 'warning'),
                TextColumn::make('paid_at')->label('Оплачено')->dateTime('d.m.Y H:i')->sortable()->placeholder('—'),
                TextColumn::make('external_id')->label('ID операції')->toggleable(isToggledHiddenByDefault: true)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options([Payment::PAID => 'Оплачено', Payment::PENDING => 'Очікує']),
                SelectFilter::make('plan')->label('Тариф')->options([Payment::MONTH => 'на місяць', Payment::LIFETIME => 'назавжди']),
                SelectFilter::make('provider')->label('Спосіб')->options([Payment::MONOBANK => 'monobank', Payment::PAYPAL => 'PayPal']),
            ])
            ->recordActions([
                // Якщо переказ надійшов без коду чи з помилкою в коді — адміністратор зараховує вручну
                Action::make('confirm')
                    ->label('Зарахувати вручну')
                    ->schema([
                        \Filament\Forms\Components\Radio::make('plan')->label('Тариф')->required()->default(Payment::MONTH)
                            ->options([Payment::MONTH => 'на місяць ('.Payment::priceUah().' грн)', Payment::LIFETIME => 'назавжди ('.Payment::priceUah(Payment::LIFETIME).' грн)']),
                    ])
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Payment $record): bool => $record->status === Payment::PENDING)
                    ->requiresConfirmation()
                    ->modalDescription('Переконайтеся, що переказ справді надійшов. Користувач отримає статус «Преміум».')
                    ->action(function (Payment $record, array $data): void {
                        $record->markPaid(null, Payment::priceUah($data['plan']) * 100, 'UAH', ['manual' => auth()->id()], $data['plan']);
                        Notification::make()->title("{$record->user->name} отримав «Преміум»")->success()->send();
                    }),
            ]);
    }
}
