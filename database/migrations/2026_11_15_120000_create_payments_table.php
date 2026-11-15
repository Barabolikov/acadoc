<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Пожертви за статус «Преміум» (monobank-банка або PayPal)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);              // monobank | paypal
            $table->string('plan', 10)->default('month'); // month (місяць) | lifetime (назавжди)
            $table->string('code', 20)->nullable();      // код у коментарі до переказу (monobank)
            $table->string('external_id')->nullable();   // id операції / замовлення в платіжній системі
            $table->unsignedInteger('amount')->nullable(); // сума в копійках / центах
            $table->string('currency', 3)->nullable();
            $table->string('status', 20)->default('pending'); // pending | paid
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['provider', 'status', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
