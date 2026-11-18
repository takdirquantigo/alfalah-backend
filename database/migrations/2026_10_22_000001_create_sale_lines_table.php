<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_lines')) {
            return;
        }

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id');
            $table->string('size')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('empty_quantity')->default(0);
            $table->integer('unit_price')->default(0);
            $table->integer('line_total')->default(0);
            $table->timestamps();
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_lines');
    }
};
