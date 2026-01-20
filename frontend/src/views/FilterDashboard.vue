<template>
  <div class="min-h-screen bg-gray-50">
    <div v-if="!isAuthenticated" class="min-h-screen bg-gray-50 flex items-center justify-center">
      <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">
        <h1 class="text-2xl font-bold text-gray-900 mb-2 text-center">Sports Feed Dashboard</h1>
        <form @submit.prevent="handleLogin" class="space-y-4">
          <div>
            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input type="password" id="password" v-model="password"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="Enter dashboard password"
              required />
          </div>
          <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg">Access Dashboard</button>
        </form>
      </div>
    </div>

    <div v-else :key="pageTrigger" class="max-w-7xl mx-auto py-8 px-4">
      <!-- Header -->
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-6 border-b border-gray-200">
          <div class="flex items-center justify-between">
            <div>
              <h1 class="text-2xl font-bold text-gray-900">Sports Feed</h1>
              <p class="text-sm text-gray-600 mt-1">Filter by Sport and League to view live odds</p>
            </div>
            <div class="flex gap-3">
              <button @click="openBetTypesModal"
                class="px-4 py-2 text-sm font-medium text-purple-600 bg-purple-50 border border-purple-200 rounded-lg hover:bg-purple-100 transition-colors">
                All Available Bet Types
              </button>
              <button @click="handleLogout"
                class="px-4 py-2 text-sm font-medium text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-colors">
                Logout
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 p-6">
        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-3">Select Sport</label>

        <!-- Loading state for sports -->
        <div v-if="sports.length === 0" class="text-center py-8">
          <div class="text-gray-400 mb-4 text-4xl">⏳</div>
          <p class="text-gray-500">Loading sports...</p>
        </div>

        <!-- Sports selection -->
        <div v-else class="flex flex-wrap gap-3">
          <button v-for="sport in sports" :key="sport.id" @click="selectSport(sport)"
            class="px-4 py-2 text-sm font-medium rounded-lg"
            :class="selectedSport?.id === sport.id ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700'">
            {{ sport.name }}
          </button>
        </div>

        <div v-if="selectedSport" class="mt-6 p-4 border-2 border-green-500 bg-green-50">
          <button @click="loadAllMatchesForSport" :disabled="loadingMatches"
            class="w-full px-6 py-3 bg-green-600 text-white rounded-lg">
            <span v-if="loadingMatches">Loading Matches...</span>
            <span v-else>Get Matches for {{ selectedSport.name }}</span>
          </button>
        </div>
      </div>

      <div v-if="allMatchesLoaded" class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-gray-900">Matches ({{ filteredMatches.length }})</h3>
          <!-- Match Type Filters -->
          <div class="flex items-center gap-2">
            <button @click="setMatchTypeFilter('live')"
              :class="['px-3 py-1 text-xs font-medium rounded-full transition-colors',
                matchTypeFilter === 'live' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
              Live ({{ liveMatchesCount }})
            </button>
            <button @click="setMatchTypeFilter('prematch')"
              :class="['px-3 py-1 text-xs font-medium rounded-full transition-colors',
                matchTypeFilter === 'prematch' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
              Pre-match ({{ upcomingMatchesCount }})
            </button>
            <button @click="setMatchTypeFilter('all')"
              :class="['px-3 py-1 text-xs font-medium rounded-full transition-colors',
                matchTypeFilter === 'all' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
              All ({{ allMatches.length }})
            </button>
          </div>
        </div>

        <!-- Search Bar -->
        <div class="mb-4">
          <div class="relative">
            <input v-model="matchSearchTerm" type="text" placeholder="Search teams and leagues..."
              class="w-full px-4 py-2 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" />
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </div>
            <button v-if="matchSearchTerm" @click="matchSearchTerm = ''"
              class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        <div class="space-y-4">
          <div v-for="match in filteredMatches" :key="match.id" :class="['border rounded-lg p-4',
            match.match_type === 'live' ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-white']">
            <div class="flex justify-between items-center">
              <div class="flex-1">
                <div class="flex items-center gap-3 mb-2">
                  <div class="font-semibold">{{ match.home_team }} vs {{ match.away_team }}</div>
                  <span
                    :class="['inline-flex items-center px-2 py-1 text-xs font-medium rounded-full',
                      match.match_type === 'live' ? 'bg-red-100 text-red-800 animate-pulse' : 'bg-blue-100 text-blue-800']">
                    {{ match.match_type === 'live' ? '🔴 LIVE' : '⏰ PREMATCH' }}
                  </span>
                </div>
                <div class="text-sm text-gray-600">League: {{ match.league_name }}</div>
              </div>
              <div class="text-sm text-gray-500 text-right">
                <div>{{ formatMatchTime(match) }}</div>
                <div v-if="match.match_type === 'live'" class="text-red-600 font-medium animate-pulse">LIVE NOW</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Bet Types Modal -->
      <div v-if="showBetTypesModal" :key="modalTrigger"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click="closeBetTypesModal">
        <div class="bg-white w-full max-w-5xl h-[85vh] rounded-lg shadow-sm border border-gray-200 flex flex-col"
          @click.stop>

          <!-- Header -->
          <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
              <h2 class="text-lg font-semibold text-gray-900">All Available Bet Types</h2>
              <p class="text-sm text-gray-600 mt-1">
                Browse betting markets by sport
              </p>
            </div>
            <button @click="closeBetTypesModal"
              class="px-3 py-1 text-sm font-medium text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">
              Close
            </button>
          </div>

          <!-- Body -->
          <div class="flex flex-1 overflow-hidden">

            <!-- Left Panel: Sports -->
            <aside class="w-64 border-r border-gray-200 bg-gray-50 flex flex-col">
              <div class="px-4 py-3 border-b border-gray-200">
                <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wide">
                  Sports
                </h3>
              </div>

              <div class="flex-1 overflow-y-auto p-3 space-y-2">
                <div v-if="betTypesSports.length === 0" class="text-center py-6 text-gray-500 text-sm">
                  Loading sports...
                </div>

                <button v-else v-for="sport in betTypesSports" :key="sport.id" @click="selectBetTypesSport(sport)"
                  class="w-full px-3 py-2 text-sm font-medium rounded-lg text-left border transition-colors" :class="betTypesSelectedSport?.id === sport.id
                    ? 'bg-blue-600 text-white border-blue-600'
                    : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-100'">
                  {{ sport.name }}
                </button>
              </div>
            </aside>

            <!-- Right Panel -->
            <section class="flex-1 flex flex-col overflow-hidden">

              <!-- Search -->
              <div class="px-6 py-4 border-b border-gray-200 bg-white">
                <div class="flex items-center gap-4">
                  <div class="relative w-full max-w-sm">
                    <input v-model="betTypesSearch" type="text" placeholder="Search bet types..."
                      class="w-full px-4 py-2 pl-10 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                      <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                      </svg>
                    </div>
                    <button v-if="betTypesSearch" @click="betTypesSearch = ''"
                      class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                      ✕
                    </button>
                  </div>
                </div>
              </div>

              <!-- Content -->
              <div class="flex-1 overflow-y-auto p-6 bg-gray-50">

                <!-- Empty States -->
                <div v-if="!betTypesSelectedSport"
                  class="h-full flex items-center justify-center text-sm text-gray-500">
                  Select a sport to view bet types
                </div>

                <div v-else-if="!betTypesResponse"
                  class="h-full flex items-center justify-center text-sm text-gray-500">
                  Loading bet types...
                </div>

                <div v-else-if="Object.keys(filteredBetTypesCategories).length === 0"
                  class="h-full flex items-center justify-center text-sm text-gray-500">
                  No bet types found
                </div>

                <!-- Categories -->
                <div v-else class="space-y-6">
                  <div v-for="(betTypes, category) in filteredBetTypesCategories" :key="category"
                    class="bg-white border border-gray-200 rounded-lg">
                    <div class="px-5 py-3 border-b border-gray-200">
                      <h4 class="text-sm font-semibold text-gray-900">
                        {{ category }}
                      </h4>
                    </div>

                    <div class="p-4 space-y-3">
                      <div v-for="betType in betTypes" :key="betType.id"
                        class="flex justify-between gap-4 p-3 border border-gray-200 rounded-lg bg-white hover:bg-gray-50">
                        <div>
                          <div class="text-sm font-medium text-gray-900">
                            {{ betType.name }}
                          </div>
                          <div v-if="betType.description" class="text-xs text-gray-600 mt-1">
                            {{ betType.description }}
                          </div>
                        </div>

                        <span class="text-xs font-medium px-2 py-1 rounded-full bg-green-100 text-green-800 h-fit">
                          Available
                        </span>
                      </div>
                    </div>
                  </div>
                </div>

              </div>
            </section>
          </div>
        </div>
      </div>


    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch, nextTick } from 'vue';
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

