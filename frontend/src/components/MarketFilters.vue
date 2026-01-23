<template>
  <div v-if="markets && markets.length > 0" class="mb-4 border-b border-gray-200 pb-4">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-3 flex-1">
        <!-- Search Input -->
        <div class="flex-1 max-w-md relative">
          <input
            type="text"
            v-model="searchQuery"
            :placeholder="searchPlaceholder"
            class="w-full px-4 py-2 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
          />
          <span class="absolute left-3 top-2.5 text-gray-400">🔍</span>
          <button 
            v-if="searchQuery" 
            @click="searchQuery = ''"
            class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600"
          >✕</button>
        </div>

        <!-- Filter Dropdowns -->
        <div class="flex items-center gap-2 flex-wrap">
          <div v-for="(options, type) in filterOptions" :key="type" class="relative">
            <button
              v-if="shouldShowFilter(type) && options.length > 0"
              @click="toggleDropdown(type)"
              class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center gap-2"
            >
              <span class="font-medium">{{ formatLabel(type) }}</span>
              <span v-if="selectedFilters[type].length" class="text-blue-600 font-semibold">
                ({{ selectedFilters[type].length }})
              </span>
              <span v-else class="text-gray-500">All</span>
              <span>▼</span>
            </button>
            
            <div v-if="dropdownOpen[type]" class="absolute z-20 mt-1 w-64 bg-white border border-gray-300 rounded-lg shadow-lg max-h-64 overflow-y-auto">
               <div class="p-2 border-b border-gray-200">
                  <button @click="toggleSelectAll(type, options)" class="text-xs text-blue-600 font-medium">
                    {{ selectedFilters[type].length === options.length ? 'Deselect All' : 'Select All' }}
                  </button>
               </div>
               <div class="p-1">
                 <label v-for="opt in options" :key="opt" class="flex items-center px-2 py-1.5 hover:bg-gray-50 cursor-pointer">
                   <input type="checkbox" :value="opt" v-model="selectedFilters[type]" class="mr-2 text-blue-600 rounded">
                   <span class="text-sm text-gray-700">{{ opt }}</span>
                 </label>
               </div>
            </div>
            <div v-if="dropdownOpen[type]" class="fixed inset-0 z-10" @click="toggleDropdown(type)"></div>
          </div>
        </div>

        <button 
          v-if="activeFilterCount > 0"
          @click="clearAllFilters"
          class="px-3 py-1.5 text-xs text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg"
        >
          Clear All
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  markets: Array,
  tabId: String,
  searchPlaceholder: { type: String, default: 'Search markets...' }
});

const emit = defineEmits(['filter-change']);

const searchQuery = ref('');
const selectedFilters = ref({
  period: [],
  status: [],
  teamType: [],
  line: [],
  betType: [],
  cardType: [],
  eventType: [],
  teams: [],
  teamPropType: []
});
const dropdownOpen = ref({});

const filterOptions = computed(() => {
  if (!props.markets) return {};
  const m = props.markets;

  // Generate teamType based on available odds
  const teamTypes = [];
  const match = props.match; // Get match data from props

  if (match) {
    m.forEach(odd => {
      // Check team IDs first (preferred method)
      if (odd.home_team_id && match.home_team_id && odd.home_team_id === match.home_team_id) {
        teamTypes.push('Home');
      } else if (odd.away_team_id && match.away_team_id && odd.away_team_id === match.away_team_id) {
        teamTypes.push('Away');
      }
      // Fallback to string matching
      else if (odd.bet && match.home_team && odd.bet.includes(match.home_team)) {
        teamTypes.push('Home');
      } else if (odd.bet && match.away_team && odd.bet.includes(match.away_team)) {
        teamTypes.push('Away');
      }
    });
  }

  // Always provide Home/Away options even if not detected from data
  const finalTeamTypes = teamTypes.length > 0 ? [...new Set(teamTypes)] : ['Home', 'Away'];

  return {
    period: [...new Set(m.map(x => x.period).filter(Boolean))].sort(),
    status: [...new Set(m.map(x => x.status).filter(Boolean))].sort(),
    teamType: finalTeamTypes.sort(),
    line: [...new Set(m.map(x => x.line).filter(Boolean))].sort(),
    betType: [...new Set(m.map(x => x.bet).filter(b => b === 'Over' || b === 'Under'))].sort(),
  };
});

const activeFilterCount = computed(() => {
  let count = searchQuery.value ? 1 : 0;
  Object.values(selectedFilters.value).forEach(arr => count += arr.length);
  return count;
});

const shouldShowFilter = (type) => {
  if (type === 'period' && ['yellowCards', 'incidents'].includes(props.tabId)) return false;
  if (type === 'line' && !['spreads', 'totals', 'teamTotals'].includes(props.tabId)) return false;
  return true;
};

const formatLabel = (type) => {
  const labels = {
    period: 'Period',
    status: 'Status',
    teamType: 'Team',
    line: 'Line',
    betType: 'Bet Type'
  };
  return labels[type] || type.replace(/([A-Z])/g, ' ').replace(/^./, str => str.toUpperCase());
};

const toggleDropdown = (type) => {
  dropdownOpen.value[type] = !dropdownOpen.value[type];
};

const toggleSelectAll = (type, options) => {
  if (selectedFilters.value[type].length === options.length) {
    selectedFilters.value[type] = [];
  } else {
    selectedFilters.value[type] = [...options];
  }
};

const clearAllFilters = () => {
  searchQuery.value = '';
  Object.keys(selectedFilters.value).forEach(k => selectedFilters.value[k] = []);
};

watch([searchQuery, selectedFilters], () => {
  emit('filter-change', {
    searchQuery: searchQuery.value,
    ...selectedFilters.value
  });
}, { deep: true });
</script>
