<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Full Page Loading Overlay -->
    <div v-if="pageLoading" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
      <div class="bg-white rounded-lg shadow-xl p-8 flex flex-col items-center space-y-4">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
        <div class="text-center">
          <h3 class="text-lg font-semibold text-gray-900">Loading Matches</h3>
          <p class="text-sm text-gray-600 mt-1">Please wait while we fetch the latest data...</p>
        </div>
      </div>
    </div>
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
              <button @click="openLeaguesModal"
                class="px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors">
                All Available Leagues
              </button>
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
            <span v-else>Get Matches</span>
          </button>
        </div>
      </div>

      <div v-if="allMatchesLoaded" class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-3">
            <h3 class="text-lg font-semibold text-gray-900">Matches ({{ filteredMatches.length }})</h3>
            <button @click="manualRefresh"
              :disabled="isRefreshing || !selectedSport"
              :title="isRefreshing ? 'Refreshing...' : 'Refresh data'"
              class="p-2 text-sm font-medium text-green-600 bg-green-50 border border-green-200 rounded-lg hover:bg-green-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
              <svg v-if="isRefreshing" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
            </button>
          </div>
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
          </div>
        </div>

        <!-- Matches Display -->
        <MatchesDisplay :matches="filteredMatches" :selected-sport="selectedSport" :loading="loadingMatches" />
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

                  <div v-if="betTypesSelectedSport" class="text-sm text-gray-600">
                    {{ betTypesSelectedSport.name }}
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

      <!-- Leagues Modal -->
      <div v-if="showLeaguesModal" :key="leaguesModalTrigger" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click="closeLeaguesModal">
        <div class="bg-white w-full max-w-6xl h-[85vh] rounded-lg shadow-sm border border-gray-200 flex flex-col"
          @click.stop>

          <!-- Header -->
          <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
              <h2 class="text-lg font-semibold text-gray-900">All Available Leagues</h2>
              <p class="text-sm text-gray-600 mt-1">
                Browse leagues by sport
              </p>
            </div>
            <button @click="closeLeaguesModal"
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
                <div v-if="leaguesSports.length === 0" class="text-center py-6 text-gray-500 text-sm">
                  Loading sports...
                </div>

                <button v-else v-for="sport in leaguesSports" :key="sport.id" @click="selectLeaguesSport(sport)"
                  class="w-full px-3 py-2 text-sm font-medium rounded-lg text-left border transition-colors" :class="leaguesSelectedSport?.id === sport.id
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
                    <input v-model="leaguesSearch" type="text" placeholder="Search leagues..."
                      class="w-full px-4 py-2 pl-10 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                      <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                      </svg>
                    </div>
                    <button v-if="leaguesSearch" @click="leaguesSearch = ''"
                      class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                      ✕
                    </button>
                  </div>
                </div>
              </div>

              <!-- Content -->
              <div class="flex-1 overflow-y-auto p-6 bg-gray-50">

                <!-- Empty States -->
                <div v-if="!leaguesSelectedSport"
                  class="h-full flex items-center justify-center text-sm text-gray-500">
                  Select a sport to view leagues
                </div>

                <div v-else-if="leaguesLoading"
                  class="h-full flex items-center justify-center text-sm text-gray-500">
                  Loading leagues...
                </div>

                <div v-else-if="leaguesError"
                  class="h-full flex items-center justify-center text-center">
                  <div class="text-red-500 mb-4 text-4xl">⚠️</div>
                  <p class="text-red-600 mb-4">{{ leaguesError }}</p>
                  <button @click="selectLeaguesSport(leaguesSelectedSport)"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    Try Again
                  </button>
                </div>

                <div v-else-if="filteredLeagues.length === 0"
                  class="h-full flex items-center justify-center text-center">
                  <div class="text-gray-400 mb-4 text-4xl">🏆</div>
                  <h3 class="text-lg font-medium text-gray-900 mb-2">No leagues found</h3>
                  <p class="text-gray-500">
                    {{ leaguesSearch ? 'Try adjusting your search terms.' : 'No leagues available for this sport.' }}
                  </p>
                </div>

                <!-- Leagues list -->
                <div v-else class="space-y-3">
                  <div v-for="league in filteredLeagues" :key="league.id"
                    class="p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors bg-white">
                    <div class="flex items-center justify-between">
                      <div class="flex-1">
                        <h4 class="font-semibold text-gray-900">{{ league.name }}</h4>
                        <p class="text-sm text-gray-600">{{ league.container }}</p>
                        <div class="flex items-center gap-4 mt-2 text-xs text-gray-500">
                          <span>Events: {{ league.event_count || 0 }}</span>
                          <span v-if="league.has_offerings" class="text-green-600 font-medium">Active</span>
                        </div>
                      </div>
                      <div class="flex items-center gap-2">
                        <span class="px-2 py-1 text-xs font-medium rounded-full"
                          :class="league.has_offerings
                            ? 'bg-green-100 text-green-800'
                            : 'bg-gray-100 text-gray-800'">
                          {{ league.has_offerings ? 'Available' : 'Inactive' }}
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
import { ref, computed, onMounted, nextTick } from 'vue'
import http from '../services/http.js'
import { API_ENDPOINTS } from '../services/api.js'
import MatchesDisplay from '../components/MatchesDisplay.vue'
import Swal from 'sweetalert2'

