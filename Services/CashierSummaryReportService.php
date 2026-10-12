<?php

namespace App\Modules\Reports\Services;

use App\Modules\Orders\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CashierSummaryReportService
{
    /**
     * Get cash summary per cashier for a given period.
     *
     * @return Collection<int, array{cashier_id: int, cashier_name: string, orders_count: int, total_sales: float, total_cash_received: float}>
     */
    public function getReport(int $branchId, Carbon $from, Carbon $to): Collection
    {
        return Order::query()
            ->join('users', 'orders.cashier_id', '=', 'users.id')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->where('orders.branch_id', $branchId)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('
                users.id as cashier_id,
                users.name as cashier_name,
                COUNT(orders.id) as orders_count,
                SUM(orders.total) as total_sales,
                SUM(payments.cash_received) as total_cash_received
            ')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_sales')
            ->get()
            ->map(fn ($row) => [
                'cashier_id' => (int) $row->cashier_id,
                'cashier_name' => $row->cashier_name,
                'orders_count' => (int) $row->orders_count,
                'total_sales' => (float) $row->total_sales,
                'total_cash_received' => (float) $row->total_cash_received,
            ]);
    }
}
