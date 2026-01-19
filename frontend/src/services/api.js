// API Endpoints Constants
export const API_ENDPOINTS = {
  // Sports
  SPORTS: '/sports',

  // Bet Types
  BET_TYPES: {
    SPORTS: '/bet-types/sports',
    LEAGUES: (sportId) => `/bet-types/leagues/${sportId}`,
    MATCHES: '/bet-types/matches',
    MATCH: (matchId) => `/bet-types/match/${matchId}`
  },

  // Reference Data
  REFERENCE: {
    LEAGUES_SEARCH: '/reference/leagues/search',
    BET_TYPES: '/reference/bet-types'
  },

  // Matches
  MATCHES: '/matches',
  MATCH_ODDS: (matchId) => `/matches/${matchId}/odds`
};

// HTTP methods
export const HTTP_METHODS = {
  GET: 'get',
  POST: 'post',
  PUT: 'put',
  DELETE: 'delete',
  PATCH: 'patch'
};

// Common API parameters
export const API_PARAMS = {
  SPORT_ID: 'sport_id',
  SPORTID: 'sportId', // Alternative naming
  LEAGUE_ID: 'league_id',
  LEAGUE_IDS: 'league_ids',
  MATCH_TYPE: 'match_type',
  MARKET_TYPE: 'market_type',
  PERIOD: 'period',
  SEARCH: 'search',
  LIMIT: 'limit'
};

// Match types
export const MATCH_TYPES = {
  ALL: 'all',
  LIVE: 'live',
  PREMATCH: 'prematch'
};

// Market types
export const MARKET_TYPES = {
  MONEY_LINE: 'money_line',
  SPREADS: 'spreads',
  TOTALS: 'totals',
  PLAYER_PROPS: 'player_props',
  TEAM_TOTALS: 'team_totals',
  TEAM_PROPS: 'team_props',
  CORNERS: 'corners',
  DRAW_NO_BET: 'draw_no_bet',
  BOTH_TEAMS_TO_SCORE: 'both_teams_to_score',
  CORRECT_SCORE: 'correct_score'
};
