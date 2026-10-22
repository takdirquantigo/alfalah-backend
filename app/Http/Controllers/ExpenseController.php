<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Products;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function addExpense(Request $request)
    {
        try {
            $data = $this->validatedExpense($request);

            $expense = new Expense();
            $expense->purpose = $data['purpose'];
            $expense->amount = $data['amount'];
            $expense->date = $data['date'];
            $expense->save();

            return response()->json([
                'status' => true,
                'message' => 'Expense added successfully',
                'data' => $expense,
            ], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Error adding expense: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to add expense'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function updateExpense(Request $request, $id)
    {
        try {
            $expense = Expense::query()->find($id);

            if (!$expense) {
                return response()->json(['error' => 'Expense not found'], Response::HTTP_NOT_FOUND);
            }

            $data = $this->validatedExpense($request);
            $expense->purpose = $data['purpose'];
            $expense->amount = $data['amount'];
            $expense->date = $data['date'];
            $expense->save();

            return response()->json([
                'status' => true,
                'message' => 'Expense updated successfully',
                'data' => $expense,
            ], Response::HTTP_OK);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Error updating expense: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update expense'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getExpense(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        if ($perPage < 1 || $perPage > 100) {
            $perPage = 20;
        }

        $query = Expense::query();
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where('purpose', 'like', '%' . $search . '%');
        }

        $totalAmount = (clone $query)->sum(DB::raw('CAST(amount AS DECIMAL(15,2))'));

        $expenses = $query
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'total_amount' => (float) $totalAmount,
            'summary' => $this->periodSummary(),
            'data' => $expenses,
        ]);
    }

    public function singleExpense($id)
    {
        $expense = Expense::query()->find($id);

        if (!$expense) {
            return response()->json(['error' => 'Expense not found'], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'status' => true,
            'data' => $expense,
        ]);
    }

    public function deleteExpense($id)
    {
        try {
            $expense = Expense::query()->find($id);
            if (!$expense) {
                return response()->json(['error' => 'Expense not found'], Response::HTTP_NOT_FOUND);
            }

            $expense->delete();

            return response()->json([
                'status' => true,
                'message' => 'Expense deleted successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error deleting expense: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete expense'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getStates(Request $request)
    {
        try {
            $days = (int) $request->input('days', 30);
            if ($days < 1) {
                $days = 30;
            }
            $startDate = now()->subDays($days)->toDateString();

            $expenseSummaryQuery = Expense::query()->whereDate('date', '>=', $startDate);
            $productSummaryQuery = Products::query()->whereDate('date', '>=', $startDate);

            $expenseSummary = (clone $expenseSummaryQuery)
                ->selectRaw('COALESCE(SUM(CAST(amount AS DECIMAL(15,2))), 0) as totalExpense, COUNT(*) as totalExpenseItems')
                ->first();

            $productSummary = (clone $productSummaryQuery)
                ->selectRaw('COALESCE(SUM(CAST(price AS DECIMAL(15,2))), 0) as totalBuyCost, COUNT(*) as totalProductsBought')
                ->first();

            $expenses = (clone $expenseSummaryQuery)
                ->selectRaw('id, date, amount as cost, purpose');

            $products = (clone $productSummaryQuery)
                ->selectRaw("id, date, price as cost, 'Stock purchase' as purpose");

            $combined = $expenses->unionAll($products)
                ->orderBy('date', 'desc')
                ->get();

            $page = (int) $request->get('page', 1);
            $perPage = (int) $request->get('per_page', 10);
            $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
                $combined->forPage($page, $perPage)->values(),
                $combined->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return response()->json([
                'filterDays' => $days,
                'expenseSummary' => $expenseSummary,
                'productSummary' => $productSummary,
                'grandTotal' => (float) $expenseSummary->totalExpense + (float) $productSummary->totalBuyCost,
                'combinedList' => $paginated,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            \Log::error('Error getting states: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to get expense states'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function periodSummary(): array
    {
        $now = Carbon::now('Asia/Dhaka');
        $today = $now->toDateString();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();
        $lastMonthStart = $now->copy()->startOfMonth()->subMonth()->toDateString();
        $lastMonthEnd = $now->copy()->startOfMonth()->subDay()->toDateString();

        return [
            'today' => $this->sumAmount($today, $today),
            'this_month' => $this->sumAmount($monthStart, $monthEnd),
            'last_month' => $this->sumAmount($lastMonthStart, $lastMonthEnd),
        ];
    }

    private function sumAmount(string $from, string $to): float
    {
        $total = Expense::query()
            ->where('date', '>=', $from)
            ->where('date', '<=', $to)
            ->sum(DB::raw('CAST(amount AS DECIMAL(15,2))'));

        return round((float) $total, 2);
    }

    private function validatedExpense(Request $request): array
    {
        $validated = $request->validate([
            'purpose' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'date' => 'required|date',
        ]);

        return [
            'purpose' => trim($validated['purpose']),
            'amount' => $validated['amount'],
            'date' => Carbon::parse($validated['date'])->toDateString(),
        ];
    }
}
