<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('day_close')) {
            return;
        }

        DB::table('day_close')->orderBy('id')->get()->each(function ($row) {
            $sales = DB::table('sales')
                ->where('date', $row->date)
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN 0 ELSE CAST(pay AS DECIMAL(15,2)) END), 0) as total_pay,
                    COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN CAST(pay AS DECIMAL(15,2)) ELSE 0 END), 0) as due_pay
                ")
                ->first();

            $totalPay = (int) round((float) ($sales->total_pay ?? 0));
            $duePay = (int) round((float) ($sales->due_pay ?? 0));
            $totalCost = (int) ($row->total_cost ?? 0);

            DB::table('day_close')->where('id', $row->id)->update([
                'total_pay' => $totalPay,
                'due_pay' => $duePay,
                'total_remain' => $totalPay + $duePay - $totalCost,
            ]);
        });
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('day_close')) {
            return;
        }

        DB::table('day_close')->orderBy('id')->get()->each(function ($row) {
            $totalPay = (int) ($row->total_pay ?? 0) + (int) ($row->due_pay ?? 0);

            DB::table('day_close')->where('id', $row->id)->update([
                'total_pay' => $totalPay,
                'total_remain' => $totalPay - (int) ($row->total_cost ?? 0),
            ]);
        });
    }
};
