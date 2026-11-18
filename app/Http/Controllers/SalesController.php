<?php

namespace App\Http\Controllers;

use App\Models\Products;
use App\Models\Sales;
use App\Models\Stoke;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use App\Models\Company;
use App\Models\Customers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesController extends Controller
{
    public function newSales(Request $request)
    {
        try {
            $customers = $this->normalizeCustomers($request->input('customer'));

            if (empty($customers)) {
                return response()->json(['error' => 'Customer is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $lines = $this->linesFromRequest($request);
            if (empty($lines)) {
                return response()->json(['error' => 'Add at least one cylinder'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            DB::transaction(function () use ($request, $customers, $lines) {
                $stoke = Stoke::query()->latest('id')->lockForUpdate()->first();
                $shortage = $this->stockShortageMessage($stoke, $this->filledQuantities($lines));
                if ($shortage) {
                    throw new \InvalidArgumentException($shortage);
                }

                foreach ($customers as $customer) {
                    $customerDetails = Customers::find($customer['value']);

                    $newSales = new Sales();
                    $newSales->customer_name = $customer['label'];
                    $newSales->customer_id = $customer['value'];
                    $newSales->date = $request->date;
                    $newSales->is_due_bill = $request->is_due_bill ?? 0;
                    $newSales->is_package = $request->boolean('is_package');
                    $newSales->cylinder_price = $request->boolean('is_package') ? $this->toNumber($request->cylinder_price) : 0;
                    $newSales->whole_sale = $customerDetails && $customerDetails->is_retailer ? 1 : 0;
                    $newSales->pay = $this->toNumber($request->pay);
                    $newSales->due = $this->toNumber($request->due);
                    $newSales->other_cost_name = $request->other_cost_name ?? '';
                    $newSales->other_cost_amount = $this->toNumber($request->other_cost_amount);

                    $this->applyLinesToSale($newSales, $lines);
                    $newSales->save();
                    $this->syncLines($newSales, $lines);
                }

                $stoke = $this->getOrCreateStoke();
                foreach ($this->sizeMap() as $size => $cols) {
                    $qty = 0;
                    $empty = 0;
                    foreach ($lines as $line) {
                        if ($line['size'] !== $size) {
                            continue;
                        }
                        $qty += $line['quantity'];
                        $empty += $line['empty_quantity'];
                    }
                    $stoke->{$size} = ($stoke->{$size} ?? 0) - $qty;
                    $stoke->{$cols['empty']} = ($stoke->{$cols['empty']} ?? 0) + $empty;
                }
                $stoke->save();
            });

            return response()->json(['message' => 'Sales added successfully'], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            \Log::error('Error adding sales: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function normalizeCustomers($customerInput): array
    {
        if (!is_array($customerInput) || empty($customerInput)) {
            return [];
        }

        // Supports frontend payload like { value: 1, label: "test" }
        if (array_key_exists('value', $customerInput) && array_key_exists('label', $customerInput)) {
            return [$customerInput];
        }

        // Supports multi-select payload like [{ value: 1, label: "test" }, ...]
        return array_values(array_filter($customerInput, function ($customer) {
            return is_array($customer)
                && array_key_exists('value', $customer)
                && array_key_exists('label', $customer);
        }));
    }

    private function toNumber($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) $value;
    }

    private function getOrCreateStoke(): Stoke
    {
        $stoke = Stoke::latest()->first();
        if ($stoke) {
            return $stoke;
        }

        return Stoke::create([
            'five_kg' => 0,
            'twelve_kg' => 0,
            'twentyfive_kg' => 0,
            'thirtythree_kg' => 0,
            'thirtyfive_kg' => 0,
            'fourtyfive_kg' => 0,
            'others_kg' => 0,
            'empty_five_kg' => 0,
            'empty_twelve_kg' => 0,
            'empty_twentyfive_kg' => 0,
            'empty_thirtythree_kg' => 0,
            'empty_thirtyfive_kg' => 0,
            'empty_fourtyfive_kg' => 0,
            'empty_others_kg' => 0,
        ]);
    }


    public function updateSale(Request $request)
    {
        try {
            $lines = $this->linesFromRequest($request);
            if (empty($lines)) {
                return response()->json(['message' => 'Add at least one cylinder'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            DB::transaction(function () use ($request, $lines) {

                $sale = Sales::find($request->id);

                if (!$sale) {
                    throw new \Exception('Sale not found');
                }

                $stoke = Stoke::query()->latest('id')->lockForUpdate()->first();
                $parts = [];
                foreach ($this->filledQuantities($lines) as $size => $newQty) {
                    $alreadySold = (int) ($sale->{$size} ?? 0);
                    $onHand = $stoke ? (int) ($stoke->{$size} ?? 0) : 0;
                    $available = $onHand + $alreadySold;
                    if ($newQty > $available) {
                        $parts[] = $this->sizeLabel($size).': available '.$available.', requested '.$newQty;
                    }
                }
                if ($parts) {
                    throw new \InvalidArgumentException('Not enough stock. '.implode('. ', $parts).'.');
                }

                $stoke = $stoke ?: $this->getOrCreateStoke();

                $oldData = $sale->only([
                    'five_kg',
                    'twelve_kg',
                    'twentyfive_kg',
                    'thirtythree_kg',
                    'thirtyfive_kg',
                    'fourtyfive_kg',
                    'others_kg',
                    'empty_five_kg',
                    'empty_twelve_kg',
                    'empty_twentyfive_kg',
                    'empty_thirtythree_kg',
                    'empty_thirtyfive_kg',
                    'empty_fourtyfive_kg',
                    'empty_others_kg'
                ]);

                $customers = $this->normalizeCustomers($request->input('customer'));
                if (!empty($customers)) {
                    $sale->customer_name = $customers[0]['label'];
                    $sale->customer_id = $customers[0]['value'];
                    $customerDetails = Customers::find($customers[0]['value']);
                    if ($customerDetails) {
                        $sale->whole_sale = $customerDetails->is_retailer ? 1 : 0;
                    }
                }

                $sale->date = $request->date;
                $sale->is_due_bill = $request->is_due_bill ?? 0;
                $sale->is_package = $request->boolean('is_package');
                if ($request->exists('cylinder_price')) {
                    $sale->cylinder_price = $request->boolean('is_package') ? $this->toNumber($request->cylinder_price) : 0;
                }
                $sale->pay = $this->toNumber($request->pay);
                $sale->due = $this->toNumber($request->due);
                $sale->other_cost_name = $request->other_cost_name ?? '';
                $sale->other_cost_amount = $this->toNumber($request->other_cost_amount);

                $this->applyLinesToSale($sale, $lines);
                $sale->save();
                $this->syncLines($sale, $lines);

                $newData = $sale->only(array_keys($oldData));

                foreach (['five_kg', 'twelve_kg', 'twentyfive_kg', 'thirtythree_kg', 'thirtyfive_kg', 'fourtyfive_kg', 'others_kg'] as $col) {
                    $stoke->$col = max(0, ($stoke->$col + ($oldData[$col] ?? 0)) - ($newData[$col] ?? 0));
                }

                foreach (['empty_five_kg', 'empty_twelve_kg', 'empty_twentyfive_kg', 'empty_thirtythree_kg', 'empty_thirtyfive_kg', 'empty_fourtyfive_kg', 'empty_others_kg'] as $col) {
                    $stoke->$col = max(0, ($stoke->$col - ($oldData[$col] ?? 0)) + ($newData[$col] ?? 0));
                }

                $stoke->save();
            });

            return response()->json([
                'status' => true,
                'message' => 'Sale updated successfully'
            ], Response::HTTP_OK);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            \Log::error("Error updating sale: " . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function getSalesList(Request $request)
    {
        try {
            $nested = $request->input('params', []);
            $nested = is_array($nested) ? $nested : [];
            $merged = array_merge(
                $request->query(),
                $request->except(['params']),
                $nested
            );

            $perPage = (int) ($merged['per_page'] ?? 10);
            $perPage = min(max(1, $perPage), 100);
            $page = (int) ($merged['page'] ?? 1);
            $page = max(1, $page);

            $date = $merged['date'] ?? null;
            $startDate = $merged['start_date'] ?? null;
            $endDate = $merged['end_date'] ?? null;

            $search = isset($merged['search']) ? trim((string) $merged['search']) : '';
            if ($search === '' && isset($merged['customer_name'])) {
                $search = trim((string) $merged['customer_name']);
            }

            $isSale = filter_var($merged['isSale'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $isDueBill = $isSale ? false : true;

            $query = Sales::with(['customer', 'lines'])
                ->where('is_due_bill', $isDueBill);

            if ($startDate && $endDate) {
                $query->whereBetween('date', [$startDate, $endDate]);
            } elseif ($date) {
                $query->whereDate('date', $date);
            }

            if (array_key_exists('whole_sale', $merged)) {
                $query->where('whole_sale', filter_var($merged['whole_sale'], FILTER_VALIDATE_BOOLEAN));
            }

            if (array_key_exists('is_package', $merged)) {
                $query->where('is_package', filter_var($merged['is_package'], FILTER_VALIDATE_BOOLEAN));
            }

            if ($search !== '') {
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('sales.customer_name', 'like', $like)
                        ->orWhereHas('customer', function ($cq) use ($like) {
                            $cq->where('customers.name', 'like', $like);
                        });
                });
            }

            $totals = $this->sumSaleColumns(clone $query);

            $rangeQuery = Sales::query()->where('is_due_bill', $isDueBill);
            if ($startDate && $endDate) {
                $rangeQuery->whereBetween('date', [$startDate, $endDate]);
            } elseif ($date) {
                $rangeQuery->whereDate('date', $date);
            }

            $sales = $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'data' => $sales,
                'totals' => $totals,
                'summary' => $this->sumSaleColumns($rangeQuery),
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    private function sumSaleColumns($query): array
    {
        $columns = [
            'five_kg',
            'twelve_kg',
            'twentyfive_kg',
            'thirtythree_kg',
            'thirtyfive_kg',
            'fourtyfive_kg',
            'others_kg',
            'empty_five_kg',
            'empty_twelve_kg',
            'empty_twentyfive_kg',
            'empty_thirtythree_kg',
            'empty_thirtyfive_kg',
            'empty_fourtyfive_kg',
            'empty_others_kg',
        ];

        $selects = [
            DB::raw('COUNT(*) as sale_count'),
            DB::raw('COALESCE(SUM(CAST(COALESCE(price, 0) AS DECIMAL(14,2)) + CAST(COALESCE(other_cost_amount, 0) AS DECIMAL(14,2))), 0) as sale'),
            DB::raw('COALESCE(SUM(CAST(COALESCE(pay, 0) AS DECIMAL(14,2))), 0) as pay'),
            DB::raw('COALESCE(SUM(CAST(COALESCE(due, 0) AS DECIMAL(14,2))), 0) as due'),
        ];
        foreach ($columns as $column) {
            $selects[] = DB::raw("COALESCE(SUM(CAST(COALESCE(`{$column}`, 0) AS SIGNED)), 0) as `{$column}`");
        }

        $row = $query->clone()->setEagerLoads([])->select($selects)->first();

        $totals = [
            'sale_count' => (int) ($row->sale_count ?? 0),
            'sale' => (float) ($row->sale ?? 0),
            'pay' => (float) ($row->pay ?? 0),
            'due' => (float) ($row->due ?? 0),
        ];
        $emptyCount = 0;
        foreach ($columns as $column) {
            $totals[$column] = (int) ($row->{$column} ?? 0);
            if (str_starts_with($column, 'empty_')) {
                $emptyCount += $totals[$column];
            }
        }
        $totals['empty_count'] = $emptyCount;

        return $totals;
    }

    public function getCollectionList(Request $request)
    {
        try {
            $nested = $request->input('params', []);
            $nested = is_array($nested) ? $nested : [];
            $merged = array_merge(
                $request->query(),
                $request->except(['params']),
                $nested
            );

            $perPage = (int) ($merged['per_page'] ?? 20);
            $perPage = min(max(1, $perPage), 100);
            $page = (int) ($merged['page'] ?? 1);
            $page = max(1, $page);

            $startDate = $merged['start_date'] ?? null;
            $endDate = $merged['end_date'] ?? null;

            $search = isset($merged['search']) ? trim((string) $merged['search']) : '';

            $query = Sales::query()
                ->where('is_due_bill', true)
                ->join('customers', 'sales.customer_id', '=', 'customers.id')
                ->select('sales.*', 'customers.name as customer_name')
                ->orderBy('sales.created_at', 'desc');

            if ($startDate && $endDate) {
                $query->whereBetween('sales.date', [$startDate, $endDate]);
            }

            if ($search !== '') {
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('customers.name', 'like', $like)
                        ->orWhere('sales.date', 'like', $like);
                });
            }

            $allResult = $query->with('customer')->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'data' => $allResult,
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }




    public function getLastsale()
    {
        try {
            $sales = Sales::latest()->first();
            return response()->json($sales, Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function getCompares(Request $request)
    {
        try {

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

    }
    public function getStock()
    {
        try {
            $twelve_kg = Products::sum('twelve_kg') - Sales::sum('twelve_kg');
            $twentyfive_kg = Products::sum('twentyfive_kg') - Sales::sum('twentyfive_kg');
            $thirtythree_kg = Products::sum('thirtythree_kg') - Sales::sum('thirtythree_kg');
            $thirtyfive_kg = Products::sum('thirtyfive_kg') - Sales::sum('thirtyfive_kg');
            $fourtyfive_kg = Products::sum('fourtyfive_kg') - Sales::sum('fourtyfive_kg');

            $twelve_kg_empty = Sales::sum('empty_twelve_kg') - Products::sum('empty_twelve_kg');
            $twentyfive_kg_empty = Sales::sum('empty_twentyfive_kg') - Products::sum('empty_twentyfive_kg');
            $thirtythree_kg_empty = Sales::sum('empty_thirtythree_kg') - Products::sum('empty_thirtythree_kg');
            $thirtyfive_kg_empty = Sales::sum('empty_thirtyfive_kg') - Products::sum('empty_thirtyfive_kg');
            $fourtyfive_kg_empty = Sales::sum('empty_fourtyfive_kg') - Products::sum('empty_fourtyfive_kg');

            $filledStock = (object) [
                'twelve_kg' => $twelve_kg,
                'twentyfive_kg' => $twentyfive_kg,
                'thirtythree_kg' => $thirtythree_kg,
                'thirtyfive_kg' => $thirtyfive_kg,
                'fourtyfive_kg' => $fourtyfive_kg,
                'empty_twelve_kg' => $twelve_kg_empty,
                'empty_twentyfive_kg' => $twentyfive_kg_empty,
                'empty_thirtythree_kg' => $thirtythree_kg_empty,
                'empty_thirtyfive_kg' => $thirtyfive_kg_empty,
                'empty_fourtyfive_kg' => $fourtyfive_kg_empty,
            ];


            return response()->json($filledStock, Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function getTodaySales()
    {
        try {
            $bangladeshDate = Carbon::now()->setTimezone('Asia/Dhaka')->format('Y-m-d');
            $totalPrice = Sales::where('date', $bangladeshDate)->sum('price');
            return response()->json($totalPrice, Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function cash()
    {
        try {
            $bangladeshDate = Carbon::now()->setTimezone('Asia/Dhaka')->format('Y-m-d');
            $totalPrice = Sales::where('date', $bangladeshDate)->sum('pay');
            return response()->json($totalPrice, Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function monthsales()
    {
        try {
            $todayDate = Carbon::now()->setTimezone('Asia/Dhaka')->format('Y-m-d');
            $startDate = Carbon::now()->startOfMonth()->format('Y-m-01');
            $thisMonths = Sales::whereBetween('date', [$startDate, $todayDate])
                ->sum('price');
            return response()->json($thisMonths, Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function getRecent()
    {
        try {
            $recentSale = Sales::latest()->take(10)->get();
            return response()->json($recentSale, Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function customerStates(Request $request)
    {
        try {
            $userId = Auth::id();

            $days = $request->input('days', 30);
            $startDate = now()->subDays($days);

            $totals = Sales::where('customer_id', $userId)
                ->where('created_at', '>=', $startDate)
                ->selectRaw('
                    COALESCE(SUM(price),0) as totalBuy,
                    COALESCE(SUM(pay),0) as totalPay,
                    COALESCE(SUM(due),0) as totalDue,
                    COALESCE(SUM(twelve_kg),0) as twelve,
                    COALESCE(SUM(twentyfive_kg),0) as twentyfive,
                    COALESCE(SUM(thirtythree_kg),0) as thirtythree,
                    COALESCE(SUM(thirtyfive_kg),0) as thirtyfive,
                    COALESCE(SUM(fourtyfive_kg),0) as fourtyfive,
                    COALESCE(SUM(others_kg),0) as others
                ')
                ->first();

            $recentActivity = Sales::where('customer_id', $userId)
                ->where('created_at', '>=', $startDate)
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            return response()->json([
                'filterDays' => $days,
                'totalBuy' => $totals->totalBuy,
                'totalPay' => $totals->totalPay,
                'totalDue' => $totals->totalDue,
                'twelve' => $totals->twelve,
                'twentyfive' => $totals->twentyfive,
                'thirtythree' => $totals->thirtythree,
                'thirtyfive' => $totals->thirtyfive,
                'fourtyfive' => $totals->fourtyfive,
                'others' => $totals->others,
                'recentActivity' => $recentActivity,
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            \Log::error("Error getting customer states: " . $e->getMessage());
            return response()->json(['error' => 'Failed to get customer states'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function details(Request $request, $id)
    {
        try {
            $sales = Sales::where('customer_id', $id)
                ->selectRaw('SUM(CAST(price AS UNSIGNED)) as totalBuy, SUM(CAST(pay AS UNSIGNED)) as totalPay')
                ->first();

            $totalBuy = $sales->totalBuy ?? 0;
            $totalPay = $sales->totalPay ?? 0;
            $totalDue = $totalBuy - $totalPay;

            return response()->json([
                'totalBuy' => $totalBuy,
                'totalPay' => $totalPay,
                'totalDue' => $totalDue,
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function collection(Request $request)
    {
        try {
            $customer = Customers::find($request->customer);
            if (!$customer) {
                return response()->json(['error' => 'Customer not found'], Response::HTTP_NOT_FOUND);
            }

            if ($request->id) {
                $sale = Sales::find($request->id);
                if (!$sale) {
                    return response()->json(['error' => 'Sale not found'], Response::HTTP_NOT_FOUND);
                }

                $sale->customer_id = $request->customer;
                $sale->customer_name = $customer->name;
                $sale->pay = $request->pay;
                $sale->date = $request->date;
                $sale->is_due_bill = 1;
                $sale->save();

                return response()->json(['message' => 'Collection updated successfully'], Response::HTTP_OK);
            }

            $newCollection = new Sales();
            $newCollection->customer_id = $request->customer;
            $newCollection->customer_name = $customer->name;
            $newCollection->pay = $request->pay;
            $newCollection->date = $request->date;
            $newCollection->is_due_bill = 1;
            $newCollection->save();

            return response()->json(['message' => 'Collection added successfully'], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function emptyReturn(Request $request)
    {
        try {
            $customers = $this->normalizeCustomers($request->input('customer'));
            if (empty($customers)) {
                return response()->json(['error' => 'Customer is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (!$request->filled('date')) {
                return response()->json(['error' => 'Date is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $customer = Customers::find($customers[0]['value']);
            if (!$customer) {
                return response()->json(['error' => 'Customer not found'], Response::HTTP_NOT_FOUND);
            }

            $lines = [];
            foreach ($this->normalizeLines($request->input('lines')) as $line) {
                if ($line['empty_quantity'] <= 0) {
                    continue;
                }
                $lines[] = [
                    'size' => $line['size'],
                    'quantity' => 0,
                    'empty_quantity' => $line['empty_quantity'],
                    'unit_price' => 0,
                    'line_total' => 0,
                ];
            }

            if (empty($lines)) {
                return response()->json(['error' => 'Add at least one empty cylinder'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $due = $this->cylinderDueForCustomer($customer->id);
            $requested = [];
            foreach ($lines as $line) {
                $requested[$line['size']] = ($requested[$line['size']] ?? 0) + $line['empty_quantity'];
            }
            foreach ($requested as $size => $qty) {
                $allowed = $due[$size] ?? 0;
                if ($qty > $allowed) {
                    return response()->json([
                        'error' => $this->sizeLabel($size).' return cannot be greater than due ('.$allowed.')',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            DB::transaction(function () use ($request, $customer, $lines) {
                $sale = new Sales();
                $sale->customer_name = $customer->name;
                $sale->customer_id = $customer->id;
                $sale->date = $request->date;
                $sale->is_due_bill = 0;
                $sale->is_package = 0;
                $sale->whole_sale = $customer->is_retailer ? 1 : 0;
                $sale->pay = 0;
                $sale->due = 0;
                $sale->other_cost_name = '';
                $sale->other_cost_amount = 0;

                $this->applyLinesToSale($sale, $lines);
                $sale->save();
                if (Schema::hasTable('sale_lines')) {
                    $this->syncLines($sale, $lines);
                }

                $stoke = $this->getOrCreateStoke();
                foreach ($lines as $line) {
                    $emptyCol = $this->sizeMap()[$line['size']]['empty'];
                    $stoke->{$emptyCol} = ($stoke->{$emptyCol} ?? 0) + $line['empty_quantity'];
                }
                $stoke->save();
            });

            return response()->json(['message' => 'Empty cylinders saved'], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            \Log::error('Error saving empty return: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function deleteSale($id)
    {
        try {
            $sale = Sales::find($id);

            if (!$sale) {
                return response()->json(['error' => 'Sale not found'], Response::HTTP_NOT_FOUND);
            }

            $stoke = $this->getOrCreateStoke();

            $stoke->five_kg = ($stoke->five_kg ?? 0) + ($sale->five_kg ?? 0);
            $stoke->twelve_kg = $stoke->twelve_kg + ($sale->twelve_kg ?? 0);
            $stoke->twentyfive_kg = $stoke->twentyfive_kg + ($sale->twentyfive_kg ?? 0);
            $stoke->thirtythree_kg = $stoke->thirtythree_kg + ($sale->thirtythree_kg ?? 0);
            $stoke->thirtyfive_kg = $stoke->thirtyfive_kg + ($sale->thirtyfive_kg ?? 0);
            $stoke->fourtyfive_kg = $stoke->fourtyfive_kg + ($sale->fourtyfive_kg ?? 0);

            $stoke->empty_five_kg = $stoke->empty_five_kg - ($sale->empty_five_kg ?? 0);
            $stoke->empty_twelve_kg = $stoke->empty_twelve_kg - ($sale->empty_twelve_kg ?? 0);
            $stoke->empty_twentyfive_kg = $stoke->empty_twentyfive_kg - ($sale->empty_twentyfive_kg ?? 0);
            $stoke->empty_thirtythree_kg = $stoke->empty_thirtythree_kg - ($sale->empty_thirtythree_kg ?? 0);
            $stoke->empty_thirtyfive_kg = $stoke->empty_thirtyfive_kg - ($sale->empty_thirtyfive_kg ?? 0);
            $stoke->empty_fourtyfive_kg = $stoke->empty_fourtyfive_kg - ($sale->empty_fourtyfive_kg ?? 0);
            $stoke->save();

            $sale->lines()->delete();
            $sale->delete();

            return response()->json(['message' => 'Sale deleted successfully'], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function sizeLabel(string $size): string
    {
        return [
            'five_kg' => '5 KG',
            'twelve_kg' => '12 KG',
            'twentyfive_kg' => '25 KG',
            'thirtythree_kg' => '33 KG',
            'thirtyfive_kg' => '35 KG',
            'fourtyfive_kg' => '45 KG',
        ][$size] ?? $size;
    }

    private function cylinderDueForCustomer($customerId): array
    {
        $due = [];
        foreach (array_keys($this->sizeMap()) as $size) {
            $due[$size] = 0;
        }

        $sales = Sales::where('customer_id', $customerId)->get();
        foreach ($sales as $sale) {
            foreach ($this->sizeMap() as $size => $cols) {
                $purchased = (int) $sale->{$size};
                $returned = $sale->is_package ? $purchased : (int) $sale->{$cols['empty']};
                $due[$size] += $purchased - $returned;
            }
        }

        foreach ($due as $size => $value) {
            $due[$size] = max(0, $value);
        }

        return $due;
    }

    private function filledQuantities(array $lines): array
    {
        $quantities = [];
        foreach (array_keys($this->sizeMap()) as $size) {
            $quantities[$size] = 0;
        }
        foreach ($lines as $line) {
            if (!array_key_exists($line['size'], $quantities)) {
                continue;
            }
            $quantities[$line['size']] += (int) $line['quantity'];
        }

        return $quantities;
    }

    private function stockShortageMessage($stoke, array $neededBySize): ?string
    {
        $parts = [];
        foreach ($neededBySize as $size => $needed) {
            if ($needed <= 0) {
                continue;
            }
            $available = $stoke ? (int) ($stoke->{$size} ?? 0) : 0;
            if ($needed > $available) {
                $parts[] = $this->sizeLabel($size).': available '.$available.', requested '.$needed;
            }
        }

        if (!$parts) {
            return null;
        }

        return 'Not enough stock. '.implode('. ', $parts).'.';
    }

    private function sizeMap(): array
    {
        return [
            'five_kg' => ['empty' => 'empty_five_kg', 'price' => 'price_5_kg'],
            'twelve_kg' => ['empty' => 'empty_twelve_kg', 'price' => 'price_12_kg'],
            'twentyfive_kg' => ['empty' => 'empty_twentyfive_kg', 'price' => 'price_25_kg'],
            'thirtythree_kg' => ['empty' => 'empty_thirtythree_kg', 'price' => 'price_33_kg'],
            'thirtyfive_kg' => ['empty' => 'empty_thirtyfive_kg', 'price' => 'price_35_kg'],
            'fourtyfive_kg' => ['empty' => 'empty_fourtyfive_kg', 'price' => 'price_45_kg'],
        ];
    }

    private function linesFromRequest(Request $request): array
    {
        if ($request->exists('lines')) {
            return $this->normalizeLines($request->input('lines'));
        }

        $lines = [];
        foreach ($this->sizeMap() as $size => $cols) {
            $quantity = $this->toNumber($request->input($size));
            $empty = $this->toNumber($request->input($cols['empty']));
            $unitPrice = $this->toNumber($request->input($cols['price']));
            if ($quantity === 0 && $empty === 0) {
                continue;
            }
            $lines[] = [
                'size' => $size,
                'quantity' => $quantity,
                'empty_quantity' => $empty,
                'unit_price' => $unitPrice,
                'line_total' => $quantity * $unitPrice,
            ];
        }

        return $lines;
    }

    private function normalizeLines($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $allowed = $this->sizeMap();
        $lines = [];
        foreach ($input as $line) {
            if (!is_array($line)) {
                continue;
            }
            $size = $line['size'] ?? '';
            if (!isset($allowed[$size])) {
                continue;
            }
            $quantity = $this->toNumber($line['quantity'] ?? 0);
            $empty = $this->toNumber($line['empty_quantity'] ?? 0);
            $unitPrice = $this->toNumber($line['unit_price'] ?? 0);
            if ($quantity === 0 && $empty === 0) {
                continue;
            }
            $lines[] = [
                'size' => $size,
                'quantity' => $quantity,
                'empty_quantity' => $empty,
                'unit_price' => $unitPrice,
                'line_total' => $quantity * $unitPrice,
            ];
        }

        return $lines;
    }

    private function applyLinesToSale(Sales $sale, array $lines): void
    {
        foreach ($this->sizeMap() as $size => $cols) {
            $sale->{$size} = 0;
            $sale->{$cols['empty']} = 0;
            $sale->{$cols['price']} = 0;
        }

        $total = 0;
        foreach ($lines as $line) {
            $cols = $this->sizeMap()[$line['size']];
            $sale->{$line['size']} = (int) $sale->{$line['size']} + $line['quantity'];
            $sale->{$cols['empty']} = (int) $sale->{$cols['empty']} + $line['empty_quantity'];
            $sale->{$cols['price']} = (int) $sale->{$cols['price']} + $line['line_total'];
            $total += $line['line_total'];
        }

        if ($sale->is_package) {
            $total += (int) ($sale->cylinder_price ?? 0);
        }

        $sale->price = $total;
    }

    private function syncLines(Sales $sale, array $lines): void
    {
        $sale->lines()->delete();
        foreach ($lines as $line) {
            $sale->lines()->create($line);
        }
    }
}
