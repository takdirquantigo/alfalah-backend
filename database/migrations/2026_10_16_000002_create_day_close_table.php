<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('day_close')) {
            return;
        }

        Schema::create('day_close', function (Blueprint $table) {
            $table->id();
            $table->integer('five_kg')->default(0);
            $table->integer('twelve_kg')->default(0);
            $table->integer('twentyfive_kg')->default(0);
            $table->integer('thirtythree_kg')->default(0);
            $table->integer('thirtyfive_kg')->default(0);
            $table->integer('fourtyfive_kg')->default(0);
            $table->integer('empty_five_kg')->default(0);
            $table->integer('empty_twelve_kg')->default(0);
            $table->integer('empty_twentyfive_kg')->default(0);
            $table->integer('empty_thirtythree_kg')->default(0);
            $table->integer('empty_thirtyfive_kg')->default(0);
            $table->integer('empty_fourtyfive_kg')->default(0);
            $table->integer('total_sale')->default(0);
            $table->integer('total_pay')->default(0);
            $table->integer('due_pay')->default(0);
            $table->integer('total_due')->default(0);
            $table->integer('total_cost')->default(0);
            $table->integer('total_remain')->default(0);
            $table->string('date')->nullable();
            $table->timestamps();

            $table->index('date');
        });
    }

    public function down(): void
    {
    }
};
