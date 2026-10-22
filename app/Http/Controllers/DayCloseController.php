<?php

namespace App\Http\Controllers;

use App\Models\DayClose;
use App\Services\DayCloseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DayCloseController extends Controller
{
    public function __construct(private DayCloseService $dayCloseService)
    {
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        if ($perPage < 1 || $perPage > 100) {
            $perPage = 20;
        }

        $query = DayClose::query();
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where('date', 'like', '%' . $search . '%');
        }

        $rows = $query
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => $rows,
        ]);
    }

    public function today()
    {
        $date = $this->dayCloseService->todayDate();
        $row = DayClose::query()->where('date', $date)->first();

        return response()->json([
            'status' => true,
            'date' => $date,
            'closed' => (bool) $row,
            'data' => $row,
        ]);
    }

    public function close(Request $request)
    {
        $today = $this->dayCloseService->todayDate();
        $date = trim((string) $request->input('date', $today));

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date, 'Asia/Dhaka');
        } catch (\Exception $e) {
            $parsed = false;
        }

        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return response()->json(['error' => 'Choose a valid date'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($date > $today) {
            return response()->json(['error' => 'A future day cannot be closed'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->dayCloseService->closeForDate($date, true);

            return response()->json([
                'status' => true,
                'already_closed' => $result['already_closed'],
                'updated' => $result['updated'],
                'message' => $result['updated'] ? 'Day updated' : 'Day closed successfully',
                'data' => $result['data'],
            ], $result['updated'] ? Response::HTTP_OK : Response::HTTP_CREATED);
        } catch (\Exception $e) {
            \Log::error('Error closing day: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to close day'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
};
