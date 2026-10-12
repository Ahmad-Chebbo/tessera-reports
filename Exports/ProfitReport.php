<?php

namespace App\Modules\Reports\Exports;

use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tessera\Engine\Exports\Column;
use Tessera\Engine\Exports\ExportContext;

class ProfitReport extends ItemsReport
{
    public function key(): string
    {
        return 'profit';
    }

    public function columns(ExportContext $context): array
    {
        return [
            new Column('product', Column::TEXT, 32),
            new Column('sku', Column::TEXT, 14),
            new Column('category', Column::TEXT, 20),
            new Column('quantity', Column::NUMBER, 12),
            new Column('revenue', Column::MONEY, 16),
            new Column('cost', Column::MONEY, 16),
            new Column('profit', Column::MONEY, 16),
            new Column('margin', Column::PERCENT, 12),
            new Column('order_count', Column::INTEGER, 10),
        ];
    }

    public function rows(ExportContext $context): iterable
    {
        foreach ($this->query($context)->orderByDesc('total_profit')->orderBy('products.id')->cursor() as $item) {
            $revenue = (float) $item->total_revenue;
            $profit = (float) $item->total_profit;

            yield [
                'product' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category_name,
                'quantity' => (float) $item->total_quantity,
                'revenue' => $revenue,
                'cost' => (float) $item->total_cost,
                'profit' => $profit,
                'margin' => $revenue > 0 ? round($profit / $revenue * 100, 2) : 0,
                'order_count' => (int) $item->order_count,
            ];
        }
    }

    public function summary(ExportContext $context): array
    {
        $totals = DB::query()
            ->fromSub($this->query($context)->toBase()->cloneWithout(['orders']), 'grouped')
            ->selectRaw('COALESCE(SUM(total_revenue), 0) as revenue, COALESCE(SUM(total_cost), 0) as cost')
            ->first();

        $revenue = (float) $totals->revenue;
        $cost = (float) $totals->cost;
        $profit = $revenue - $cost;

        return [
            $this->figure($context, 'revenue', $revenue),
            $this->figure($context, 'cost', $cost),
            $this->figure($context, 'profit', $profit),
            $this->figure($context, 'margin', $revenue > 0 ? round($profit / $revenue * 100, 2) : 0, Column::PERCENT),
        ];
    }

    /**
     * @return Builder<OrderItem>
     */
    protected function query(ExportContext $context): Builder
    {
        return parent::query($context)->addSelect(
            DB::raw('SUM(order_items.cost_price * order_items.quantity) as total_cost'),
            DB::raw('SUM(order_items.total) - SUM(order_items.cost_price * order_items.quantity) as total_profit'),
        );
    }
}