// Match loading and filtering state
const allMatchesLoaded = ref(false);
const loadingMatches = ref(false);
const allMatches = ref([]);
const matchTypeFilter = ref('all');
const matchSearchTerm = ref('');
const pageTrigger = ref(0); // Force re-render trigger for main page

// Modal state
const showBetTypesModal = ref(false);
const betTypesResponse = ref(null);
const betTypesSelectedSport = ref(null);
const betTypesSearch = ref('');
const modalTrigger = ref(0); // Force re-render trigger

const filteredMatches = computed(() => {
  let matches = [...leagueFilteredMatches.value];

  // Filter by match type
  if (matchTypeFilter.value !== 'all') {
    matches = matches.filter(match => match.match_type === matchTypeFilter.value);
  }

  // Filter by search term
  if (matchSearchTerm.value.trim()) {
    const searchTerm = matchSearchTerm.value.toLowerCase().trim();
    matches = matches.filter(match => {
      const homeTeam = match.home_team?.toLowerCase() || '';
      const awayTeam = match.away_team?.toLowerCase() || '';
      const leagueName = match.league_name?.toLowerCase() || '';

      return homeTeam.includes(searchTerm) ||
        awayTeam.includes(searchTerm) ||
        leagueName.includes(searchTerm);
    });
  }

  // Sort matches: Live matches first, then pre-match matches by time (earliest first)
  matches.sort((a, b) => {
    // Live matches always come first
    if (a.match_type === 'live' && b.match_type !== 'live') return -1;
    if (b.match_type === 'live' && a.match_type !== 'live') return 1;

    // If both are live or both are pre-match, sort by time
    const aTime = new Date(a.scheduled_time).getTime();
    const bTime = new Date(b.scheduled_time).getTime();

    // Handle invalid dates
    if (isNaN(aTime) && isNaN(bTime)) return 0;
    if (isNaN(aTime)) return 1;
    if (isNaN(bTime)) return -1;

    return aTime - bTime; // Earliest first
  });

  return matches;
});

