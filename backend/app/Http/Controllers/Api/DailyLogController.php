<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DailyLogResource;
use App\Models\DailyLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DailyLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $request->user()->dailyLogs()
            ->orderByDesc('date')
            ->limit($request->integer('limit', 30));

        if ($from = $request->input('from')) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->whereDate('date', '<=', $to);
        }

        return DailyLogResource::collection($query->get());
    }

    public function show(Request $request, string $date): DailyLogResource
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 422, 'date must be YYYY-MM-DD');

        $log = $request->user()->dailyLogs()
            ->whereDate('date', $date)
            ->firstOrFail();

        return new DailyLogResource($log);
    }

    public function store(Request $request): DailyLogResource
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'water_ml' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'exercise_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'weight_kg' => ['nullable', 'numeric', 'between:30,250'],
            'daily_summary' => ['nullable', 'string', 'max:2000'],
        ]);

        $existing = $request->user()->dailyLogs()
            ->whereDate('date', $data['date'])
            ->first();

        $payload = collect($data)->except('date')->toArray();

        if ($existing) {
            $existing->update($payload);
            return new DailyLogResource($existing);
        }

        $log = $request->user()->dailyLogs()->create($payload + ['date' => $data['date']]);

        return new DailyLogResource($log);
    }
}
