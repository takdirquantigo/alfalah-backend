<?php

namespace App\Http\Controllers;

use App\Models\Customers;
use App\Models\Products;
use App\Models\Sales;
use App\Models\Stoke;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CustomersController extends Controller
{
    public function addCustomer(Request $request)
    {
        try {
            $id = $request->input('id');
            $isUpdate = (bool) $id;

            if ($isUpdate) {
                $customer = Customers::find($id);
                if (! $customer) {
                    return response()->json(['error' => 'Customer not found'], Response::HTTP_NOT_FOUND);
                }
            } else {
                $request->validate([
                    'name' => 'required|string|max:255',
                    'password' => 'required|string|min:6',
                ]);

                if (! $request->boolean('force')) {
                    $duplicates = $this->findDuplicateCustomers(
                        $request->input('name'),
                        $request->input('phone'),
                        $request->input('address')
                    );

                    if (! empty($duplicates['matches'])) {
                        $hasPhone = ! empty($duplicates['phone_matches']);

                        return response()->json([
                            'error' => $hasPhone
                                ? 'A customer with this phone number already exists'
                                : 'A similar customer already exists (60%+ name/address match)',
                            'code' => 'duplicate_customer',
                            'requires_confirmation' => true,
                            'phone_matches' => $duplicates['phone_matches'],
                            'similar_matches' => $duplicates['similar_matches'],
                            'matches' => $duplicates['matches'],
                        ], Response::HTTP_CONFLICT);
                    }
                }

                $customer = new Customers;
            }

            $customer->name = $request->name;
            $customer->phone = $request->phone;
            $customer->address = $request->address;
            $customer->is_retailer = $request->boolean('is_retailer') ? 1 : 0;

            if ($request->filled('password')) {
                $request->validate(['password' => 'string|min:6']);
                $customer->password = Hash::make($request->password);
            }

            if (empty($customer->customer_login_id)) {
                $customer->customer_login_id = $this->generateUniqueCustomerLoginId();
            }

            $customer->save();

            $payload = [
                'message' => 'Customer '.($isUpdate ? 'updated' : 'added').' successfully',
                'customer_login_id' => $customer->customer_login_id,
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'is_retailer' => (int) $customer->is_retailer,
                ],
            ];

            return response()->json($payload, Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->getMessage(), 'errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            \Log::error('Error saving customer: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Unique 6-digit numeric ID for customer portal login.
     */
    private function generateUniqueCustomerLoginId(): string
    {
        for ($i = 0; $i < 40; $i++) {
            $candidate = (string) random_int(100000, 999999);
            if (! Customers::where('customer_login_id', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not generate unique customer login id');
    }

    private function normalizePhone($phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    private function phonesMatch($a, $b): bool
    {
        $na = $this->normalizePhone($a);
        $nb = $this->normalizePhone($b);
        if ($na === '' || $nb === '') {
            return false;
        }
        if ($na === $nb) {
            return true;
        }

        $ta = substr($na, -11);
        $tb = substr($nb, -11);
        if (strlen($ta) >= 10 && $ta === $tb) {
            return true;
        }

        return str_ends_with($na, $nb) || str_ends_with($nb, $na);
    }

    private function similarityPercent(string $a, string $b): float
    {
        $a = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $a)));
        $b = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $b)));
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 100.0;
        }

        similar_text($a, $b, $percent);

        return (float) $percent;
    }

    private function detailsMatchPercent($name, $address, $otherName, $otherAddress): float
    {
        $nameScore = $this->similarityPercent((string) $name, (string) $otherName);
        $addrA = trim((string) $address);
        $addrB = trim((string) $otherAddress);

        if ($addrA === '' || $addrB === '') {
            return $nameScore;
        }

        $addressScore = $this->similarityPercent($addrA, $addrB);

        return ($nameScore + $addressScore) / 2;
    }

    private function customerMatchPayload($customer, $percent = null, string $reason = 'similar'): array
    {
        $row = [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'address' => $customer->address,
            'customer_login_id' => $customer->customer_login_id,
            'match_reason' => $reason,
        ];

        if ($percent !== null) {
            $row['match_percent'] = round((float) $percent, 1);
        }

        return $row;
    }

    private function findDuplicateCustomers($name, $phone, $address, $excludeId = null): array
    {
        $customers = Customers::query()
            ->select('id', 'name', 'phone', 'address', 'customer_login_id')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();

        $phoneMatches = [];
        $similarMatches = [];
        $seen = [];

        foreach ($customers as $customer) {
            if ($this->phonesMatch($phone, $customer->phone)) {
                $phoneMatches[] = $this->customerMatchPayload($customer, 100, 'phone');
                $seen[$customer->id] = true;
            }
        }

        foreach ($customers as $customer) {
            $percent = $this->detailsMatchPercent($name, $address, $customer->name, $customer->address);
            if ($percent < 60) {
                continue;
            }
            if (isset($seen[$customer->id])) {
                continue;
            }
            $similarMatches[] = $this->customerMatchPayload($customer, $percent, 'similar');
            $seen[$customer->id] = true;
        }

        usort($similarMatches, fn ($a, $b) => ($b['match_percent'] ?? 0) <=> ($a['match_percent'] ?? 0));
        $similarMatches = array_slice($similarMatches, 0, 8);

        return [
            'phone_matches' => $phoneMatches,
            'similar_matches' => $similarMatches,
            'matches' => array_values(array_merge($phoneMatches, $similarMatches)),
        ];
    }

    public function getCustomer()
    {
        $customer = Customers::all();
        if (sizeof($customer) == 0) {
            return response()->json(['error' => 'No data found'], Response::HTTP_NOT_FOUND);
        }
        return $customer;
    }




    public function getCustomerWithSum(Request $request)
    {
        $params = $request->input('params', []);
        $page = $params['page'] ?? $request->input('page', 1);
        $perPage = $params['per_page'] ?? $request->input('per_page', 10);
        $name = $params['search'] ?? $request->input('search', '');

        $customers = Customers::with([
            'sales' => function ($query) {
                $query->orderByDesc('created_at');
            }
        ]);

        if ($name) {
            $customers->where(function ($query) use ($name) {
                $query->where('name', 'like', '%' . $name . '%')
                    ->orWhere('phone', 'like', '%' . $name . '%')
                    ->orWhere('address', 'like', '%' . $name . '%');
            });
        }


        $customers = $customers->paginate($perPage, ['*'], 'page', $page);

        $customersTransformed = $customers->getCollection()->map(function ($customer) {
            $totalBuy = $customer->sales->sum('price');
            $totalPay = $customer->sales->sum('pay');
            $totalDue = $customer->sales->sum('due');

            $purchaseCylinders = [
                'five_kg' => $customer->sales->sum(fn($s) => (int) $s->five_kg),
                'twelve_kg' => $customer->sales->sum(fn($s) => (int) $s->twelve_kg),
                'twentyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->twentyfive_kg),
                'thirtythree_kg' => $customer->sales->sum(fn($s) => (int) $s->thirtythree_kg),
                'thirtyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->thirtyfive_kg),
                'fourtyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->fourtyfive_kg),
                'others_kg' => $customer->sales->sum(fn($s) => (int) $s->others_kg),
            ];

            $emptyCylinders = [
                'empty_five_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_five_kg),
                'empty_twelve_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_twelve_kg),
                'empty_twentyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_twentyfive_kg),
                'empty_thirtythree_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_thirtythree_kg),
                'empty_thirtyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_thirtyfive_kg),
                'empty_fourtyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_fourtyfive_kg),
                'empty_others_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_others_kg),
            ];

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'customer_login_id' => $customer->customer_login_id,
                'is_retailer' => (int) ($customer->is_retailer ?? 0),
                'total_purchase' => $totalBuy,
                'total_paid' => $totalPay,
                'total_due' => $totalDue,
                'purchase_cylinders' => $purchaseCylinders,
                'empty_cylinders' => $emptyCylinders,
            ];
        });

        // Pagination এর collection override করে দিলাম
        $customers->setCollection($customersTransformed);

        // Grand totals সব পেইজ মিলিয়ে বের করতে চাইলে আলাদা query চালাতে হবে
        $allCustomers = Customers::with('sales');
        if ($name) {
            $allCustomers->where('name', 'like', '%' . $name . '%');
        }
        $allData = $allCustomers->get();

        $customersWithSumAll = $allData->map(function ($customer) {
            return [
                'total_purchase' => $customer->sales->sum('price'),
                'total_paid' => $customer->sales->sum('pay'),
                'total_due' => $customer->sales->sum('price') - $customer->sales->sum('pay'),
                'purchase_cylinders' => [
                    'twelve_kg' => $customer->sales->sum(fn($s) => (int) $s->twelve_kg),
                    'twentyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->twentyfive_kg),
                    'thirtythree_kg' => $customer->sales->sum(fn($s) => (int) $s->thirtythree_kg),
                    'thirtyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->thirtyfive_kg),
                    'fourtyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->fourtyfive_kg),
                    'others_kg' => $customer->sales->sum(fn($s) => (int) $s->others_kg),
                ],
                'empty_cylinders' => [
                    'empty_twelve_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_twelve_kg),
                    'empty_twentyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_twentyfive_kg),
                    'empty_thirtythree_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_thirtythree_kg),
                    'empty_thirtyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_thirtyfive_kg),
                    'empty_fourtyfive_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_fourtyfive_kg),
                    'empty_others_kg' => $customer->sales->sum(fn($s) => (int) $s->empty_others_kg),
                ],
            ];
        });

        $grandTotals = [
            'purchase' => $customersWithSumAll->sum('total_purchase'),
            'paid' => $customersWithSumAll->sum('total_paid'),
            'due' => $customersWithSumAll->sum('total_purchase') - $customersWithSumAll->sum('total_paid'),
            'purchase_cylinders' => [
                'twelve_kg' => $customersWithSumAll->sum(fn($c) => $c['purchase_cylinders']['twelve_kg']),
                'twentyfive_kg' => $customersWithSumAll->sum(fn($c) => $c['purchase_cylinders']['twentyfive_kg']),
                'thirtythree_kg' => $customersWithSumAll->sum(fn($c) => $c['purchase_cylinders']['thirtythree_kg']),
                'thirtyfive_kg' => $customersWithSumAll->sum(fn($c) => $c['purchase_cylinders']['thirtyfive_kg']),
                'fourtyfive_kg' => $customersWithSumAll->sum(fn($c) => $c['purchase_cylinders']['fourtyfive_kg']),
                'others_kg' => $customersWithSumAll->sum(fn($c) => $c['purchase_cylinders']['others_kg']),
            ],
            'empty_cylinders' => [
                'empty_twelve_kg' => $customersWithSumAll->sum(fn($c) => $c['empty_cylinders']['empty_twelve_kg']),
                'empty_twentyfive_kg' => $customersWithSumAll->sum(fn($c) => $c['empty_cylinders']['empty_twentyfive_kg']),
                'empty_thirtythree_kg' => $customersWithSumAll->sum(fn($c) => $c['empty_cylinders']['empty_thirtythree_kg']),
                'empty_thirtyfive_kg' => $customersWithSumAll->sum(fn($c) => $c['empty_cylinders']['empty_thirtyfive_kg']),
                'empty_fourtyfive_kg' => $customersWithSumAll->sum(fn($c) => $c['empty_cylinders']['empty_fourtyfive_kg']),
                'empty_others_kg' => $customersWithSumAll->sum(fn($c) => $c['empty_cylinders']['empty_others_kg']),
            ],
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'customers' => $customers,
                'grand_totals' => $grandTotals,
            ]
        ], 200);
    }



    public function lastCustomers(Request $request)
    {
        $limit = $request->input('limit');
        try {
            if ($limit) {
                $recentCustomer = Customers::latest()->take(5)->get();
                return response()->json($recentCustomer, Response::HTTP_OK);
            }
            $customer = Customers::all();
            return $customer;
        } catch (\Exception $e) {
            \Log::error('Error getting last customer: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }


    }


    public function updateCustomer(Request $request, $id)
    {

        try {
            $customer = Customers::find($id);
            if (! $customer) {
                return response()->json(['error' => 'Customer not found'], Response::HTTP_NOT_FOUND);
            }
            $customer->name = $request->name;
            $customer->is_retailer = $request->boolean('is_retailer') ? 1 : 0;
            $customer->save();
            return response()->json([
                'message' => 'Customer updated successfully',
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'is_retailer' => (int) $customer->is_retailer,
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            \Log::error('Error updating expense: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function deleteCustomer($id)
    {
        try {
            $customer = Customers::find($id);
            if (! $customer) {
                return response()->json(['error' => 'Customer not found'], Response::HTTP_NOT_FOUND);
            }

            $reversed = [
                'filled' => [],
                'empty' => [],
            ];

            DB::transaction(function () use ($customer, &$reversed) {
                $sales = Sales::where('customer_id', $customer->id)->get();
                $stoke = Stoke::latest()->first();

                $filledTotals = [];
                $emptyTotals = [];

                foreach ($this->cylinderSizePairs() as $filled => $empty) {
                    $filledTotals[$filled] = 0;
                    $emptyTotals[$empty] = 0;
                }

                foreach ($sales as $sale) {
                    foreach ($this->cylinderSizePairs() as $filled => $empty) {
                        $filledTotals[$filled] += (int) ($sale->{$filled} ?? 0);
                        $emptyTotals[$empty] += (int) ($sale->{$empty} ?? 0);
                    }
                }

                if ($stoke) {
                    foreach ($this->cylinderSizePairs() as $filled => $empty) {
                        $stoke->{$filled} = (int) ($stoke->{$filled} ?? 0) + $filledTotals[$filled];
                        $stoke->{$empty} = max(0, (int) ($stoke->{$empty} ?? 0) - $emptyTotals[$empty]);
                    }
                    $stoke->save();
                }

                $reversed['filled'] = $filledTotals;
                $reversed['empty'] = $emptyTotals;

                Sales::where('customer_id', $customer->id)->delete();
                if (method_exists($customer, 'tokens')) {
                    $customer->tokens()->delete();
                }
                $customer->delete();
            });

            return response()->json([
                'message' => 'Customer deleted and cylinder stock reversed',
                'reversed_stock' => $reversed,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            \Log::error('Error deleting customer: '.$e->getMessage());

            return response()->json(['error' => 'Failed to delete customer'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function allCustomers()
    {
        $customers = Customers::orderBy('name', 'asc')->get();

        return response()->json([
            'data' => $customers
        ], Response::HTTP_OK);
    }

    public function details(Request $request, $id)
    {
        try {
            $customer = Customers::find($id);

            if (!$customer) {
                return response()->json([
                    'error' => 'No data found for the given ID'
                ], Response::HTTP_NOT_FOUND);
            }

            $query = Sales::where('customer_id', $id);

            if ($request->has('start_date') && $request->has('end_date')) {
                $startDate = Carbon::parse($request->start_date)->startOfDay();
                $endDate = Carbon::parse($request->end_date)->endOfDay();

                $query->whereBetween('created_at', [$startDate, $endDate]);
            }

            $sales = $query->limit(20)->orderByDesc('created_at')->get();

            if ($sales->isEmpty()) {
                return response()->json([
                    'data' => $customer,
                    'analytics' => [],
                    'message' => 'No sales found for this period'
                ], Response::HTTP_OK);
            }

            $totalSalesCount = $sales->count();
            $totalQuantity = [
                '5kg' => $sales->sum('five_kg'),
                '12kg' => $sales->sum('twelve_kg'),
                '25kg' => $sales->sum('twentyfive_kg'),
                '33kg' => $sales->sum('thirtythree_kg'),
                '35kg' => $sales->sum('thirtyfive_kg'),
                '45kg' => $sales->sum('fourtyfive_kg'),
                'others' => $sales->sum('others_kg'),
            ];

            $emptyReturn = [
                '5kg' => $sales->sum('empty_five_kg'),
                '12kg' => $sales->sum('empty_twelve_kg'),
                '25kg' => $sales->sum('empty_twentyfive_kg'),
                '33kg' => $sales->sum('empty_thirtythree_kg'),
                '35kg' => $sales->sum('empty_thirtyfive_kg'),
                '45kg' => $sales->sum('empty_fourtyfive_kg'),
                'others' => $sales->sum('empty_others_kg'),
            ];

            $packageSales = $sales->filter(fn ($sale) => (bool) $sale->is_package);

            $packageQuantities = [
                '5kg' => $packageSales->sum('five_kg'),
                '12kg' => $packageSales->sum('twelve_kg'),
                '25kg' => $packageSales->sum('twentyfive_kg'),
                '33kg' => $packageSales->sum('thirtythree_kg'),
                '35kg' => $packageSales->sum('thirtyfive_kg'),
                '45kg' => $packageSales->sum('fourtyfive_kg'),
                'others' => $packageSales->sum('others_kg'),
            ];

            $packageCount = array_sum($packageQuantities);

            $totalAmount = $sales->sum('price');
            $totalPaid = $sales->sum('pay');
            $totalDue = $sales->sum('due');

            // Final response
            return response()->json([
                'customer' => $customer,
                'analytics' => [
                    'sales_count' => $totalSalesCount,
                    'quantities' => $totalQuantity,
                    'empty_return' => $emptyReturn,
                    'package_count' => $packageCount,
                    'package_quantities' => $packageQuantities,
                    'total_amount' => $totalAmount,
                    'total_paid' => $totalPaid,
                    'total_due' => $totalDue,
                ],
                'sales' => $sales,
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            \Log::error("Error fetching analytics: " . $e->getMessage());

            return response()->json([
                'error' => 'Failed to fetch analytics'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    public function dashboard(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $today = Carbon::now('Asia/Dhaka')->toDateString();

        $dateFilter = '';
        if ($startDate && $endDate) {
            $dateFilter = "WHERE date BETWEEN '{$startDate}' AND '{$endDate}'";
        }

        $total = DB::select("
            SELECT 
                COALESCE(SUM(CAST(price AS DECIMAL(20,2))), 0) AS total_sale,
                COALESCE(SUM(CAST(pay AS DECIMAL(20,2))), 0) AS total_paid,
                COALESCE(SUM(CAST(due AS DECIMAL(20,2))), 0) AS total_due
            FROM sales
            {$dateFilter}
        ");
        $columns = [
            'twelve_kg',
            'twentyfive_kg',
            'thirtythree_kg',
            'thirtyfive_kg',
            'fourtyfive_kg',
            'others_kg',
            'empty_twelve_kg',
            'empty_twentyfive_kg',
            'empty_thirtythree_kg',
            'empty_thirtyfive_kg',
            'empty_fourtyfive_kg',
            'empty_others_kg'
        ];

        $sumColumns = function ($cols) {
            return implode(', ', array_map(fn($col) => "SUM(CAST($col AS DECIMAL(20,2))) as $col", $cols));
        };

        $salesQuery = Sales::selectRaw($sumColumns($columns));
        if ($startDate && $endDate) {
            $salesQuery->whereBetween('date', [$startDate, $endDate]);
        }

        $productQuery = Products::selectRaw($sumColumns($columns));
        if ($startDate && $endDate) {
            $productQuery->whereBetween('date', [$startDate, $endDate]);
        }
        $todayTotals = Sales::where('date', $today)
            ->selectRaw("
                COALESCE(SUM(CAST(price AS DECIMAL(20,2))), 0) AS total_sale,
                COALESCE(SUM(CAST(pay AS DECIMAL(20,2))), 0) AS total_paid,
                COALESCE(SUM(CAST(due AS DECIMAL(20,2))), 0) AS total_due
            ")
            ->first();

        $now = Carbon::now('Asia/Dhaka');
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();
        $monthTotals = Sales::whereBetween('date', [$monthStart, $monthEnd])
            ->selectRaw("
                COALESCE(SUM(CAST(price AS DECIMAL(20,2))), 0) AS total_sale,
                COALESCE(SUM(CAST(pay AS DECIMAL(20,2))), 0) AS total_paid,
                COALESCE(SUM(CAST(due AS DECIMAL(20,2))), 0) AS total_due
            ")
            ->first();

        $todaySale = $todayTotals?->total_sale ?? 0;
        $todayPaid = $todayTotals?->total_paid ?? 0;
        $todayDue = $todayTotals?->total_due ?? 0;
        $collectBill = Sales::where('date', $today)->where('is_due_bill', 1)->sum('pay');
        $totalCustomer = Customers::count();

        $topCustomers = DB::select("
            SELECT 
                customer_id, 
                customer_name, 
                COALESCE(SUM(CAST(price AS DECIMAL(20,2))), 0) AS total_bought
            FROM sales
            {$dateFilter}
            GROUP BY customer_id, customer_name
            ORDER BY total_bought DESC
            LIMIT 4
        ");
        $currentStoke = Stoke::first();

        return response()->json([
            'total_customer' => $totalCustomer,
            'today_paid' => $todayPaid,
            'today_due' => $todayDue,
            'collectBill' => $collectBill,
            'today_sale' => $todaySale,
            'total_sale' => $total[0]->total_sale ?? 0,
            'total_paid' => $total[0]->total_paid ?? 0,
            'total_due' => $total[0]->total_due ?? 0,
            'month_sale' => $monthTotals?->total_sale ?? 0,
            'month_paid' => $monthTotals?->total_paid ?? 0,
            'month_due' => $monthTotals?->total_due ?? 0,
            'current_stock' => $currentStoke,
            'top_customers' => $topCustomers,
        ]);
    }

    public function dailySales(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $perPage = 10;
        $page = max(1, (int) $request->query('page', 1));

        $base = Sales::query();
        if ($startDate && $endDate) {
            $base->whereBetween('date', [$startDate, $endDate]);
        }

        $totalDays = (clone $base)->distinct()->count('date');

        $rows = (clone $base)
            ->selectRaw("
                date,
                COALESCE(SUM(CAST(twelve_kg AS SIGNED)), 0) AS twelve_kg,
                COALESCE(SUM(CAST(empty_twelve_kg AS SIGNED)), 0) AS empty_twelve_kg,
                COALESCE(SUM(CAST(thirtyfive_kg AS SIGNED)), 0) AS thirtyfive_kg,
                COALESCE(SUM(CAST(empty_thirtyfive_kg AS SIGNED)), 0) AS empty_thirtyfive_kg,
                COALESCE(SUM(CAST(fourtyfive_kg AS SIGNED)), 0) AS fourtyfive_kg,
                COALESCE(SUM(CAST(empty_fourtyfive_kg AS SIGNED)), 0) AS empty_fourtyfive_kg,
                COALESCE(SUM(CAST(price AS DECIMAL(20,2))), 0) AS amount,
                COALESCE(SUM(CAST(pay AS DECIMAL(20,2))), 0) AS paid,
                COALESCE(SUM(CAST(due AS DECIMAL(20,2))), 0) AS due
            ")
            ->groupBy('date')
            ->orderByDesc('date')
            ->forPage($page, $perPage)
            ->get();

        $paginator = new LengthAwarePaginator(
            $rows,
            $totalDays,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginator);
    }

    public function dailyDues(Request $request)
    {
        $date = $request->query('date');
        if (!$date) {
            return response()->json(['data' => []]);
        }

        $rows = Sales::query()
            ->where('date', $date)
            ->whereRaw('CAST(due AS DECIMAL(20,2)) > 0')
            ->orderBy('customer_name')
            ->get([
                'id',
                'customer_name',
                'twelve_kg',
                'empty_twelve_kg',
                'thirtyfive_kg',
                'empty_thirtyfive_kg',
                'fourtyfive_kg',
                'empty_fourtyfive_kg',
                'price',
                'pay',
                'due',
                'date',
            ]);

        return response()->json(['data' => $rows]);
    }

    public function inactiveCustomers(Request $request)
    {
        $start = $request->input('start_date');
        $end = $request->input('end_date');

        if (!$start || !$end) {
            $end = now();
            $start = now()->subMonths(3);
        } else {
            $start = Carbon::parse($start);
            $end = Carbon::parse($end);
        }

        $perPage = (int) $request->input('per_page', 15);

        $totalPriceSub = function ($query) {
            $query->from('sales')
                ->selectRaw(
                    "COALESCE(SUM(CAST(REGEXP_REPLACE(price, '[^0-9.]', '') AS DECIMAL(15,2))), 0)"
                )
                ->whereColumn('sales.customer_id', 'customers.id');
        };

        $totalPaySub = function ($query) {
            $query->from('sales')
                ->selectRaw(
                    "COALESCE(SUM(CAST(REGEXP_REPLACE(pay, '[^0-9.]', '') AS DECIMAL(15,2))), 0)"
                )
                ->whereColumn('sales.customer_id', 'customers.id');
        };


        $customers = Customers::select('customers.*')
            ->selectSub($totalPriceSub, 'total_price')
            ->selectSub($totalPaySub, 'total_pay')
            ->whereDoesntHave('sales', function ($q) use ($start, $end) {
                $q->whereBetween('date', [$start->toDateString(), $end->toDateString()]);
            })
            ->paginate($perPage);

        $customers->getCollection()->transform(function ($c) {
            $c->total_price = (float) $c->total_price;
            $c->total_pay = (float) $c->total_pay;
            $c->due = round($c->total_price - $c->total_pay, 2);
            return $c;
        });

        return response()->json([
            'status' => true,
            'data' => $customers,
            'date_range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
        ]);
    }

    /**
     * Customers with positive receivable balance: SUM(price) - SUM(pay) over all sales rows
     * (includes is_due_bill payment rows so partial due payments reduce the balance).
     */
    public function dueBalances(Request $request)
    {
        try {
            $page = max(1, (int) $request->input('page', 1));
            $perPage = min(max(1, (int) $request->input('per_page', 20)), 100);
            $search = $request->input('search', '');
            $search = is_string($search) ? trim($search) : '';

            $inner = DB::table('sales as s')
                ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
                ->selectRaw("
                    s.customer_id,
                    COALESCE(MAX(c.name), MAX(s.customer_name), 'Unknown') AS customer_name,
                    COALESCE(MAX(c.phone), '') AS phone,
                    COALESCE(SUM(CAST(s.price AS DECIMAL(15,2))), 0) AS total_price,
                    COALESCE(SUM(CAST(s.pay AS DECIMAL(15,2))), 0) AS total_pay,
                    (COALESCE(SUM(CAST(s.price AS DECIMAL(15,2))), 0) - COALESCE(SUM(CAST(s.pay AS DECIMAL(15,2))), 0)) AS outstanding_due
                ")
                ->groupBy('s.customer_id')
                ->havingRaw('(COALESCE(SUM(CAST(s.price AS DECIMAL(15,2))), 0) - COALESCE(SUM(CAST(s.pay AS DECIMAL(15,2))), 0)) > 0.009');

            $outer = DB::query()->fromSub($inner, 'balances');

            if ($search !== '') {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $outer->where(function ($q) use ($like) {
                    $q->where('balances.customer_name', 'like', $like)
                        ->orWhere('balances.phone', 'like', $like)
                        ->orWhere('balances.customer_id', 'like', $like);
                });
            }

            $total = (clone $outer)->count();
            $filteredOutstanding = (float) ((clone $outer)->sum('outstanding_due') ?? 0);

            $rows = (clone $outer)
                ->orderByDesc('outstanding_due')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get()
                ->map(function ($row) {
                    return [
                        'customer_id' => $row->customer_id,
                        'customer_name' => $row->customer_name,
                        'phone' => $row->phone,
                        'total_price' => round((float) $row->total_price, 2),
                        'total_pay' => round((float) $row->total_pay, 2),
                        'outstanding_due' => round((float) $row->outstanding_due, 2),
                    ];
                });

            $paginator = new LengthAwarePaginator(
                $rows,
                $total,
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'page']
            );

            return response()->json([
                'status' => true,
                'summary' => [
                    'filtered_outstanding' => round($filteredOutstanding, 2),
                    'customers_with_due_count' => $total,
                ],
                'data' => $paginator,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            \Log::error('dueBalances: '.$e->getMessage());

            return response()->json(['error' => 'Failed to load due customers'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Cylinder size pairs: filled qty vs empty return qty.
     */
    private function cylinderSizePairs(): array
    {
        return [
            'five_kg' => 'empty_five_kg',
            'twelve_kg' => 'empty_twelve_kg',
            'twentyfive_kg' => 'empty_twentyfive_kg',
            'thirtythree_kg' => 'empty_thirtythree_kg',
            'thirtyfive_kg' => 'empty_thirtyfive_kg',
            'fourtyfive_kg' => 'empty_fourtyfive_kg',
            'others_kg' => 'empty_others_kg',
        ];
    }

    /**
     * Effective empty return for a size: package sales count filled qty as returned
     * (purchase and empty are the same). Non-package uses the empty_* column.
     */
    private function cylinderEmptySql(string $filledCol, string $emptyCol): string
    {
        return "CASE WHEN COALESCE(s.is_package, 0) = 1 THEN CAST(s.{$filledCol} AS SIGNED) ELSE CAST(s.{$emptyCol} AS SIGNED) END";
    }

    /**
     * Customers with cylinder due: overall SUM(purchased) - SUM(effective empty)
     * per size across all sales. Package rows treat empty = purchased.
     */
    public function cylinderDues(Request $request)
    {
        try {
            $page = max(1, (int) $request->input('page', 1));
            $perPage = min(max(1, (int) $request->input('per_page', 20)), 100);
            $search = $request->input('search', '');
            $search = is_string($search) ? trim($search) : '';

            $sizeSelects = [];
            $dueParts = [];

            foreach ($this->cylinderSizePairs() as $filled => $empty) {
                $purchased = "COALESCE(SUM(CAST(s.{$filled} AS SIGNED)), 0)";
                $returned = "COALESCE(SUM(".$this->cylinderEmptySql($filled, $empty)."), 0)";
                $due = "GREATEST({$purchased} - {$returned}, 0)";

                $sizeSelects[] = "{$purchased} AS purchased_{$filled}";
                $sizeSelects[] = "{$returned} AS returned_{$filled}";
                $sizeSelects[] = "{$due} AS due_{$filled}";
                $dueParts[] = $due;
            }

            $totalDueSql = '('.implode(' + ', $dueParts).')';
            $sizeSelectSql = implode(",\n                    ", $sizeSelects);

            $inner = DB::table('sales as s')
                ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
                ->selectRaw("
                    s.customer_id,
                    COALESCE(MAX(c.name), MAX(s.customer_name), 'Unknown') AS customer_name,
                    COALESCE(MAX(c.phone), '') AS phone,
                    {$sizeSelectSql},
                    {$totalDueSql} AS total_cylinder_due
                ")
                ->groupBy('s.customer_id')
                ->havingRaw("{$totalDueSql} > 0");

            $outer = DB::query()->fromSub($inner, 'balances');

            if ($search !== '') {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $outer->where(function ($q) use ($like) {
                    $q->where('balances.customer_name', 'like', $like)
                        ->orWhere('balances.phone', 'like', $like)
                        ->orWhere('balances.customer_id', 'like', $like);
                });
            }

            $aggParts = [
                'COUNT(*) AS customers_with_due_count',
                'COALESCE(SUM(total_cylinder_due), 0) AS filtered_outstanding_cylinders',
            ];
            foreach (array_keys($this->cylinderSizePairs()) as $filled) {
                $aggParts[] = "COALESCE(SUM(due_{$filled}), 0) AS due_{$filled}";
            }
            $agg = (clone $outer)->selectRaw(implode(', ', $aggParts))->first();

            $total = (int) ($agg->customers_with_due_count ?? 0);
            $filteredOutstanding = (int) ($agg->filtered_outstanding_cylinders ?? 0);
            $dueBySize = [];
            foreach (array_keys($this->cylinderSizePairs()) as $filled) {
                $dueBySize[$filled] = (int) ($agg->{"due_{$filled}"} ?? 0);
            }

            $rows = (clone $outer)
                ->orderByDesc('total_cylinder_due')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get()
                ->map(function ($row) {
                    $purchased = [];
                    $returned = [];
                    $due = [];

                    foreach (array_keys($this->cylinderSizePairs()) as $filled) {
                        $purchased[$filled] = (int) $row->{"purchased_{$filled}"};
                        $returned[$filled] = (int) $row->{"returned_{$filled}"};
                        $due[$filled] = (int) $row->{"due_{$filled}"};
                    }

                    return [
                        'customer_id' => $row->customer_id,
                        'customer_name' => $row->customer_name,
                        'phone' => $row->phone,
                        'purchased' => $purchased,
                        'returned' => $returned,
                        'due' => $due,
                        'total_cylinder_due' => (int) $row->total_cylinder_due,
                    ];
                });

            $paginator = new LengthAwarePaginator(
                $rows,
                $total,
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'page']
            );

            return response()->json([
                'status' => true,
                'summary' => [
                    'filtered_outstanding_cylinders' => $filteredOutstanding,
                    'customers_with_due_count' => $total,
                    'due_by_size' => $dueBySize,
                ],
                'data' => $paginator,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            \Log::error('cylinderDues: '.$e->getMessage());

            return response()->json(['error' => 'Failed to load cylinder due customers'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

}
