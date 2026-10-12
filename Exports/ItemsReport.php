<?php

namespace App\Modules\Reports\Exports;

use App\Modules\Catalog\Models\Category;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Tessera\Engine\Exports\Column;
use Tessera\Engine\Exports\ExportContext;

class ItemsReport extends AnalyticsReport
{
    public function key(): string
    {
        return 'items';
    }

    public function filterRules(int $companyId): array
    {
        return [
            ...$this->branchRules($companyId),
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function columns(ExportContext $context): array
    {
        return [
            new Column('product', Column::TEXT, 34),
            new Column('sku', Column::TEXT, 16),
            new Column('category', Column::TEXT, 22),
            new Column('quantity', Column::NUMBER, 14),
            new Column('revenue', Column::MONEY, 18),
            new Column('order_count', Column::INTEGER, 12),
        ];
    }

    public function rows(ExportContext $context): iterable
    {
        foreach ($this->query($context)->orderByDesc('total_revenue')->orderBy('products.id')->cursor() as $item) {
            yield [
                'product' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category_name,
                'quantity' => (float) $item->total_quantity,
                'revenue' => (float) $item->total_revenue,
                'order_count' => (int) $item->order_count,
            ];
        }
    }

    public function count(ExportContext $context): int
    {
        return $this->countGrouped($this->query($context)->toBase());
    }

    public function summary(ExportContext $context): array
    {
        $totals = DB::query()
            ->fromSub($this->query($context)->toBase()->cloneWithout(['orders']), 'grouped')
            ->selectRaw('COALESCE(SUM(total_revenue), 0) as revenue, COALESCE(SUM(total_quantity), 0) as quantity, COUNT(*) as products')
            ->first();

        return [
            $this->figure($context, 'revenue', (float) $totals->revenue),
            $this->figure($context, 'total_quantity', (float) $totals->quantity, Column::NUMBER),
            $this->figure($context, 'products', (int) $totals->products, Column::INTEGER),
        ];
    }

    public function filterLines(ExportContext $context): array
    {
        $lines = parent::filterLines($context);

        if ($categoryId = $context->filter('category_id')) {
            $lines[] = $context->t('filters.category', ['value' => Category::query()->where('company_id', $context->company->id)->whereKey($categoryId)->value('name')]);
        }

        if ($search = $context->filter('search')) {
            $lines[] = $context->t('filters.search', ['value' => $search]);
        }

        return $lines;
    }

    /**
     * Same selection as the on-screen report, unpaginated.
     *
     * @return Builder<OrderItem>
     */
    protected function query(ExportContext $context): Builder
    {
        [$from, $to] = $this->range($context);

        $query = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('orders.company_id', $context->company->id)
            ->when($this->branchId($context), fn ($query, $branchId) => $query->where('orders.branch_id', $branchId))
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                'categories.name as category_name',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_items.order_id) as order_count'),
            )
            ->groupBy('products.id', 'products.name', 'products.sku', 'categories.name');

        if ($categoryId = $context->filter('category_id')) {
            $query->where('products.category_id', $categoryId);
        }

        if ($search = $context->filter('search')) {
            $query->where('products.name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $search).'%');
        }

        return $query;
    }
}
