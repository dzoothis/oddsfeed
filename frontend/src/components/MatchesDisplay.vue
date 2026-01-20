<template>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
      <div class="px-6 py-4 border-b border-gray-200">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-lg font-semibold text-gray-900">Matches</h2>
            <p class="text-sm text-gray-600 mt-1">
              {{ props.loading ? 'Loading matches...' : `${props.matches.length} matches found` }}
            </p>
          </div>
        </div>
      </div>
  
      <div class="p-6">
        <div v-if="props.loading" class="flex justify-center items-center py-12">
          <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
          <span class="ml-3 text-gray-600">Loading matches...</span>
        </div>
  
        <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-md p-4 mb-6">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
              </svg>
            </div>
            <div class="ml-3">
              <p class="text-sm text-red-800">{{ error }}</p>
            </div>
          </div>
        </div>
  
        <div v-else-if="props.matches.length === 0" class="text-center py-12">
          <div class="text-gray-400 mb-4 text-4xl">⚽</div>
          <h3 class="text-lg font-medium text-gray-900">No matches found</h3>
          <p class="text-gray-500 mt-1">No matches available for selected filters.</p>
        </div>

        <div v-else class="space-y-4">
          <div
            v-for="match in props.matches"
            :key="match.id"
            class="match-card bg-white border border-gray-200 rounded-lg overflow-hidden"
          >
            <!-- Match Card Header -->
            <div class="match-card-header p-4 border-b border-gray-200">
              <!-- Teams Section -->
              <div class="flex items-center justify-between mb-3">
                <!-- Home Team -->
                <div class="team-info flex items-center space-x-3 flex-1">
                  <div class="team-logo">
                    <img v-if="match.images?.home_team_logo"
                         :src="match.images.home_team_logo"
                         :alt="match.home_team"
                         class="w-8 h-8 rounded-full object-cover border border-gray-200"
                         @error="handleImageError">
                    <div v-else class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600 border border-gray-200">
                      {{ getTeamInitials(match.home_team) }}
                    </div>
                  </div>
                  <div class="team-details">
                    <div class="team-name font-semibold text-gray-900 text-lg">{{ match.home_team }}</div>
                    <div v-if="match.home_team_data?.venue" class="text-xs text-gray-500">
                      {{ match.home_team_data.venue.city || match.home_team_data.venue.name }}
                    </div>
                  </div>
                </div>

                <!-- VS Divider -->
                <div class="match-vs px-4">
                  <div class="text-center">
                    <div class="text-2xl font-bold text-gray-400 mb-1">VS</div>
                    <span :class="['inline-flex items-center px-2 py-1 text-xs font-medium rounded-full',
                      match.match_type === 'live' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800']">
                      {{ match.match_type === 'live' ? 'LIVE' : 'PREMATCH' }}
                    </span>
                  </div>
                </div>

                <!-- Away Team -->
                <div class="team-info flex items-center space-x-3 flex-1 justify-end">
                  <div class="team-details text-right">
                    <div class="team-name font-semibold text-gray-900 text-lg">{{ match.away_team }}</div>
                    <div v-if="match.away_team_data?.venue" class="text-xs text-gray-500">
                      {{ match.away_team_data.venue.city || match.away_team_data.venue.name }}
                    </div>
                  </div>
                  <div class="team-logo">
                    <img v-if="match.images?.away_team_logo"
                         :src="match.images.away_team_logo"
                         :alt="match.away_team"
                         class="w-8 h-8 rounded-full object-cover border border-gray-200"
                         @error="handleImageError">
                    <div v-else class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600 border border-gray-200">
                      {{ getTeamInitials(match.away_team) }}
                    </div>
                  </div>
                </div>
              </div>

              <!-- Match Info Section -->
              <div class="flex items-center justify-between">
                <div class="match-meta flex items-center space-x-4 text-sm text-gray-600">
                  <span class="match-league font-medium text-gray-900">{{ match.league_name || match.league }}</span>
                  <span v-if="match.home_team_data?.country" class="match-country px-2 py-0.5 bg-gray-100 text-gray-700 text-xs rounded">
                    {{ match.home_team_data.country }}
                  </span>
                  <span v-if="match.match_type !== 'live'" class="match-time font-medium">{{ formatMatchTime(match) }}</span>
                </div>

                <div class="match-status-group flex items-center space-x-3">
                  <!-- Updated Badge -->
                  <span v-if="match.last_updated" class="updated-badge px-2 py-1 bg-green-50 text-green-700 text-xs rounded-md font-medium border border-green-200">
                    Updated {{ formatTimeAgo(match.last_updated) }} ago
                  </span>

                  <!-- Markets Badge -->
                  <div v-if="match.has_open_markets || match.odds_count > 0" class="markets-badge flex items-center space-x-1">
                    <span class="text-green-600">💰</span>
                    <span class="text-sm font-medium text-green-700">
                      {{ getTotalOddsCount(match.id) || match.odds_count || 'Markets' }}
                    </span>
                  </div>


                  <!-- Expand Button -->
                  <button
                    type="button"
                    @click.prevent.stop="toggleMatchExpansion(match.id)"
                    :class="['expand-button p-2 rounded-lg hover:bg-gray-100 transition-colors',
                      expandedMatches.includes(match.id) ? 'bg-blue-50 text-blue-600' : 'text-gray-500']"
                  >
                    <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': expandedMatches.includes(match.id) }"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
  
            <!-- Match Card Body - Expandable -->
            <div v-if="expandedMatches.includes(match.id)" class="match-card-body border-t border-gray-200">
              <!-- Market Tabs -->
              <div class="market-tabs">
                <div class="market-tabs-header flex border-b border-gray-200">
                  <button
                    v-for="market in availableMarkets"
                    :key="market.key"
                    @click="selectMarket(match.id, market.key)"
                    :disabled="loadingMarkets[match.id]"
                    :class="['market-tab px-4 py-2 text-sm font-medium border-b-2',
                      activeMarket(match.id) === market.key
                        ? 'border-blue-500 text-blue-600 bg-blue-50'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                      loadingMarkets[match.id] ? 'opacity-50 cursor-not-allowed' : '']"
                  >
                    {{ market.name }}
                    <span v-if="loadingMarkets[match.id]" class="ml-2">
                      <svg class="animate-spin h-4 w-4 inline" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                    </span>
                    <span v-else class="ml-1">({{ getMarketCount(match, market.key) }})</span>
                    <span v-if="market.key === 'player_props' && match.markets.player_props.available" class="ml-1" title="Available">🟢</span>
                  </button>
                </div>
  
                <!-- Market Content -->
                <div class="market-tabs-content p-4">
                  <!-- Market Filters -->
                  <div class="market-filters mb-4 border-b border-gray-200 pb-4">
                    <div class="flex items-center justify-between mb-3">
                      <div class="flex items-center gap-3 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                          <!-- Period Filter -->
                          <div class="relative">
                            <button
                              @click="toggleDropdown(match.id, 'period')"
                              type="button"
                              class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center gap-2 min-w-[120px]"
                            >
                              <span>{{ getFilterValue(match.id, 'period') || 'Period' }}</span>
                              <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': dropdowns[match.id]?.period }"
                                   fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                              </svg>
                            </button>
  
                            <div v-if="dropdowns[match.id]?.period"
                                 class="absolute z-10 mt-1 w-full bg-white border border-gray-300 rounded-lg shadow-lg">
                              <div v-for="period in periodOptions" :key="period"
                                   @click="setFilter(match.id, 'period', period)"
                                   class="px-3 py-2 hover:bg-gray-100 cursor-pointer text-sm">
                                {{ period }}
                              </div>
                            </div>
                          </div>
  
                          <!-- Status Filter -->
                          <div class="relative">
                            <button
                              @click="toggleDropdown(match.id, 'status')"
                              type="button"
                              class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center gap-2 min-w-[120px]"
                            >
                              <span>{{ getFilterValue(match.id, 'status') || 'Status' }}</span>
                              <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': dropdowns[match.id]?.status }"
                                   fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                              </svg>
                            </button>
  
                            <div v-if="dropdowns[match.id]?.status"
                                 class="absolute z-10 mt-1 w-full bg-white border border-gray-300 rounded-lg shadow-lg">
                              <div v-for="status in statusOptions" :key="status"
                                   @click="setFilter(match.id, 'status', status)"
                                   class="px-3 py-2 hover:bg-gray-100 cursor-pointer text-sm">
                                {{ status }}
                              </div>
                            </div>
                          </div>
  
                          <!-- Team Type Filter -->
                          <div class="relative">
                            <button
                              @click="toggleDropdown(match.id, 'teamType')"
                              type="button"
                              class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center gap-2 min-w-[120px]"
                            >
                              <span>{{ getFilterValue(match.id, 'teamType') || 'Team Type' }}</span>
                              <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': dropdowns[match.id]?.teamType }"
                                   fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                              </svg>
                            </button>
  
                            <div v-if="dropdowns[match.id]?.teamType"
                                 class="absolute z-10 mt-1 w-full bg-white border border-gray-300 rounded-lg shadow-lg">
                              <div v-for="type in teamTypeOptions" :key="type"
                                   @click="setFilter(match.id, 'teamType', type)"
                                   class="px-3 py-2 hover:bg-gray-100 cursor-pointer text-sm">
                                {{ type }}
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
  
                  <!-- Market Table -->
                  <div v-if="marketOdds[match.id] && marketOdds[match.id][activeMarket(match.id)]" class="market-table">
                    <div class="mb-3 text-sm text-gray-600">
                      Showing {{ marketOdds[match.id][activeMarket(match.id)].showing_count }} of {{ marketOdds[match.id][activeMarket(match.id)].total_count }} markets
                    </div>
  
                    <div class="overflow-x-auto">
                      <table class="w-full">
                        <thead>
                          <tr class="market-table-header bg-gray-50">
                            <th class="market-table-th px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bet</th>
                            <th class="market-table-th px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Line</th>
                            <th class="market-table-th px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Odds</th>
                            <th class="market-table-th px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="market-table-th px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Period</th>
                          </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                          <tr v-for="odd in filteredOdds(match.id)" :key="odd.id || Math.random()"
                              class="market-table-row hover:bg-gray-50">
                            <td class="market-table-td px-4 py-3">
                              <span class="font-medium">{{ odd.bet }}</span>
                              <span v-if="isHomeTeam(odd, match)" class="text-xs text-gray-500 ml-1">(Home)</span>
                              <span v-else-if="isAwayTeam(odd, match)" class="text-xs text-gray-500 ml-1">(Away)</span>
                            </td>
                            <td class="market-table-td px-4 py-3">{{ odd.line || '-' }}</td>
                            <td class="market-table-td px-4 py-3">
                              <span class="font-mono font-semibold text-blue-600">{{ odd.odds }}</span>
                              <span v-if="odd.updated_at" class="ml-1.5 px-1.5 py-0.5 bg-green-100 text-green-800 text-xs rounded font-medium">
                                Updated {{ formatTimeAgo(odd.updated_at) }} ago
                              </span>
                            </td>
                            <td class="market-table-td px-4 py-3">
                              <span :class="['status-badge px-2 py-1 text-xs rounded font-medium',
                                odd.status === 'open' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800']">
                                {{ odd.status === 'open' ? 'Open' : 'Closed' }}
                              </span>
                            </td>
                            <td class="market-table-td px-4 py-3 text-xs text-gray-600">{{ odd.period }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
  
                  <!-- Loading Market Data -->
                  <div v-else class="flex justify-center items-center py-8">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                    <span class="ml-2 text-gray-600">Loading market data...</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </template>
  
  <script setup>
  import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
  import http from '../services/http.js';
  import { API_ENDPOINTS, API_PARAMS } from '../services/api.js';
  
  // Props
  const props = defineProps({
    matches: {
      type: Array,
      default: () => []
    },
    selectedSport: {
      type: Object,
      default: null
    },
    loading: {
      type: Boolean,
      default: false
    }
  });
  
  // Reactive data
  const error = ref('');

  const expandedMatches = ref([]);
  const activeMarkets = ref({});
  const marketOdds = ref({});
  const marketFilters = ref({});
  const dropdowns = ref({});
  const loadingMarkets = ref({});
  
  // Options
  const availableMarkets = [
    { key: 'money_line', name: 'Money Line' },
    { key: 'spreads', name: 'Spreads' },
    { key: 'totals', name: 'Totals' },
    { key: 'player_props', name: 'Player Props' }
  ];
  
  const periodOptions = ['All', 'Game', '1st Half', '1st Quarter', '2nd Half', '2nd Quarter'];
  const statusOptions = ['All', 'Open', 'Closed'];
  const teamTypeOptions = ['All', 'Home', 'Away'];
  
  // API Configuration - using centralized HTTP service

  // Helper function to initialize filters for matches
  const initializeFiltersForMatches = (matchesList) => {
    if (!Array.isArray(matchesList)) return;

    matchesList.forEach(match => {
      if (!marketFilters.value[match.id]) {
        marketFilters.value[match.id] = {
          search: '',
          period: 'All',
          status: 'All',
          teamType: 'All'
        };
      }
      if (!dropdowns.value[match.id]) {
        dropdowns.value[match.id] = {
          period: false,
          status: false,
          teamType: false
        };
      }
    });
  };

  // Watch for matches changes to initialize filters
  watch(() => props.matches, (newMatches) => {
    initializeFiltersForMatches(newMatches);
  }, { immediate: true });


  
  const toggleMatchExpansion = async (matchId) => {
    try {
      const index = expandedMatches.value.indexOf(matchId);
  
    if (index > -1) {
      expandedMatches.value.splice(index, 1);
    } else {
      expandedMatches.value.push(matchId);
  
      // Load market data for all available markets when match is expanded
      // This ensures tab counts are accurate
      if (!marketOdds.value[matchId]) {
        loadingMarkets.value[matchId] = true;
        console.log('Loading all markets for match', matchId);

        try {
          // Load all market types in parallel for better performance
          const loadPromises = availableMarkets.map(market =>
            loadMarketOdds(matchId, market.key)
          );
          await Promise.all(loadPromises);
          console.log('All markets loaded for match', matchId, ':', marketOdds.value[matchId]);
        } finally {
          loadingMarkets.value[matchId] = false;
        }
      }
  
      // Set default active market
      if (!activeMarkets.value[matchId]) {
        activeMarkets.value[matchId] = 'money_line';
      }
  
      // Initialize filters
      if (!marketFilters.value[matchId]) {
        marketFilters.value[matchId] = {
          search: '',
          period: 'All',
          status: 'All',
          teamType: 'All'
        };
      }
    }
    } catch (error) {
      console.error('Error in toggleMatchExpansion:', error);
    }
  };
  
  const selectMarket = async (matchId, marketType) => {
    activeMarkets.value[matchId] = marketType;
  
    // Load market odds if not already loaded
    if (!marketOdds.value[matchId] || !marketOdds.value[matchId][marketType]) {
      await loadMarketOdds(matchId, marketType);
    }
  };
  
  const loadMarketOdds = async (matchId, marketType) => {
    try {
      const response = await http.get(API_ENDPOINTS.MATCH_ODDS(matchId), {
        params: {
          sport_id: props.selectedSport.pinnacleId || props.selectedSport.id,
          market_type: marketType,
          period: 'all'
        }
      });
  
      if (!marketOdds.value[matchId]) {
        marketOdds.value[matchId] = {};
      }
  
      marketOdds.value[matchId][marketType] = response.data;
    } catch (err) {
      console.error('Error loading market odds:', err);
    }
  };
  
  const activeMarket = (matchId) => {
    return activeMarkets.value[matchId] || 'money_line';
  };
  
  const getMarketCount = (match, marketKey) => {
    // First try to get count from loaded market odds data
    const loadedMarketData = marketOdds.value[match.id]?.[marketKey];
    if (loadedMarketData && loadedMarketData.total_count !== undefined) {
      return loadedMarketData.total_count;
    }

    // Fall back to match summary count
    return match.markets[marketKey]?.count || 0;
  };

  const getTotalOddsCount = (matchId) => {
    // Only show count from loaded market data (accurate)
    // Don't use the fake summary counts from match.markets
    const matchMarketData = marketOdds.value[matchId];
    if (!matchMarketData) {
      return 0; // No market data loaded yet - show 0 instead of fake counts
    }

    console.log('Calculating total odds for match', matchId, 'from loaded data:', matchMarketData);

    let totalCount = 0;
    let loadedMarkets = 0;

    Object.entries(matchMarketData).forEach(([marketKey, marketData]) => {
      if (marketData && marketData.total_count !== undefined) {
        console.log(`Market ${marketKey}: total_count =`, marketData.total_count);
        totalCount += marketData.total_count;
        loadedMarkets++;
      } else {
        console.log(`Market ${marketKey}: no total_count or missing data`, marketData);
      }
    });

    console.log(`Total odds count for match ${matchId}: ${totalCount} (from ${loadedMarkets} loaded markets)`);
    return totalCount;
  };
  
  const toggleDropdown = (matchId, dropdownType) => {
    if (!dropdowns.value[matchId]) {
      dropdowns.value[matchId] = {};
    }
  
    // Close other dropdowns
    Object.keys(dropdowns.value[matchId]).forEach(key => {
      if (key !== dropdownType) {
        dropdowns.value[matchId][key] = false;
      }
    });
  
    dropdowns.value[matchId][dropdownType] = !dropdowns.value[matchId][dropdownType];
  };
  
  const getFilterValue = (matchId, filterType) => {
    return marketFilters.value[matchId]?.[filterType] || '';
  };

  const setFilterValue = (matchId, filterType, value) => {
    if (!marketFilters.value[matchId]) {
      marketFilters.value[matchId] = {
        search: '',
        period: 'All',
        status: 'All',
        teamType: 'All'
      };
    }
    marketFilters.value[matchId][filterType] = value;
  };

  const setFilter = (matchId, filterType, value) => {
    setFilterValue(matchId, filterType, value);
    if (!dropdowns.value[matchId]) {
      dropdowns.value[matchId] = {
        period: false,
        status: false,
        teamType: false
      };
    }
    dropdowns.value[matchId][filterType] = false;
  };
  
  // Team identification methods (replacing string matching)
  const isHomeTeam = (odd, match) => {
    // Use team IDs if available (preferred method)
    if (odd.home_team_id && match.home_team_id) {
      return odd.home_team_id === match.home_team_id;
    }
    if (odd.away_team_id && match.away_team_id) {
      return false; // If it has away_team_id, it's not home
    }

    // Fallback to string matching for backward compatibility
    return odd.bet && match.home_team && odd.bet.includes(match.home_team);
  };

  const isAwayTeam = (odd, match) => {
    // Use team IDs if available (preferred method)
    if (odd.away_team_id && match.away_team_id) {
      return odd.away_team_id === match.away_team_id;
    }
    if (odd.home_team_id && match.home_team_id) {
      return false; // If it has home_team_id, it's not away
    }

    // Fallback to string matching for backward compatibility
    return odd.bet && match.away_team && odd.bet.includes(match.away_team);
  };

  const filteredOdds = (matchId) => {
    const marketType = activeMarket(matchId);
    const odds = marketOdds.value[matchId]?.[marketType]?.odds || [];
    const filters = marketFilters.value[matchId] || {};

    return odds.filter(odd => {
      // Search filter
      if (filters.search) {
        const searchLower = filters.search.toLowerCase();
        const match = props.matches.find(m => m.id == matchId);

        // Search in bet field, line field, and actual team names
        const betMatches = odd.bet.toLowerCase().includes(searchLower);
        const lineMatches = odd.line && odd.line.toString().includes(searchLower);
        const homeTeamMatches = match && match.home_team && match.home_team.toLowerCase().includes(searchLower);
        const awayTeamMatches = match && match.away_team && match.away_team.toLowerCase().includes(searchLower);

        if (!betMatches && !lineMatches && !homeTeamMatches && !awayTeamMatches) {
          return false;
        }
      }

      // Period filter
      if (filters.period && filters.period !== 'All' && odd.period !== filters.period) {
        return false;
      }

      // Status filter
      if (filters.status && filters.status !== 'All') {
        const statusMatch = filters.status === 'Open' ? 'open' : 'closed';
        if (odd.status !== statusMatch) {
          return false;
        }
      }

      // Team type filter
      if (filters.teamType && filters.teamType !== 'All') {
        const match = matches.value.find(m => m.id == matchId);
        if (!match) return true;

        if (filters.teamType === 'Home' && !isHomeTeam(odd, match)) return false;
        if (filters.teamType === 'Away' && !isAwayTeam(odd, match)) return false;
        // 'Both' shows all
      }

      return true;
    });
  };
  
  const formatTimeAgo = (timestamp) => {
    if (!timestamp) return '';

    const now = new Date();
    let time;

    // Handle different timestamp formats
    if (typeof timestamp === 'string') {
      // Try ISO format first (2026-01-16T05:53:57+00:00)
      if (timestamp.includes('T')) {
        time = new Date(timestamp);
      } else {
        // Fallback for other formats
        time = new Date(timestamp.replace(' ', 'T') + '+00:00');
      }
    } else {
      time = new Date(timestamp);
    }

    // Check if date parsing failed
    if (isNaN(time.getTime())) {
      console.warn('Invalid timestamp:', timestamp);
      return 'Unknown';
    }

    const diff = Math.floor((now - time) / 1000);

    if (diff < 0) return 'Future';
    if (diff < 60) return `${diff}s`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
    return `${Math.floor(diff / 86400)}d`;
  };

  const formatMatchTime = (match) => {
    // For live matches, don't show scheduled time
    if (match.match_type === 'live') {
      return '';
    }

    const scheduledTime = match.scheduled_time;
    if (!scheduledTime || scheduledTime === 'TBD') return 'TBD';

    // Handle the format "01/16/2026, 03:10:00"
    if (scheduledTime.includes(',')) {
      const parts = scheduledTime.split(', ');
      if (parts.length === 2) {
        const datePart = parts[0]; // "01/16/2026"
        const timePart = parts[1]; // "03:10:00"

        // Convert to more readable format
        const date = new Date(datePart + ' ' + timePart);
        if (!isNaN(date.getTime())) {
          const now = new Date();
          const matchDate = new Date(date);

          // If same day, just show time
          if (matchDate.toDateString() === now.toDateString()) {
            return timePart;
          }

          // If today or tomorrow, show relative
          const tomorrow = new Date(now);
          tomorrow.setDate(tomorrow.getDate() + 1);
          if (matchDate.toDateString() === tomorrow.toDateString()) {
            return `Tomorrow ${timePart}`;
          }

          // Otherwise show date and time
          return `${datePart} ${timePart}`;
        }
      }
    }

    return scheduledTime;
  };

  // Helper function to get team initials for fallback display
  const getTeamInitials = (teamName) => {
    if (!teamName) return '??';
    const words = teamName.split(' ');
    if (words.length === 1) {
      return teamName.substring(0, 2).toUpperCase();
    }
    return (words[0][0] + words[words.length - 1][0]).toUpperCase();
  };

  // Handle image loading errors
  const handleImageError = (event) => {
    // Replace broken image with initials
    const img = event.target;
    const teamName = img.alt || 'Unknown';
    const parent = img.parentElement;

    // Hide the img and show initials instead
    img.style.display = 'none';

    // Find or create the initials div
    let initialsDiv = parent.querySelector('.team-initials-fallback');
    if (!initialsDiv) {
      initialsDiv = document.createElement('div');
      initialsDiv.className = 'team-initials-fallback w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600';
      initialsDiv.textContent = getTeamInitials(teamName);
      parent.appendChild(initialsDiv);
    }
    initialsDiv.style.display = 'flex';
  };


  // Initialize - no longer needed since match fetching is handled by parent component
  onMounted(() => {
    // Component initialization handled by watch on props.matches
  });
  </script>
  
  <style scoped>
  .match-card {
    transition: all 0.2s ease;
  }

  .match-card:hover {
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
  }

  .market-table-th {
    padding: 0.75rem 1rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 500;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }

  .market-table-td {
    padding: 0.75rem 1rem;
    font-size: 0.875rem;
    color: #111827;
  }

  .market-table-row {
    transition: color 150ms, background-color 150ms;
  }
  
  .status-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.75rem;
    font-weight: 500;
  }
  
  .rotate-180 {
    transform: rotate(180deg);
  }
  </style>