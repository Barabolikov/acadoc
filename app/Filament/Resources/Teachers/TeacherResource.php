<?php

namespace App\Filament\Resources\Teachers;

use App\Filament\Resources\Teachers\Pages\EditTeacher;
use App\Filament\Resources\Teachers\Pages\ListTeachers;
use App\Filament\Resources\Teachers\Schemas\TeacherForm;
use App\Filament\Resources\Teachers\Tables\TeachersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TeacherResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';
    protected static ?string $slug = 'teachers';

    public static function getModelLabel(): string
    {
        return __('models.teacher');
    }

    public static function getPluralModelLabel(): string
    {
        return __('models.teachers');
    }

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TeacherForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeachersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->role('Викладач');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeachers::route('/'),
            'edit' => EditTeacher::route('/{record}/edit'),
        ];
    }
}
