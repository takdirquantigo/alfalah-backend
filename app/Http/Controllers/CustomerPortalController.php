<?php

namespace App\Http\Controllers;

use App\Models\Customers;
use App\Models\Sales;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CustomerPortalController extends Controller
{
    private array $filledColumns = [
        'five_kg', 'twelve_kg', 'twentyfive_kg', 'thirtythree_kg',
        'thirtyfive_kg', 'fourtyfive_kg', 'others_kg',
    ];

    private array $emptyColumns = [
        'empty_five_kg', 'empty_twelve_kg', 'empty_twentyfive_kg', 'empty_thirtythree_kg',
        'empty_thirtyfive_kg', 'empty_fourtyfive_kg', 'empty_others_kg',
    ];

    private function customer(Request $request): Customers
    {
        $user = $request->user();
        if (! $user instanceof Customers) {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthorized');
        }

        return $user;
    }

    public function dashboard(Request $request)
    {
        try {
            $customer = $this->customer($request);
            $id = $customer->id;

            $totals = DB::table('sales')
                ->where('customer_id', $id)
                ->selectRaw('
                    COALESCE(SUM(CAST(price AS DECIMAL(15,2))), 0) AS total_purchase,
                    COALESCE(SUM(CAST(pay AS DECIMAL(15,2))), 0) AS total_pay
                ')
                ->first();

            $totalPurchase = (float) $totals->total_purchase;
            $totalPay = (float) $totals->total_pay;
            $totalDue = max(0, $totalPurchase - $totalPay);

            $filledParts = [];
            foreach ($this->filledColumns as $col) {
                $filledParts[] = "COALESCE(SUM(CAST({$col} AS UNSIGNED)), 0) AS {$col}";
            }
            $emptyParts = [];
            foreach ($this->emptyColumns as $col) {
                $emptyParts[] = "COALESCE(SUM(CAST({$col} AS UNSIGNED)), 0) AS {$col}";
            }

            $cylindersFilled = DB::table('sales')
                ->where('customer_id', $id)
                ->where(function ($q) {
                    $q->where('is_due_bill', 0)->orWhereNull('is_due_bill');
                })
                ->selectRaw(implode(', ', $filledParts))
                ->first();

            $cylindersEmpty = DB::table('sales')
                ->where('customer_id', $id)
                ->where(function ($q) {
                    $q->where('is_due_bill', 0)->orWhereNull('is_due_bill');
                })
                ->selectRaw(implode(', ', $emptyParts))
                ->first();

            $monthly = DB::table('sales')
                ->where('customer_id', $id)
                ->selectRaw("
                    DATE_FORMAT(`date`, '%Y-%m') AS ym,
                    COALESCE(SUM(CAST(price AS DECIMAL(15,2))), 0) AS purchase,
                    COALESCE(SUM(CAST(pay AS DECIMAL(15,2))), 0) AS paid
                ")
                ->groupBy('ym')
                ->orderByDesc('ym')
                ->limit(12)
                ->get()
                ->reverse()
                ->values();

            $cylinderChart = [];
            foreach ($this->filledColumns as $i => $col) {
                $emptyCol = $this->emptyColumns[$i] ?? null;
                $cylinderChart[] = [
                    'key' => $col,
                    'label' => $this->labelFor($col),
                    'filled_purchased' => (int) ($cylindersFilled->{$col} ?? 0),
                    'empty_provided' => $emptyCol ? (int) ($cylindersEmpty->{$emptyCol} ?? 0) : 0,
                ];
            }

            return response()->json([
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'customer_login_id' => $customer->customer_login_id,
                ],
                'summary' => [
                    'total_purchase' => $totalPurchase,
                    'total_pay' => $totalPay,
                    'total_due' => $totalDue,
                ],
                'cylinders' => $cylinderChart,
                'trends' => [
                    'monthly' => $monthly,
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            \Log::error('Customer dashboard: '.$e->getMessage());

            return response()->json(['error' => 'Failed to load dashboard'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function labelFor(string $col): string
    {
        return match ($col) {
            'five_kg' => '5 KG',
            'twelve_kg' => '12 KG',
            'twentyfive_kg' => '25 KG',
            'thirtythree_kg' => '33 KG',
            'thirtyfive_kg' => '35 KG',
            'fourtyfive_kg' => '45 KG',
            'others_kg' => 'Others',
            default => $col,
        };
    }

    public function transactions(Request $request)
    {
        try {
            $customer = $this->customer($request);
            $perPage = min((int) $request->get('per_page', 15), 50);

            $rows = Sales::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'data' => $rows,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load transactions'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function transactionDetail(Request $request, $id)
    {
        try {
            $customer = $this->customer($request);

            $sale = Sales::query()
                ->with('lines')
                ->where('customer_id', $customer->id)
                ->where('id', $id)
                ->first();

            if (! $sale) {
                return response()->json(['error' => 'Sale not found'], Response::HTTP_NOT_FOUND);
            }

            $filledBreakdown = [];
            foreach ($this->filledColumns as $col) {
                $filledBreakdown[] = [
                    'key' => $col,
                    'label' => $this->labelFor($col).' (filled)',
                    'quantity' => (int) ($sale->{$col} ?? 0),
                ];
            }

            $emptyBreakdown = [];
            foreach ($this->emptyColumns as $col) {
                $emptyBreakdown[] = [
                    'key' => $col,
                    'label' => $this->labelForEmpty($col),
                    'quantity' => (int) ($sale->{$col} ?? 0),
                ];
            }

            if ($sale->lines->isNotEmpty()) {
                $pricingBreakdown = $sale->lines->map(function ($line) {
                    return [
                        'key' => 'line-'.$line->id,
                        'label' => $this->labelFor($line->size),
                        'quantity' => (int) $line->quantity,
                        'empty_quantity' => (int) $line->empty_quantity,
                        'unit_price' => (int) $line->unit_price,
                        'amount' => (int) $line->line_total,
                    ];
                })->values()->all();
            } else {
                $pricingBreakdown = [
                    ['key' => 'price_5_kg', 'label' => '5 KG line total', 'amount' => $sale->price_5_kg],
                    ['key' => 'price_12_kg', 'label' => '12 KG line total', 'amount' => $sale->price_12_kg],
                    ['key' => 'price_25_kg', 'label' => '25 KG line total', 'amount' => $sale->price_25_kg],
                    ['key' => 'price_33_kg', 'label' => '33 KG line total', 'amount' => $sale->price_33_kg],
                    ['key' => 'price_35_kg', 'label' => '35 KG line total', 'amount' => $sale->price_35_kg],
                    ['key' => 'price_45_kg', 'label' => '45 KG line total', 'amount' => $sale->price_45_kg],
                ];
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'sale' => $sale,
                    'filled_cylinders' => $filledBreakdown,
                    'empty_cylinders' => $emptyBreakdown,
                    'pricing_lines' => $pricingBreakdown,
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            \Log::error('Customer transaction detail: '.$e->getMessage());

            return response()->json(['error' => 'Failed to load sale'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function labelForEmpty(string $col): string
    {
        return match ($col) {
            'empty_five_kg' => '5 KG (empty returned)',
            'empty_twelve_kg' => '12 KG (empty returned)',
            'empty_twentyfive_kg' => '25 KG (empty returned)',
            'empty_thirtythree_kg' => '33 KG (empty returned)',
            'empty_thirtyfive_kg' => '35 KG (empty returned)',
            'empty_fourtyfive_kg' => '45 KG (empty returned)',
            'empty_others_kg' => 'Others (empty returned)',
            default => $col,
        };
    }

    public function dueBills(Request $request)
    {
        try {
            $customer = $this->customer($request);
            $perPage = min((int) $request->get('per_page', 15), 50);

            $rows = Sales::query()
                ->where('customer_id', $customer->id)
                ->where('is_due_bill', 1)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'data' => $rows,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load due bills'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
