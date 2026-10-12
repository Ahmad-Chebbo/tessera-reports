<?php

namespace App\Modules\Reports\Services;

use App\Modules\Sales\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProfitReportService
{
    /**
     * Get profit report (selling price - cost price) per product.
     *
     * @return Collection<int, array{product_id: int, product_name: string, total_quantity: int, total_revenue: float, total_cost: float, profit: float, margin_percentage: float}>
     */
    public function getReport(int $tenantId, Carbon $from, Carbon $to, ?int $branchId = null): Collection
    {
        $query = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()]);

        if ($branchId) {
            $query->where('orders.branch_id', $branchId);
        }

        return $query
            ->selectRaw('
                products.id as product_id,
                products.name as product_name,
                SUM(order_items.quantity) as total_quantity,
                SUM(order_items.total) as total_revenue,
                SUM(order_items.cost_price * order_items.quantity) as total_cost
            ')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_revenue')
            ->get()
            ->map(function ($row) {
                $revenue = (float) $row->total_revenue;
                $cost = (float) $row->total_cost;
                $profit = $revenue - $cost;

                return [
                    'product_id' => (int) $row->product_id,
                    'product_name' => $row->product_name,
                    'total_quantity' => (int) $row->total_quantity,
                    'total_revenue' => $revenue,
                    'total_cost' => $cost,
                    'profit' => $profit,
                    'margin_percentage' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
                ];
            });
    }

    /**
     * Get summary totals for the profit report.
     *
     * @return array{total_revenue: float, total_cost: float, total_profit: float, overall_margin: float}
     */
    public function getSummary(int $tenantId, Carbon $from, Carbon $to, ?int $branchId = null): array
    {
        $report = $this->getReport($tenantId, $from, $to, $branchId);

        $totalRevenue = $report->sum('total_revenue');
        $totalCost = $report->sum('total_cost');
        $totalProfit = $totalRevenue - $totalCost;

        return [
            'total_revenue' => $totalRevenue,
            'total_cost' => $totalCost,
            'total_profit' => $totalProfit,
            'overall_margin' => $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 2) : 0,
        ];
    }
}
