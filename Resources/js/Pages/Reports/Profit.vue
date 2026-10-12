<script setup>
import BranchFilter from '@/Components/BranchFilter.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCurrency } from '@/Composables/useCurrency';
import SidebarLayout from '@/Layouts/SidebarLayout.vue';
import debounce from 'lodash/debounce';
import Select from '@ahmad-chebbo/tessera-ui/Components/Select.vue';
import DataTable from '@ahmad-chebbo/tessera-ui/Components/DataTable.vue';
import ChartCard from '@ahmad-chebbo/tessera-ui/Components/ChartCard.vue';
import BarChart from '@ahmad-chebbo/tessera-ui/Components/BarChart.vue';
import LineChart from '@ahmad-chebbo/tessera-ui/Components/LineChart.vue';
import DatePicker from '@ahmad-chebbo/tessera-ui/Components/DatePicker.vue';
import PeriodPresets from '@ahmad-chebbo/tessera-ui/Components/PeriodPresets.vue';

const props = defineProps({
    net: { type: Object, default: null },
    canIncludeExpenses: { type: Boolean, default: false },
    presets: { type: Array, default: () => [] },
    branchId: { type: [Number, String], default: null },
    products: Object,
    categories: Array,
    stats: Object,
    filters: Object,
    charts: Object,
});

const { t } = useI18n();

const tableColumns = computed(() => [
    { key: 'col0', label: '#', cellClass: 'text-sm text-muted', wrap: true },
    { key: 'productName', label: t('products.productName'), wrap: true },
    { key: 'category', label: t('products.category'), cellClass: 'text-sm text-muted', wrap: true },
    { key: 'quantity', label: t('common.quantity'), cellClass: 'text-sm text-fg', wrap: true },
    { key: 'totalRevenue', label: t('reports.totalRevenue'), align: 'end', cellClass: 'text-sm text-fg', wrap: true },
    { key: 'totalCost', label: t('reports.totalCost'), align: 'end', cellClass: 'text-sm text-muted', wrap: true },
    { key: 'totalProfit', label: t('reports.totalProfit'), align: 'end', cellClass: 'text-sm font-semibold', wrap: true },
    { key: 'margin', label: t('reports.margin'), align: 'end', cellClass: 'text-sm font-medium', wrap: true },
]);
const { formatCurrency } = useCurrency();
const money = (value) => formatCurrency(value);
const hasProfitData = computed(() => props.charts?.daily.some((point) => point.revenue > 0) ?? false);

const dateFrom = ref(props.filters?.dateFrom || '');
const dateTo = ref(props.filters?.dateTo || '');
const branch = ref(props.branchId ?? '');
const categoryId = ref(props.filters?.categoryId || '');
const search = ref(props.filters?.search || '');
const includeExpenses = ref(props.filters?.includeExpenses || false);

const applyFilters = debounce(() => {
    router.get(route('reports.profit'), {
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        category_id: categoryId.value || undefined,
        search: search.value || undefined,
        branch_id: branch.value || undefined,
        include_expenses: includeExpenses.value ? 1 : undefined,
    }, { preserveState: true, replace: true });
}, 400);

watch([dateFrom, dateTo, categoryId, search, branch, includeExpenses], applyFilters);

const marginClass = (margin) => {
    if (margin >= 30) return 'text-status-success';
    if (margin >= 10) return 'text-status-warn';
    return 'text-status-danger';
};
</script>

