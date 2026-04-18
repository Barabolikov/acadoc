<?php

namespace App\Filament\Imports;

use App\Models\User;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class UserImporter extends Importer
{
    protected static ?string $model = User::class;



    public static function getColumns(): array
    {
        return [
            \Filament\Actions\Imports\ImportColumn::make('name')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->label('ПІБ'),

            \Filament\Actions\Imports\ImportColumn::make('email')
                ->requiredMapping()
                ->rules(['required', 'email', 'max:255'])
                ->label('Електронна пошта'),
            \Filament\Actions\Imports\ImportColumn::make('department')
                ->label('Відділення/Кафедра')
                ->fillRecordUsing(fn () => null), // <--- Додаємо цей рядок
            \Filament\Actions\Imports\ImportColumn::make('group')
                ->label('Група')
                ->fillRecordUsing(fn () => null), // <--- Додаємо цей рядок
            \Filament\Actions\Imports\ImportColumn::make('roles')
                ->label('Ролі (через кому)')
                ->fillRecordUsing(fn () => null), // <--- Додаємо цей рядок
        ];
    }

    public function resolveRecord(): ?\App\Models\User
    {
        try {
            // 1. Шукаємо або створюємо користувача за email
            $user = \App\Models\User::firstOrNew([
                'email' => $this->data['email'],
            ]);

            // 2. Якщо це новий користувач, задаємо базові дані
            if (! $user->exists) {
                $user->name = $this->data['name'];
                $user->password = bcrypt('acadoc123!');
            }

            // 3. ПРИВ'ЯЗКА КАФЕДРИ
            if (!empty($this->data['department'])) {
                $dept = \App\Models\Department::where('name', $this->data['department'])->first();
                if ($dept) {
                    $user->department_id = $dept->id;
                }
            }

            // 4. ПРИВ'ЯЗКА ГРУПИ
            if (!empty($this->data['group'])) {
                $group = \App\Models\Group::where('name', $this->data['group'])->first();
                if ($group) {
                    $user->group_id = $group->id;
                }
            }

            return $user;

        } catch (\Exception $e) {
            // Записуємо ТОЧНУ причину в лог
            \Log::error('ПОМИЛКА ЗБЕРЕЖЕННЯ КОРИСТУВАЧА: ' . $e->getMessage() . ' (Рядок: ' . $e->getLine() . ')');
            throw $e;
        }

        return $user;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your user import has completed and ' . Number::format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }
    protected function afterSave(): void
    {
        try {
            if (!empty($this->data['roles'])) {
                $roleNames = array_map('trim', explode(',', $this->data['roles']));

                /** @var \App\Models\User $user */
                $user = $this->record;
                $user->syncRoles($roleNames);
            }
        } catch (\Exception $e) {
            // Примусово пишемо помилку червоним у лог!
            \Log::error('ПОМИЛКА ІМПОРТУ РОЛЕЙ: ' . $e->getMessage());
            throw $e; // Кидаємо помилку далі
        }
    }
}
