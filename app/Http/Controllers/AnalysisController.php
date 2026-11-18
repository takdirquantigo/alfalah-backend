<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AnalysisController extends Controller
{
    private array $saleCountColumns = [
        'five_kg',
        'twelve_kg',
        'twentyfive_kg',
        'thirtythree_kg',
        'thirtyfive_kg',
        'fourtyfive_kg',
    ];

    private array $stockColumns = [
        'five_kg',
        'twelve_kg',
        'twentyfive_kg',
        'thirtythree_kg',
        'thirtyfive_kg',
        'fourtyfive_kg',
        'empty_five_kg',
        'empty_twelve_kg',
        'empty_twentyfive_kg',
        'empty_thirtythree_kg',
        'empty_thirtyfive_kg',
        'empty_fourtyfive_kg',
    ];

    public function overview(Request $request)
    {
        try {
            $year = $this->resolveYear($request);
            $startDate = sprintf('%d-01-01', $year);
            $endDate = sprintf('%d-12-31', $year);

            $salesByMonth = $this->salesByMonth($startDate, $endDate);
            $purchasesByMonth = $this->purchasesByMonth($startDate, $endDate);
            $expensesByMonth = $this->expensesByMonth($startDate, $endDate);
            $stockByMonth = $this->closingStockByMonth($startDate, $endDate);
            [$monthlyTops, $yearRetailers, $yearCustomers] = $this->customerRanks($startDate, $endDate);

            $months = [];
            $summary = [
                'sale_amount' => 0,
                'pay_amount' => 0,
                'due_amount' => 0,
                'collection_amount' => 0,
                'purchase_amount' => 0,
                'purchase_count' => 0,
                'expense_amount' => 0,
                'remain_amount' => 0,
                'cylinder_sales' => array_fill_keys($this->saleCountColumns, 0),
                'cylinder_products' => array_fill_keys($this->saleCountColumns, 0),
            ];

            for ($month = 1; $month <= 12; $month++) {
                $ym = sprintf('%d-%02d', $year, $month);
                $sales = $salesByMonth[$ym] ?? null;
                $purchase = $purchasesByMonth[$ym] ?? null;
                $expense = (float) ($expensesByMonth[$ym] ?? 0);

                $saleAmount = (float) ($sales->sale_amount ?? 0);
                $payAmount = (float) ($sales->pay_amount ?? 0);
                $dueAmount = (float) ($sales->due_amount ?? 0);
                $collectionAmount = (float) ($sales->collection_amount ?? 0);
                $purchaseAmount = (float) ($purchase->amount ?? 0);
                $purchaseCount = (int) ($purchase->entries ?? 0);
                $remainAmount = $payAmount + $collectionAmount - $expense;
                $cylinderSales = [];
                $cylinderProducts = [];
                foreach ($this->saleCountColumns as $column) {
                    $count = (int) ($sales->{$column} ?? 0);
                    $cylinderSales[$column] = $count;
                    $summary['cylinder_sales'][$column] += $count;

                    $productCount = (int) ($purchase->{$column} ?? 0);
                    $cylinderProducts[$column] = $productCount;
                    $summary['cylinder_products'][$column] += $productCount;
                }

                $summary['sale_amount'] += $saleAmount;
                $summary['pay_amount'] += $payAmount;
                $summary['due_amount'] += $dueAmount;
                $summary['collection_amount'] += $collectionAmount;
                $summary['purchase_amount'] += $purchaseAmount;
                $summary['purchase_count'] += $purchaseCount;
                $summary['expense_amount'] += $expense;
                $summary['remain_amount'] += $remainAmount;

                $months[] = [
                    'month' => $ym,
                    'month_label' => Carbon::parse($ym . '-01')->format('M Y'),
                    'sale_amount' => $saleAmount,
                    'pay_amount' => $payAmount,
                    'due_amount' => $dueAmount,
                    'collection_amount' => $collectionAmount,
                    'purchase_amount' => $purchaseAmount,
                    'purchase_count' => $purchaseCount,
                    'expense_amount' => $expense,
                    'remain_amount' => $remainAmount,
                    'cylinder_sales' => $cylinderSales,
                    'cylinder_products' => $cylinderProducts,
                    'stock' => $stockByMonth[$ym] ?? null,
                    'top_retailer' => $monthlyTops[$ym]['top_retailer'] ?? null,
                    'top_customer' => $monthlyTops[$ym]['top_customer'] ?? null,
                ];
            }

            return response()->json([
                'year' => $year,
                'summary' => $summary,
                'months' => $months,
                'top_retailers' => $yearRetailers,
                'top_customers' => $yearCustomers,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            \Log::error('Error loading analysis overview: ' . $e->getMessage());

            return response()->json(['error' => 'Failed to load analysis overview'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function resolveYear(Request $request): int
    {
        $year = (int) $request->query('year', Carbon::now('Asia/Dhaka')->year);
        if ($year < 2000 || $year > 2100) {
            return Carbon::now('Asia/Dhaka')->year;
        }

        return $year;
    }

    private function salesByMonth(string $startDate, string $endDate)
    {
        return DB::table('sales')
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw("
                SUBSTRING(date, 1, 7) AS ym,
                COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN 0 ELSE CAST(price AS DECIMAL(15,2)) + CAST(COALESCE(other_cost_amount, 0) AS DECIMAL(15,2)) END), 0) AS sale_amount,
                COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN 0 ELSE CAST(pay AS DECIMAL(15,2)) END), 0) AS pay_amount,
                COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN 0 ELSE CAST(due AS DECIMAL(15,2)) END), 0) AS due_amount,
                COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN CAST(pay AS DECIMAL(15,2)) ELSE 0 END), 0) AS collection_amount,
                COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN 0 ELSE CAST(COALESCE(five_kg, 0) AS SIGNED) END), 0) AS five_kg,
                COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN 0 ELSE CAST(COALESCE(twelve_kg, 0) AS SIGNED) END), 0) AS twelve_kg,
                COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN 0 ELSE CAST(COALESCE(twentyfive_kg, 0) AS SIGNED) END), 0) AS twentyfive_kg,
                COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN 0 ELSE CAST(COALESCE(thirtythree_kg, 0) AS SIGNED) END), 0) AS thirtythree_kg,
                COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN 0 ELSE CAST(COALESCE(thirtyfive_kg, 0) AS SIGNED) END), 0) AS thirtyfive_kg,
                COALESCE(SUM(CASE WHEN CAST(is_due_bill AS CHAR) = '1' THEN 0 ELSE CAST(COALESCE(fourtyfive_kg, 0) AS SIGNED) END), 0) AS fourtyfive_kg
            ")
            ->groupByRaw('SUBSTRING(date, 1, 7)')
            ->get()
            ->keyBy('ym');
    }

    private function purchasesByMonth(string $startDate, string $endDate)
    {
        return DB::table('products')
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw("
                SUBSTRING(date, 1, 7) AS ym,
                COALESCE(SUM(CAST(price AS DECIMAL(15,2))), 0) AS amount,
                COUNT(*) AS entries,
                COALESCE(SUM(CAST(COALESCE(five_kg, 0) AS SIGNED)), 0) AS five_kg,
                COALESCE(SUM(CAST(COALESCE(twelve_kg, 0) AS SIGNED)), 0) AS twelve_kg,
                COALESCE(SUM(CAST(COALESCE(twentyfive_kg, 0) AS SIGNED)), 0) AS twentyfive_kg,
                COALESCE(SUM(CAST(COALESCE(thirtythree_kg, 0) AS SIGNED)), 0) AS thirtythree_kg,
                COALESCE(SUM(CAST(COALESCE(thirtyfive_kg, 0) AS SIGNED)), 0) AS thirtyfive_kg,
                COALESCE(SUM(CAST(COALESCE(fourtyfive_kg, 0) AS SIGNED)), 0) AS fourtyfive_kg
            ")
            ->groupByRaw('SUBSTRING(date, 1, 7)')
            ->get()
            ->keyBy('ym');
    }

    private function expensesByMonth(string $startDate, string $endDate): array
    {
        return DB::table('expenses')
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw("
                SUBSTRING(date, 1, 7) AS ym,
                COALESCE(SUM(CAST(amount AS DECIMAL(15,2))), 0) AS amount
            ")
            ->groupByRaw('SUBSTRING(date, 1, 7)')
            ->pluck('amount', 'ym')
            ->all();
    }

    private function closingStockByMonth(string $startDate, string $endDate): array
    {
        $rows = DB::table('day_close')
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $byMonth = [];
        foreach ($rows as $row) {
            $ym = substr((string) $row->date, 0, 7);
            $stock = ['closed_on' => substr((string) $row->date, 0, 10)];
            foreach ($this->stockColumns as $column) {
                $stock[$column] = (int) ($row->{$column} ?? 0);
            }
            $byMonth[$ym] = $stock;
        }

        return $byMonth;
    }

    private function customerRanks(string $startDate, string $endDate): array
    {
        $rows = DB::table('sales')
            ->leftJoin('customers', 'sales.customer_id', '=', 'customers.id')
            ->whereBetween('sales.date', [$startDate, $endDate])
            ->whereRaw("CAST(sales.is_due_bill AS CHAR) <> '1'")
            ->selectRaw("
                SUBSTRING(sales.date, 1, 7) AS ym,
                sales.customer_id,
                COALESCE(MAX(customers.name), MAX(sales.customer_name), 'Unknown') AS customer_name,
                MAX(COALESCE(sales.whole_sale, customers.is_retailer, 0)) AS is_retailer,
                COALESCE(SUM(CAST(sales.price AS DECIMAL(15,2)) + CAST(COALESCE(sales.other_cost_amount, 0) AS DECIMAL(15,2))), 0) AS sale_amount
            ")
            ->groupByRaw('SUBSTRING(sales.date, 1, 7), sales.customer_id')
            ->get();

        $monthlyTops = [];
        $yearTotals = [];

        foreach ($rows as $row) {
            $isRetailer = (int) $row->is_retailer === 1;
            $entry = [
                'customer_id' => $row->customer_id,
                'customer_name' => $row->customer_name,
                'sale_amount' => (float) $row->sale_amount,
            ];

            $slot = $isRetailer ? 'top_retailer' : 'top_customer';
            $current = $monthlyTops[$row->ym][$slot] ?? null;
            if ($current === null || $entry['sale_amount'] > $current['sale_amount']) {
                $monthlyTops[$row->ym][$slot] = $entry;
            }

            $yearKey = ($row->customer_id ?? 'none') . ':' . ($isRetailer ? '1' : '0');
            if (!isset($yearTotals[$yearKey])) {
                $yearTotals[$yearKey] = $entry + ['is_retailer' => $isRetailer];
            } else {
                $yearTotals[$yearKey]['sale_amount'] += $entry['sale_amount'];
            }
        }

        $retailers = [];
        $customers = [];
        foreach ($yearTotals as $item) {
            if ($item['is_retailer']) {
                $retailers[] = $item;
            } else {
                $customers[] = $item;
            }
        }

        $sort = function (array $items): array {
            usort($items, fn ($a, $b) => $b['sale_amount'] <=> $a['sale_amount']);

            return array_slice($items, 0, 5);
        };

        return [$monthlyTops, $sort($retailers), $sort($customers)];
    }
}