<template>
    <Head :title="t('reports.profitReport')" />

    <SidebarLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-display text-xl font-normal text-fg">{{ t('reports.profitReport') }}</h1>
                    <p class="text-sm text-muted">{{ t('reports.analyzeProfit') }}</p>
                </div>
            </div>
        </template>

        <div class="mb-4 flex justify-end print:hidden">
            <ExportMenu report="profit" :filters="{ date_from: dateFrom, date_to: dateTo, category_id: categoryId, search, branch_id: branch }" />
        </div>

        <!-- Filters -->
        <PeriodPresets
            v-if="presets.length"
            class="mb-3"
            :presets="presets"
            :from="dateFrom"
            :to="dateTo"
            @select="({ from, to }) => { dateFrom = from; dateTo = to; }"
        />

        <div class="mb-6 rounded-2xl border border-line bg-surface p-4">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-muted">{{ t('reports.fromDate') }}</label>
                    <DatePicker v-model="dateFrom" :max="dateTo || null" class="w-44" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-muted">{{ t('reports.toDate') }}</label>
                    <DatePicker v-model="dateTo" :min="dateFrom || null" class="w-44" />
                    <BranchFilter v-model="branch" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-muted">{{ t('products.category') }}</label>
                    <Select v-model="categoryId"
                        class="w-full">
                        <option value="">{{ t('pos.allCategories') }}</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                    </Select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-muted">{{ t('common.search') }}</label>
                    <input v-model="search" type="text" :placeholder="t('products.productName')"
                        class="block w-full rounded-2xl border border-line bg-surface p-2 text-sm" />
                </div>
            </div>
        </div>

        <label v-if="canIncludeExpenses" class="mb-4 inline-flex items-center gap-2 text-sm text-fg">
            <input v-model="includeExpenses" type="checkbox" class="rounded border-line text-accent focus:ring-ring-accent" />
            {{ t('expenses.includeExpenses') }}
        </label>

        <!-- Summary Stats -->
        <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-2xl border border-line bg-surface p-5">
                <p class="text-sm text-muted">{{ t('reports.totalRevenue') }}</p>
                <p class="font-display mt-1 text-2xl font-normal text-fg">{{ formatCurrency(stats.totalRevenue) }}</p>
            </div>
            <div class="rounded-2xl border border-line bg-surface p-5">
                <p class="text-sm text-muted">{{ t('reports.totalCost') }}</p>
                <p class="font-display mt-1 text-2xl font-normal text-fg">{{ formatCurrency(stats.totalCost) }}</p>
            </div>
            <div class="rounded-2xl border border-line bg-surface p-5">
                <p class="text-sm text-muted">{{ t('reports.totalProfit') }}</p>
                <p class="font-display mt-1 text-2xl font-normal" :class="stats.totalProfit >= 0 ? 'text-status-success' : 'text-status-danger'">
                    {{ formatCurrency(stats.totalProfit) }}
                </p>
            </div>
            <div class="rounded-2xl border border-line bg-surface p-5">
                <p class="text-sm text-muted">{{ t('reports.profitMargin') }}</p>
                <p class="font-display mt-1 text-2xl font-normal" :class="marginClass(stats.profitMargin)">
                    {{ stats.profitMargin }}%
                </p>
            </div>
        </div>

        <div v-if="includeExpenses && net" class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-line bg-surface p-5">
                <p class="text-sm text-muted">{{ t('expenses.discountsCard') }}</p>
                <p class="font-display mt-1 text-2xl font-normal text-fg">{{ formatCurrency(net.discounts) }}</p>
            </div>
            <div class="rounded-2xl border border-line bg-surface p-5">
                <p class="text-sm text-muted">{{ t('expenses.expensesCard') }}</p>
                <p class="font-display mt-1 text-2xl font-normal text-fg">{{ formatCurrency(net.expenses) }}</p>
            </div>
            <div class="col-span-2 rounded-2xl border border-line bg-surface p-5 sm:col-span-1">
                <p class="text-sm text-muted">{{ t('expenses.netProfitCard') }}</p>
                <p class="font-display mt-1 text-2xl font-normal" :class="net.net_profit >= 0 ? 'text-status-success' : 'text-status-danger'">{{ formatCurrency(net.net_profit) }}</p>
            </div>
        </div>
        <p v-else-if="includeExpenses" class="mb-6 rounded-lg bg-status-warn-wash px-4 py-3 text-sm text-status-warn">{{ t('expenses.netUnavailable') }}</p>

        <div v-if="charts" class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <ChartCard class="lg:col-span-2" :title="t('analytics.profitTrend')" :description="t('analytics.profitTrendDesc')" :empty="!hasProfitData">
                <LineChart
                    :dates="charts.daily.map((point) => point.date)"
                    :datasets="[
                        { label: t('analytics.revenue'), data: charts.daily.map((point) => point.revenue), color: 'accent' },
                        { label: t('analytics.cost'), data: charts.daily.map((point) => point.cost), color: 'danger' },
                        { label: t('analytics.profit'), data: charts.daily.map((point) => point.profit), color: 'success', fill: true },
                    ]"
                    :format="money"
                    :label="t('analytics.profitTrend')"
                />
            </ChartCard>
            <ChartCard :title="t('analytics.topProducts')" :description="t('analytics.topProductsProfitDesc')" :empty="!charts.topProducts.length">
                <BarChart
                    horizontal
                    :labels="charts.topProducts.map((product) => product.name)"
                    :datasets="[{ label: t('analytics.profit'), data: charts.topProducts.map((product) => product.profit), color: 'success' }]"
                    :format="money"
                    :label="t('analytics.topProducts')"
                />
            </ChartCard>
        </div>

        <!-- Products Profit Table -->
        <div class="overflow-hidden rounded-2xl bg-surface border border-line">
            <DataTable
                :columns="tableColumns"
                :rows="products.data"
                :empty-title="t('reports.noData')"
                :card="false"
            >
                <template #cell-col0="{ row: product, index: index }">
                    {{ (products.current_page - 1) * products.per_page + index + 1 }}
                </template>
                <template #cell-productName="{ row: product, index: index }">
                    <p class="font-medium text-fg">{{ product.name }}</p>
                    <p v-if="product.sku" class="text-xs text-faint">{{ product.sku }}</p>
                </template>
                <template #cell-category="{ row: product, index: index }">
                    {{ product.category_name || '-' }}
                </template>
                <template #cell-quantity="{ row: product, index: index }">
                    {{ product.total_quantity }}
                </template>
                <template #cell-totalRevenue="{ row: product, index: index }">
                    {{ formatCurrency(product.total_revenue) }}
                </template>
                <template #cell-totalCost="{ row: product, index: index }">
                    {{ formatCurrency(product.total_cost) }}
                </template>
                <template #cell-totalProfit="{ row: product, index: index }">
                    <span :class="product.total_profit >= 0 ? 'text-status-success' : 'text-status-danger'">{{ formatCurrency(product.total_profit) }}</span>
                </template>
                <template #cell-margin="{ row: product, index: index }">
                    <span :class="marginClass(product.total_revenue > 0 ? (product.total_profit / product.total_revenue) * 100 : 0)">{{ product.total_revenue > 0 ? ((product.total_profit / product.total_revenue) * 100).toFixed(1) : '0.0' }}%</span>
                </template>
            </DataTable>

            <!-- Pagination -->
            <div v-if="products.links?.length > 3" class="border-t border-line px-6 py-4">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-muted">Showing {{ products.from }} to {{ products.to }} of {{ products.total }}</div>
                    <div class="flex gap-2">
                        <template v-for="link in products.links" :key="link.label">
                            <Link v-if="link.url" :href="link.url" class="rounded-lg px-3 py-2 text-sm"
                                :class="link.active ? 'bg-gold text-ink' : 'bg-hover-wash text-muted hover:bg-line'"
                                v-html="link.label" />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </SidebarLayout>
</template>
