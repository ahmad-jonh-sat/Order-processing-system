<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saga_instances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('status')->default('running');
            $table->string('current_step')->nullable();
            $table->jsonb('payload');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('saga_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saga_instance_id')->constrained()->cascadeOnDelete();
            $table->string('step');
            $table->string('status')->default('pending');
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->unique(['saga_instance_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saga_steps');
        Schema::dropIfExists('saga_instances');
    }
};
