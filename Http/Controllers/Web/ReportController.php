<?php

namespace App\Modules\Reports\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Reports\Http\Requests\ReportRequest;
use App\Modules\Reports\Services\SalesAnalyticsService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Tenant\Services\BranchAccess;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Tessera\Core\Contracts\NetProfit;

class ReportController extends Controller
{
    public function __construct(private SalesAnalyticsService $analytics, private BranchAccess $access) {}

    public function sales(ReportRequest $request): Response
    {
        $user = $request->user();
        $branchId = $this->access->effective($user, $request->query('branch_id'));

        [$from, $to] = $request->dateRange();
        $dateFrom = $from->toDateString();
        $dateTo = $to->toDateString();

        $orders = Order::query()
            ->where('tenant_id', $user->tenant_id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->get();

        $totalSales = $orders->sum('total');
        $totalOrders = $orders->count();
        $averageOrder = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

        // Daily sales chart data
        $dailySales = Order::query()
            ->where('tenant_id', $user->tenant_id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top selling products
        $topProducts = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.tenant_id', $user->tenant_id)
            ->when($branchId, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->select(
                'products.id',
                'products.name',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total) as total_revenue')
            )
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get();

        return Inertia::render('Reports/Sales', [
            'stats' => [
                'totalSales' => $totalSales,
                'totalOrders' => $totalOrders,
                'averageOrder' => $averageOrder,
            ],
            'dailySales' => $dailySales,
            'topProducts' => $topProducts,
            'charts' => [
                'daily' => $this->analytics->dailySeries($user->tenant_id, $from, $to, $branchId),
                'topProducts' => $this->analytics->topProducts($user->tenant_id, $from, $to, branchId: $branchId, limit: 8),
                'categories' => $this->analytics->categoryShare($user->tenant_id, $from, $to, branchId: $branchId, limit: 6),
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'presets' => $request->presets(),
            'branchId' => $this->access->canSeeAll($user) ? $branchId : null,
        ]);
    }

    public function items(ReportRequest $request): Response
    {
        $user = $request->user();
        $branchId = $this->access->effective($user, $request->query('branch_id'));

        [$from, $to] = $request->dateRange();
        $dateFrom = $from->toDateString();
        $dateTo = $to->toDateString();
        $categoryId = $request->input('category_id');
        $search = $request->input('search');

        $query = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('orders.tenant_id', $user->tenant_id)
            ->when($branchId, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                'categories.name as category_name',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_items.order_id) as order_count')
            )
            ->groupBy('products.id', 'products.name', 'products.sku', 'categories.name');

        if ($categoryId) {
            $query->where('products.category_id', $categoryId);
        }

        if ($search) {
            $query->where('products.name', 'like', "%{$search}%");
        }

        $items = $query->orderByDesc('total_revenue')->paginate(25)->withQueryString();

        $categories = Category::where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $totals = [
            'totalRevenue' => (float) $items->sum('total_revenue'),
            'totalQuantity' => (float) $items->sum('total_quantity'),
            'totalItems' => $items->total(),
        ];

        return Inertia::render('Reports/Items', [
            'items' => $items,
            'categories' => $categories,
            'totals' => $totals,
            'charts' => [
                'topProducts' => $this->analytics->topProducts($user->tenant_id, $from, $to, branchId: $branchId, limit: 10, categoryId: $categoryId ? (int) $categoryId : null, search: $search, netOfDiscounts: false),
                'categories' => $this->analytics->categoryShare($user->tenant_id, $from, $to, branchId: $branchId, limit: 6, categoryId: $categoryId ? (int) $categoryId : null, search: $search, netOfDiscounts: false),
            ],
            'filters' => compact('dateFrom', 'dateTo', 'categoryId', 'search'),
            'presets' => $request->presets(),
            'branchId' => $this->access->canSeeAll($user) ? $branchId : null,
        ]);
    }

    public function profit(ReportRequest $request): Response
    {
        $user = $request->user();
        $branchId = $this->access->effective($user, $request->query('branch_id'));

        [$from, $to] = $request->dateRange();
        $dateFrom = $from->toDateString();
        $dateTo = $to->toDateString();
        $categoryId = $request->input('category_id');
        $search = $request->input('search');

        $query = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('orders.tenant_id', $user->tenant_id)
            ->when($branchId, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                'categories.name as category_name',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total) as total_revenue'),
                DB::raw('SUM(order_items.cost_price * order_items.quantity) as total_cost'),
                DB::raw('SUM(order_items.total) - SUM(order_items.cost_price * order_items.quantity) as total_profit'),
                DB::raw('COUNT(DISTINCT order_items.order_id) as order_count')
            )
            ->groupBy('products.id', 'products.name', 'products.sku', 'categories.name');

        if ($categoryId) {
            $query->where('products.category_id', $categoryId);
        }

        if ($search) {
            $query->where('products.name', 'like', "%{$search}%");
        }

        $products = $query->orderByDesc('total_profit')->paginate(25)->withQueryString();

        $categories = Category::where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $summary = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.tenant_id', $user->tenant_id)
            ->when($branchId, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('SUM(order_items.total) as total_revenue, SUM(order_items.cost_price * order_items.quantity) as total_cost')
            ->first();

        $netProfit = app(NetProfit::class);
        $canIncludeExpenses = $netProfit->available() && $user->can('view expenses');
        $includeExpenses = $request->boolean('include_expenses') && $canIncludeExpenses;
        $net = $includeExpenses && ! $categoryId && ! $search
            ? $netProfit->forPeriod($user->tenant_id, $from, $to, $branchId ?: null)['totals']
            : null;

        $totalRevenue = (float) ($summary->total_revenue ?? 0);
        $totalCost = (float) ($summary->total_cost ?? 0);
        $totalProfit = $totalRevenue - $totalCost;

        return Inertia::render('Reports/Profit', [
            'products' => $products,
            'categories' => $categories,
            'stats' => [
                'totalRevenue' => $totalRevenue,
                'totalCost' => $totalCost,
                'totalProfit' => $totalProfit,
                'profitMargin' => $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 2) : 0,
            ],
            'charts' => [
                'daily' => $this->analytics->profitSeries($user->tenant_id, $from, $to, branchId: $branchId, categoryId: $categoryId ? (int) $categoryId : null, search: $search, netOfDiscounts: false),
                'topProducts' => $this->analytics->topProducts($user->tenant_id, $from, $to, branchId: $branchId, limit: 10, by: 'profit', categoryId: $categoryId ? (int) $categoryId : null, search: $search, netOfDiscounts: false),
            ],
            'net' => $net,
            'filters' => [...compact('dateFrom', 'dateTo', 'categoryId', 'search'), 'includeExpenses' => $includeExpenses],
            'canIncludeExpenses' => $canIncludeExpenses,
            'presets' => $request->presets(),
            'branchId' => $this->access->canSeeAll($user) ? $branchId : null,
        ]);
    }
}