const leagueFilteredMatches = computed(() => {
  let matches = allMatches.value;

  // Apply league filtering only
  if (selectedLeagues.value.length > 0) {
    const selectedLeagueIds = selectedLeagues.value.map(league => league.id);
    matches = matches.filter(match => selectedLeagueIds.includes(match.league_id));
  }

  return matches;
});

const liveMatchesCount = computed(() => {
  return leagueFilteredMatches.value.filter(match => match.match_type === 'live').length;
});

const upcomingMatchesCount = computed(() => {
  return leagueFilteredMatches.value.filter(match => match.match_type === 'prematch').length;
});

const filteredBetTypesCategories = computed(() => {
  if (!betTypesSelectedSport.value || !betTypesResponse.value) {
    return {};
  }

  const categories = {};
  const responseCategories = betTypesResponse.value.categories || {};

  // Get bet types for the selected sport
  Object.entries(responseCategories).forEach(([category, betTypes]) => {
    const filteredBetTypes = betTypes.filter(betType => {
      if (!betTypesSearch.value.trim()) return true;

      const searchTerm = betTypesSearch.value.toLowerCase().trim();
      const name = betType.name?.toLowerCase() || '';
      const description = betType.description?.toLowerCase() || '';

      return name.includes(searchTerm) || description.includes(searchTerm);
    });

    if (filteredBetTypes.length > 0) {
      categories[category] = filteredBetTypes;
    }
  });

  return categories;
});

// Authentication
const handleLogin = async () => {
  if (password.value === 'sportsfeed2025') {
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
  allMatchesLoaded.value = false;
  allMatches.value = [];
  activeMatchTypeFilter.value = 'all';
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
  setTimeout(() => {
    showLeagueDropdown.value = false;
  }, 200);
};

const loadLeaguesForSport = async () => {
  if (!selectedSport.value) return;

  loadingLeagues.value = true;

  try {
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
    filteredLeagues.value = leagues.value.slice(0, 10);
    return;
  }

  filteredLeagues.value = leagues.value
    .filter(league => matchesLeagueSearch(league, leagueSearch.value))
    .sort((a, b) => calculateSearchScore(b, leagueSearch.value) - calculateSearchScore(a, leagueSearch.value))
    .slice(0, 20);
};

const matchesLeagueSearch = (league, search) => {
  if (!search) return true;

  const name = league.name.toLowerCase();
  const query = search.toLowerCase();

  if (name === query) return true;
  if (name.startsWith(query)) return true;
  if (name.includes(' ' + query) || name.includes(query + ' ')) return true;
  if (name.includes(query)) return true;

  const nameWords = name.split(' ');
  return nameWords.some(word => word.startsWith(query));
};

const calculateSearchScore = (league, search) => {
  const name = league.name.toLowerCase();
  const query = search.toLowerCase();

  if (name === query) return 100;
  if (name.startsWith(query)) return 80;
  if (name.includes(' ' + query + ' ')) return 60;
  if (name.includes(query)) return 40;

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

    if (response.data.data && Array.isArray(response.data.data)) {
      sports.value = response.data.data;
    } else if (response.data && Array.isArray(response.data)) {
      sports.value = response.data;
    } else if (response.data && Array.isArray(response.data.sports)) {
      sports.value = response.data.sports;
    } else {
      sports.value = [];
    }

    try {
      const betTypesResponse = await http.get(API_ENDPOINTS.BET_TYPES.SPORTS);
      betTypesSports.value = betTypesResponse.data || [];
    } catch (error) {
      console.error('Error loading bet types sports:', error);
      betTypesSports.value = [];
    }
  } catch (error) {
    console.error('Error fetching sports:', error);
    sports.value = [];
  }
};

