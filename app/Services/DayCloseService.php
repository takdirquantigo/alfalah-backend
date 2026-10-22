<?php

namespace App\Services;

use App\Models\DayClose;
use App\Models\Expense;
use App\Models\Sales;
use App\Models\Stoke;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DayCloseService
{
    public function todayDate(): string
    {
        return Carbon::now('Asia/Dhaka')->toDateString();
    }

    public function closeToday(bool $update = false): array
    {
        return $this->closeForDate($this->todayDate(), $update);
    }

    public function closeForDate(string $date, bool $update = false): array
    {
        return DB::transaction(function () use ($date, $update) {
            $existing = DayClose::query()->where('date', $date)->lockForUpdate()->orderByDesc('id')->first();
            if ($existing && !$update) {
                return [
                    'already_closed' => true,
                    'updated' => false,
                    'data' => $existing,
                ];
            }

            $stoke = $this->stockForDate($date);
            $sales = Sales::query()
                ->where('date', $date)
                ->selectRaw('
                    COALESCE(SUM(CAST(price AS DECIMAL(15,2))), 0) as total_sale,
                    COALESCE(SUM(CAST(pay AS DECIMAL(15,2))), 0) as total_pay,
                    COALESCE(SUM(CASE WHEN is_due_bill = 1 THEN CAST(pay AS DECIMAL(15,2)) ELSE 0 END), 0) as due_pay,
                    COALESCE(SUM(CAST(due AS DECIMAL(15,2))), 0) as total_due
                ')
                ->first();

            $totalCost = Expense::query()
                ->where('date', $date)
                ->sum(DB::raw('CAST(amount AS DECIMAL(15,2))'));

            $totalSale = $this->money($sales->total_sale ?? 0);
            $totalPay = $this->money($sales->total_pay ?? 0);
            $duePay = $this->money($sales->due_pay ?? 0);
            $totalDue = $this->money($sales->total_due ?? 0);
            $totalCostAmount = $this->money($totalCost);

            $payload = [
                'five_kg' => $this->stock($stoke, 'five_kg'),
                'twelve_kg' => $this->stock($stoke, 'twelve_kg'),
                'twentyfive_kg' => $this->stock($stoke, 'twentyfive_kg'),
                'thirtythree_kg' => $this->stock($stoke, 'thirtythree_kg'),
                'thirtyfive_kg' => $this->stock($stoke, 'thirtyfive_kg'),
                'fourtyfive_kg' => $this->stock($stoke, 'fourtyfive_kg'),
                'empty_five_kg' => $this->stock($stoke, 'empty_five_kg'),
                'empty_twelve_kg' => $this->stock($stoke, 'empty_twelve_kg'),
                'empty_twentyfive_kg' => $this->stock($stoke, 'empty_twentyfive_kg'),
                'empty_thirtythree_kg' => $this->stock($stoke, 'empty_thirtythree_kg'),
                'empty_thirtyfive_kg' => $this->stock($stoke, 'empty_thirtyfive_kg'),
                'empty_fourtyfive_kg' => $this->stock($stoke, 'empty_fourtyfive_kg'),
                'total_sale' => $totalSale,
                'total_pay' => $totalPay,
                'due_pay' => $duePay,
                'total_due' => $totalDue,
                'total_cost' => $totalCostAmount,
                'total_remain' => $totalPay - $totalCostAmount,
                'date' => $date,
            ];

            if ($existing) {
                $existing->update($payload);

                return [
                    'already_closed' => true,
                    'updated' => true,
                    'data' => $existing->fresh(),
                ];
            }

            return [
                'already_closed' => false,
                'updated' => false,
                'data' => DayClose::query()->create($payload),
            ];
        });
    }

    private function stockForDate(string $date): ?Stoke
    {
        $end = Carbon::parse($date, 'Asia/Dhaka')->endOfDay()->utc();

        return Stoke::query()
            ->where('created_at', '<=', $end)
            ->orderByDesc('id')
            ->first();
    }

    private function stock(?Stoke $stoke, string $column): int
    {
        if (!$stoke) {
            return 0;
        }

        return (int) ($stoke->{$column} ?? 0);
    }

    private function money(mixed $value): int
    {
        return (int) round((float) $value);
    }
};
