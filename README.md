# Tessera Reports

The dashboard and the sales, items and profit reports, with charts and period presets.

A module for [Tessera](https://github.com/Tessera-kit/tessera-kit). It needs a project made from one of the Tessera starters, the [SaaS starter](https://github.com/Tessera-kit/tessera-starter) or the [fresh starter](https://github.com/Tessera-kit/tessera-fresh): the engine, the base and the interface kit.

## Install

```bash
php artisan module:add reports
bun run build
```

`module:add` requires `tessera/tessera-reports`, runs the migrations and gives the roles of every tenant the new permissions. Without it:

```bash
composer config repositories.tessera composer https://tessera-kit.github.io/tessera-kit
composer require tessera/tessera-reports
php artisan migrate
php artisan modules:sync
bun run build
```

## What it needs

- Other Tessera modules: `catalog`, `tenant`, `orders`. Composer installs them for you.

## What it adds

| Plan feature | Permissions |
|---|---|
| Reports | view reports, view sales reports, view profit reports, view inventory reports |

Menu entries: Dashboard, Sales report, Items sales report, Profit report.

- The dashboard is built from widgets that other modules contribute (`dashboardWidgets()` hook), for example the low-stock widget of Inventory.
- Exports three reports: sales, items and profit.
- Also grants the `view dashboard` permission.


## Turn it off without removing it

Set `MODULES_ENABLED` in `.env` to the modules you want, for example `MODULES_ENABLED=catalog,inventory,customers,orders,reports`. The core modules (Auth, Team and Tenant) are always on. A module that is left out disappears from the menu, the routes and the plan features.

## Develop

The module lives in `packages/modules/reports` of the monorepo, with its tests in `Tests/Feature`. Run `php artisan module:check` after changing it: it verifies that the module only uses what it declares.

## Part of Tessera

This repository is a read-only copy of a folder in the [tessera-kit](https://github.com/Tessera-kit/tessera-kit) monorepo. Open issues and pull requests there, not here.

## License

All rights reserved until a license is added to the monorepo.