// Authentication
const isAuthenticated = ref(localStorage.getItem('isAuthenticated') === 'true')
const password = ref('')

// Data
const sports = ref([])
const selectedSport = ref(null)

// Match management
const allMatches = ref([])
const allMatchesLoaded = ref(false)
const loadingMatches = ref(false)
const pageLoading = ref(false) // Full page loading overlay
const matchTypeFilter = ref('all')
const matchSearchTerm = ref('')
const pageTrigger = ref(0)

// Bet Types Modal
const showBetTypesModal = ref(false)
const betTypesSports = ref([])
const betTypesSelectedSport = ref(null)
const betTypesResponse = ref({})
const betTypesLoading = ref(false)
const betTypesError = ref('')
const betTypesSearch = ref('')
const modalTrigger = ref(0)

// Leagues Modal
const showLeaguesModal = ref(false)
const leaguesSports = ref([])
const leaguesSelectedSport = ref(null)
const leaguesData = ref([])
const leaguesLoading = ref(false)
const leaguesError = ref('')
const leaguesSearch = ref('')
const leaguesModalTrigger = ref(0)

// Manual Refresh
const isRefreshing = ref(false)

// Computed properties
const liveMatchesCount = computed(() =>
  allMatches.value.filter(match => match.match_type === 'live').length
)

const upcomingMatchesCount = computed(() =>
  allMatches.value.filter(match => match.match_type === 'prematch').length
)

const filteredMatches = computed(() => {
  let matches = [...allMatches.value]

  // Filter by match type
  if (matchTypeFilter.value !== 'all') {
    matches = matches.filter(match => match.match_type === matchTypeFilter.value)
  }

  // Filter by search term
  if (matchSearchTerm.value.trim()) {
    const search = matchSearchTerm.value.toLowerCase()
    matches = matches.filter(match => {
      const homeTeam = match.home_team?.toLowerCase() || ''
      const awayTeam = match.away_team?.toLowerCase() || ''
      const league = match.league_name?.toLowerCase() || ''

      return homeTeam.includes(search) ||
        awayTeam.includes(search) ||
        league.includes(search) ||
        `${homeTeam} vs ${awayTeam}`.includes(search)
    })
  }

  return matches
})

const filteredBetTypesCategories = computed(() => {
  return groupBetTypesByCategory()
})

// Authentication
const handleLogin = async () => {
  if (password.value === 'sportsfeed2025') {
    isAuthenticated.value = true
    localStorage.setItem('isAuthenticated', 'true')
    await fetchSports()
  } else {
    await Swal.fire({
      icon: 'error',
      title: 'Access Denied',
      text: 'Invalid password. Please try again.',
      confirmButtonColor: '#EF4444'
    })
  }
}

const handleLogout = () => {
  isAuthenticated.value = false
  localStorage.removeItem('isAuthenticated', '')
  password.value = ''
  selectedSport.value = null
  allMatches.value = []
  allMatchesLoaded.value = false
  resetBetTypesState()
  resetLeaguesState()
}

