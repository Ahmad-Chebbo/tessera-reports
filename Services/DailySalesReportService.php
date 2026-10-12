<?php

namespace App\Modules\Reports\Services;

use App\Modules\Sales\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DailySalesReportService
{
    /**
     * Get daily sales summary for a branch.
     *
     * @return Collection<int, array{date: string, orders_count: int, total_sales: float}>
     */
    public function getReport(int $branchId, Carbon $from, Carbon $to): Collection
    {
        return Order::query()
            ->where('branch_id', $branchId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as orders_count, SUM(total) as total_sales')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'orders_count' => (int) $row->orders_count,
                'total_sales' => (float) $row->total_sales,
            ]);
    }
}
