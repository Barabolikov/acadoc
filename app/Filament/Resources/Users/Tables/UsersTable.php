<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class UsersTable
{
    /** Заявка на роль викладача, ще не задоволена */
    public static function hasPendingTeacherRequest(User $user): bool
    {
        return $user->requested_role === 'teacher' && ! $user->hasRole(User::ROLE_TEACHER);
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('models.name')) // <--- Додано переклад
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('models.email')) // <--- Додано переклад
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Роль')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        User::ROLE_ADMIN => 'danger',
                        User::ROLE_TEACHER => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('premium_until')
                    ->label('Преміум')
                    ->state(fn (User $record): ?string => $record->premiumStatusText()
                        ?? ($record->premium_until ? 'закінчився '.$record->premium_until->timezone(User::LIMIT_TIMEZONE)->format('d.m.Y') : null))
                    ->badge()
                    ->color(fn (User $record): string => $record->premium_lifetime ? 'success' : ($record->premium_until?->isFuture() ? 'warning' : 'gray'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('requested_role')
                    ->label('Обрав при реєстрації')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'teacher' => 'Викладач',
                        'student' => 'Студент',
                        default => '—',
                    })
                    ->color(fn (User $record): string => self::hasPendingTeacherRequest($record) ? 'warning' : 'gray')
                    ->description(fn (User $record): ?string => self::hasPendingTeacherRequest($record) ? 'очікує підтвердження' : null)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('models.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('models.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('department.name')
                    ->label(__('models.department_id'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('group.name')
                    ->label(__('models.group_id'))
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                Filter::make('teacher_requests')
                    ->label('Заявки на роль викладача')
                    ->query(fn (Builder $query) => $query
                        ->where('requested_role', 'teacher')
                        ->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('name', User::ROLE_TEACHER))),
                SelectFilter::make('roles')
                    ->label('Роль')
                    ->relationship('roles', 'name')
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('makeTeacher')
                        ->label('Призначити викладачем')
                        ->icon('heroicon-o-academic-cap')
                        ->color('success')
                        ->visible(fn (User $record): bool => ! $record->hasRole(User::ROLE_TEACHER))
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record): string => "Призначити {$record->name} викладачем?")
                        ->modalDescription('Роль «Студент» буде замінено на «Викладач»: для користувача зніметься денний ліміт перевірок.')
                        ->action(function (User $record): void {
                            $record->removeRole(User::ROLE_STUDENT);
                            $record->assignRole(Role::findOrCreate(User::ROLE_TEACHER, 'web'));

                            Notification::make()->title("{$record->name} тепер викладач")->success()->send();
                        }),
                    Action::make('removeTeacher')
                        ->label('Зняти роль викладача')
                        ->icon('heroicon-o-arrow-uturn-down')
                        ->color('gray')
                        ->visible(fn (User $record): bool => $record->hasRole(User::ROLE_TEACHER))
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record): string => "Зняти з {$record->name} роль викладача?")
                        ->modalDescription('Користувач знову стане студентом з денним лімітом перевірок.')
                        ->action(function (User $record): void {
                            $record->removeRole(User::ROLE_TEACHER);
                            $record->assignRole(Role::findOrCreate(User::ROLE_STUDENT, 'web'));

                            Notification::make()->title("{$record->name} тепер студент")->success()->send();
                        }),
                    Action::make('grantPremium')
                        ->label(fn (User $record): string => $record->premium_until?->isFuture() ? 'Продовжити «Преміум» на місяць' : 'Надати «Преміум» на місяць')
                        ->visible(fn (User $record): bool => ! $record->premium_lifetime)
                        ->icon('heroicon-o-star')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record): string => "«Преміум» для {$record->name} на місяць?")
                        ->modalDescription('Місяць додається від сьогодні або від кінця чинного періоду.')
                        ->action(function (User $record): void {
                            $record->extendPremium();

                            Notification::make()->title("«Преміум» для {$record->name} діє до ".$record->premium_until->timezone(User::LIMIT_TIMEZONE)->format('d.m.Y'))->success()->send();
                        }),
                    Action::make('grantLifetimePremium')
                        ->label('Надати «Преміум» назавжди')
                        ->icon('heroicon-o-sparkles')
                        ->color('success')
                        ->visible(fn (User $record): bool => ! $record->premium_lifetime)
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record): string => "«Преміум» для {$record->name} назавжди?")
                        ->action(function (User $record): void {
                            $record->grantLifetimePremium();

                            Notification::make()->title("{$record->name} отримав «Преміум» назавжди")->success()->send();
                        }),
                    Action::make('revokePremium')
                        ->label('Скасувати «Преміум»')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (User $record): bool => $record->premium_lifetime || $record->premium_until?->isFuture())
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record): string => "Скасувати статус «Преміум» для {$record->name}?")
                        ->action(function (User $record): void {
                            $record->revokePremium();

                            Notification::make()->title("Статус «Преміум» для {$record->name} скасовано")->success()->send();
                        }),
                ])
                    ->label('Статус')
                    ->icon('heroicon-o-identification')
                    ->button()
                    ->color('gray'),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