// Data fetching
const fetchSports = async () => {
  try {
    const response = await http.get(API_ENDPOINTS.SPORTS)
    sports.value = response.data.data || response.data || []

    // Also fetch bet types and leagues sports for the modals
    try {
      const betTypesResponse = await http.get(API_ENDPOINTS.BET_TYPES.SPORTS)
      betTypesSports.value = betTypesResponse.data || []
      leaguesSports.value = [...betTypesSports.value] // Use same sports list for leagues
    } catch (betTypesError) {
      console.error('Error fetching bet types sports:', betTypesError)
      betTypesSports.value = []
      leaguesSports.value = []
    }
  } catch (error) {
    console.error('Error fetching sports:', error)
    sports.value = []
    betTypesSports.value = []
    leaguesSports.value = []
  }
}

const selectSport = async (sport) => {
  selectedSport.value = { ...sport }
  allMatchesLoaded.value = false
  allMatches.value = []
  matchTypeFilter.value = 'all'
  matchSearchTerm.value = ''
  pageTrigger.value++
  await nextTick()
}

const loadAllMatchesForSport = async () => {
  if (!selectedSport.value) return

  loadingMatches.value = true
  pageLoading.value = true
  try {
    const response = await http.post(API_ENDPOINTS.MATCHES, {
      sport_id: selectedSport.value.pinnacleId || selectedSport.value.id
    })

    allMatches.value = response.data.matches || []

    // Sort matches: live first, then by date
    allMatches.value.sort((a, b) => {
      if (a.match_type === 'live' && b.match_type !== 'live') return -1
      if (b.match_type === 'live' && a.match_type !== 'live') return 1

      // For same type, sort by time
      const aTime = new Date(a.scheduled_time || a.last_updated || '2026-01-01')
      const bTime = new Date(b.scheduled_time || b.last_updated || '2026-01-01')
      return aTime - bTime
    })

    allMatchesLoaded.value = true

    console.log(`Loaded ${allMatches.value.length} matches for ${selectedSport.value.name}`)
  } catch (error) {
    console.error('Error loading matches:', error)
    allMatches.value = []
  } finally {
    loadingMatches.value = false
    pageLoading.value = false
  }
}

// Match type filtering
const setMatchTypeFilter = (type) => {
  matchTypeFilter.value = type
}


// Bet Types Modal
const openBetTypesModal = () => {
  showBetTypesModal.value = true
  betTypesSelectedSport.value = selectedSport.value ? { ...selectedSport.value } : null
  if (betTypesSelectedSport.value) {
    selectBetTypesSport(betTypesSelectedSport.value)
  }
}

const closeBetTypesModal = () => {
  showBetTypesModal.value = false
  resetBetTypesState()
}

const resetBetTypesState = () => {
  betTypesSelectedSport.value = null
  betTypesResponse.value = {}
  betTypesLoading.value = false
  betTypesError.value = ''
  betTypesSearch.value = ''
}

const selectBetTypesSport = async (sport) => {
  betTypesSelectedSport.value = { ...sport }
  betTypesLoading.value = true
  betTypesError.value = ''
  betTypesSearch.value = ''

  try {
    const response = await http.get(API_ENDPOINTS.REFERENCE.BET_TYPES, {
      params: { sportId: sport.pinnacleId || sport.id }
    })

    betTypesResponse.value = { ...response.data }
  } catch (error) {
    betTypesError.value = 'Failed to load bet types'
    console.error('Error loading bet types:', error)
  } finally {
    betTypesLoading.value = false
  }

  modalTrigger.value++
  await nextTick()
}

const groupBetTypesByCategory = () => {
  const categories = betTypesResponse.value.categories || {}
  const searchTerm = betTypesSearch.value.toLowerCase().trim()

  if (!searchTerm) return categories

  const filteredCategories = {}
  Object.keys(categories).forEach(categoryName => {
    const categoryBetTypes = categories[categoryName]
    const filteredBetTypes = categoryBetTypes.filter(betType => {
      const nameMatch = betType.name.toLowerCase().includes(searchTerm)
      const descMatch = betType.description?.toLowerCase().includes(searchTerm)
      return nameMatch || descMatch
    })

    if (filteredBetTypes.length > 0) {
      filteredCategories[categoryName] = filteredBetTypes
    }
  })

  return filteredCategories
}

