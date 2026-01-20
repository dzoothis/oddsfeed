<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Authentication Screen -->
    <div v-if="!isAuthenticated" class="min-h-screen bg-gray-50 flex items-center justify-center">
      <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">
        <div class="text-center mb-6">
          <h1 class="text-2xl font-bold text-gray-900 mb-2">Sports Feed Dashboard</h1>
          <p class="text-gray-600">Enter password to access live odds</p>
        </div>

        <form @submit.prevent="handleLogin" class="space-y-4">
          <div>
            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
              Password
            </label>
            <input
              type="password"
              id="password"
              v-model="password"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
              placeholder="Enter dashboard password"
              required
            />
          </div>

          <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            Access Dashboard
          </button>
        </form>
      </div>
    </div>

    <!-- Main Dashboard -->
    <div v-else>
      <!-- Header -->
      <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
          <div class="px-6 py-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
              <div>
                <h1 class="text-2xl font-bold text-gray-900">Sports Feed</h1>
                <p class="text-sm text-gray-600 mt-1">Filter by Sport and League to view live odds</p>
              </div>
              <div class="flex gap-3">
                <button
                  @click="showBetTypesModal = true"
                  class="px-4 py-2 text-sm font-medium text-purple-600 bg-purple-50 border border-purple-200 rounded-lg hover:bg-purple-100 transition-colors"
                >
                  All Available Bet Types
                </button>
                <button
                  @click="handleLogout"
                  class="px-4 py-2 text-sm font-medium text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-colors"
                >
                  Logout
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Filter Section -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
          <div class="px-6 py-6 bg-gray-50">
            
            <!-- Sport Selection -->
            <div class="mb-6">
              <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-3">
                Select Sport
              </label>
              <div class="flex flex-wrap gap-3">
                <button
                  v-for="sport in sports"
                  :key="sport.id"
                  @click="selectSport(sport)"
                  class="px-4 py-2 text-sm font-medium rounded-lg transition-colors"
                  :class="selectedSport?.id === sport.id ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                >
                  {{ sport.name }}
                </button>
              </div>
            </div>

            <!-- League Filter - Search + Multi-select -->
            <div class="relative">
              <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-2">
                League <span class="text-gray-500">(Required for filtering)</span>
              </label>
              <input
                type="text"
                v-model="leagueSearch"
                @input="handleLeagueSearch"
                @focus="handleLeagueFocus"
                @blur="handleLeagueBlur"
                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm placeholder-gray-400"
                placeholder="Search and add leagues"
                :disabled="!selectedSport"
              />

              <!-- League Dropdown -->
              <div v-if="showLeagueDropdown && filteredLeagues.length > 0"
                   class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-64 overflow-y-auto">
                <div v-for="league in filteredLeagues"
                     :key="league.id"
                     @mousedown="selectLeague(league)"
                     class="px-4 py-2 hover:bg-gray-100 cursor-pointer"
                     :class="isLeagueSelected(league) ? 'bg-blue-50' : 'bg-white'">
                  <span class="text-sm">{{ league.name }}</span>
                  <span v-if="league.container" class="text-xs text-gray-500 ml-2">({{ league.container }})</span>
                </div>
              </div>

              <!-- No Results Message -->
              <div v-else-if="showLeagueDropdown && leagueSearch && filteredLeagues.length === 0"
                   class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg p-4">
                <p class="text-sm text-gray-500">No leagues match "{{ leagueSearch }}"</p>
              </div>

              <!-- Loading State -->
              <div v-else-if="showLeagueDropdown && loadingLeagues"
                   class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg p-4">
                <p class="text-sm text-gray-500">Loading leagues...</p>
              </div>

              <!-- Selected Leagues Tags -->
              <div class="mt-2 flex flex-wrap gap-2">
                <span v-for="league in selectedLeagues"
                      :key="league.id"
                      class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                  {{ league.name }}
                  <button @click="removeLeague(league)"
                          class="ml-2 text-blue-600 hover:text-blue-800">
                    ×
                  </button>
                </span>
              </div>
            </div>

            <!-- Match Type Filter -->
            <div v-if="showFilters" class="mt-6">
              <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-3">
                Match Type
              </label>
              <div class="flex flex-wrap gap-3">
                <button
                  v-for="matchType in matchTypeOptions"
                  :key="matchType.key"
                  @click="selectMatchType(matchType.key)"
                  class="px-4 py-2 text-sm font-medium rounded-lg transition-colors"
                  :class="selectedMatchType === matchType.key
                    ? 'bg-green-600 text-white hover:bg-green-700'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                >
                  {{ matchType.name }}
                </button>
              </div>
              <p class="text-xs text-gray-500 mt-2">
                Filter matches: All matches, Live (in-play), or Prematch (upcoming)
              </p>
            </div>

          </div>
        </div>

        <!-- Matches Display -->
        <MatchesDisplay
          v-if="showFilters"
          :selected-leagues="selectedLeagues"
          :selected-sport="selectedSport"
          :selected-match-type="selectedMatchType"
        />


        <!-- Bet Types Modal -->
        <div v-if="showBetTypesModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" @click="closeBetTypesModal">
          <div class="bg-white rounded-lg shadow-xl w-full max-w-6xl max-h-[90vh] mx-4 flex flex-col" @click.stop>
            <!-- Modal Header -->
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
              <h2 class="text-xl font-semibold text-gray-900">All Available Bet Types</h2>
              <button
                @click="closeBetTypesModal"
                class="text-gray-400 hover:text-gray-600 text-2xl font-bold leading-none"
              >
                ×
              </button>
            </div>

            <!-- Modal Content -->
            <div class="flex-1 overflow-hidden" style="max-height: calc(90vh - 120px);">
              <!-- Side by Side Layout -->
              <div class="flex flex-col md:flex-row h-full">
                <!-- Left Side: Sports List -->
                <div class="w-full md:w-1/3 border-b md:border-b-0 md:border-r border-gray-200 bg-gray-50 overflow-y-auto" style="max-height: calc(90vh - 140px);">
                  <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Select Sport</h3>
                    <div class="space-y-2">
                      <button
                        v-for="sport in betTypesSports"
                        :key="sport.id"
                        @click="selectSportForBetTypes(sport.id)"
                        class="w-full text-left px-4 py-3 rounded-lg border transition-colors"
                        :class="betTypesSelectedSport === sport.id
                          ? 'bg-blue-600 text-white border-blue-600'
                          : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-100'"
                      >
                        <div class="font-medium">{{ sport.name }}</div>
                      </button>
                    </div>
                  </div>
                </div>

                <!-- Right Side: Bet Types -->
                <div class="flex-1 overflow-hidden">
                  <div class="h-full overflow-y-auto p-6" style="max-height: calc(90vh - 140px);">
                    <!-- Loading State -->
                    <div v-if="betTypesLoading" class="flex justify-center items-center py-12">
                      <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                      <span class="ml-3 text-gray-600">Loading bet types...</span>
                    </div>

                    <!-- Error State -->
                    <div v-else-if="betTypesError" class="bg-red-50 border border-red-200 rounded-md p-4 mb-6">
                      <div class="flex">
                        <div class="flex-shrink-0">
                          <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                          </svg>
                        </div>
                        <div class="ml-3">
                          <p class="text-sm text-red-800">{{ betTypesError }}</p>
                        </div>
                      </div>
                    </div>

                    <!-- No Sport Selected Message -->
                    <div v-else-if="!betTypesSelectedSport" class="text-center py-12">
                      <div class="text-gray-400 mb-4 text-4xl">🎯</div>
                      <h3 class="text-lg font-medium text-gray-900">Select a Sport</h3>
                      <p class="text-gray-500 mt-1">Choose a sport from the left panel to view available bet types.</p>
                    </div>

                    <!-- Bet Types Display -->
                    <div v-else-if="Object.keys(betTypesResponse.categories || {}).length > 0">
                      <div class="mb-6">
                        <h3 class="text-lg font-medium text-gray-900">
                          Bet Types for {{ getSelectedSportName() }}
                        </h3>
                      </div>

                      <!-- Search Bar -->
                      <div class="mb-6">
                        <div class="relative">
                          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                          </div>
                          <input
                            type="text"
                            v-model="betTypesSearch"
                            placeholder="Search bet types..."
                            class="block w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm placeholder-gray-400"
                          />
                        </div>
                      </div>

                      <!-- Bet Types List Grouped by Category -->
                      <div class="space-y-6">
                        <div
                          v-for="(categoryGroup, categoryName) in groupBetTypesByCategory()"
                          :key="categoryName"
                          class="mb-6"
                        >
                          <div class="space-y-3">
                            <div
                              v-for="betType in categoryGroup"
                              :key="betType.id || betType.type"
                              class="bg-white rounded-lg p-4 border border-gray-200"
                            >
                              <div class="flex items-center justify-between">
                                <div class="flex-1">
                                  <h5 class="font-medium text-gray-900">{{ betType.name }}</h5>
                                  <p class="text-sm text-gray-600 mt-1">{{ betType.description }}</p>
                                </div>
                                <div class="flex items-center ml-4">
                                  <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    ✓ Available
                                  </span>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>

                    </div>

                    <!-- No Bet Types Message -->
                    <div v-else class="text-center py-12">
                      <div class="text-gray-400 mb-4 text-4xl">🎲</div>
                      <h3 class="text-lg font-medium text-gray-900">No bet types available</h3>
                      <p class="text-gray-500 mt-1">No betting markets are currently available for this sport.</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Modal Footer -->
            <div class="p-6 border-t border-gray-200 flex justify-end">
              <button
                @click="closeBetTypesModal"
                class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import http from '../services/http.js';
