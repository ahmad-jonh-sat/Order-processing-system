<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('operation_id')->unique();
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('status')->default('completed');
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_operations');
    }
};
