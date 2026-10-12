<?php

namespace App\Modules\Reports\Exports;

use App\Modules\Orders\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tessera\Engine\Exports\Column;
use Tessera\Engine\Exports\ExportContext;

class SalesReport extends AnalyticsReport
{
    public function key(): string
    {
        return 'sales';
    }

    public function columns(ExportContext $context): array
    {
        return [
            new Column('date', Column::DATE, 14),
            new Column('orders', Column::INTEGER, 12),
            new Column('gross_sales', Column::MONEY, 18),
            new Column('discounts', Column::MONEY, 16),
            new Column('net_sales', Column::MONEY, 18),
            new Column('average_order', Column::MONEY, 18),
        ];
    }

    public function rows(ExportContext $context): iterable
    {
        foreach ($this->daily($context) as $day) {
            $orders = (int) $day->orders;
            $net = (float) $day->net_sales;

            yield [
                'date' => $day->day,
                'orders' => $orders,
                'gross_sales' => (float) $day->gross_sales,
                'discounts' => (float) $day->discounts,
                'net_sales' => $net,
                'average_order' => $orders > 0 ? $net / $orders : 0,
            ];
        }
    }

    public function count(ExportContext $context): int
    {
        return $this->daily($context)->count();
    }

    public function summary(ExportContext $context): array
    {
        $days = $this->daily($context);
        $orders = (int) $days->sum('orders');
        $net = (float) $days->sum('net_sales');

        return [
            $this->figure($context, 'total_sales', $net),
            $this->figure($context, 'total_orders', $orders, Column::INTEGER),
            $this->figure($context, 'average_order', $orders > 0 ? $net / $orders : 0),
        ];
    }

    /**
     * Completed orders grouped by day. A report spans at most a year, so this is always small.
     *
     * @return Collection<int, object>
     */
    private function daily(ExportContext $context): Collection
    {
        return $context->remember('daily', function () use ($context): Collection {
            [$from, $to] = $this->range($context);

            return Order::query()
                ->where('tenant_id', $context->tenant->id)
                ->when($this->branchId($context), fn ($query, $branchId) => $query->where('branch_id', $branchId))
                ->where('status', 'completed')
                ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
                ->select(
                    DB::raw('DATE(created_at) as day'),
                    DB::raw('COUNT(*) as orders'),
                    DB::raw('SUM(subtotal) as gross_sales'),
                    DB::raw('SUM(discount_amount) as discounts'),
                    DB::raw('SUM(total) as net_sales'),
                )
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('day')
                ->get();
        });
    }
}
