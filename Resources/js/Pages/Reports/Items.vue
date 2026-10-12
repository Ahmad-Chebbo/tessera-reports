<script setup>
import BranchFilter from '@/Components/BranchFilter.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCurrency } from '@/Composables/useCurrency';
import SidebarLayout from '@/Layouts/SidebarLayout.vue';
import debounce from 'lodash/debounce';
import Select from '@tessera/tessera-ui/Components/Select.vue';
import DataTable from '@tessera/tessera-ui/Components/DataTable.vue';
import ChartCard from '@tessera/tessera-ui/Components/ChartCard.vue';
import { categoryLabel } from '@tessera/tessera-ui/charts/labels';
import TrendChart from '@tessera/tessera-ui/Components/TrendChart.vue';
import BarChart from '@tessera/tessera-ui/Components/BarChart.vue';
import DoughnutChart from '@tessera/tessera-ui/Components/DoughnutChart.vue';
import DatePicker from '@tessera/tessera-ui/Components/DatePicker.vue';
import PeriodPresets from '@tessera/tessera-ui/Components/PeriodPresets.vue';

const props = defineProps({
    presets: { type: Array, default: () => [] },
    branchId: { type: [Number, String], default: null },
    items: Object,
    categories: Array,
    totals: Object,
    filters: Object,
    charts: Object,
});

const { t } = useI18n();

const tableColumns = computed(() => [
    { key: 'col0', label: '#', cellClass: 'text-sm text-muted', wrap: true },
    { key: 'productName', label: t('products.productName'), wrap: true },
    { key: 'category', label: t('products.category'), cellClass: 'text-sm text-muted', wrap: true },
    { key: 'quantity', label: t('common.quantity'), cellClass: 'text-sm font-medium text-fg', wrap: true },
    { key: 'orders', label: t('reports.orders'), cellClass: 'text-sm text-muted', wrap: true },
    { key: 'totalRevenue', label: t('reports.totalRevenue'), align: 'end', cellClass: 'text-sm font-semibold text-fg', wrap: true },
]);
const { formatCurrency } = useCurrency();
const money = (value) => formatCurrency(value);
const page = usePage();
const currency = page.props.currency;

const dateFrom = ref(props.filters?.dateFrom || '');
const dateTo = ref(props.filters?.dateTo || '');
const branch = ref(props.branchId ?? '');
const categoryId = ref(props.filters?.categoryId || '');
const search = ref(props.filters?.search || '');

const applyFilters = debounce(() => {
    router.get(route('reports.items'), {
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        category_id: categoryId.value || undefined,
        search: search.value || undefined,
        branch_id: branch.value || undefined,
    }, { preserveState: true, replace: true });
}, 400);

watch([dateFrom, dateTo, categoryId, search, branch], applyFilters);
</script>

<template>
    <Head :title="t('reports.itemsSalesReport')" />

    <SidebarLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-display text-xl font-normal text-fg">{{ t('reports.itemsSalesReport') }}</h1>
                    <p class="text-sm text-muted">{{ t('reports.analyzeItemSales') }}</p>
                </div>
            </div>
        </template>

        <div class="mb-4 flex justify-end print:hidden">
            <ExportMenu report="items" :filters="{ date_from: dateFrom, date_to: dateTo, category_id: categoryId, search, branch_id: branch }" />
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

        <!-- Summary Cards -->
        <div class="mb-6 grid grid-cols-3 gap-4">
            <div class="rounded-2xl border border-line bg-surface p-5">
                <p class="text-sm text-muted">{{ t('reports.totalRevenue') }}</p>
                <p class="font-display mt-1 text-2xl font-normal text-fg">{{ formatCurrency(totals.totalRevenue, currency) }}</p>
            </div>
            <div class="rounded-2xl border border-line bg-surface p-5">
                <p class="text-sm text-muted">{{ t('reports.totalQuantity') }}</p>
                <p class="font-display mt-1 text-2xl font-normal text-fg">{{ totals.totalQuantity }}</p>
            </div>
            <div class="rounded-2xl border border-line bg-surface p-5">
                <p class="text-sm text-muted">{{ t('reports.distinctProducts') }}</p>
                <p class="font-display mt-1 text-2xl font-normal text-fg">{{ totals.totalItems }}</p>
            </div>
        </div>

        <div v-if="charts" class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <ChartCard :class="filters?.categoryId ? 'lg:col-span-2' : ''" :title="t('analytics.topProducts')" :description="t('analytics.topProductsRevenueDesc')" :empty="!charts.topProducts.length">
                <BarChart
                    horizontal
                    :labels="charts.topProducts.map((product) => product.name)"
                    :datasets="[{ label: t('analytics.revenue'), data: charts.topProducts.map((product) => product.revenue) }]"
                    :format="money"
                    :label="t('analytics.topProducts')"
                />
            </ChartCard>
            <ChartCard v-if="!filters?.categoryId" :title="t('analytics.byCategory')" :description="t('analytics.byCategoryDesc')" :empty="!charts.categories.length">
                <DoughnutChart
                    :labels="charts.categories.map((category) => categoryLabel(category, t))"
                    :values="charts.categories.map((category) => category.revenue)"
                    :format="money"
                    :label="t('analytics.byCategory')"
                />
            </ChartCard>
        </div>

        <!-- Items Table -->
        <div class="overflow-hidden rounded-2xl bg-surface border border-line">
            <DataTable
                :columns="tableColumns"
                :rows="items.data"
                :empty-title="t('reports.noData')"
                :card="false"
            >
                <template #cell-col0="{ row: item, index: index }">
                    {{ (items.current_page - 1) * items.per_page + index + 1 }}
                </template>
                <template #cell-productName="{ row: item, index: index }">
                    <p class="font-medium text-fg">{{ item.name }}</p>
                    <p v-if="item.sku" class="text-xs text-faint">{{ item.sku }}</p>
                </template>
                <template #cell-category="{ row: item, index: index }">
                    {{ item.category_name || '-' }}
                </template>
                <template #cell-quantity="{ row: item, index: index }">
                    {{ item.total_quantity }}
                </template>
                <template #cell-orders="{ row: item, index: index }">
                    {{ item.order_count }}
                </template>
                <template #cell-totalRevenue="{ row: item, index: index }">
                    {{ formatCurrency(item.total_revenue, currency) }}
                </template>
            </DataTable>

            <!-- Pagination -->
            <div v-if="items.links?.length > 3" class="border-t border-line px-6 py-4">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-muted">Showing {{ items.from }} to {{ items.to }} of {{ items.total }}</div>
                    <div class="flex gap-2">
                        <template v-for="link in items.links" :key="link.label">
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
