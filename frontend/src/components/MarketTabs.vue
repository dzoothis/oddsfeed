<template>
  <div class="market-tabs">
    <!-- Tab Navigation -->
    <div class="flex border-b border-gray-200 mb-4 overflow-x-auto">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        @click="activeTab = tab.id"
        class="px-4 py-2 text-sm font-medium whitespace-nowrap border-b-2 transition-colors"
        :class="[
          activeTab === tab.id
            ? 'border-blue-500 text-blue-600'
            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
        ]"
      >
        {{ tab.label }}
        <span class="ml-2 bg-gray-100 text-gray-600 py-0.5 px-2 rounded-full text-xs">
          {{ getMarketCount(tab.id) }}
        </span>
      </button>
    </div>

    <!-- Filters -->
    <MarketFilters
      :markets="getMarketsForTab(activeTab)"
      :tabId="activeTab"
      :sportId="sportId"
      :match="match"
      @filter-change="handleFilterChange"
    />

    <!-- Tab Content -->
    <div class="tab-content min-h-[200px]">
      <div v-if="loading" class="flex justify-center py-8">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
      </div>
      
      <div v-else-if="filteredMarkets.length === 0" class="text-center py-8 text-gray-500">
        No markets found for this category.
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div 
          v-for="(market, index) in paginatedMarkets" 
          :key="index"
          class="bg-gray-50 p-3 rounded-lg border border-gray-200 hover:border-blue-300 transition-colors"
        >
          <div class="flex justify-between items-start mb-2">
            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
              {{ market.period || 'Full Time' }}
            </span>
            <span 
              class="px-2 py-0.5 text-xs rounded-full"
              :class="market.status === 'Open' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
            >
              {{ market.status || 'Closed' }}
            </span>
          </div>

          <div class="flex justify-between items-center">
            <div class="flex flex-col">
              <span class="font-medium text-gray-900">
                {{ getMarketLabel(market) }}
              </span>
              <span v-if="market.line" class="text-sm text-gray-600">
                {{ market.line }}
              </span>
            </div>
            <span class="text-lg font-bold text-blue-600">
              {{ market.price }}
            </span>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="flex justify-center mt-6 gap-2">
        <button
          @click="currentPage--"
          :disabled="currentPage === 1"
          class="px-3 py-1 rounded border border-gray-300 disabled:opacity-50"
        >
          Previous
        </button>
        <span class="px-3 py-1 text-sm text-gray-600">
          Page {{ currentPage }} of {{ totalPages }}
        </span>
        <button
          @click="currentPage++"
          :disabled="currentPage === totalPages"
          class="px-3 py-1 rounded border border-gray-300 disabled:opacity-50"
        >
          Next
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import MarketFilters from './MarketFilters.vue';

const props = defineProps({
  match: { type: Object, required: true },
  sportId: { type: Number, required: true }
});

const activeTab = ref('moneyLine');
const currentPage = ref(1);
const itemsPerPage = 12;
const filters = ref({});
const loading = ref(false);

const tabs = [
  { id: 'moneyLine', label: 'Money Line' },
  { id: 'spreads', label: 'Spreads' },
  { id: 'totals', label: 'Totals' },
  { id: 'teamTotals', label: 'Team Totals' },
  { id: 'teamProps', label: 'Team Props' },
  { id: 'corners', label: 'Corners' },
  { id: 'yellowCards', label: 'Cards' },
  { id: 'incidents', label: 'Incidents' }
];

const getMarketsForTab = (tabId) => {
  if (!props.match.markets) return [];
  const allMarkets = Array.isArray(props.match.markets) ? props.match.markets : [];
  
  switch (tabId) {
    case 'moneyLine': return allMarkets.filter(m => m.marketType === 'moneyLine');
    case 'spreads': return allMarkets.filter(m => m.marketType === 'spreads');
    case 'totals': return allMarkets.filter(m => m.marketType === 'totals');
    case 'teamTotals': return allMarkets.filter(m => m.marketType === 'teamTotals');
    case 'teamProps': return allMarkets.filter(m => m.marketType === 'teamProps');
    case 'corners': return allMarkets.filter(m => m.marketType === 'corners');
    case 'yellowCards': return allMarkets.filter(m => m.marketType === 'yellowCards');
    case 'incidents': return allMarkets.filter(m => m.marketType === 'incidents');
    default: return [];
  }
};

const getMarketCount = (tabId) => {
  return getMarketsForTab(tabId).length;
};

const filteredMarkets = computed(() => {
  let markets = getMarketsForTab(activeTab.value);
  
  if (filters.value.searchQuery) {
    const q = filters.value.searchQuery.toLowerCase();
    markets = markets.filter(m =>
      (m.bet && m.bet.toLowerCase().includes(q))
    );
  }
  
  if (filters.value.selectedPeriods?.length) {
    markets = markets.filter(m => filters.value.selectedPeriods.includes(m.period));
  }

  if (filters.value.teamType?.length) {
    markets = markets.filter(m => {
      const match = props.match;
      if (!match) return true;

      // Check if this market is for Home or Away team
      let marketTeamType = null;

      // Check team IDs first (preferred method)
      if (m.home_team_id && match.home_team_id && m.home_team_id === match.home_team_id) {
        marketTeamType = 'Home';
      } else if (m.away_team_id && match.away_team_id && m.away_team_id === match.away_team_id) {
        marketTeamType = 'Away';
      }
      // Fallback to string matching
      else if (m.bet && match.home_team && m.bet.includes(match.home_team)) {
        marketTeamType = 'Home';
      } else if (m.bet && match.away_team && m.bet.includes(match.away_team)) {
        marketTeamType = 'Away';
      }

      return marketTeamType && filters.value.teamType.includes(marketTeamType);
    });
  }

  return markets;
});

const totalPages = computed(() => Math.ceil(filteredMarkets.value.length / itemsPerPage));

const paginatedMarkets = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage;
  return filteredMarkets.value.slice(start, start + itemsPerPage);
});

const getMarketLabel = (market) => {
  if (market.bet) return market.bet;
  return 'Market';
};

const handleFilterChange = (newFilters) => {
  filters.value = newFilters;
  currentPage.value = 1;
};

watch(activeTab, () => {
  currentPage.value = 1;
  filters.value = {};
});
</script>
