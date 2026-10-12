<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import SidebarLayout from '@/Layouts/SidebarLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import BranchFilter from '@/Components/BranchFilter.vue';
import { useCurrency } from '@/Composables/useCurrency';
import { Building, CircleDollarSign, FileText, Package } from 'lucide-vue-next';
import DataTable from '@tessera/tessera-ui/Components/DataTable.vue';
import ChartCard from '@tessera/tessera-ui/Components/ChartCard.vue';
import { categoryLabel } from '@tessera/tessera-ui/charts/labels';
import TrendChart from '@tessera/tessera-ui/Components/TrendChart.vue';
import BarChart from '@tessera/tessera-ui/Components/BarChart.vue';
import DoughnutChart from '@tessera/tessera-ui/Components/DoughnutChart.vue';
import FilterTabs from '@tessera/tessera-ui/Components/FilterTabs.vue';
import StatCard from '@tessera/tessera-ui/Components/StatCard.vue';
import { indexWidgets } from '@/utils/widgets';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    stats: {
        type: Object,
        default: () => ({
            todaySales: 0,
            todayOrders: 0,
            activeProducts: 0,
            branches: 0,
        }),
    },
    recentOrders: {
        type: Array,
        default: () => [],
    },
    widgets: {
        type: Array,
        default: () => [],
    },
    charts: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const currency = page.props.currency;


const { t } = useI18n();

const widgetComponents = indexWidgets({
    ...import.meta.glob('/app/Modules/*/Resources/js/Widgets/*.vue'),
    ...import.meta.glob('/vendor/tessera/*/Resources/js/Widgets/*.vue'),
    ...import.meta.glob('/vendor/tessera/*/modules/*/Resources/js/Widgets/*.vue'),
});
const tableColumns2 = computed(() => [
    { key: 'col0', label: 'Order #', cellClass: 'text-sm font-medium text-fg' },
    { key: 'branch', label: t('inventory.branch'), cellClass: 'text-sm text-muted' },
    { key: 'cashier', label: t('orders.cashier'), cellClass: 'text-sm text-muted' },
    { key: 'total', label: t('common.total'), cellClass: 'text-sm font-medium text-fg' },
    { key: 'date', label: t('common.date'), cellClass: 'text-sm text-muted' },
    { key: 'status', label: t('common.status') },
]);
const { formatCurrency } = useCurrency();
const money = (value) => formatCurrency(value, currency);

const period = ref(props.charts?.period ?? 30);
const branchId = ref(props.filters?.branch_id ?? '');

const periodTabs = computed(() => [7, 30, 90].map((days) => ({ value: days, label: t('analytics.period', { count: days }) })));