// Leagues Modal functionality
const openLeaguesModal = () => {
  showLeaguesModal.value = true
  leaguesSelectedSport.value = selectedSport.value ? { ...selectedSport.value } : null
  if (leaguesSelectedSport.value) {
    selectLeaguesSport(leaguesSelectedSport.value)
  }
}

const closeLeaguesModal = () => {
  showLeaguesModal.value = false
  resetLeaguesState()
}

const resetLeaguesState = () => {
  leaguesSelectedSport.value = null
  leaguesData.value = []
  leaguesLoading.value = false
  leaguesError.value = ''
  leaguesSearch.value = ''
}

const selectLeaguesSport = async (sport) => {
  leaguesSelectedSport.value = { ...sport }
  leaguesLoading.value = true
  leaguesError.value = ''
  leaguesSearch.value = ''

  try {
    const response = await http.get(API_ENDPOINTS.LEAGUES, {
      params: {
        sportId: sport.pinnacleId || sport.id,
        per_page: 1000 // Request all leagues (set high limit)
      }
    })

    leaguesData.value = response.data.data || response.data || []
  } catch (error) {
    console.error('Error fetching leagues:', error)
    leaguesError.value = 'Failed to load leagues. Please try again.'
    leaguesData.value = []
  } finally {
    leaguesLoading.value = false
  }

  leaguesModalTrigger.value++
  await nextTick()
}

const filteredLeagues = computed(() => {
  let leagues = [...leaguesData.value]

  // First sort by availability (available leagues first)
  leagues.sort((a, b) => {
    const aHasOfferings = a.has_offerings ? 1 : 0
    const bHasOfferings = b.has_offerings ? 1 : 0
    return bHasOfferings - aHasOfferings // Available first (descending)
  })

  // Then apply search filter if there's a search term
  if (leaguesSearch.value.trim()) {
    const searchTerm = leaguesSearch.value.toLowerCase().trim()
    leagues = leagues.filter(league =>
      league.name?.toLowerCase().includes(searchTerm) ||
      league.container?.toLowerCase().includes(searchTerm)
    )
  }

  return leagues
})

// Manual refresh functionality
const manualRefresh = async () => {
  if (isRefreshing.value || !selectedSport.value) return

  isRefreshing.value = true

  try {
    // Only refresh the currently selected sport to avoid rate limiting
    const sportId = selectedSport.value.id
    const leagueIds = selectedSport.value.selectedLeagues?.map(league => league.id) || []
    const matchType = matchTypeFilter.value

    const refreshData = {
      sport_id: sportId,
      league_ids: leagueIds, // Empty array means all leagues for this sport
      match_type: matchType,
      force_refresh: true
    }

    console.log('Triggering sport-specific manual refresh:', refreshData)

    const response = await http.post(API_ENDPOINTS.MATCHES_REFRESH, refreshData)

    console.log('Manual refresh response:', response.data)

    // Show success message with sport name
    await Swal.fire({
      icon: 'success',
      title: 'Refresh Initiated!',
      text: `Data refresh initiated for ${selectedSport.value.name}! Matches and odds will be updated shortly.`,
      confirmButtonColor: '#10B981',
      timer: 3000,
      timerProgressBar: true
    })

    // Reload matches after a short delay
    setTimeout(() => {
      loadMatches()
    }, 2000)

  } catch (error) {
    console.error('Manual refresh failed:', error)
    await Swal.fire({
      icon: 'error',
      title: 'Refresh Failed',
      text: `Failed to refresh ${selectedSport.value.name} data. Please try again.`,
      confirmButtonColor: '#EF4444'
    })
  } finally {
    isRefreshing.value = false
  }
}

// Lifecycle
onMounted(() => {
  if (isAuthenticated.value) {
    fetchSports()
  }
})
</script>

<style scoped>
/* Add any scoped styles here */
</style>