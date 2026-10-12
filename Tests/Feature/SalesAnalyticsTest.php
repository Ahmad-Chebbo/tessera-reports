<?php

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Company\Models\Branch;
use App\Modules\Company\Models\Company;
use App\Modules\Reports\Services\SalesAnalyticsService;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;
use App\Modules\Team\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

function analyticsOrder(array $order, array $items = [], ?Carbon $at = null): Order
{
    static $sequence = 0;
    $sequence++;

    $created = Order::create(array_merge([
        'order_number' => 'ANALYTICS-'.$sequence,
        'subtotal' => 0,
        'total' => 0,
        'status' => OrderStatus::Completed,
        'payment_type' => 'cash',
    ], $order));

    foreach ($items as $item) {
        $created->items()->create(array_merge([
            'quantity' => 1,
            'unit_price' => $item['total'],
            'cost_price' => 0,
        ], $item));
    }

    if ($at) {
        $created->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    return $created;
}

beforeEach(function () {
    Carbon::setTestNow('2026-05-15 12:00:00');

    $this->company = Company::factory()->create(['currency' => 'USD']);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->otherBranch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id]);

    $this->drinks = Category::factory()->create(['company_id' => $this->company->id, 'name' => 'Drinks']);
    $this->food = Category::factory()->create(['company_id' => $this->company->id, 'name' => 'Food']);
    $this->cola = Product::factory()->create(['company_id' => $this->company->id, 'category_id' => $this->drinks->id, 'name' => 'Cola']);
    $this->burger = Product::factory()->create(['company_id' => $this->company->id, 'category_id' => $this->food->id, 'name' => 'Burger']);

    $this->base = [
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'cashier_id' => $this->user->id,
    ];

    $this->analytics = app(SalesAnalyticsService::class);
    $this->from = Carbon::parse('2026-05-09');
    $this->to = Carbon::parse('2026-05-15');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('builds a zero-filled daily revenue series counting only completed orders of the company', function () {
    analyticsOrder($this->base + ['total' => 30], [], Carbon::parse('2026-05-10 09:00'));
    analyticsOrder($this->base + ['total' => 20], [], Carbon::parse('2026-05-10 18:00'));
    analyticsOrder($this->base + ['total' => 50, 'status' => OrderStatus::Voided], [], Carbon::parse('2026-05-11'));
    analyticsOrder($this->base + ['total' => 70, 'status' => OrderStatus::Debt], [], Carbon::parse('2026-05-12'));
    analyticsOrder(['company_id' => Company::factory()->create()->id, 'branch_id' => Branch::factory()->create()->id, 'cashier_id' => $this->user->id, 'total' => 999], [], Carbon::parse('2026-05-10'));

    $series = $this->analytics->dailySeries($this->company->id, $this->from, $this->to);

    expect($series)->toHaveCount(7)
        ->and($series[0])->toBe(['date' => '2026-05-09', 'revenue' => 0.0, 'orders' => 0])
        ->and($series[1])->toBe(['date' => '2026-05-10', 'revenue' => 50.0, 'orders' => 2])
        ->and($series[2])->toBe(['date' => '2026-05-11', 'revenue' => 0.0, 'orders' => 0])
        ->and($series[6]['date'])->toBe('2026-05-15');
});

it('includes the whole last day of the range', function () {
    analyticsOrder($this->base + ['total' => 12], [], Carbon::parse('2026-05-15 23:59:00'));

    $series = $this->analytics->dailySeries($this->company->id, $this->from, $this->to);

    expect(end($series))->toBe(['date' => '2026-05-15', 'revenue' => 12.0, 'orders' => 1]);
});

it('can be limited to one branch', function () {
    analyticsOrder($this->base + ['total' => 10], [], Carbon::parse('2026-05-10'));
    analyticsOrder(array_merge($this->base, ['branch_id' => $this->otherBranch->id, 'total' => 25]), [], Carbon::parse('2026-05-10'));

    $all = $this->analytics->dailySeries($this->company->id, $this->from, $this->to);
    $one = $this->analytics->dailySeries($this->company->id, $this->from, $this->to, $this->branch->id);

    expect($all[1]['revenue'])->toBe(35.0)
        ->and($one[1]['revenue'])->toBe(10.0);
});

it('builds a profit series with revenue, cost and profit per day', function () {
    $order = analyticsOrder($this->base + ['total' => 100], [
        ['product_id' => $this->cola->id, 'quantity' => 2, 'total' => 60, 'cost_price' => 10],
        ['product_id' => $this->burger->id, 'quantity' => 1, 'total' => 40, 'cost_price' => 25],
    ], Carbon::parse('2026-05-12'));

    $series = $this->analytics->profitSeries($this->company->id, $this->from, $this->to);
    $day = collect($series)->firstWhere('date', '2026-05-12');

    expect($order)->not->toBeNull()
        ->and($series)->toHaveCount(7)
        ->and($day)->toBe(['date' => '2026-05-12', 'revenue' => 100.0, 'cost' => 45.0, 'profit' => 55.0])
        ->and($series[0])->toBe(['date' => '2026-05-09', 'revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0]);
});

it('ranks top products by revenue, quantity or profit', function () {
    analyticsOrder($this->base + ['total' => 100], [
        ['product_id' => $this->cola->id, 'quantity' => 10, 'total' => 30, 'cost_price' => 2],
        ['product_id' => $this->burger->id, 'quantity' => 2, 'total' => 70, 'cost_price' => 5],
    ], Carbon::parse('2026-05-12'));

    $byRevenue = $this->analytics->topProducts($this->company->id, $this->from, $this->to, by: 'revenue');
    $byQuantity = $this->analytics->topProducts($this->company->id, $this->from, $this->to, by: 'quantity');
    $byProfit = $this->analytics->topProducts($this->company->id, $this->from, $this->to, by: 'profit');
    $limited = $this->analytics->topProducts($this->company->id, $this->from, $this->to, limit: 1);

    expect(array_column($byRevenue, 'name'))->toBe(['Burger', 'Cola'])
        ->and(array_column($byQuantity, 'name'))->toBe(['Cola', 'Burger'])
        ->and(array_column($byProfit, 'name'))->toBe(['Burger', 'Cola'])
        ->and($byRevenue[0])->toMatchArray(['name' => 'Burger', 'revenue' => 70.0, 'quantity' => 2.0, 'profit' => 60.0])
        ->and($limited)->toHaveCount(1);
});

it('ignores voided and credit orders when ranking products', function () {
    analyticsOrder($this->base + ['total' => 40, 'status' => OrderStatus::Voided], [
        ['product_id' => $this->cola->id, 'total' => 40],
    ], Carbon::parse('2026-05-12'));
    analyticsOrder($this->base + ['total' => 25, 'status' => OrderStatus::Debt, 'payment_type' => 'debt'], [
        ['product_id' => $this->burger->id, 'total' => 25],
    ], Carbon::parse('2026-05-12'));

    expect($this->analytics->topProducts($this->company->id, $this->from, $this->to))->toBe([]);
});

it('groups revenue by category, folding the tail into Other and naming missing categories', function () {
    $loose = Product::factory()->create(['company_id' => $this->company->id, 'category_id' => null, 'name' => 'Loose']);

    analyticsOrder($this->base + ['total' => 100], [
        ['product_id' => $this->cola->id, 'total' => 50],
        ['product_id' => $this->burger->id, 'total' => 30],
        ['product_id' => $loose->id, 'total' => 20],
    ], Carbon::parse('2026-05-12'));

    $all = $this->analytics->categoryShare($this->company->id, $this->from, $this->to, limit: 5);
    $folded = $this->analytics->categoryShare($this->company->id, $this->from, $this->to, limit: 2);

    expect($all)->toBe([
        ['name' => 'Drinks', 'key' => null, 'revenue' => 50.0],
        ['name' => 'Food', 'key' => null, 'revenue' => 30.0],
        ['name' => 'Uncategorized', 'key' => 'uncategorized', 'revenue' => 20.0],
    ])->and($folded)->toBe([
        ['name' => 'Drinks', 'key' => null, 'revenue' => 50.0],
        ['name' => 'Other', 'key' => 'other', 'revenue' => 50.0],
    ]);
});

it('splits revenue into paid and on-credit, excluding voided orders', function () {
    analyticsOrder($this->base + ['total' => 80], [], Carbon::parse('2026-05-12'));
    analyticsOrder($this->base + ['total' => 45, 'status' => OrderStatus::Debt, 'payment_type' => 'debt', 'debt_status' => 'unpaid'], [], Carbon::parse('2026-05-12'));
    analyticsOrder($this->base + ['total' => 99, 'status' => OrderStatus::Voided], [], Carbon::parse('2026-05-12'));

    expect($this->analytics->paymentSplit($this->company->id, $this->from, $this->to))
        ->toBe(['paid' => 80.0, 'credit' => 45.0]);
});

it('compares the period with the one before it', function () {
    analyticsOrder($this->base + ['total' => 150], [], Carbon::parse('2026-05-12'));
    analyticsOrder($this->base + ['total' => 100], [], Carbon::parse('2026-05-04'));
    analyticsOrder($this->base + ['total' => 100], [], Carbon::parse('2026-05-05'));

    $comparison = $this->analytics->comparison($this->company->id, $this->from, $this->to);

    expect($comparison['revenue'])->toBe(['current' => 150.0, 'previous' => 200.0, 'change' => -25.0])
        ->and($comparison['orders'])->toBe(['current' => 1, 'previous' => 2, 'change' => -50.0]);
});

it('reports no percentage change when the previous period had no sales', function () {
    analyticsOrder($this->base + ['total' => 150], [], Carbon::parse('2026-05-12'));

    $comparison = $this->analytics->comparison($this->company->id, $this->from, $this->to);

    expect($comparison['revenue'])->toBe(['current' => 150.0, 'previous' => 0.0, 'change' => null]);
});

it('exposes dashboard charts for the chosen period, limited to the user branch', function () {
    Permission::findOrCreate('view dashboard');
    $this->user->givePermissionTo('view dashboard');

    analyticsOrder($this->base + ['total' => 60], [['product_id' => $this->cola->id, 'total' => 60]], Carbon::parse('2026-05-14'));
    analyticsOrder(array_merge($this->base, ['branch_id' => $this->otherBranch->id, 'total' => 500]), [['product_id' => $this->burger->id, 'total' => 500]], Carbon::parse('2026-05-14'));

    $this->actingAs($this->user)
        ->get(route('dashboard', ['period' => 7]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('charts.period', 7)
            ->has('charts.series', 7)
            ->where('charts.series.5.date', '2026-05-14')
            ->where('charts.series.5.revenue', 60)
            ->where('charts.topProducts.0.name', 'Cola')
            ->where('charts.payment.paid', 60)
            ->where('charts.comparison.revenue.current', 60)
        );
});

it('falls back to 30 days for an unsupported dashboard period', function () {
    Permission::findOrCreate('view dashboard');
    $this->user->givePermissionTo('view dashboard');

    $this->actingAs($this->user)
        ->get(route('dashboard', ['period' => 5]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('charts.period', 30)->has('charts.series', 30));
});

it('adds charts to the sales report for the filtered range', function () {
    Permission::findOrCreate('view reports');
    $this->user->givePermissionTo('view reports');

    analyticsOrder($this->base + ['total' => 90], [['product_id' => $this->burger->id, 'total' => 90]], Carbon::parse('2026-05-12'));

    $this->actingAs($this->user)
        ->get(route('reports.sales', ['date_from' => '2026-05-09', 'date_to' => '2026-05-15']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/Sales')
            ->has('charts.daily', 7)
            ->where('charts.daily.3.revenue', 90)
            ->where('charts.topProducts.0.name', 'Burger')
            ->where('charts.categories.0.name', 'Food')
        );
});

it('adds charts to the items report and honours the category filter', function () {
    Permission::findOrCreate('view reports');
    $this->user->givePermissionTo('view reports');

    analyticsOrder($this->base + ['total' => 100], [
        ['product_id' => $this->cola->id, 'total' => 40],
        ['product_id' => $this->burger->id, 'total' => 60],
    ], Carbon::parse('2026-05-12'));

    $this->actingAs($this->user)
        ->get(route('reports.items', ['date_from' => '2026-05-09', 'date_to' => '2026-05-15', 'category_id' => $this->drinks->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/Items')
            ->has('charts.topProducts', 1)
            ->where('charts.topProducts.0.name', 'Cola')
            ->has('charts.categories', 1)
        );
});

it('adds charts to the profit report', function () {
    Permission::findOrCreate('view reports');
    $this->user->givePermissionTo('view reports');

    analyticsOrder($this->base + ['total' => 100], [
        ['product_id' => $this->cola->id, 'quantity' => 1, 'total' => 100, 'cost_price' => 40],
    ], Carbon::parse('2026-05-12'));

    $this->actingAs($this->user)
        ->get(route('reports.profit', ['date_from' => '2026-05-09', 'date_to' => '2026-05-15']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/Profit')
            ->has('charts.daily', 7)
            ->where('charts.daily.3.profit', 60)
            ->where('charts.topProducts.0.profit', 60)
        );
});

it('spreads an order discount over its items so item charts match order revenue', function () {
    analyticsOrder($this->base + ['subtotal' => 100, 'total' => 90], [
        ['product_id' => $this->cola->id, 'quantity' => 1, 'total' => 100, 'cost_price' => 20],
    ], Carbon::parse('2026-05-12'));

    $net = $this->analytics->topProducts($this->company->id, $this->from, $this->to);
    $gross = $this->analytics->topProducts($this->company->id, $this->from, $this->to, netOfDiscounts: false);
    $categories = $this->analytics->categoryShare($this->company->id, $this->from, $this->to);
    $profit = collect($this->analytics->profitSeries($this->company->id, $this->from, $this->to))->firstWhere('date', '2026-05-12');
    $grossProfit = collect($this->analytics->profitSeries($this->company->id, $this->from, $this->to, netOfDiscounts: false))->firstWhere('date', '2026-05-12');
    $daily = collect($this->analytics->dailySeries($this->company->id, $this->from, $this->to))->firstWhere('date', '2026-05-12');

    expect($net[0]['revenue'])->toBe(90.0)
        ->and($net[0]['profit'])->toBe(70.0)
        ->and($gross[0]['revenue'])->toBe(100.0)
        ->and($categories[0]['revenue'])->toBe(90.0)
        ->and($profit)->toMatchArray(['revenue' => 90.0, 'cost' => 20.0, 'profit' => 70.0])
        ->and($grossProfit['revenue'])->toBe(100.0)
        ->and($daily['revenue'])->toBe(90.0);
});

it('does not rank products from another company', function () {
    $other = Company::factory()->create();
    $otherBranch = Branch::factory()->create(['company_id' => $other->id]);
    $otherProduct = Product::factory()->create(['company_id' => $other->id, 'name' => 'Rival']);

    analyticsOrder(['company_id' => $other->id, 'branch_id' => $otherBranch->id, 'cashier_id' => $this->user->id, 'total' => 500], [
        ['product_id' => $otherProduct->id, 'total' => 500],
    ], Carbon::parse('2026-05-12'));

    expect($this->analytics->topProducts($this->company->id, $this->from, $this->to))->toBe([])
        ->and($this->analytics->categoryShare($this->company->id, $this->from, $this->to))->toBe([]);
});

it('narrows item charts with the report search filter', function () {
    Permission::findOrCreate('view reports');
    $this->user->givePermissionTo('view reports');

    analyticsOrder($this->base + ['total' => 100], [
        ['product_id' => $this->cola->id, 'total' => 40],
        ['product_id' => $this->burger->id, 'total' => 60],
    ], Carbon::parse('2026-05-12'));

    $this->actingAs($this->user)
        ->get(route('reports.items', ['date_from' => '2026-05-09', 'date_to' => '2026-05-15', 'search' => 'Col']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('charts.topProducts', 1)
            ->where('charts.topProducts.0.name', 'Cola')
            ->has('charts.categories', 1)
        );
});

it('uses one normalised date range for charts, stats and the echoed filters', function () {
    Permission::findOrCreate('view reports');
    $this->user->givePermissionTo('view reports');

    analyticsOrder($this->base + ['total' => 90], [], Carbon::parse('2026-05-12'));

    $this->actingAs($this->user)
        ->get(route('reports.sales', ['date_from' => '2026-05-15', 'date_to' => '2026-05-09']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('filters.date_from', '2026-05-09')
            ->where('filters.date_to', '2026-05-15')
            ->has('charts.daily', 7)
            ->where('stats.totalSales', 90)
        );
});

it('caps very long ranges for charts and stats alike', function () {
    Permission::findOrCreate('view reports');
    $this->user->givePermissionTo('view reports');

    analyticsOrder($this->base + ['total' => 777], [], Carbon::parse('2024-06-01'));
    analyticsOrder($this->base + ['total' => 90], [], Carbon::parse('2026-05-12'));

    $this->actingAs($this->user)
        ->get(route('reports.sales', ['date_from' => '2024-01-01', 'date_to' => '2026-05-15']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('charts.daily', 366)
            ->where('filters.date_from', '2025-05-15')
            ->where('stats.totalSales', 90)
        );
});

it('falls back to the last 30 days for blank, impossible or array dates instead of failing', function (array $query) {
    Permission::findOrCreate('view reports');
    $this->user->givePermissionTo('view reports');

    $this->actingAs($this->user)
        ->get(route('reports.sales', $query))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('charts.daily', 30)->where('filters.date_to', '2026-05-15'));
})->with([
    'blank' => [['date_from' => '', 'date_to' => '']],
    'impossible' => [['date_to' => '2026-02-30']],
    'garbage' => [['date_from' => 'yesterday-ish', 'date_to' => 'nope']],
    'arrays' => [['date_from' => ['x'], 'date_to' => ['y']]],
]);

it('applies the same range handling to the items and profit reports', function (string $route) {
    Permission::findOrCreate('view reports');
    $this->user->givePermissionTo('view reports');

    $this->actingAs($this->user)
        ->get(route($route, ['date_from' => '2026-05-15', 'date_to' => '2026-05-09']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('filters.dateFrom', '2026-05-09')->where('filters.dateTo', '2026-05-15'));
})->with(['reports.items', 'reports.profit']);

it('only computes the charts on a dashboard period change', function () {
    Permission::findOrCreate('view dashboard');
    $this->user->givePermissionTo('view dashboard');

    $version = $this->actingAs($this->user)->get(route('dashboard'))->viewData('page')['version'] ?? '';

    DB::enableQueryLog();

    $this->actingAs($this->user)
        ->get(route('dashboard', ['period' => 7]), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'charts',
        ])
        ->assertSuccessful()
        ->assertJsonPath('props.charts.period', 7)
        ->assertJsonMissingPath('props.recentOrders')
        ->assertJsonMissingPath('props.widgets');

    $tables = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    expect($tables)->not->toContain('stock_movements');
});

it('sends no charts for a user without a company', function () {
    Permission::findOrCreate('view dashboard');
    $loner = User::factory()->create(['company_id' => null, 'branch_id' => null]);
    $loner->givePermissionTo('view dashboard');

    $this->actingAs($loner)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('charts', null));
});

it('keeps dashboard and reports behind their permissions', function () {
    $this->actingAs($this->user)->get(route('dashboard'))->assertForbidden();
    $this->actingAs($this->user)->get(route('reports.sales'))->assertForbidden();
    $this->actingAs($this->user)->get(route('reports.items'))->assertForbidden();
    $this->actingAs($this->user)->get(route('reports.profit'))->assertForbidden();
});
