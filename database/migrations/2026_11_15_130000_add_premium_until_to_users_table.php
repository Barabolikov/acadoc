<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «Преміум»: до дати (пожертва на місяць, повторна — продовжує) або назавжди
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('premium_until')->nullable()->after('requested_role');
            $table->boolean('premium_lifetime')->default(false)->after('premium_until'); // «Преміум» назавжди
        });

        // Раніше «Преміум» був роллю — переносимо власників ролі на місяць від сьогодні
        $roleId = DB::table('roles')->where('name', 'Преміум')->value('id');
        if ($roleId) {
            $userIds = DB::table('model_has_roles')->where('role_id', $roleId)->pluck('model_id');
            DB::table('users')->whereIn('id', $userIds)->update(['premium_until' => now()->addMonth()]);
            DB::table('model_has_roles')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['premium_until', 'premium_lifetime']);
        });
    }
};
