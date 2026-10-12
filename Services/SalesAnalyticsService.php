<?php

namespace App\Modules\Reports\Services;

use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesAnalyticsService
{
    private const UNCATEGORIZED = 'Uncategorized';

    private const OTHER = 'Other';

    /**
     * Completed revenue and order count for every day in the range, with empty days as zero.
     *
     * @return list<array{date: string, revenue: float, orders: int}>
     */
    public function dailySeries(int $companyId, CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): array
    {
        $rows = $this->orders($companyId, $from, $to, $branchId, OrderStatus::Completed)
            ->selectRaw('DATE(created_at) as day, SUM(total) as revenue, COUNT(*) as orders')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        return array_map(function (string $date) use ($rows): array {
            $row = $rows->get($date);

            return [
                'date' => $date,
                'revenue' => round((float) ($row->revenue ?? 0), 2),
                'orders' => (int) ($row->orders ?? 0),
            ];
        }, $this->days($from, $to));
    }

    /**
     * Revenue, cost and profit for every day in the range, with empty days as zero.
     *
     * @return list<array{date: string, revenue: float, cost: float, profit: float}>
     */
    public function profitSeries(
        int $companyId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $branchId = null,
        ?int $categoryId = null,
        ?string $search = null,
        bool $netOfDiscounts = true,
    ): array {
        $revenue = $this->revenueExpression($netOfDiscounts);

        $rows = $this->items($companyId, $from, $to, $branchId, $categoryId, $search)
            ->selectRaw("DATE(orders.created_at) as day, SUM({$revenue}) as revenue, SUM(order_items.cost_price * order_items.quantity) as cost")
            ->groupByRaw('DATE(orders.created_at)')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        return array_map(function (string $date) use ($rows): array {
            $row = $rows->get($date);
            $revenue = round((float) ($row->revenue ?? 0), 2);
            $cost = round((float) ($row->cost ?? 0), 2);

            return [
                'date' => $date,
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => round($revenue - $cost, 2),
            ];
        }, $this->days($from, $to));
    }

    /**
     * @param  'revenue'|'quantity'|'profit'  $by
     * @return list<array{id: int, name: string, revenue: float, quantity: float, profit: float}>
     */
    public function topProducts(
        int $companyId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $branchId = null,
        int $limit = 5,
        string $by = 'revenue',
        ?int $categoryId = null,
        ?string $search = null,
        bool $netOfDiscounts = true,
    ): array {
        $revenue = $this->revenueExpression($netOfDiscounts);

        $order = match ($by) {
            'quantity' => 'SUM(order_items.quantity)',
            'profit' => "SUM({$revenue}) - SUM(order_items.cost_price * order_items.quantity)",
            default => "SUM({$revenue})",
        };

        return $this->items($companyId, $from, $to, $branchId, $categoryId, $search)
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->selectRaw("products.id as id, products.name as name, SUM(order_items.quantity) as quantity, SUM({$revenue}) as revenue, SUM(order_items.cost_price * order_items.quantity) as cost")
            ->groupBy('products.id', 'products.name')
            ->orderByRaw("{$order} DESC")
            ->orderBy('products.name')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'revenue' => round((float) $row->revenue, 2),
                'quantity' => round((float) $row->quantity, 4),
                'profit' => round((float) $row->revenue - (float) $row->cost, 2),
            ])
            ->all();
    }

    /**
     * Revenue per category. Categories beyond the limit are folded into "Other".
     *
     * @return list<array{name: string, key: string|null, revenue: float}>
     */
    public function categoryShare(
        int $companyId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $branchId = null,
        int $limit = 5,
        ?int $categoryId = null,
        ?string $search = null,
        bool $netOfDiscounts = true,
    ): array {
        $revenue = $this->revenueExpression($netOfDiscounts);

        $rows = $this->items($companyId, $from, $to, $branchId, $categoryId, $search)
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->selectRaw("categories.name as name, SUM({$revenue}) as revenue")
            ->groupBy('categories.id', 'categories.name')
            ->orderByRaw("SUM({$revenue}) DESC")
            ->orderBy('categories.name')
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->name ?? self::UNCATEGORIZED,
                'key' => $row->name === null ? 'uncategorized' : null,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();

        if (count($rows) <= $limit) {
            return $rows;
        }

        $kept = array_slice($rows, 0, max($limit - 1, 1));
        $rest = array_slice($rows, count($kept));

        return [...$kept, ['name' => self::OTHER, 'key' => 'other', 'revenue' => round(array_sum(array_column($rest, 'revenue')), 2)]];
    }

    /**
     * Money collected (completed orders) versus sold on credit (unpaid debt orders).
     *
     * @return array{paid: float, credit: float}
     */
    public function paymentSplit(int $companyId, CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): array
    {
        $totals = $this->orders($companyId, $from, $to, $branchId, OrderStatus::Completed, OrderStatus::Debt)
            ->selectRaw('status, SUM(total) as revenue')
            ->groupBy('status')
            ->pluck('revenue', 'status');

        return [
            'paid' => round((float) ($totals[OrderStatus::Completed->value] ?? 0), 2),
            'credit' => round((float) ($totals[OrderStatus::Debt->value] ?? 0), 2),
        ];
    }

    /**
     * Revenue and orders for the period against the equally long period right before it.
     *
     * @return array{revenue: array{current: float, previous: float, change: float|null}, orders: array{current: int, previous: int, change: float|null}}
     */
    public function comparison(int $companyId, CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): array
    {
        $length = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $previousTo = $from->copy()->startOfDay()->subDay();
        $previousFrom = $previousTo->copy()->subDays($length - 1);

        $current = $this->totals($companyId, $from, $to, $branchId);
        $previous = $this->totals($companyId, $previousFrom, $previousTo, $branchId);

        return [
            'revenue' => [
                'current' => $current['revenue'],
                'previous' => $previous['revenue'],
                'change' => $this->percentChange($current['revenue'], $previous['revenue']),
            ],
            'orders' => [
                'current' => $current['orders'],
                'previous' => $previous['orders'],
                'change' => $this->percentChange($current['orders'], $previous['orders']),
            ],
        ];
    }

    /**
     * @return array{revenue: float, orders: int}
     */
    private function totals(int $companyId, CarbonInterface $from, CarbonInterface $to, ?int $branchId): array
    {
        $row = $this->orders($companyId, $from, $to, $branchId, OrderStatus::Completed)
            ->selectRaw('SUM(total) as revenue, COUNT(*) as orders')
            ->first();

        return [
            'revenue' => round((float) ($row->revenue ?? 0), 2),
            'orders' => (int) ($row->orders ?? 0),
        ];
    }

    private function percentChange(float|int $current, float|int $previous): ?float
    {
        if ($previous == 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @return Builder<Order>
     */
    private function orders(int $companyId, CarbonInterface $from, CarbonInterface $to, ?int $branchId, OrderStatus ...$statuses): Builder
    {
        return Order::query()
            ->where('company_id', $companyId)
            ->whereIn('status', array_map(fn (OrderStatus $status) => $status->value, $statuses))
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /**
     * @return Builder<OrderItem>
     */
    private function items(int $companyId, CarbonInterface $from, CarbonInterface $to, ?int $branchId, ?int $categoryId = null, ?string $search = null): Builder
    {
        return OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.company_id', $companyId)
            ->where('orders.status', OrderStatus::Completed->value)
            ->when($branchId, fn (Builder $query) => $query->where('orders.branch_id', $branchId))
            ->when($categoryId, fn (Builder $query) => $query
                ->whereIn('order_items.product_id', DB::table('products')->where('category_id', $categoryId)->select('id')))
            ->when($search, fn (Builder $query) => $query
                ->whereIn('order_items.product_id', DB::table('products')->where('name', 'like', "%{$search}%")->select('id')))
            ->whereBetween('orders.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /**
     * Line revenue, optionally spread across the order discount so item figures add up to order revenue.
     */
    private function revenueExpression(bool $netOfDiscounts): string
    {
        if (! $netOfDiscounts) {
            return 'order_items.total';
        }

        return 'CASE WHEN orders.subtotal > 0 THEN 1.0 * order_items.total * orders.total / orders.subtotal ELSE order_items.total END';
    }

    /**
     * @return list<string>
     */
    private function days(CarbonInterface $from, CarbonInterface $to): array
    {
        $dates = [];

        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }
}
