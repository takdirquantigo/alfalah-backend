<?php

namespace App\Http\Controllers;

use App\Models\Stoke;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AnalysisController extends Controller
{
    private array $filledColumns = [
        'five_kg',
        'twelve_kg',
        'twentyfive_kg',
        'thirtythree_kg',
        'thirtyfive_kg',
        'fourtyfive_kg',
        'others_kg',
    ];

    private array $emptyColumns = [
        'empty_five_kg',
        'empty_twelve_kg',
        'empty_twentyfive_kg',
        'empty_thirtythree_kg',
        'empty_thirtyfive_kg',
        'empty_fourtyfive_kg',
        'empty_others_kg',
    ];

    public function overview(Request $request)
    {
        try {
            [$startDate, $endDate] = $this->resolveDateRange($request);

            $salesWithinRange = DB::table('sales')
                ->whereBetween('date', [$startDate, $endDate]);

            $collectionWithinRange = DB::table('sales')
                ->whereBetween('date', [$startDate, $endDate])
                ->where('is_due_bill', 1);

            $stockInWithinRange = DB::table('products')
                ->whereBetween('date', [$startDate, $endDate]);

            $expenseWithinRange = DB::table('expenses')
                ->whereBetween('date', [$startDate, $endDate]);

            $salesSummary = $salesWithinRange
                ->selectRaw("
                    COALESCE(SUM(CAST(price AS DECIMAL(15,2))), 0) AS total_sales,
                    COALESCE(SUM(CAST(pay AS DECIMAL(15,2))), 0) AS total_collection,
                    COALESCE(SUM(CAST(due AS DECIMAL(15,2))), 0) AS total_due_booked,
                    COUNT(*) AS total_transactions,
                    COUNT(DISTINCT customer_id) AS active_customers
                ")
                ->first();

            $collectionsSummary = $collectionWithinRange
                ->selectRaw("
                    COALESCE(SUM(CAST(pay AS DECIMAL(15,2))), 0) AS due_collection,
                    COUNT(*) AS total_due_collections
                ")
                ->first();

            $purchaseSummary = $stockInWithinRange
                ->selectRaw("
                    COALESCE(SUM(CAST(price AS DECIMAL(15,2))), 0) AS total_purchase_cost,
                    COUNT(*) AS total_purchase_entries
                ")
                ->first();

            $expenseSummary = $expenseWithinRange
                ->selectRaw("
                    COALESCE(SUM(CAST(amount AS DECIMAL(15,2))), 0) AS total_expense,
                    COUNT(*) AS total_expense_entries
                ")
                ->first();

            $stockInByCylinder = $this->sumColumns('products', array_merge($this->filledColumns, $this->emptyColumns), $startDate, $endDate);
            $salesByCylinder = $this->sumColumns('sales', array_merge($this->filledColumns, $this->emptyColumns), $startDate, $endDate);

            $cylinderMovement = [];
            foreach ($this->filledColumns as $column) {
                $inValue = (float) ($stockInByCylinder[$column] ?? 0);
                $outValue = (float) ($salesByCylinder[$column] ?? 0);
                $cylinderMovement[$column] = [
                    'in' => $inValue,
                    'out' => $outValue,
                    'net' => $inValue - $outValue,
                ];
            }

            foreach ($this->emptyColumns as $column) {
                $returnValue = (float) ($salesByCylinder[$column] ?? 0);
                $releaseValue = (float) ($stockInByCylinder[$column] ?? 0);
                $cylinderMovement[$column] = [
                    'in' => $returnValue,
                    'out' => $releaseValue,
                    'net' => $returnValue - $releaseValue,
                ];
            }

            $dailyTrend = DB::table('sales')
                ->whereBetween('date', [$startDate, $endDate])
                ->selectRaw("
                    date,
                    COALESCE(SUM(CAST(price AS DECIMAL(15,2))), 0) AS sales,
                    COALESCE(SUM(CAST(pay AS DECIMAL(15,2))), 0) AS collection,
                    COALESCE(SUM(CAST(due AS DECIMAL(15,2))), 0) AS due
                ")
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'sales' => (float) $row->sales,
                    'collection' => (float) $row->collection,
                    'due' => (float) $row->due,
                ]);

            $monthlyCylinderBreakdown = $this->buildMonthlyCylinderBreakdown($startDate, $endDate);

            $topCustomers = DB::table('sales')
                ->leftJoin('customers', 'sales.customer_id', '=', 'customers.id')
                ->whereBetween('sales.date', [$startDate, $endDate])
                ->selectRaw("
                    sales.customer_id,
                    COALESCE(MAX(customers.name), MAX(sales.customer_name), 'Unknown') AS customer_name,
                    COALESCE(SUM(CAST(sales.price AS DECIMAL(15,2))), 0) AS total_sales,
                    COALESCE(SUM(CAST(sales.pay AS DECIMAL(15,2))), 0) AS total_paid,
                    COALESCE(SUM(CAST(sales.due AS DECIMAL(15,2))), 0) AS total_due
                ")
                ->groupBy('sales.customer_id')
                ->orderByDesc('total_sales')
                ->limit(10)
                ->get();

            $receivableCustomers = DB::table('sales')
                ->leftJoin('customers', 'sales.customer_id', '=', 'customers.id')
                ->selectRaw("
                    sales.customer_id,
                    COALESCE(MAX(customers.name), MAX(sales.customer_name), 'Unknown') AS customer_name,
                    COALESCE(SUM(CAST(sales.price AS DECIMAL(15,2))), 0) AS total_sales,
                    COALESCE(SUM(CAST(sales.pay AS DECIMAL(15,2))), 0) AS total_paid
                ")
                ->groupBy('sales.customer_id')
                ->havingRaw('COALESCE(SUM(CAST(sales.price AS DECIMAL(15,2))), 0) - COALESCE(SUM(CAST(sales.pay AS DECIMAL(15,2))), 0) > 0')
                ->orderByRaw('(COALESCE(SUM(CAST(sales.price AS DECIMAL(15,2))), 0) - COALESCE(SUM(CAST(sales.pay AS DECIMAL(15,2))), 0)) DESC')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'customer_id' => $row->customer_id,
                    'customer_name' => $row->customer_name,
                    'total_sales' => (float) $row->total_sales,
                    'total_paid' => (float) $row->total_paid,
                    'total_due' => (float) $row->total_sales - (float) $row->total_paid,
                ]);

            $currentStock = Stoke::query()->latest()->first();

            $totalSales = (float) $salesSummary->total_sales;
            $totalCollection = (float) $salesSummary->total_collection;
            $totalPurchaseCost = (float) $purchaseSummary->total_purchase_cost;
            $totalExpense = (float) $expenseSummary->total_expense;
            $grossProfit = $totalSales - $totalPurchaseCost;
            $netProfit = $grossProfit - $totalExpense;

            return response()->json([
                'date_range' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'summary' => [
                    'total_sales' => $totalSales,
                    'total_collection' => $totalCollection,
                    'total_due_booked' => (float) $salesSummary->total_due_booked,
                    'due_collection' => (float) $collectionsSummary->due_collection,
                    'total_purchase_cost' => $totalPurchaseCost,
                    'total_expense' => $totalExpense,
                    'gross_profit' => $grossProfit,
                    'net_profit' => $netProfit,
                    'total_transactions' => (int) $salesSummary->total_transactions,
                    'active_customers' => (int) $salesSummary->active_customers,
                    'total_due_collections' => (int) $collectionsSummary->total_due_collections,
                    'total_purchase_entries' => (int) $purchaseSummary->total_purchase_entries,
                    'total_expense_entries' => (int) $expenseSummary->total_expense_entries,
                ],
                'cylinder_movement' => $cylinderMovement,
                'trends' => [
                    'daily' => $dailyTrend,
                    'monthly_cylinder_breakdown' => $monthlyCylinderBreakdown,
                ],
                'top_customers' => $topCustomers,
                'high_receivables' => $receivableCustomers,
                'current_stock' => $currentStock,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            \Log::error('Error loading analysis overview: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to load analysis overview'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function resolveDateRange(Request $request): array
    {
        $tz = 'Asia/Dhaka';
        $startDateInput = $request->query('start_date');
        $endDateInput = $request->query('end_date');
        $defaultDays = (int) $request->query('days', 30);

        $endDate = $endDateInput
            ? Carbon::parse($endDateInput, $tz)->toDateString()
            : Carbon::now($tz)->toDateString();

        $startDate = $startDateInput
            ? Carbon::parse($startDateInput, $tz)->toDateString()
            : Carbon::parse($endDate, $tz)->subDays(max(1, $defaultDays) - 1)->toDateString();

        return [$startDate, $endDate];
    }

    private function sumColumns(string $table, array $columns, string $startDate, string $endDate): array
    {
        $selectParts = [];
        foreach ($columns as $column) {
            $selectParts[] = "COALESCE(SUM(CAST({$column} AS DECIMAL(15,2))), 0) AS {$column}";
        }

        $row = DB::table($table)
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw(implode(', ', $selectParts))
            ->first();

        return (array) $row;
    }

    /**
     * Per calendar month: purchase (products) vs sale quantities for each cylinder column.
     *
     * @return list<array{month: string, month_label: string, cylinders: array<string, array{purchase: float, sale: float}>}>
     */
    private function buildMonthlyCylinderBreakdown(string $startDate, string $endDate): array
    {
        $allCols = array_merge($this->filledColumns, $this->emptyColumns);

        $selectParts = ['SUBSTRING(date, 1, 7) AS ym'];
        foreach ($allCols as $c) {
            $selectParts[] = "COALESCE(SUM(CAST({$c} AS SIGNED)), 0) AS {$c}";
        }
        $selectRaw = implode(', ', $selectParts);

        $salesByMonth = DB::table('sales')
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw($selectRaw)
            ->groupByRaw('SUBSTRING(date, 1, 7)')
            ->get()
            ->keyBy('ym');

        $productsByMonth = DB::table('products')
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw($selectRaw)
            ->groupByRaw('SUBSTRING(date, 1, 7)')
            ->get()
            ->keyBy('ym');

        $monthKeys = [];
        $cursor = Carbon::parse($startDate)->startOfMonth();
        $endMonth = Carbon::parse($endDate)->startOfMonth();
        while ($cursor->lte($endMonth)) {
            $monthKeys[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        $rows = [];
        foreach ($monthKeys as $ym) {
            $sRow = (array) ($salesByMonth->get($ym) ?? []);
            $pRow = (array) ($productsByMonth->get($ym) ?? []);
            $cylinders = [];
            foreach ($allCols as $col) {
                $cylinders[$col] = [
                    'purchase' => (float) ($pRow[$col] ?? 0),
                    'sale' => (float) ($sRow[$col] ?? 0),
                ];
            }
            $rows[] = [
                'month' => $ym,
                'month_label' => Carbon::parse($ym . '-01')->format('M Y'),
                'cylinders' => $cylinders,
            ];
        }

        return $rows;
    }
}
