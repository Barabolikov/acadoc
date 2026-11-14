<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Роль, яку користувач обрав під час реєстрації ('student' або 'teacher').
            // Фактичну роль (spatie/laravel-permission) призначає адміністратор.
            $table->string('requested_role', 20)->nullable()->after('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('requested_role');
        });
    }
};