const selectSport = async (sport) => {
  selectedSport.value = { ...sport }; // Force reactivity
  pageTrigger.value++; // Force re-render

  selectedLeagues.value = [];
  leagueSearch.value = '';
  leagues.value = [];

  allMatchesLoaded.value = false;
  allMatches.value = [];
  matchTypeFilter.value = 'all';
  matchSearchTerm.value = '';

  await nextTick(); // Wait for DOM update
  fetchLeagues();
};

const fetchLeagues = async () => {
  if (!selectedSport.value) return;

  loadingLeagues.value = true;
  try {
    const popularSearches = ['NBA', 'NHL', 'MLB', 'NFL', 'WNBA', 'NCAAB', 'NCAAF'];

    let allLeagues = [];

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

    const uniqueLeagues = allLeagues.filter((league, index, self) =>
      index === self.findIndex(l => l.id === league.id)
    );

    leagues.value = uniqueLeagues;
  } catch (error) {
    console.error('Error fetching leagues:', error);
    leagues.value = [];
  } finally {
    loadingLeagues.value = false;
  }
};

const loadAllMatchesForSport = async () => {
  if (!selectedSport.value) return;

  loadingMatches.value = true;

  try {
    const response = await http.post(API_ENDPOINTS.MATCHES, {
      sport_id: selectedSport.value.pinnacleId || selectedSport.value.id,
    });

    allMatches.value = response.data.matches || [];
    allMatchesLoaded.value = true;

    console.log(`Loaded ${allMatches.value.length} matches for sport ${selectedSport.value.name}`);
  } catch (error) {
    console.error('Error loading matches:', error);
    allMatches.value = [];
  } finally {
    loadingMatches.value = false;
  }
};

const setMatchTypeFilter = (matchType) => {
  matchTypeFilter.value = matchType;
};

const clearFilters = () => {
  selectedLeagues.value = [];
  matchTypeFilter.value = 'all';
  leagueSearch.value = '';
  matchSearchTerm.value = '';
};

const openBetTypesModal = () => {
  showBetTypesModal.value = true;
  betTypesSearch.value = '';
  betTypesResponse.value = null;
  modalTrigger.value = 0; // Reset trigger

  // Pre-select the currently selected sport if any
  if (selectedSport.value) {
    betTypesSelectedSport.value = selectedSport.value;
    // Load bet types for the pre-selected sport
    selectBetTypesSport(selectedSport.value);
  }
};

const closeBetTypesModal = () => {
  showBetTypesModal.value = false;
  betTypesSelectedSport.value = null;
  betTypesSearch.value = '';
  betTypesResponse.value = null;
  modalTrigger.value = 0;
};

const selectBetTypesSport = async (sport) => {
  betTypesSelectedSport.value = { ...sport };
  modalTrigger.value++; // Force re-render

  await nextTick(); // Force DOM update

  betTypesResponse.value = null; // Reset while loading

  try {
    const sportId = sport.pinnacleId || sport.id;
    const response = await http.get(API_ENDPOINTS.REFERENCE.BET_TYPES, {
      params: { sportId: sportId }
    });
    betTypesResponse.value = { ...response.data };
    modalTrigger.value++; // Force final re-render

    await nextTick();
  } catch (error) {
    console.error('Error fetching bet types:', error);
    betTypesResponse.value = {}; // Set to empty object to indicate we tried
    modalTrigger.value++; // Force re-render on error
    await nextTick();
  }
};

const formatMatchTime = (match) => {
  if (match.match_type === 'live') {
    return 'Live Now';
  }

  if (!match.scheduled_time || match.scheduled_time === 'TBD') {
    return 'TBD';
  }

  // Handle the format "01/16/2026, 03:10:00"
  if (match.scheduled_time.includes(',')) {
    const parts = match.scheduled_time.split(', ');
    if (parts.length === 2) {
      const datePart = parts[0];
      const timePart = parts[1];

      const date = new Date(datePart + ' ' + timePart);
      if (!isNaN(date.getTime())) {
        const now = new Date();
        const matchDate = new Date(date);

        // If same day, just show time
        if (matchDate.toDateString() === now.toDateString()) {
          return `Today ${timePart}`;
        }

        // If tomorrow, show tomorrow
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

  return match.scheduled_time;
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