import { API_ENDPOINTS, API_PARAMS } from '../services/api.js';
import MatchesDisplay from '../components/MatchesDisplay.vue';

// Authentication
const isAuthenticated = ref(false);
const password = ref('');

// Data
const sports = ref([]);
const betTypesSports = ref([]);
const selectedSport = ref(null);

// League filtering state
const leagueSearch = ref('');
const showLeagueDropdown = ref(false);
const selectedLeagues = ref([]);
const filteredLeagues = ref([]);
const leagues = ref([]);
const loadingLeagues = ref(false);

// Match type filtering state
const selectedMatchType = ref('all');
const matchTypeOptions = [
  { key: 'all', name: 'All Matches' },
  { key: 'live', name: 'Live Matches' },
  { key: 'prematch', name: 'Prematch Matches' }
];

// Modal state
const showBetTypesModal = ref(false);

// Bet Types Modal state
const betTypesSelectedSport = ref('');
const betTypesResponse = ref({ categories: {}, flat: [] });
const betTypesLoading = ref(false);
const betTypesError = ref('');
const betTypesSearch = ref('');

// API Configuration - using centralized HTTP service

const DASHBOARD_PASSWORD = 'sportsfeed2025';

// Computed properties
const hasSelections = computed(() => {
  return selectedSport.value && selectedLeagues.value.length > 0;
});

