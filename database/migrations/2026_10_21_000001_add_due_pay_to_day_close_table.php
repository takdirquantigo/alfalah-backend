<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('day_close') || Schema::hasColumn('day_close', 'due_pay')) {
            return;
        }

        Schema::table('day_close', function (Blueprint $table) {
            $table->integer('due_pay')->default(0)->after('total_pay');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('day_close') || ! Schema::hasColumn('day_close', 'due_pay')) {
            return;
        }

        Schema::table('day_close', function (Blueprint $table) {
            $table->dropColumn('due_pay');
        });
    }
};
