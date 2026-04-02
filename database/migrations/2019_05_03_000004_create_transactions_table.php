<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('provider_id')->unique();
            $table->string('provider_subscription_id')->nullable()->index();
            $table->string('payment_status');
            $table->decimal('amount_gross', 10, 2);
            $table->decimal('amount_fee', 10, 2)->default(0);
            $table->decimal('amount_net', 10, 2);
            $table->string('currency', 3)->default('ZAR');
            $table->timestamp('billed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
