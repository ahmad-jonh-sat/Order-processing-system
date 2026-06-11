<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->string('status')->default('generated');
            $table->string('file_path');
            $table->jsonb('payload');
            $table->timestamps();

            $table->unique(['order_id', 'type']);
            $table->index(['order_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
