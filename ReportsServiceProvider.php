<?php

namespace App\Modules\Reports;

use App\Modules\Reports\Exports\ItemsReport;
use App\Modules\Reports\Exports\ProfitReport;
use App\Modules\Reports\Exports\SalesReport;
use Tessera\Engine\MenuItem;
use Tessera\Engine\ModuleManifest;
use Tessera\Engine\ModuleServiceProvider;
use Tessera\Engine\PlanFeature;

/**
 * Reports module.
 */
class ReportsServiceProvider extends ModuleServiceProvider
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'reports',
            name: 'Reports',
            requires: ['catalog', 'tenant', 'orders'],
        );
    }

    public function features(): array
    {
        return [
            new PlanFeature('reports', 'Reports', ['view reports', 'view sales reports', 'view profit reports', 'view inventory reports'], order: 60),
        ];
    }

    public function permissionGroups(): array
    {
        return [
            'dashboard' => ['view dashboard'],
            'reports' => ['view reports', 'view sales reports', 'view profit reports', 'view inventory reports'],
        ];
    }

    public function defaultRolePermissions(): array
    {
        return [
            'Owner' => ['view dashboard', 'view reports', 'view sales reports', 'view profit reports', 'view inventory reports'],
            'Manager' => ['view dashboard', 'view reports', 'view sales reports', 'view profit reports', 'view inventory reports'],
            'Stock Keeper' => ['view dashboard', 'view inventory reports'],
        ];
    }

    public function menu(): array
    {
        return [
            new MenuItem('main', 'nav.dashboard', 'dashboard', 'home', 'view dashboard', ['dashboard'], 10),
            new MenuItem('reports', 'reports.salesReport', 'reports.sales', 'chart', 'view reports', ['reports.sales'], 10),
            new MenuItem('reports', 'reports.itemsSalesReport', 'reports.items', 'list-chart', 'view reports', ['reports.items'], 20),
            new MenuItem('reports', 'reports.profitReport', 'reports.profit', 'profit', 'view reports', ['reports.profit'], 30),
        ];
    }

    public function exports(): array
    {
        return [
            SalesReport::class,
            ItemsReport::class,
            ProfitReport::class,
        ];
    }
}
