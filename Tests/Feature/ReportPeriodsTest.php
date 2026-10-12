<?php

use App\Modules\Company\Models\Branch;
use App\Modules\Company\Models\Company;
use App\Modules\Team\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function presetsOf(Assert $page): array
{
    return collect($page->toArray()['props']['presets'])->mapWithKeys(fn (array $preset): array => [$preset['key'] => [$preset['from'], $preset['to']]])->all();
}

beforeEach(function () {
    Permission::findOrCreate('view reports');
    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    $branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id])->givePermissionTo('view reports');
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

it('offers the same ready-made periods on every report', function (string $route) {
    $this->actingAs($this->user)->get(route($route))
        ->assertInertia(fn (Assert $page) => $page->has('presets', 11)->where('presets.0.key', 'today'));
})->with(['reports.sales', 'reports.items', 'reports.profit']);

it('computes the periods from the shop day', function () {
    $this->actingAs($this->user)->get(route('reports.sales'))
        ->assertInertia(function (Assert $page) {
            expect(presetsOf($page))->toBe([
                'today' => ['2026-10-14', '2026-10-14'],
                'yesterday' => ['2026-10-13', '2026-10-13'],
                'last_7_days' => ['2026-10-08', '2026-10-14'],
                'last_30_days' => ['2026-09-15', '2026-10-14'],
                'last_90_days' => ['2026-07-17', '2026-10-14'],
                'this_week' => ['2026-10-12', '2026-10-14'],
                'last_week' => ['2026-10-05', '2026-10-11'],
                'this_month' => ['2026-10-01', '2026-10-14'],
                'last_month' => ['2026-09-01', '2026-09-30'],
                'this_quarter' => ['2026-10-01', '2026-10-14'],
                'this_year' => ['2026-01-01', '2026-10-14'],
            ]);

            return $page;
        });
});

it('uses the company timezone to decide what today is', function () {
    $this->company->update(['timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 23:00:00', 'UTC'));

    $this->actingAs($this->user)->get(route('reports.sales'))
        ->assertInertia(function (Assert $page) {
            expect(presetsOf($page)['today'])->toBe(['2026-10-15', '2026-10-15']);

            return $page;
        });
});

it('handles month and year boundaries', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00:00', 'UTC'));

    $this->actingAs($this->user)->get(route('reports.sales'))
        ->assertInertia(function (Assert $page) {
            $presets = presetsOf($page);
            expect($presets['last_month'])->toBe(['2026-02-01', '2026-02-28'])
                ->and($presets['this_quarter'])->toBe(['2026-01-01', '2026-03-31'])
                ->and($presets['this_week'])->toBe(['2026-03-30', '2026-03-31']);

            return $page;
        });

    $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));

    $this->actingAs($this->user)->get(route('reports.sales'))
        ->assertInertia(function (Assert $page) {
            expect(presetsOf($page)['last_month'])->toBe(['2025-12-01', '2025-12-31']);

            return $page;
        });
});

it('filters a report by the dates of a chosen period', function () {
    $this->actingAs($this->user)->get(route('reports.sales', ['date_from' => '2026-10-12', 'date_to' => '2026-10-14']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.date_from', '2026-10-12')->where('filters.date_to', '2026-10-14'));
});

it('defaults a report to exactly the last 30 days, matching the preset', function () {
    $this->actingAs($this->user)->get(route('reports.sales'))
        ->assertInertia(fn (Assert $page) => $page->where('filters.date_from', '2026-09-15')->where('filters.date_to', '2026-10-14'));
});

it('keeps every preset inside the maximum report range', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-31 10:00:00', 'UTC'));

    $this->actingAs($this->user)->get(route('reports.sales'))
        ->assertInertia(function (Assert $page) {
            foreach (presetsOf($page) as [$from, $to]) {
                expect(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)))->toBeLessThan(366);
            }

            return $page;
        });
});