const showFilters = computed(() => {
  return hasSelections.value;
});

const groupBetTypesByCategory = () => {
  // Use the categories directly from the API response
  const categories = betTypesResponse.value.categories || {};
  const searchTerm = betTypesSearch.value.toLowerCase().trim();

  if (!searchTerm) {
    // Show all categories if no search term
    return categories;
  }

  // Filter categories and bet types based on search term
  const filteredCategories = {};

  Object.keys(categories).forEach(categoryName => {
    const categoryBetTypes = categories[categoryName];

    // Check if category name matches search
    const categoryMatches = categoryName.toLowerCase().includes(searchTerm);

    // Filter bet types within this category
    const filteredBetTypes = categoryBetTypes.filter(betType => {
      const nameMatches = betType.name.toLowerCase().includes(searchTerm);
      const descriptionMatches = betType.description.toLowerCase().includes(searchTerm);
      return nameMatches || descriptionMatches;
    });

    // Include category if category name matches OR if it has any matching bet types
    if (categoryMatches || filteredBetTypes.length > 0) {
      filteredCategories[categoryName] = filteredBetTypes;
    }
  });

  return filteredCategories;
};


// Watch for league changes to trigger matches loading
watch(() => selectedLeagues.value, (newLeagues) => {
  // The MatchesDisplay component will handle this automatically
}, { deep: true });

// Authentication
const handleLogin = async () => {
  if (password.value === DASHBOARD_PASSWORD) {
    isAuthenticated.value = true;
    localStorage.setItem('isAuthenticated', 'true');
    await fetchSports();
  } else {
    alert('Invalid password');
  }
};

