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

class SalesController extends Controller
{
    public function newSales(Request $request)
    {
        try {
            $customers = $this->normalizeCustomers($request->input('customer'));

            if (empty($customers)) {
                return response()->json(['error' => 'Customer is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            foreach ($customers as $customer) {
                $customerDetails = Customers::find($customer['value']);
                
                $newSales = new Sales();
                $newSales->customer_name = $customer['label'];
                $newSales->customer_id = $customer['value'];

                $newSales->five_kg = $this->toNumber($request->five_kg);
                $newSales->twelve_kg = $this->toNumber($request->twelve_kg);
                $newSales->twentyfive_kg = $this->toNumber($request->twentyfive_kg);
                $newSales->thirtythree_kg = $this->toNumber($request->thirtythree_kg);
                $newSales->thirtyfive_kg = $this->toNumber($request->thirtyfive_kg);
                $newSales->fourtyfive_kg = $this->toNumber($request->fourtyfive_kg);

                $newSales->empty_five_kg = $this->toNumber($request->empty_five_kg);
                $newSales->empty_twelve_kg = $this->toNumber($request->empty_twelve_kg);
                $newSales->empty_twentyfive_kg = $this->toNumber($request->empty_twentyfive_kg);
                $newSales->empty_thirtythree_kg = $this->toNumber($request->empty_thirtythree_kg);
                $newSales->empty_thirtyfive_kg = $this->toNumber($request->empty_thirtyfive_kg);
                $newSales->empty_fourtyfive_kg = $this->toNumber($request->empty_fourtyfive_kg);
                $newSales->empty_others_kg = $this->toNumber($request->empty_others_kg);

                $newSales->date = $request->date;
                $newSales->is_due_bill = $request->is_due_bill ?? 0;
                $newSales->is_package = $request->boolean('is_package');
                $newSales->whole_sale = $customerDetails->is_retailer ? 1 : 0;
                $newSales->price = $this->toNumber($request->price);
                $newSales->pay = $this->toNumber($request->pay);
                $newSales->due = $this->toNumber($request->due);
                $newSales->other_cost_name = $request->other_cost_name ?? '';
                $newSales->other_cost_amount = $this->toNumber($request->other_cost_amount);

                $newSales->price_5_kg = $this->toNumber($request->price_5_kg) ;
                $newSales->price_12_kg = $this->toNumber($request->price_12_kg) ;
                $newSales->price_25_kg = $this->toNumber($request->price_25_kg) ;
                $newSales->price_33_kg = $this->toNumber($request->price_33_kg);
                $newSales->price_35_kg = $this->toNumber($request->price_35_kg);
                $newSales->price_45_kg = $this->toNumber($request->price_45_kg) ;

                $newSales->save();
            }

            $stoke = $this->getOrCreateStoke();

            $stoke->five_kg = ($stoke->five_kg ?? 0) - $this->toNumber($request->five_kg);
            $stoke->twelve_kg = ($stoke->twelve_kg ?? 0) - $this->toNumber($request->twelve_kg);
            $stoke->twentyfive_kg = ($stoke->twentyfive_kg ?? 0) - $this->toNumber($request->twentyfive_kg);
            $stoke->thirtythree_kg = ($stoke->thirtythree_kg ?? 0) - $this->toNumber($request->thirtythree_kg);
            $stoke->thirtyfive_kg = ($stoke->thirtyfive_kg ?? 0) - $this->toNumber($request->thirtyfive_kg);
            $stoke->fourtyfive_kg = ($stoke->fourtyfive_kg ?? 0) - $this->toNumber($request->fourtyfive_kg);

            $stoke->empty_five_kg = ($stoke->empty_five_kg ?? 0) + $this->toNumber($request->empty_five_kg);
            $stoke->empty_twelve_kg = ($stoke->empty_twelve_kg ?? 0) + $this->toNumber($request->empty_twelve_kg);
            $stoke->empty_twentyfive_kg = ($stoke->empty_twentyfive_kg ?? 0) + $this->toNumber($request->empty_twentyfive_kg);
            $stoke->empty_thirtythree_kg = ($stoke->empty_thirtythree_kg ?? 0) + $this->toNumber($request->empty_thirtythree_kg);
            $stoke->empty_thirtyfive_kg = ($stoke->empty_thirtyfive_kg ?? 0) + $this->toNumber($request->empty_thirtyfive_kg);
            $stoke->empty_fourtyfive_kg = ($stoke->empty_fourtyfive_kg ?? 0) + $this->toNumber($request->empty_fourtyfive_kg);
            $stoke->save();




            return response()->json(['message' => 'Sales added successfully'], Response::HTTP_CREATED);
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

            DB::transaction(function () use ($request) {

                $sale = Sales::find($request->id);

                if (!$sale) {
                    throw new \Exception('Sale not found');
                }

                $stoke = $this->getOrCreateStoke();

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

                if (isset($request->customer[0])) {
                    $sale->customer_name = $request->customer[0]['label'];
                    $sale->customer_id = $request->customer[0]['value'];
                } else {
                    $sale->customer_name = null;
                    $sale->customer_id = null;
                }

                $sale->five_kg = $request->five_kg ?? 0;
                $sale->twelve_kg = $request->twelve_kg ?? 0;
                $sale->twentyfive_kg = $request->twentyfive_kg ?? 0;
                $sale->thirtythree_kg = $request->thirtythree_kg ?? 0;
                $sale->thirtyfive_kg = $request->thirtyfive_kg ?? 0;
                $sale->fourtyfive_kg = $request->fourtyfive_kg ?? 0;
                $sale->others_kg = $request->others_kg ?? 0;

                $sale->empty_five_kg = $request->empty_five_kg ?? 0;
                $sale->empty_twelve_kg = $request->empty_twelve_kg ?? 0;
                $sale->empty_twentyfive_kg = $request->empty_twentyfive_kg ?? 0;
                $sale->empty_thirtythree_kg = $request->empty_thirtythree_kg ?? 0;
                $sale->empty_thirtyfive_kg = $request->empty_thirtyfive_kg ?? 0;
                $sale->empty_fourtyfive_kg = $request->empty_fourtyfive_kg ?? 0;
                $sale->empty_others_kg = $request->empty_others_kg ?? 0;

                $sale->date = $request->date;
                $sale->is_due_bill = $request->is_due_bill ?? 0;
                $sale->is_package = $request->boolean('is_package');
                $sale->whole_sale = $request->boolean('whole_sale');
                $sale->price = $request->price ?? 0;
                $sale->pay = $request->pay ?? 0;
                $sale->due = $request->due ?? 0;

                $sale->price_5_kg = ((int) ($request->price_5_kg ?? 0)) ;
                $sale->price_12_kg = ((int) ($request->price_12_kg ?? 0)) ;
                $sale->price_25_kg = ((int) ($request->price_25_kg ?? 0)) ;
                $sale->price_33_kg = ((int) ($request->price_33_kg ?? 0));
                $sale->price_35_kg = ((int) ($request->price_35_kg ?? 0)) ;
                $sale->price_45_kg = ((int) ($request->price_45_kg ?? 0));

                $sale->save();

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

            $query = Sales::with('customer')
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

            $sales = $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'data' => $sales,
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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

            $sale->delete();

            return response()->json(['message' => 'Sale deleted successfully'], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


}
