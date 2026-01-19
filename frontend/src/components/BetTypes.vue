<template>
    <div class="bet-types-container">
      <div class="filters">
        <select v-model="selectedSport" @change="loadLeagues">
          <option value="">Select Sport</option>
          <option v-for="sport in sports" :key="sport.id" :value="sport.id">
            {{ sport.name }}
          </option>
        </select>
        
        <div v-if="leagues.length > 0" class="league-selector">
          <input
            type="text"
            v-model="leagueSearch"
            placeholder="Search leagues..."
            class="league-search"
          >
          <select v-model="selectedLeague" @change="loadMatches" size="5" class="league-dropdown">
            <option value="">Select League</option>
            <option
              v-for="league in filteredLeagues"
              :key="league.id"
              :value="league.id"
            >
              {{ league.name }}
            </option>
          </select>
        </div>
        
        <label>
          <input type="checkbox" v-model="showLiveOnly" @change="loadMatches">
          Live Matches Only
        </label>
      </div>
      
      <div class="matches-grid" v-if="matches.length > 0">
        <div 
          v-for="match in matches" 
          :key="match.id" 
          class="match-card"
          @click="selectMatch(match)"
        >
          <div class="match-header">
            <div class="teams">
              <span class="home-team">{{ match.home_team }}</span>
              <span class="vs">vs</span>
              <span class="away-team">{{ match.away_team }}</span>
            </div>
            <div class="league">{{ match.league_name }}</div>
          </div>
          
          <div class="match-time">
            {{ formatTime(match.scheduled_at) }}
            <span v-if="match.status === 'live'" class="live-badge">LIVE</span>
          </div>
          
          <div v-if="match.has_open_markets" class="betting-available">
            ✓ Betting Available
          </div>
        </div>
      </div>
      
      <div v-if="selectedMatch" class="bet-types-modal">
        <div class="modal-content">
          <h3>{{ selectedMatch.home_team }} vs {{ selectedMatch.away_team }}</h3>
          
          <div class="market-tabs">
            <button 
              v-for="marketType in availableMarketTypes"
              :key="marketType"
              :class="{ active: activeMarketType === marketType }"
              @click="activeMarketType = marketType"
            >
              {{ formatMarketType(marketType) }}
            </button>
          </div>
          
          <div class="markets-list">
            <div 
              v-for="market in activeMarkets" 
              :key="market.type"
              class="market-item"
            >
              <h4>{{ market.name }}</h4>
              <div class="outcomes">
                <div 
                  v-for="outcome in market.outcomes" 
                  :key="outcome.name"
                  class="outcome"
                >
                  <span class="name">{{ outcome.name }}</span>
                  <span class="odds">{{ outcome.odds }}</span>
                </div>
              </div>
            </div>
          </div>
          
          <button @click="closeModal" class="close-btn">Close</button>
        </div>
      </div>
      
      <!-- Loading and Error States -->
      <div v-if="loading" class="loading">
        Loading bet types...
      </div>
      
      <div v-if="error" class="error">
        {{ error }}
      </div>
    </div>
  </template>
  
  <script>
  import http from '../services/http.js'
  import { API_ENDPOINTS, API_PARAMS } from '../services/api.js'
  
  export default {
    name: 'BetTypes',
    data() {
      return {
        sports: [],
        leagues: [],
        matches: [],
        selectedSport: '',
        selectedLeague: '',
        leagueSearch: '',
        showLiveOnly: false,
        selectedMatch: null,
        betTypes: [],
        activeMarketType: 'money_line',
        loading: false,
        error: null
      }
    },
    
    computed: {
      availableMarketTypes() {
        if (!this.betTypes.length) return []
        return [...new Set(this.betTypes.map(m => m.type))]
      },
      
      activeMarkets() {
        return this.betTypes.filter(market => market.type === this.activeMarketType)
      },

      filteredLeagues() {
        if (!this.leagueSearch.trim()) {
          return this.leagues.slice(0, 50) // Show first 50 if no search
        }

        const search = this.leagueSearch.toLowerCase()
        return this.leagues.filter(league =>
          league.name.toLowerCase().includes(search)
        ).slice(0, 20) // Limit to 20 results for performance
      }
    },
    
    mounted() {
      this.loadSports()
    },
    
    methods: {
      async loadSports() {
        try {
          this.loading = true
          this.error = null
          
          const response = await http.get(API_ENDPOINTS.BET_TYPES.SPORTS)
          this.sports = response.data.sports || response.data || []
          
          console.log('Sports loaded:', this.sports.length)
        } catch (error) {
          this.error = 'Failed to load sports'
          console.error('Error loading sports:', error)
        } finally {
          this.loading = false
        }
      },
      
      async loadLeagues() {
        if (!this.selectedSport) return

        try {
          this.loading = true
          this.error = null
          this.leagueSearch = '' // Clear search when loading new leagues
          this.selectedLeague = '' // Clear selected league

          const response = await http.get(API_ENDPOINTS.BET_TYPES.LEAGUES(this.selectedSport))
          this.leagues = response.data.leagues || response.data || []

          console.log('Leagues loaded for sport', this.selectedSport, ':', this.leagues.length)
        } catch (error) {
          this.error = 'Failed to load leagues'
          console.error('Error loading leagues:', error)
        } finally {
          this.loading = false
        }
      },
      
      async loadMatches() {
        if (!this.selectedSport) return
        
        try {
          this.loading = true
          this.error = null
          
          const params = {
            sport_id: this.selectedSport,
            league_id: this.selectedLeague || '',
            live: this.showLiveOnly ? 1 : 0
          }
          
          const response = await http.get(API_ENDPOINTS.BET_TYPES.MATCHES, { params })
          this.matches = response.data || []
          
          console.log('Matches loaded:', this.matches.length)
        } catch (error) {
          this.error = 'Failed to load matches'
          console.error('Error loading matches:', error)
        } finally {
          this.loading = false
        }
      },
      
      async selectMatch(match) {
        try {
          this.loading = true
          this.error = null
          
          this.selectedMatch = match
          
          const response = await http.get(API_ENDPOINTS.BET_TYPES.MATCH(match.id))
          this.betTypes = response.data || []
          
          console.log('Bet types loaded for match', match.id, ':', this.betTypes.length)
          
          // Set first market type as active if available
          if (this.availableMarketTypes.length > 0) {
            this.activeMarketType = this.availableMarketTypes[0]
          }
        } catch (error) {
          this.error = 'Failed to load bet types'
          console.error('Error loading bet types:', error)
        } finally {
          this.loading = false
        }
      },
      
      closeModal() {
        this.selectedMatch = null
        this.betTypes = []
        this.activeMarketType = 'money_line'
      },
      
      formatTime(dateString) {
        if (!dateString) return 'TBD'
        
        const date = new Date(dateString)
        return date.toLocaleTimeString([], { 
          hour: '2-digit', 
          minute: '2-digit',
          month: 'short',
          day: 'numeric'
        })
      },
      
      formatMarketType(type) {
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
        }
        
        return typeMap[type] || type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
      }
    }
  }
  </script>
  
  <style scoped>
  .bet-types-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
  }
  
  .filters {
    display: flex;
    gap: 15px;
    margin-bottom: 30px;
    flex-wrap: wrap;
    align-items: center;
  }
  
  .filters select, .filters input {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
  }
  
  .filters label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
  }

  .league-selector {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .league-search {
    width: 300px;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
  }

  .league-dropdown {
    width: 300px;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    max-height: 200px;
  }
  
  .matches-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
  }
  
  .match-card {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
  }
  
  .match-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-color: #007bff;
    transform: translateY(-2px);
  }
  
  .match-header {
    margin-bottom: 12px;
  }
  
  .teams {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  
  .home-team, .away-team {
    flex: 1;
    text-align: center;
  }
  
  .vs {
    color: #666;
    margin: 0 8px;
    font-weight: 400;
  }
  
  .league {
    color: #666;
    font-size: 13px;
    text-align: center;
  }
  
  .match-time {
    font-size: 14px;
    color: #333;
    font-weight: 500;
    text-align: center;
  }
  
  .live-badge {
    background: #dc3545;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    margin-left: 8px;
    font-weight: bold;
    animation: pulse 2s infinite;
  }
  
  @keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.7; }
    100% { opacity: 1; }
  }
  
  .betting-available {
    color: #28a745;
    font-weight: 600;
    font-size: 13px;
    margin-top: 8px;
    text-align: center;
  }
  
  .bet-types-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 20px;
  }
  
  .modal-content {
    background: white;
    padding: 24px;
    border-radius: 12px;
    max-width: 900px;
    max-height: 80vh;
    overflow-y: auto;
    width: 100%;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
  }
  
  .modal-content h3 {
    margin: 0 0 20px 0;
    color: #333;
    font-size: 20px;
    text-align: center;
  }
  
  .market-tabs {
    display: flex;
    gap: 8px;
    margin: 20px 0;
    flex-wrap: wrap;
    justify-content: center;
  }
  
  .market-tabs button {
    padding: 8px 16px;
    border: 2px solid #e0e0e0;
    background: white;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
  }
  
  .market-tabs button:hover {
    border-color: #007bff;
    background: #f8f9ff;
  }
  
  .market-tabs button.active {
    background: #007bff;
    color: white;
    border-color: #007bff;
  }
  
  .markets-list {
    max-height: 400px;
    overflow-y: auto;
  }
  
  .market-item {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    background: #fafafa;
  }
  
  .market-item h4 {
    margin: 0 0 12px 0;
    color: #333;
    font-size: 16px;
  }
  
  .outcomes {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
  }
  
  .outcome {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    background: white;
    border-radius: 6px;
    border: 1px solid #e0e0e0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
  }
  
  .outcome .name {
    font-weight: 500;
    color: #333;
  }
  
  .outcome .odds {
    font-weight: bold;
    color: #007bff;
    font-size: 16px;
  }
  
  .close-btn {
    background: #6c757d;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    margin-top: 20px;
    width: 100%;
    transition: background 0.2s ease;
  }
  
  .close-btn:hover {
    background: #5a6268;
  }
  
  .loading {
    text-align: center;
    padding: 40px;
    color: #666;
    font-size: 16px;
  }
  
  .error {
    text-align: center;
    padding: 20px;
    color: #dc3545;
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    border-radius: 4px;
    margin: 20px 0;
  }
  
  /* Responsive Design */
  @media (max-width: 768px) {
    .bet-types-container {
      padding: 15px;
    }
    
    .filters {
      flex-direction: column;
      align-items: stretch;
    }
    
    .matches-grid {
      grid-template-columns: 1fr;
    }
    
    .modal-content {
      margin: 10px;
      padding: 20px;
    }
    
    .outcomes {
      grid-template-columns: 1fr;
    }
    
    .teams {
      flex-direction: column;
      gap: 8px;
    }
    
    .home-team, .away-team {
      text-align: center;
    }
  }
  </style>