const handleLogout = () => {
  isAuthenticated.value = false;
  localStorage.removeItem('isAuthenticated');
  password.value = '';
  selectedSport.value = null;
  leagues.value = [];
  selectedLeagues.value = [];
  resetBetTypesState();
};

// League filtering methods
const handleLeagueSearch = async () => {
  if (!selectedSport.value) return;

  showLeagueDropdown.value = true;
  await filterLeagues();
};

const handleLeagueFocus = () => {
  if (!selectedSport.value) return;
  showLeagueDropdown.value = true;
  loadLeaguesForSport();
};

const handleLeagueBlur = () => {
  // Delay hiding dropdown to allow for clicks
  setTimeout(() => {
    showLeagueDropdown.value = false;
  }, 200);
};

const loadLeaguesForSport = async () => {
  if (!selectedSport.value) return;

  loadingLeagues.value = true;

  try {
    // If user has typed something, use search, otherwise load popular leagues
    if (leagueSearch.value.trim()) {
      const response = await http.get(API_ENDPOINTS.REFERENCE.LEAGUES_SEARCH, {
        params: {
          sportId: selectedSport.value.id,
          search: leagueSearch.value.trim(),
          limit: 50
        }
      });
      leagues.value = response.data.leagues || [];
    } else {
      // Load popular leagues when no search term
      await fetchLeagues();
    }
    await filterLeagues();
  } catch (error) {
    console.error('Error loading leagues:', error);
    leagues.value = [];
  } finally {
    loadingLeagues.value = false;
  }
};

const filterLeagues = async () => {
  if (!leagueSearch.value) {
    filteredLeagues.value = leagues.value.slice(0, 10); // Show first 10
    return;
  }

  // Filter and score leagues based on search
  filteredLeagues.value = leagues.value
    .filter(league => matchesLeagueSearch(league, leagueSearch.value))
    .sort((a, b) => calculateSearchScore(b, leagueSearch.value) - calculateSearchScore(a, leagueSearch.value))
    .slice(0, 20); // Limit results
};

const matchesLeagueSearch = (league, search) => {
  if (!search) return true;

  const name = league.name.toLowerCase();
  const query = search.toLowerCase();

  // Exact match
  if (name === query) return true;

  // Starts with query
  if (name.startsWith(query)) return true;

  // Contains query as whole word
  if (name.includes(' ' + query) || name.includes(query + ' ')) return true;

  // Contains query anywhere
  if (name.includes(query)) return true;

  // Word starts with query
  const nameWords = name.split(' ');
  return nameWords.some(word => word.startsWith(query));
};

const calculateSearchScore = (league, search) => {
  const name = league.name.toLowerCase();
  const query = search.toLowerCase();

  // Exact match
  if (name === query) return 100;

  // Starts with query
  if (name.startsWith(query)) return 80;

  // Contains as whole word
  if (name.includes(' ' + query + ' ')) return 60;

  // Contains anywhere
  if (name.includes(query)) return 40;

  // Word starts with query
  const nameWords = name.split(' ');
  if (nameWords.some(word => word.startsWith(query))) return 20;

  return 0;
};

const selectLeague = (league) => {
  const isSelected = selectedLeagues.value.find(l => l.id === league.id);

  if (isSelected) {
    selectedLeagues.value = selectedLeagues.value.filter(l => l.id !== league.id);
  } else {
    selectedLeagues.value.push(league);
  }

  leagueSearch.value = '';
  showLeagueDropdown.value = false;
};

const removeLeague = (league) => {
  selectedLeagues.value = selectedLeagues.value.filter(l => l.id !== league.id);
};

const isLeagueSelected = (league) => {
  return selectedLeagues.value.some(l => l.id === league.id);
};

const selectMatchType = (matchType) => {
  selectedMatchType.value = matchType;
};

// Data fetching
const fetchSports = async () => {
  try {
    const response = await http.get(API_ENDPOINTS.SPORTS);
    sports.value = response.data.data;

    // Also fetch bet types sports
    const betTypesResponse = await http.get(API_ENDPOINTS.BET_TYPES.SPORTS);
    betTypesSports.value = betTypesResponse.data || [];
  } catch (error) {
    console.error('Error fetching sports:', error);
  }
};

