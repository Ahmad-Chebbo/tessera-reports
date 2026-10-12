<script setup>
import BranchFilter from '@/Components/BranchFilter.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SidebarLayout from '@/Layouts/SidebarLayout.vue';
import { useCurrency } from '@/Composables/useCurrency';
import { ChartColumn, CircleDollarSign, FileText } from 'lucide-vue-next';
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
    stats: Object,
    dailySales: Array,
    topProducts: Array,
    filters: Object,
    charts: Object,
    // currency: {
    //     type: String,
    //     default: currency,
    // },
});

const { t } = useI18n();
const { formatCurrency } = useCurrency();
const money = (value) => formatCurrency(value);
const hasSales = computed(() => props.charts?.daily.some((point) => point.revenue > 0 || point.orders > 0) ?? false);

const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
const branch = ref(props.branchId ?? '');

const applyFilters = () => {
    router.get(route('reports.sales'), {
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        branch_id: branch.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

watch([dateFrom, dateTo, branch], applyFilters);
</script>

<template>
    <Head :title="t('reports.salesReport')" />

    <SidebarLayout>
        <template #header>
            <div>
                <h1 class="font-display text-xl font-normal text-fg">
                    {{ t('reports.salesReport') }}
                </h1>
                <p class="text-sm text-muted">
                    {{ t('reports.analyzeSales') }}
                </p>
            </div>
        </template>

        <div class="mb-4 flex justify-end print:hidden">
            <ExportMenu report="sales" :filters="{ date_from: dateFrom, date_to: dateTo, branch_id: branch }" />
        </div>

        <!-- Date Filters -->
        <PeriodPresets
            v-if="presets.length"
            class="mb-3"
            :presets="presets"
            :from="dateFrom"
            :to="dateTo"
            @select="({ from, to }) => { dateFrom = from; dateTo = to; }"
        />

        <div class="mb-6 rounded-2xl border border-line bg-surface p-4">
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex items-center gap-2">
                    <label class="text-sm font-medium text-muted">{{ t('reports.fromDate') }}:</label>
                    <DatePicker v-model="dateFrom" :max="dateTo || null" class="w-44" />
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-sm font-medium text-muted">{{ t('reports.toDate') }}:</label>
                    <DatePicker v-model="dateTo" :min="dateFrom || null" class="w-44" />
                    <BranchFilter v-model="branch" />
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-line bg-surface p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-muted">
                            {{ t('reports.totalSales') }}
                        </p>
                        <p class="font-display mt-2 text-2xl font-normal text-fg">
                            {{ formatCurrency(stats.totalSales) }}
                        </p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-status-success-wash">
                        <CircleDollarSign class="h-6 w-6 text-status-success" />
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-line bg-surface p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-muted">
                            {{ t('reports.totalOrders') }}
                        </p>
                        <p class="font-display mt-2 text-2xl font-normal text-fg">
                            {{ stats.totalOrders }}
                        </p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-accent-wash">
                        <FileText class="h-6 w-6 text-status-info" />
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-line bg-surface p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-muted">
                            {{ t('reports.averageOrder') }}
                        </p>
                        <p class="font-display mt-2 text-2xl font-normal text-fg">
                            {{ formatCurrency(stats.averageOrder) }}
                        </p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-status-info-wash">
                        <ChartColumn class="h-6 w-6 text-status-info" />
                    </div>
                </div>
            </div>
        </div>

        <div v-if="charts" class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <ChartCard class="lg:col-span-2" :title="t('analytics.salesTrend')" :description="t('analytics.salesTrendDesc')" :empty="!hasSales">
                <TrendChart
                    :series="charts.daily"
                    :format="money"
                    :label="t('analytics.salesTrend')"
                    :revenue-label="t('analytics.revenue')"
                    :orders-label="t('analytics.orders')"
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

        <ChartCard v-if="charts" class="mb-6" :title="t('analytics.topProducts')" :description="t('analytics.topProductsRevenueDesc')" :empty="!charts.topProducts.length">
            <BarChart
                horizontal
                :labels="charts.topProducts.map((product) => product.name)"
                :datasets="[{ label: t('analytics.revenue'), data: charts.topProducts.map((product) => product.revenue) }]"
                :format="money"
                :label="t('analytics.topProducts')"
            />
        </ChartCard>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Daily Sales -->
            <div class="rounded-2xl border border-line bg-surface p-6">
                <h3 class="font-display mb-4 text-lg font-normal text-fg">
                    {{ t('reports.dailySales') }}
                </h3>

                <div v-if="dailySales.length === 0" class="py-8 text-center text-muted">
                    {{ t('reports.noData') }}
                </div>

                <div v-else class="space-y-3">
                    <div
                        v-for="day in dailySales"
                        :key="day.date"
                        class="flex items-center justify-between rounded-lg border border-line p-3"
                    >
                        <div>
                            <p class="font-medium text-fg">{{ day.date }}</p>
                            <p class="text-sm text-muted">{{ day.count }} {{ t('reports.orders') }}</p>
                        </div>
                        <div class="text-end">
                            <p class="font-semibold text-fg">
                                {{ formatCurrency(day.total) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Products -->
            <div class="rounded-2xl border border-line bg-surface p-6">
                <h3 class="font-display mb-4 text-lg font-normal text-fg">
                    {{ t('dashboard.topProducts') }}
                </h3>

                <div v-if="topProducts.length === 0" class="py-8 text-center text-muted">
                    {{ t('reports.noData') }}
                </div>

                <div v-else class="space-y-3">
                    <div
                        v-for="(product, index) in topProducts"
                        :key="product.id"
                        class="flex items-center gap-4 rounded-lg border border-line p-3"
                    >
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-accent-wash text-sm font-bold text-accent">
                            {{ index + 1 }}
                        </div>
                        <div class="flex-1">
                            <p class="font-medium text-fg">{{ product.name }}</p>
                            <p class="text-sm text-muted">
                                {{ product.total_quantity }} {{ t('reports.unitsSold') }}
                            </p>
                        </div>
                        <div class="text-end">
                            <p class="font-semibold text-fg">
                                {{ formatCurrency(product.total_revenue) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </SidebarLayout>
</template>
