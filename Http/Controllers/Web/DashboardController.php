<?php

namespace App\Modules\Reports\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Company\Models\Branch;
use App\Modules\Company\Services\BranchAccess;
use App\Modules\Reports\Http\Requests\DashboardRequest;
use App\Modules\Reports\Services\SalesAnalyticsService;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;
use App\Modules\Team\Models\User;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Tessera\Engine\Dashboard\DashboardWidget;
use Tessera\Engine\ModuleRegistry;

class DashboardController extends Controller
{
    public function __construct(private SalesAnalyticsService $analytics, private BranchAccess $access, private ModuleRegistry $modules) {}

    public function index(DashboardRequest $request): Response
    {
        $user = $request->user();
        $companyId = $user->company_id;
        $branchId = $this->access->effective($user, $request->query('branch_id'));

        // If user has no company, show empty dashboard
        if (! $companyId) {
            return Inertia::render('Dashboard', [
                'stats' => [
                    'todaySales' => 0,
                    'todayOrders' => 0,
                    'activeProducts' => 0,
                    'branches' => 0,
                ],
                'recentOrders' => [],
                'widgets' => [],
                'currency' => 'USD',
                'charts' => null,
            ]);
        }

        return Inertia::render('Dashboard', [
            'stats' => fn () => $this->stats($companyId, $branchId),
            'recentOrders' => fn () => $this->recentOrders($companyId, $branchId),
            'widgets' => fn () => $this->widgets($user, $branchId),
            'charts' => fn () => $this->charts($companyId, $branchId, $request->period()),
            'filters' => ['branch_id' => $this->access->canSeeAll($user) ? $branchId : null],
        ]);
    }

    /**
     * @return array{todaySales: float, todayOrders: int, activeProducts: int, branches: int}
     */
    private function stats(int $companyId, ?int $branchId): array
    {
        $todayOrdersQuery = Order::query()
            ->where('company_id', $companyId)
            ->where('status', OrderStatus::Completed)
            ->whereDate('created_at', now()->startOfDay());

        if ($branchId) {
            $todayOrdersQuery->where('branch_id', $branchId);
        }

        return [
            'todaySales' => (float) $todayOrdersQuery->sum('total'),
            'todayOrders' => $todayOrdersQuery->count(),
            'activeProducts' => Product::where('company_id', $companyId)->where('is_active', true)->count(),
            'branches' => $branchId ? 1 : Branch::where('company_id', $companyId)->count(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recentOrders(int $companyId, ?int $branchId)
    {
        $query = Order::query()
            ->where('company_id', $companyId)
            ->with(['cashier:id,name'])
            ->orderByDesc('created_at')
            ->limit(10);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->get()->map(fn ($order) => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'total' => (float) $order->total,
            'status' => $order->status->value,
            'cashier_name' => $order->cashier?->name,
            'branch_name' => $order->branch?->name ?? 'Main Branch',
            'created_at' => $order->created_at->format('M d, H:i'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function charts(int $companyId, ?int $branchId, int $period): array
    {
        $to = now()->startOfDay();
        $from = $to->copy()->subDays($period - 1);

        return [
            'period' => $period,
            'series' => $this->analytics->dailySeries($companyId, $from, $to, $branchId),
            'categories' => $this->analytics->categoryShare($companyId, $from, $to, $branchId, 5),
            'payment' => $this->analytics->paymentSplit($companyId, $from, $to, $branchId),
            'topProducts' => $this->analytics->topProducts($companyId, $from, $to, $branchId, 5),
            'comparison' => $this->analytics->comparison($companyId, $from, $to, $branchId),
        ];
    }

    /**
     * @return list<array{key: string, component: string, data: array<int|string, mixed>}>
     */
    private function widgets(User $user, ?int $branchId): array
    {
        return collect($this->modules->dashboardWidgets())
            ->filter(fn (DashboardWidget $widget): bool => $widget->permission() === null || $user->can($widget->permission()))
            ->map(fn (DashboardWidget $widget): array => ['key' => $widget->key(), 'component' => $widget->component(), 'data' => $widget->data($user, $branchId)])
            ->values()
            ->all();
    }
}
