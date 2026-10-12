<?php

namespace App\Modules\Reports\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tessera\Engine\Exports\ExportContext;
use Tessera\Engine\Exports\ReportDefinition;

/**
 * Shared behaviour of the sales, items and profit reports: the same period defaults and limits as the on-screen reports.
 */
abstract class AnalyticsReport extends ReportDefinition
{
    private const DEFAULT_DAYS = 30;

    private const MAX_DAYS = 366;

    public function permission(): string
    {
        return 'view reports';
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function range(ExportContext $context): array
    {
        return $context->remember('range', function () use ($context): array {
            $today = CarbonImmutable::now($context->timezone())->startOfDay();
            $to = $context->date('date_to') ?? $today;
            $from = $context->date('date_from') ?? $to->subDays(self::DEFAULT_DAYS - 1);

            if ($from->greaterThan($to)) {
                [$from, $to] = [$to, $from];
            }

            if ($from->diffInDays($to) >= self::MAX_DAYS) {
                $from = $to->subDays(self::MAX_DAYS - 1);
            }

            return [$from, $to];
        });
    }

    public function filename(ExportContext $context): string
    {
        [$from, $to] = $this->range($context);

        return str_replace('_', '-', $this->key())."_{$from->toDateString()}_{$to->toDateString()}";
    }

    public function filterRules(int $tenantId): array
    {
        return $this->branchRules($tenantId);
    }

    /**
     * @return list<string>
     */
    public function filterLines(ExportContext $context): array
    {
        [$from, $to] = $this->range($context);

        return array_values(array_filter([
            $context->t('filters.period', ['from' => $from->toDateString(), 'to' => $to->toDateString()]),
            $this->branchLine($context),
        ]));
    }

    /**
     * Counts the rows of a grouped query without loading them.
     */
    protected function countGrouped(Builder $grouped): int
    {
        return (int) DB::query()->fromSub($grouped->cloneWithout(['orders', 'limit', 'offset']), 'grouped')->count();
    }
}