watch(branchId, () => {
    router.get(route('dashboard'), { period: period.value, branch_id: branchId.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
});

watch(period, (days) => {
    if (days !== props.charts?.period) {
        router.get(route('dashboard'), { period: days, branch_id: branchId.value || undefined }, { preserveState: true, preserveScroll: true, replace: true, only: ['charts'] });
    }
});

const percentChange = (current, previous) => (previous ? Math.round(((current - previous) / previous) * 1000) / 10 : null);

const averageOrder = (revenue, orders) => (orders ? revenue / orders : 0);

const averageComparison = computed(() => {
    const { revenue, orders } = props.charts.comparison;
    const current = averageOrder(revenue.current, orders.current);
    const previous = averageOrder(revenue.previous, orders.previous);

    return { current, change: percentChange(current, previous) };
});

const changeText = (change) => (change === null ? t('analytics.noPrevious') : `${change > 0 ? '+' : ''}${change}%`);
const changeClass = (change) => (change > 0 ? 'text-status-success' : change < 0 ? 'text-status-danger' : 'text-muted');

const hasSales = computed(() => props.charts?.series.some((point) => point.revenue > 0 || point.orders > 0) ?? false);
const paymentTotal = computed(() => (props.charts ? props.charts.payment.paid + props.charts.payment.credit : 0));

const getStatusClass = (status) => {
    switch (status) {
        case 'completed':
            return 'bg-status-success-wash text-status-success';
        case 'voided':
            return 'bg-status-danger-wash text-status-danger';
        default:
            return 'bg-hover-wash text-fg';
    }
};
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <SidebarLayout>
        <template #header>
            <div>
                <h1 class="font-display text-xl font-normal text-fg">
                    {{ t('dashboard.title') }}
                </h1>
                <p class="text-sm text-muted">
                    Overview of your business
                </p>
            </div>
        </template>

        <!-- Stats Cards -->
        <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Today's Sales -->
            <div class="rounded-2xl border border-line bg-surface p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-muted">
                            {{ t('dashboard.todaySales') }}
                        </p>
                        <p class="font-display mt-2 text-2xl font-normal text-fg">
                            {{ formatCurrency(stats.todaySales, currency) }}
                        </p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-status-success-wash">
                        <CircleDollarSign class="h-6 w-6 text-status-success" />
                    </div>
                </div>
            </div>

            <!-- Today's Orders -->
            <div class="rounded-2xl border border-line bg-surface p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-muted">
                            {{ t('dashboard.todayOrders') }}
                        </p>
                        <p class="font-display mt-2 text-2xl font-normal text-fg">
                            {{ stats.todayOrders }}
                        </p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-accent-wash">
                        <FileText class="h-6 w-6 text-status-info" />
                    </div>
                </div>
            </div>

            <!-- Active Products -->
            <div class="rounded-2xl border border-line bg-surface p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-muted">
                            Active Products
                        </p>
                        <p class="font-display mt-2 text-2xl font-normal text-fg">
                            {{ stats.activeProducts }}
                        </p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-status-info-wash">
                        <Package class="h-6 w-6 text-status-info" />
                    </div>
                </div>
            </div>

            <!-- Branches -->
            <div class="rounded-2xl border border-line bg-surface p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-muted">
                            Branches
                        </p>
                        <p class="font-display mt-2 text-2xl font-normal text-fg">
                            {{ stats.branches }}
                        </p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-accent-wash">
                        <Building class="h-6 w-6 text-accent" />
                    </div>
                </div>
            </div>
        </div>

        <section v-if="charts" class="mb-8">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-display text-2xl font-normal text-fg">{{ t('analytics.title') }}</h2>
                <div class="flex flex-wrap items-center gap-3">
                    <BranchFilter v-model="branchId" />
                    <FilterTabs v-model="period" :tabs="periodTabs" />
                </div>
            </div>

            <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard :label="t('analytics.revenue')" :value="money(charts.comparison.revenue.current)">
                    <template #hint>
                        <span :class="changeClass(charts.comparison.revenue.change)">{{ changeText(charts.comparison.revenue.change) }}</span>
                        <template v-if="charts.comparison.revenue.change !== null"> {{ t('analytics.vsPrevious') }}</template>
                    </template>
                </StatCard>
                <StatCard :label="t('analytics.orders')" :value="charts.comparison.orders.current">
                    <template #hint>
                        <span :class="changeClass(charts.comparison.orders.change)">{{ changeText(charts.comparison.orders.change) }}</span>
                        <template v-if="charts.comparison.orders.change !== null"> {{ t('analytics.vsPrevious') }}</template>
                    </template>
                </StatCard>
                <StatCard :label="t('analytics.averageOrder')" :value="money(averageComparison.current)">
                    <template #hint>
                        <span :class="changeClass(averageComparison.change)">{{ changeText(averageComparison.change) }}</span>
                        <template v-if="averageComparison.change !== null"> {{ t('analytics.vsPrevious') }}</template>
                    </template>
                </StatCard>
            </div>

            <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <ChartCard class="lg:col-span-2" :title="t('analytics.salesTrend')" :description="t('analytics.salesTrendDesc')" :empty="!hasSales">
                    <TrendChart
                        :series="charts.series"
                        :format="money"
                        :label="t('analytics.salesTrend')"
                        :revenue-label="t('analytics.revenue')"
                        :orders-label="t('analytics.orders')"
                    />
                </ChartCard>
                <ChartCard :title="t('analytics.paymentSplit')" :description="t('analytics.paymentSplitDesc')" :empty="paymentTotal === 0">
                    <DoughnutChart
                        :labels="[t('analytics.paid'), t('analytics.credit')]"
                        :values="[charts.payment.paid, charts.payment.credit]"
                        :format="money"
                        :label="t('analytics.paymentSplit')"
                    />
                </ChartCard>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <ChartCard :title="t('analytics.topProducts')" :description="t('analytics.topProductsRevenueDesc')" :empty="!charts.topProducts.length">
                    <BarChart
                        horizontal
                        :labels="charts.topProducts.map((product) => product.name)"
                        :datasets="[{ label: t('analytics.revenue'), data: charts.topProducts.map((product) => product.revenue) }]"
                        :format="money"
                        :label="t('analytics.topProducts')"
                    />
                </ChartCard>
                <ChartCard :title="t('analytics.byCategory')" :description="t('analytics.byCategoryDesc')" :empty="!charts.categories.length">
                    <DoughnutChart
                        :labels="charts.categories.map((category) => categoryLabel(category, t))"
                        :values="charts.categories.map((category) => category.revenue)"
                        :format="money"
                        :label="t('analytics.byCategory')"
                    />
                </ChartCard>
            </div>
        </section>

        <!-- Blocks the other modules add -->
        <component
            :is="widgetComponents[widget.component]"
            v-for="widget in widgets"
            :key="widget.key"
            :data="widget.data"
        />

        <!-- Recent Orders Table -->
        <div class="rounded-2xl border border-line bg-surface">
            <div class="border-b border-line p-6">
                <h3 class="font-display text-lg font-normal text-fg">
                    {{ t('dashboard.recentOrders') }}
                </h3>
            </div>
            <DataTable
                :columns="tableColumns2"
                :rows="recentOrders"
                :empty-title="'No orders yet. Start selling!'"
                :card="false"
            >
                <template #cell-col0="{ row: order }">
                    {{ order.order_number }}
                </template>
                <template #cell-branch="{ row: order }">
                    {{ order.branch_name || 'Main Branch' }}
                </template>
                <template #cell-cashier="{ row: order }">
                    {{ order.cashier_name }}
                </template>
                <template #cell-total="{ row: order }">
                    {{ formatCurrency(order.total, currency) }}
                </template>
                <template #cell-date="{ row: order }">
                    {{ order.created_at }}
                </template>
                <template #cell-status="{ row: order }">
                    <span
                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="getStatusClass(order.status)"
                    >
                        {{ t(`orders.${order.status}`) }}
                    </span>
                </template>
            </DataTable>
        </div>
    </SidebarLayout>
</template>