const selectSport = (sport) => {
  selectedSport.value = sport;

  // Clear league selections when sport changes
  selectedLeagues.value = [];
  leagueSearch.value = '';
  leagues.value = [];

  fetchLeagues();
};

const fetchLeagues = async () => {
  if (!selectedSport.value) return;

  loadingLeagues.value = true;
  try {
    // Use search endpoint to get popular leagues first (NBA, NHL, MLB, etc.)
    const popularSearches = ['NBA', 'NHL', 'MLB', 'NFL', 'WNBA', 'NCAAB', 'NCAAF'];

    let allLeagues = [];

    // First, fetch popular leagues using search
    for (const searchTerm of popularSearches) {
      try {
        const response = await http.get(API_ENDPOINTS.REFERENCE.LEAGUES_SEARCH, {
          params: {
            sportId: selectedSport.value.id,
            search: searchTerm,
            limit: 10
          }
        });

        if (response.data.leagues && response.data.leagues.length > 0) {
          allLeagues = allLeagues.concat(response.data.leagues);
        }
      } catch (error) {
        console.error(`Error searching for ${searchTerm}:`, error);
      }
    }

    // Remove duplicates based on ID
    const uniqueLeagues = allLeagues.filter((league, index, self) =>
      index === self.findIndex(l => l.id === league.id)
    );

    leagues.value = uniqueLeagues;
    console.log(`Loaded ${uniqueLeagues.length} popular leagues for sport ${selectedSport.value.id}`);
  } catch (error) {
    console.error('Error fetching leagues:', error);
    leagues.value = [];
  } finally {
    loadingLeagues.value = false;
  }
};

// Bet Types Modal functions
const closeBetTypesModal = () => {
  showBetTypesModal.value = false;
  resetBetTypesState();
};

const resetBetTypesState = () => {
  betTypesSelectedSport.value = '';
  betTypesResponse.value = { categories: {}, flat: [] };
  betTypesLoading.value = false;
  betTypesError.value = '';
  betTypesSearch.value = '';
};

const selectSportForBetTypes = async (sportId) => {
  betTypesSelectedSport.value = sportId;
  betTypesLoading.value = true;
  betTypesError.value = '';
  betTypesSearch.value = ''; // Clear search when selecting new sport

  try {
    const response = await http.get(API_ENDPOINTS.REFERENCE.BET_TYPES, {
      params: {
        sportId: sportId
      }
    });

    // Store the structured response
    betTypesResponse.value = response.data || { categories: {}, flat: [] };
    console.log('Bet types loaded for sport:', sportId, 'categories:', Object.keys(betTypesResponse.value.categories || {}).length);
  } catch (error) {
    betTypesError.value = 'Failed to load bet types';
    console.error('Error loading bet types for sport:', error);
  } finally {
    betTypesLoading.value = false;
  }
};

const getSelectedSportName = () => {
  const sport = betTypesSports.value.find(s => s.id == betTypesSelectedSport.value);
  return sport ? sport.name : 'Selected Sport';
};

const formatMatchTime = (dateString) => {
  if (!dateString) return 'TBD';

  const date = new Date(dateString);
  const now = new Date();
  const diff = date - now;
  const hours = Math.floor(Math.abs(diff) / (1000 * 60 * 60));
  const minutes = Math.floor((Math.abs(diff) % (1000 * 60 * 60)) / (1000 * 60));

  if (diff > 0) {
    // Future match
    if (hours < 24) {
      return `In ${hours}h ${minutes}m`;
    } else {
      return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
  } else {
    // Past or current match
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }
};

const formatMarketType = (type) => {
  const typeMap = {
    'money_line': 'Money Line',
    'spreads': 'Spreads',
    'totals': 'Totals',
    'team_totals': 'Team Totals',
    'player_props': 'Player Props',
    'team_props': 'Team Props',
    'corners': 'Corners',
    'draw_no_bet': 'Draw No Bet',
    'both_teams_to_score': 'Both Teams To Score',
    'correct_score': 'Correct Score'
  };

  return typeMap[type] || type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};

// Lifecycle
onMounted(() => {
  const authStatus = localStorage.getItem('isAuthenticated');
  if (authStatus === 'true') {
    isAuthenticated.value = true;
    fetchSports();
  }
});
</script>