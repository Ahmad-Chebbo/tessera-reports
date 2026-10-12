<?php

namespace App\Modules\Reports\Services;

use App\Modules\Sales\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProductSalesReportService
{
    /**
     * Get product sales summary.
     *
     * @return Collection<int, array{product_id: int, product_name: string, total_quantity: int, total_revenue: float}>
     */
    public function getReport(int $companyId, Carbon $from, Carbon $to, ?int $branchId = null): Collection
    {
        $query = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.company_id', $companyId)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()]);

        if ($branchId) {
            $query->where('orders.branch_id', $branchId);
        }

        return $query
            ->selectRaw('products.id as product_id, products.name as product_name, SUM(order_items.quantity) as total_quantity, SUM(order_items.total) as total_revenue')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_revenue')
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'total_quantity' => (int) $row->total_quantity,
                'total_revenue' => (float) $row->total_revenue,
            ]);
    }
}
