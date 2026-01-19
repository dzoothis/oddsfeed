import { useState, useEffect, useRef } from 'react';
import Head from 'next/head';
import MatchCard from '../components/MatchCard';
import { debug, info, warn, error } from '../lib/client-logger';

const DASHBOARD_PASSWORD = 'sportsfeed2025';
const API_KEY = 'sf_f4446f8e65d5ca02ec79b52b6afb75ec';

// Updated RapidAPI key: 1136d969acmsh09f0b7708001d5fp182010jsn7447ede24aae

export default function FilterDashboard() {

  // Authentication
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [password, setPassword] = useState('');

  // Reference data
  const [sports, setSports] = useState([]);
  const [leagues, setLeagues] = useState([]);
  const [teams, setTeams] = useState([]);
  const [betTypes, setBetTypes] = useState({});

  // Filter state
  const [selectedSportId, setSelectedSportId] = useState(null);
  const [selectedLeagues, setSelectedLeagues] = useState([]);
  const [selectedTeams, setSelectedTeams] = useState([]);

  // View mode: 'all' (show all matches for sport) or 'filtered' (filter by league/team)
  const [viewMode, setViewMode] = useState('filtered'); // 'all' or 'filtered'
  const [eventTypeFilter, setEventTypeFilter] = useState('both'); // 'live', 'prematch', or 'both'

  // Search state
  const [leagueSearch, setLeagueSearch] = useState('');
  const [teamSearch, setTeamSearch] = useState('');
  const [showLeagueDropdown, setShowLeagueDropdown] = useState(false);
  const [showTeamDropdown, setShowTeamDropdown] = useState(false);

  // Modal state
  const [showSportsModal, setShowSportsModal] = useState(false);
  const [showBetTypesModal, setShowBetTypesModal] = useState(false);
  const [modalSelectedSport, setModalSelectedSport] = useState(null);
  const [modalSelectedLeague, setModalSelectedLeague] = useState(null);
  const [modalSelectedTeams, setModalSelectedTeams] = useState(new Set());

  // Modal data state
  const [modalLeagues, setModalLeagues] = useState([]);
  const [loadingModalLeagues, setLoadingModalLeagues] = useState(false);
  const [modalLeagueSearch, setModalLeagueSearch] = useState('');

  // Matches state
  const [matches, setMatches] = useState([]);
  const [loadingMatches, setLoadingMatches] = useState(false);

  // Loading state
  const [loading, setLoading] = useState(false);
  const [loadingTeams, setLoadingTeams] = useState(false);

  // API-Football enrichment cache (client-side)
  const enrichmentCache = useRef(new Map()); // Map<matchId, {timestamp, data}>

  // ============================================================================
  // Odds Update Tracking Utilities
  // ============================================================================

  /**
   * Generate a unique fingerprint for a market to enable efficient comparison
   * @param {Object} market - Market object with bet, teamType, line, source, bookmaker
   * @param {string} marketType - Type of market (moneyLine, spreads, totals, etc.)
   * @returns {string} Unique fingerprint string
   */
  const generateMarketFingerprint = (market, marketType) => {
    const bet = (market.bet || '').toString();
    const teamType = (market.teamType || '').toString();
    const line = (market.line || 'null').toString();
    const source = (market.source || 'pinnacle').toString();
    const bookmaker = (market.bookmaker || 'null').toString();

    return `${marketType}:${bet}:${teamType}:${line}:${source}:${bookmaker}`;
  };

  /**
   * Normalize price for comparison (handles N/A, null, undefined, formatting)
   * @param {string|number|null|undefined} price - Price value to normalize
   * @returns {string} Normalized price string for comparison
   */
  const normalizePrice = (price) => {
    if (price === null || price === undefined || price === 'N/A' || price === '') {
      return 'N/A';
    }

    // Convert to string and parse to ensure consistent decimal formatting
    const numPrice = typeof price === 'string' ? parseFloat(price) : price;

    if (isNaN(numPrice)) {
      return 'N/A';
    }

    // Format to 3 decimal places for consistent comparison
    return numPrice.toFixed(3);
  };

  /**
   * Format timestamp to "Xs ago" or "Xm ago" format
   * @param {number} timestamp - Timestamp in milliseconds
   * @returns {string} Formatted time string (e.g., "5s ago", "1m ago")
   */
  const formatTimeAgo = (timestamp) => {
    if (!timestamp || typeof timestamp !== 'number') {
      return '0s ago';
    }

    const now = Date.now();
    const diffMs = now - timestamp;

    if (diffMs < 0) {
      return '0s ago'; // Handle future timestamps gracefully
    }

    const diffSeconds = Math.floor(diffMs / 1000);

    if (diffSeconds < 60) {
      return `${diffSeconds}s ago`;
    }

    const diffMinutes = Math.floor(diffSeconds / 60);
    return `${diffMinutes}m ago`;
  };

  /**
   * Detect odds changes between old and new matches
   * @param {Array} oldMatches - Previous matches array
   * @param {Array} newMatches - New matches array from API
   * @returns {Object} Object with updatedMatches Map and updatedMarkets Map
   */
  const detectOddsChanges = (oldMatches, newMatches) => {
    const updatedMatches = new Map(); // Map<eventId, timestamp>
    const updatedMarkets = new Map(); // Map<eventId, Map<fingerprint, timestamp>>

    if (!oldMatches || oldMatches.length === 0) {
      // First load - no changes to detect
      return { updatedMatches, updatedMarkets };
    }

    if (!newMatches || newMatches.length === 0) {
      // All matches removed - mark all as updated
      oldMatches.forEach(match => {
        if (match.eventId) {
          updatedMatches.set(match.eventId, Date.now());
        }
      });
      return { updatedMatches, updatedMarkets };
    }

    const now = Date.now();

    // Build maps of old matches and markets by eventId
    const oldMatchesMap = new Map();
    const oldMarketsMap = new Map(); // Map<eventId, Map<fingerprint, market>>

    oldMatches.forEach(match => {
      if (!match.eventId) return;

      oldMatchesMap.set(match.eventId, match);

      // Build market map for this match
      const matchMarketsMap = new Map();
      if (match.markets) {
        Object.keys(match.markets).forEach(marketType => {
          const markets = match.markets[marketType];
          if (Array.isArray(markets)) {
            markets.forEach(market => {
              const fingerprint = generateMarketFingerprint(market, marketType);
              matchMarketsMap.set(fingerprint, {
                price: normalizePrice(market.price),
                line: (market.line || 'null').toString(),
                status: (market.status || 'Open').toString()
              });
            });
          }
        });
      }
      oldMarketsMap.set(match.eventId, matchMarketsMap);
    });

    // Process new matches
    newMatches.forEach(newMatch => {
      if (!newMatch.eventId) return;

      const oldMatch = oldMatchesMap.get(newMatch.eventId);
      const oldMarkets = oldMarketsMap.get(newMatch.eventId) || new Map();

      // Initialize market updates map for this match
      const matchMarketUpdates = new Map();
      let matchHasUpdates = false;

      // Check if match is new (not in old matches)
      if (!oldMatch) {
        updatedMatches.set(newMatch.eventId, now);
        matchHasUpdates = true;
      }

      // Process markets for this match
      if (newMatch.markets) {
        Object.keys(newMatch.markets).forEach(marketType => {
          const newMarkets = newMatch.markets[marketType];
          if (!Array.isArray(newMarkets)) return;

          newMarkets.forEach(newMarket => {
            const fingerprint = generateMarketFingerprint(newMarket, marketType);
            const oldMarket = oldMarkets.get(fingerprint);

            const newPrice = normalizePrice(newMarket.price);
            const newLine = (newMarket.line || 'null').toString();
            const newStatus = (newMarket.status || 'Open').toString();

            // Check if market is new or changed
            if (!oldMarket) {
              // Market fingerprint not found - could be new OR could be same market with different fingerprint
              // Try to find matching market by comparing key attributes (more lenient matching)
              let foundMatchingMarket = false;
              if (oldMatch?.markets?.[marketType]) {
                const matchingOldMarket = oldMatch.markets[marketType].find(om => {
                  const oldPriceNorm = normalizePrice(om.price);
                  const oldLineNorm = (om.line || 'null').toString();
                  const oldTeamTypeNorm = (om.teamType || '').toString();
                  const oldBetNorm = (om.bet || '').toString();
                  const newTeamTypeNorm = (newMarket.teamType || '').toString();
                  const newBetNorm = (newMarket.bet || '').toString();

                  // Match by: price, line, teamType, and bet name
                  return oldPriceNorm === newPrice &&
                    oldLineNorm === newLine &&
                    oldTeamTypeNorm === newTeamTypeNorm &&
                    oldBetNorm === newBetNorm;
                });

                if (matchingOldMarket) {
                  // Found matching market - compare prices to see if it actually changed
                  const matchingOldPrice = normalizePrice(matchingOldMarket.price);
                  if (matchingOldPrice !== newPrice) {
                    // Same market, different price - mark as updated
                    const matchingFingerprint = generateMarketFingerprint(matchingOldMarket, marketType);
                    matchMarketUpdates.set(matchingFingerprint, now);
                    matchHasUpdates = true;
                  }
                  // If prices are same, don't mark as updated (fingerprint just changed format, e.g., source/bookmaker)
                  foundMatchingMarket = true;
                }
              }

              if (!foundMatchingMarket) {
                // Truly new market - mark as updated
                matchMarketUpdates.set(fingerprint, now);
                matchHasUpdates = true;
              }
            } else {
              // Compare with old market - use strict comparison
              // Ensure both prices are normalized strings for accurate comparison
              const oldPriceNormalized = typeof oldMarket.price === 'string' ? oldMarket.price : normalizePrice(oldMarket.price);
              const newPriceNormalized = typeof newPrice === 'string' ? newPrice : normalizePrice(newPrice);

              const priceChanged = newPriceNormalized !== oldPriceNormalized;
              const lineChanged = newLine !== oldMarket.line;
              const statusChanged = newStatus !== oldMarket.status;

              // Debug logging for false positives (only in development)
              if (process.env.NODE_ENV === 'development' && (priceChanged || lineChanged || statusChanged)) {
                const oldMarketRaw = oldMatchesMap.get(newMatch.eventId)?.markets?.[marketType]?.find(
                  m => generateMarketFingerprint(m, marketType) === fingerprint
                );
                if (oldMarketRaw) {
                  const oldPriceRaw = oldMarketRaw.price;
                  const newPriceRaw = newMarket.price;
                  // Change detected
                }
              }

              // Only mark as updated if values actually changed
              if (priceChanged || lineChanged || statusChanged) {
                matchMarketUpdates.set(fingerprint, now);
                matchHasUpdates = true;
              }
            }
          });
        });
      }

      // Check for removed markets (in old but not in new)
      oldMarkets.forEach((oldMarket, fingerprint) => {
        if (!matchMarketUpdates.has(fingerprint)) {
          // Check if this market still exists in new match
          let marketExists = false;
          if (newMatch.markets) {
            Object.keys(newMatch.markets).forEach(marketType => {
              const newMarkets = newMatch.markets[marketType];
              if (Array.isArray(newMarkets)) {
                newMarkets.forEach(newMarket => {
                  if (generateMarketFingerprint(newMarket, marketType) === fingerprint) {
                    marketExists = true;
                  }
                });
              }
            });
          }

          if (!marketExists) {
            // Market was removed - mark match as updated
            matchHasUpdates = true;
          }
        }
      });

      // Store market updates for this match
      if (matchMarketUpdates.size > 0) {
        updatedMarkets.set(newMatch.eventId, matchMarketUpdates);
      }

      // Mark match as updated if any markets changed
      if (matchHasUpdates) {
        updatedMatches.set(newMatch.eventId, now);
      }
    });

    // Check for removed matches (in old but not in new)
    oldMatchesMap.forEach((oldMatch, eventId) => {
      const newMatchExists = newMatches.some(m => m.eventId === eventId);
      if (!newMatchExists) {
        // Match was removed - mark as updated
        updatedMatches.set(eventId, now);
      }
    });

    return { updatedMatches, updatedMarkets };
  };

  /**
   * Merge update timestamps into new matches, preserving timestamps for unchanged markets
   * @param {Array} oldMatches - Previous matches with timestamps
   * @param {Array} newMatches - New matches from API
   * @param {Object} changes - Result from detectOddsChanges()
   * @returns {Array} New matches with merged timestamps
   */
  const mergeUpdateTimestamps = (oldMatches, newMatches, changes) => {
    const { updatedMatches, updatedMarkets } = changes;

    // Build old matches map for quick lookup
    const oldMatchesMap = new Map();
    oldMatches.forEach(match => {
      if (match.eventId) {
        oldMatchesMap.set(match.eventId, match);
      }
    });

    // Process new matches
    return newMatches.map(newMatch => {
      if (!newMatch.eventId) return newMatch;

      const oldMatch = oldMatchesMap.get(newMatch.eventId);
      const matchUpdateTime = updatedMatches.get(newMatch.eventId);
      const marketUpdates = updatedMarkets.get(newMatch.eventId) || new Map();

      // Create enriched match
      // Only set lastUpdated if there was an actual change (matchUpdateTime exists)
      // If no changes detected, set to null to hide the badge
      const enrichedMatch = {
        ...newMatch,
        lastUpdated: matchUpdateTime || null  // Only show badge if there was an actual change
      };

      // Process markets
      if (enrichedMatch.markets) {
        const enrichedMarkets = {};

        Object.keys(enrichedMatch.markets).forEach(marketType => {
          const markets = enrichedMatch.markets[marketType];
          if (!Array.isArray(markets)) {
            enrichedMarkets[marketType] = markets;
            return;
          }

          enrichedMarkets[marketType] = markets.map(market => {
            const fingerprint = generateMarketFingerprint(market, marketType);
            const marketUpdateTime = marketUpdates.get(fingerprint);

            // Find old market to preserve timestamp if unchanged
            let oldMarketTimestamp = null;
            let oldMarketValue = null;
            if (oldMatch?.markets?.[marketType]) {
              const oldMarket = oldMatch.markets[marketType].find(
                om => generateMarketFingerprint(om, marketType) === fingerprint
              );
              if (oldMarket) {
                // Only preserve old timestamp if this market didn't change
                if (!marketUpdateTime) {
                  // Market didn't change - preserve old timestamp (or null if it was null)
                  oldMarketTimestamp = oldMarket.lastUpdated;
                }
                // If marketUpdateTime exists, this market changed - use the new timestamp
                oldMarketValue = oldMarket.price; // Store old value for comparison
              }
            }

            // CRITICAL: Only set lastUpdated if this SPECIFIC market changed
            // Don't inherit match-level timestamp - each market should only show its own update time
            // Also verify that the price actually changed before showing timestamp
            let finalTimestamp = null;
            if (marketUpdateTime) {
              // Market was marked as updated - verify price actually changed
              if (oldMarketValue !== null) {
                const normalizedOldPrice = normalizePrice(oldMarketValue);
                const normalizedNewPrice = normalizePrice(market.price);
                if (normalizedOldPrice !== normalizedNewPrice) {
                  // Price actually changed - show timestamp
                  finalTimestamp = marketUpdateTime;
                } else {
                  // Price didn't change - don't show timestamp (false positive)
                  finalTimestamp = null;
                }
              } else {
                // No old value to compare (truly new market) - show timestamp
                finalTimestamp = marketUpdateTime;
              }
            } else {
              // Market didn't change - preserve old timestamp (or null)
              finalTimestamp = oldMarketTimestamp;
            }

            const enrichedMarket = {
              ...market,
              lastUpdated: finalTimestamp
            };

            return enrichedMarket;
          });
        });

        enrichedMatch.markets = enrichedMarkets;
      }

      return enrichedMatch;
    });
  };

  // Major leagues mapping: Pinnacle sportId -> The-Odds-API sportKey
  // Only fetch The-Odds-API for these major leagues to optimize costs
  const MAJOR_LEAGUES_MAPPING = {
    1: { // Soccer
      'soccer_epl': 'English Premier League',
      'soccer_spain_la_liga': 'La Liga',
      'soccer_italy_serie_a': 'Serie A',
      'soccer_germany_bundesliga': 'Bundesliga',
      'soccer_france_ligue_one': 'Ligue 1',
      'soccer_uefa_champions_league': 'Champions League',
      'soccer_uefa_europa_league': 'Europa League',
      'soccer_usa_mls': 'MLS',
      'soccer_mexico_ligamx': 'Liga MX'
    },
    3: { // Basketball
      'basketball_nba': 'NBA',
      'basketball_ncaab': 'NCAA Basketball',
      'basketball_euroleague': 'EuroLeague'
    },
    7: { // American Football
      'americanfootball_nfl': 'NFL',
      'americanfootball_ncaaf': 'NCAA Football'
    },
    9: { // Baseball
      'baseball_mlb': 'MLB'
    },
    4: { // Hockey
      'icehockey_nhl': 'NHL'
    }
  };

  // Transform The-Odds-API event data to our internal format (client-side)
  const transformTheOddsApiToPinnacleFormat = (oddsApiEvent, sportId) => {
    const markets = {
      moneyLine: [],
      spreads: [],
      totals: [],
      teamTotals: [],
      playerProps: [],
      teamProps: [],
      gameProps: [],
      futures: []
    };

    if (!oddsApiEvent.bookmakers || !Array.isArray(oddsApiEvent.bookmakers)) {
      return markets;
    }

    // Process all bookmakers and aggregate markets
    oddsApiEvent.bookmakers.forEach(bookmaker => {
      if (!bookmaker.markets || !Array.isArray(bookmaker.markets)) return;

      bookmaker.markets.forEach(market => {
        const marketKey = market.key;
        const outcomes = market.outcomes || [];

        // Money Line (h2h)
        if (marketKey === 'h2h') {
          outcomes.forEach(outcome => {
            markets.moneyLine.push({
              bet: outcome.name,
              teamType: outcome.name === oddsApiEvent.home_team ? 'Home' :
                outcome.name === oddsApiEvent.away_team ? 'Away' : 'Draw',
              price: outcome.price ? parseFloat(outcome.price).toFixed(3) : 'N/A',
              line: null,
              status: 'Open',
              period: 'Full Time',
              source: 'the-odds-api',
              bookmaker: bookmaker.title
            });
          });
        }

        // Spreads
        if (marketKey === 'spreads') {
          outcomes.forEach(outcome => {
            markets.spreads.push({
              bet: outcome.name,
              teamType: outcome.name === oddsApiEvent.home_team ? 'Home' : 'Away',
              price: outcome.price ? parseFloat(outcome.price).toFixed(3) : 'N/A',
              line: outcome.point ? outcome.point.toString() : null,
              status: 'Open',
              period: 'Full Time',
              source: 'the-odds-api',
              bookmaker: bookmaker.title
            });
          });
        }

        // Totals
        if (marketKey === 'totals') {
          outcomes.forEach(outcome => {
            markets.totals.push({
              bet: outcome.name,
              teamType: outcome.name,
              price: outcome.price ? parseFloat(outcome.price).toFixed(3) : 'N/A',
              line: outcome.point ? outcome.point.toString() : null,
              status: 'Open',
              period: 'Full Time',
              source: 'the-odds-api',
              bookmaker: bookmaker.title
            });
          });
        }

        // Player Props (NBA and other sports)
        if (marketKey.startsWith('player_')) {
          const propType = marketKey.replace('player_', '');
          outcomes.forEach(outcome => {
            markets.playerProps.push({
              bet: `${outcome.name} ${propType}`,
              teamType: outcome.description || outcome.name,
              price: outcome.price ? parseFloat(outcome.price).toFixed(3) : 'N/A',
              line: outcome.point ? outcome.point.toString() : null,
              status: 'Open',
              period: 'Full Time',
              source: 'the-odds-api',
              bookmaker: bookmaker.title,
              propType: propType,
              playerName: outcome.description || outcome.name
            });
          });
        }

        // Team Props
        if (marketKey === 'btts' || marketKey === 'both_teams_to_score') {
          outcomes.forEach(outcome => {
            markets.teamProps.push({
              bet: `BTTS - ${outcome.name}`,
              teamType: 'BTTS',
              price: outcome.price ? parseFloat(outcome.price).toFixed(3) : 'N/A',
              line: null,
              status: 'Open',
              period: 'Full Time',
              source: 'the-odds-api',
              bookmaker: bookmaker.title,
              teamPropType: 'BTTS'
            });
          });
        }

        if (marketKey === 'double_chance') {
          outcomes.forEach(outcome => {
            markets.teamProps.push({
              bet: `Double Chance - ${outcome.name}`,
              teamType: 'Double Chance',
              price: outcome.price ? parseFloat(outcome.price).toFixed(3) : 'N/A',
              line: null,
              status: 'Open',
              period: 'Full Time',
              source: 'the-odds-api',
              bookmaker: bookmaker.title,
              teamPropType: 'Double Chance'
            });
          });
        }

        if (marketKey === 'draw_no_bet') {
          outcomes.forEach(outcome => {
            markets.teamProps.push({
              bet: `Draw No Bet - ${outcome.name}`,
              teamType: outcome.name === oddsApiEvent.home_team ? 'Home' : 'Away',
              price: outcome.price ? parseFloat(outcome.price).toFixed(3) : 'N/A',
              line: null,
              status: 'Open',
              period: 'Full Time',
              source: 'the-odds-api',
              bookmaker: bookmaker.title,
              teamPropType: 'Draw No Bet'
            });
          });
        }
      });
    });

    return markets;
  };

  // Fetch The-Odds-API data for all events (not just major leagues)
  const fetchTheOddsApiData = async (sportId, pinnacleEvents) => {
    if (pinnacleEvents.length === 0) {
      return {};
    }
    const theOddsApiMarkets = {};

    // Extract unique league IDs from Pinnacle events
    const leagueIds = [...new Set(pinnacleEvents.map(e => e.league_id).filter(Boolean))];

    // Try to get The-Odds-API sport keys for these leagues from database
    let relevantSportKeys = [];
    if (leagueIds.length > 0) {
      try {
        // Format leagueIds as array query params
        const leagueParams = leagueIds.map(id => `leagueId=${id}`).join('&');
        const leaguesResponse = await fetch(`/api/reference/leagues?${leagueParams}&sportId=${sportId}`).catch(() => null);
        if (leaguesResponse?.ok) {
          const leagues = await leaguesResponse.json();
          relevantSportKeys = leagues
            .map(l => l.oddsApiKey)
            .filter(Boolean)
            .filter((key, index, self) => self.indexOf(key) === index); // Unique

          // Debug: Log if we found league-specific mappings
          if (process.env.NODE_ENV === 'development' && relevantSportKeys.length > 0) {
            console.log(`[The-Odds-API] Found ${relevantSportKeys.length} league-specific sport keys for ${leagueIds.length} leagues`);
          }
        }
      } catch (err) {
        // Fallback to trying major leagues if database lookup fails
      }
    }

    // Fallback: Map Pinnacle sport IDs to The-Odds-API sport keys
    // Only use this if we don't have league-specific mappings
    const sportKeyMap = {
      1: [
        // Major leagues
        'soccer_epl', 'soccer_spain_la_liga', 'soccer_italy_serie_a', 'soccer_germany_bundesliga',
        'soccer_france_ligue_one', 'soccer_uefa_champions_league', 'soccer_uefa_europa_league',
        'soccer_usa_mls', 'soccer_mexico_ligamx', 'soccer_brazil_campeonato',
        'soccer_england_league1', 'soccer_england_league2', 'soccer_argentina_liga_profesional',
        'soccer_portugal_primeira_liga', 'soccer_netherlands_eredivisie', 'soccer_belgium_first_div',
        'soccer_turkey_super_league', 'soccer_russia_premier_league', 'soccer_japan_j_league',
        'soccer_australia_aleague', 'soccer_china_superleague', 'soccer_korea_kleague1',
        // Smaller European leagues
        'soccer_denmark_superliga', 'soccer_norway_eliteserien', 'soccer_sweden_allsvenskan',
        'soccer_poland_ekstraklasa', 'soccer_czech_republic_first_league', 'soccer_romania_liga_1',
        'soccer_greece_super_league', 'soccer_scotland_premiership', 'soccer_austria_bundesliga',
        'soccer_switzerland_super_league', 'soccer_ukraine_premier_league',
        // Additional leagues that might have coverage
        'soccer_algeria_ligue_1', 'soccer_egypt_premier_league', 'soccer_uae_pro_league',
        // Try general soccer endpoint as fallback (if available)
        'soccer'
      ],
      3: ['basketball_nba', 'basketball_ncaab', 'basketball_euroleague', 'basketball_wnba'],
      4: ['icehockey_nhl', 'icehockey_sweden_hockey_league', 'icehockey_sweden_allsvenskan', 'icehockey_finland_liiga', 'icehockey_switzerland_nla', 'icehockey_czech_extraliga', 'icehockey_germany_del', 'icehockey_russia_khl'],
      7: ['americanfootball_nfl', 'americanfootball_ncaaf'],
      9: ['baseball_mlb', 'baseball_npb', 'baseball_kbo'],
      2: ['tennis_atp', 'tennis_wta'],
    };

    // Use league-specific sport keys if available, otherwise fall back to major leagues only
    let sportKeys = [];
    if (relevantSportKeys.length > 0) {
      // Use database-mapped sport keys
      sportKeys = relevantSportKeys;
    } else {
      // Fallback: Only try major leagues (not all leagues) to avoid 404s
      // These are leagues that are most likely to exist in The-Odds-API
      // Note: 404s are expected when no matches are scheduled, but the sport keys are valid
      const majorLeaguesOnly = {
        1: [
          // Major soccer leagues (verified to exist in The-Odds-API)
          'soccer_epl', 'soccer_spain_la_liga', 'soccer_italy_serie_a', 'soccer_germany_bundesliga',
          'soccer_france_ligue_one', 'soccer_uefa_champions_league', 'soccer_uefa_europa_league',
          'soccer_usa_mls', 'soccer_mexico_ligamx', 'soccer_brazil_campeonato',
          'soccer_portugal_primeira_liga', 'soccer_netherlands_eredivisie'
        ],
        3: ['basketball_nba', 'basketball_ncaab', 'basketball_euroleague'],
        4: ['icehockey_nhl'],
        7: ['americanfootball_nfl', 'americanfootball_ncaaf'],
        9: ['baseball_mlb'],
        2: ['tennis_atp', 'tennis_wta'],
      };

      sportKeys = majorLeaguesOnly[sportId] || [];

      // Final fallback to MAJOR_LEAGUES_MAPPING
      if (sportKeys.length === 0) {
        const majorLeagues = MAJOR_LEAGUES_MAPPING[sportId];
        if (majorLeagues) {
          sportKeys = Object.keys(majorLeagues);
        }
      }
    }

    try {
      // Try each league/sport key for this sport
      // Only try sport keys that match leagues we have matches for

      for (const sportKey of sportKeys) {
        try {
          // Fetch events from external odds provider
          const eventsResponse = await fetch(`/api/the-odds-api/sports/${sportKey}/events`, {
            headers: { 'X-API-Key': API_KEY }
          }).catch(err => {
            return { ok: false, status: 0 };
          });

          // Silently skip 404s (no events scheduled OR league doesn't exist in The-Odds-API)
          if (!eventsResponse.ok) {
            if (eventsResponse.status === 404) {
              continue; // Silently skip - either no matches scheduled or league doesn't exist
            }
            continue; // Try next league
          }

          const oddsApiEvents = await eventsResponse.json();
          if (!Array.isArray(oddsApiEvents) || oddsApiEvents.length === 0) {
            continue; // Try next league
          }

          // Match Pinnacle events with The-Odds-API events
          let matchedCount = 0;
          for (const pinnacleEvent of pinnacleEvents) {
            // Skip if we already have data for this event
            if (theOddsApiMarkets[pinnacleEvent.event_id]) continue;

            const matchingOddsApiEvent = oddsApiEvents.find(oddsEvent => {
              // Enhanced fuzzy matching with date verification
              const pinnacleHome = (pinnacleEvent.home || '').toLowerCase().replace(/[^a-z0-9]/g, '');
              const pinnacleAway = (pinnacleEvent.away || '').toLowerCase().replace(/[^a-z0-9]/g, '');
              const oddsHome = (oddsEvent.home_team || '').toLowerCase().replace(/[^a-z0-9]/g, '');
              const oddsAway = (oddsEvent.away_team || '').toLowerCase().replace(/[^a-z0-9]/g, '');

              // Check team name matching (more lenient)
              const homeMatch = pinnacleHome.includes(oddsHome) || oddsHome.includes(pinnacleHome) ||
                (pinnacleHome.length >= 3 && oddsHome.length >= 3 && pinnacleHome.substring(0, 3) === oddsHome.substring(0, 3)) ||
                (pinnacleHome.length >= 5 && oddsHome.length >= 5 && pinnacleHome.substring(0, 5) === oddsHome.substring(0, 5));
              const awayMatch = pinnacleAway.includes(oddsAway) || oddsAway.includes(pinnacleAway) ||
                (pinnacleAway.length >= 3 && oddsAway.length >= 3 && pinnacleAway.substring(0, 3) === oddsAway.substring(0, 3)) ||
                (pinnacleAway.length >= 5 && oddsAway.length >= 5 && pinnacleAway.substring(0, 5) === oddsAway.substring(0, 5));

              if (!homeMatch || !awayMatch) return false;

              // Date verification (within 4 hours for better matching)
              if (pinnacleEvent.starts && oddsEvent.commence_time) {
                const pinnacleDate = new Date(pinnacleEvent.starts);
                const oddsDate = new Date(oddsEvent.commence_time);
                const dateDiff = Math.abs(pinnacleDate.getTime() - oddsDate.getTime());
                const fourHours = 4 * 60 * 60 * 1000;
                if (dateDiff > fourHours) return false;
              }

              return true;
            });

            if (matchingOddsApiEvent) {
              // Fetch odds for this specific event
              try {
                const oddsResponse = await fetch(
                  `/api/the-odds-api/sports/${sportKey}/events/${matchingOddsApiEvent.id}/odds?regions=us&markets=h2h,spreads,totals,player_points,player_assists,player_rebounds,btts,double_chance,draw_no_bet&oddsFormat=decimal`,
                  { headers: { 'X-API-Key': API_KEY } }
                ).catch(err => {
                  return { ok: false, status: 0 };
                });

                if (oddsResponse.ok) {
                  const oddsData = await oddsResponse.json();
                  theOddsApiMarkets[pinnacleEvent.event_id] = oddsData;
                  matchedCount++;
                }
              } catch (err) {
                error(`Error fetching odds for event ${matchingOddsApiEvent.id}:`, err);
              }
            }
          }

          // Stop early if we've matched all Pinnacle events
          const unmatchedCount = pinnacleEvents.filter(e => !theOddsApiMarkets[e.event_id]).length;
          if (unmatchedCount === 0) {
            break; // All events matched, no need to check more sport keys
          }
        } catch (err) {
          // Silently skip errors for individual sport keys
          continue; // Try next league
        }
      }
    } catch (err) {
      error('Error fetching external odds data:', err);
    }

    return theOddsApiMarkets;
  };

  // Handle login
  const handleLogin = (e) => {
    e.preventDefault();
    if (password === DASHBOARD_PASSWORD) {
      setIsAuthenticated(true);
      localStorage.setItem('isAuthenticated', 'true');
    } else {
      alert('Invalid password');
    }
  };

  // Check authentication on mount
  useEffect(() => {
    const auth = localStorage.getItem('isAuthenticated');
    if (auth === 'true') {
      setIsAuthenticated(true);
    }
  }, []);

  // Transform API events to match objects
  const transformApiEventsToMatches = (events, eventType, sportId) => {
    if (!events || !Array.isArray(events)) return [];

    const matches = [];
    let skippedNoPeriods = 0;
    let skippedCorners = 0;

    events.forEach(event => {
      // Allow events without periods - we'll try to get odds from The-Odds-API
      // For live matches, allow events without periods (they might not have market data yet)
      // For prematch matches, we'll still show them and try to get odds from The-Odds-API
      const isLiveMatch = (event.live_status_id === 1) || (eventType === 'live');

      // If no periods, create empty periods object - we'll still show the match
      if (!event.periods) {
        event.periods = {};
      }

      // Filter out "Corners" matches - these are special market events, not real matches
      const isCornerMatch =
        (event.league_name && event.league_name.toLowerCase().includes('corners')) ||
        (event.home && event.home.toLowerCase().includes('corners')) ||
        (event.away && event.away.toLowerCase().includes('corners'));

      if (isCornerMatch) {
        skippedCorners++;
        return;
      }

      // Determine if match is finished using multiple checks (for all sports)
      // live_status_id values: 1 = live, 2 = pre-match, 3 = finished/settled, 4 = cancelled/postponed
      const liveStatusId = event.live_status_id || 2;
      let isMatchFinished = false;

      // PRIMARY CHECK: Pinnacle's live_status_id (most reliable)
      if (liveStatusId === 3) {
        // Status 3 = Finished/Settled
        isMatchFinished = true;
      }

      // SECONDARY CHECK: For basketball - check if full game result is settled
      if (!isMatchFinished && sportId === 3 && event.period_results && event.period_results.length > 0) {
        const fullGameResult = event.period_results.find(pr => pr.number === 0 && pr.status === 1);
        if (fullGameResult) {
          isMatchFinished = true;
        }
      }

      // FALLBACK CHECK: For all sports - check if all periods are closed and final score exists
      if (!isMatchFinished && event.periods) {
        const allPeriods = Object.values(event.periods);
        const hasActivePeriod = allPeriods.some(p => p.period_status === 1);

        // If no active periods and we have a final score, match is finished
        if (!hasActivePeriod && event.periods.num_0) {
          const fullGamePeriod = event.periods.num_0;
          if (fullGamePeriod?.meta) {
            const homeScore = fullGamePeriod.meta.home_score;
            const awayScore = fullGamePeriod.meta.away_score;
            const homeScoreNum = typeof homeScore === 'string' ? parseInt(homeScore, 10) : homeScore;
            const awayScoreNum = typeof awayScore === 'string' ? parseInt(awayScore, 10) : awayScore;

            if (homeScoreNum !== null && homeScoreNum !== undefined && !isNaN(homeScoreNum) &&
              awayScoreNum !== null && awayScoreNum !== undefined && !isNaN(awayScoreNum)) {
              // Has final score and no active periods = finished
              isMatchFinished = true;
            }
          }
        }
      }

      // Extract match metadata
      const matchData = {
        eventId: event.event_id || 'unknown',
        homeTeam: event.home || 'Unknown Team',
        awayTeam: event.away || 'Unknown Team',
        league: event.league_name || 'Unknown League',
        leagueId: event.league_id || null, // Store league_id for filtering
        sportId: sportId,
        // Update eventType: if match is finished, mark as 'finished', otherwise use API value
        eventType: isMatchFinished ? 'finished' : (event.event_type || eventType || 'prematch'),
        startTime: event.starts ? (typeof event.starts === 'string' ? new Date(event.starts).toLocaleString() : new Date(event.starts * 1000).toLocaleString()) : 'TBD',
        liveScore: '-',
        matchStatus: liveStatusId,
        periodDescription: null,
        hasOpenMarkets: event.is_have_open_markets || false,
        markets: {
          moneyLine: [],
          spreads: [],
          totals: [],
          teamTotals: [],
          playerProps: [],
          futures: [],
          teamProps: [],
          gameProps: []
        }
      };

      // Extract live score and period description for live/finished events
      if ((matchData.eventType === 'live' || matchData.eventType === 'finished') && event.periods) {
        // Find the active period (period_status === 1)
        const activePeriod = Object.values(event.periods).find(p => p.period_status === 1);
        const firstPeriod = Object.values(event.periods)[0];

        // For basketball (sport_id: 3), scores are in period_results
        if (sportId === 3 && event.period_results && event.period_results.length > 0) {
          const settledResults = event.period_results.filter(pr => pr.status === 1);
          if (settledResults.length > 0) {
            settledResults.sort((a, b) => {
              if (a.settled_at && b.settled_at) {
                return new Date(b.settled_at) - new Date(a.settled_at);
              }
              return b.number - a.number;
            });

            const latestResult = settledResults[0];
            const fullGameResult = settledResults.find(pr => pr.number === 0);
            const scoreToUse = fullGameResult && fullGameResult.status === 1 ? fullGameResult : latestResult;

            if (scoreToUse.team_1_score !== undefined && scoreToUse.team_2_score !== undefined) {
              matchData.liveScore = `${scoreToUse.team_1_score} - ${scoreToUse.team_2_score}`;

              const periodDescriptions = {
                0: 'Game',
                1: '1st Half',
                2: '2nd Half',
                3: '1st Quarter',
                4: '2nd Quarter',
                5: '3rd Quarter',
                6: '4th Quarter'
              };

              // If full game result (number: 0) is settled, match is finished
              if (scoreToUse.number === 0 && scoreToUse.status === 1) {
                matchData.eventType = 'finished';
                matchData.periodDescription = 'Final';
              } else if (isMatchFinished) {
                // Match is finished (from live_status_id check)
                matchData.eventType = 'finished';
                matchData.periodDescription = 'Final';
              } else {
                matchData.periodDescription = periodDescriptions[scoreToUse.number] || 'Match';
              }
            }
          }
        } else {
          // Other sports - scores in periods.meta
          let periodWithScores = null;
          if (activePeriod?.meta &&
            activePeriod.meta.home_score !== undefined &&
            activePeriod.meta.away_score !== undefined) {
            periodWithScores = activePeriod;
          } else {
            // Check all periods for scores (prioritize num_0 for full game score)
            const periodsArray = Object.entries(event.periods);
            periodsArray.sort(([keyA], [keyB]) => {
              if (keyA === 'num_0') return -1;
              if (keyB === 'num_0') return 1;
              return 0;
            });

            for (const [, period] of periodsArray) {
              if (period?.meta) {
                const homeScore = period.meta.home_score;
                const awayScore = period.meta.away_score;
                const homeScoreNum = typeof homeScore === 'string' ? parseInt(homeScore, 10) : homeScore;
                const awayScoreNum = typeof awayScore === 'string' ? parseInt(awayScore, 10) : awayScore;

                if (homeScoreNum !== null && homeScoreNum !== undefined && !isNaN(homeScoreNum) &&
                  awayScoreNum !== null && awayScoreNum !== undefined && !isNaN(awayScoreNum)) {
                  periodWithScores = period;
                  break;
                }
              }
            }
          }

          if (periodWithScores) {
            matchData.periodDescription = periodWithScores.description || 'Match';
            const homeScore = periodWithScores.meta.home_score;
            const awayScore = periodWithScores.meta.away_score;
            const homeScoreNum = typeof homeScore === 'string' ? parseInt(homeScore, 10) : homeScore;
            const awayScoreNum = typeof awayScore === 'string' ? parseInt(awayScore, 10) : awayScore;

            if (homeScoreNum !== null && homeScoreNum !== undefined && !isNaN(homeScoreNum) &&
              awayScoreNum !== null && awayScoreNum !== undefined && !isNaN(awayScoreNum)) {
              matchData.liveScore = `${homeScoreNum} - ${awayScoreNum}`;

              // Check if this is the full game period (num_0) and it's closed
              const isFullGamePeriod = periodWithScores === event.periods.num_0;
              const isPeriodClosed = periodWithScores.period_status !== 1;

              // If full game period is closed, match is finished
              if (isFullGamePeriod && isPeriodClosed) {
                matchData.eventType = 'finished';
                matchData.periodDescription = 'Final';
              } else if (isMatchFinished) {
                // Match is finished (from live_status_id check)
                matchData.eventType = 'finished';
                matchData.periodDescription = 'Final';
              }
            }
          } else if (firstPeriod) {
            matchData.periodDescription = firstPeriod.description || 'Match';

            // If first period is closed and it's the full game period, match might be finished
            if (firstPeriod === event.periods.num_0 && firstPeriod.period_status !== 1) {
              matchData.eventType = 'finished';
              matchData.periodDescription = 'Final';
            } else if (isMatchFinished) {
              // Match is finished (from live_status_id check)
              matchData.eventType = 'finished';
              matchData.periodDescription = 'Final';
            }
          } else if (isMatchFinished) {
            // Match is finished (from live_status_id check) but no period data
            matchData.eventType = 'finished';
            matchData.periodDescription = 'Final';
          }
        }
      }

      // Extract markets from periods (if periods exist)
      // Don't check hasPeriods - just try to extract, and if periods don't exist, extraction will naturally skip
      // We'll still try The-Odds-API and special markets later regardless
      if (event.periods && Object.keys(event.periods).length > 0) {
        Object.entries(event.periods).forEach(([periodKey, period]) => {
          const isOpen = period.period_status === 1;
          const oddsStatus = isOpen ? 'Open' : 'Closed';
          const periodDesc = period.description || 'Full Time';

          // Money Line
          if (period.money_line) {
            if (period.money_line.home) {
              matchData.markets.moneyLine.push({
                bet: matchData.homeTeam,
                teamType: 'Home',
                price: period.money_line.home.toFixed(3),
                line: '',
                status: oddsStatus,
                period: periodDesc
              });
            }
            if (period.money_line.away) {
              matchData.markets.moneyLine.push({
                bet: matchData.awayTeam,
                teamType: 'Away',
                price: period.money_line.away.toFixed(3),
                line: '',
                status: oddsStatus,
                period: periodDesc
              });
            }
            if (period.money_line.draw) {
              matchData.markets.moneyLine.push({
                bet: 'Draw',
                teamType: 'Draw',
                price: period.money_line.draw.toFixed(3),
                line: '',
                status: oddsStatus,
                period: periodDesc
              });
            }
          }

          // Spreads
          if (period.spreads) {
            Object.entries(period.spreads).forEach(([line, spread]) => {
              if (spread.home) {
                matchData.markets.spreads.push({
                  bet: matchData.homeTeam,
                  teamType: 'Home',
                  price: spread.home.toFixed(3),
                  line: line,
                  status: oddsStatus,
                  period: periodDesc
                });
              }
              if (spread.away) {
                matchData.markets.spreads.push({
                  bet: matchData.awayTeam,
                  teamType: 'Away',
                  price: spread.away.toFixed(3),
                  line: line,
                  status: oddsStatus,
                  period: periodDesc
                });
              }
            });
          }

          // Totals (Over/Under)
          if (period.totals) {
            Object.entries(period.totals).forEach(([line, total]) => {
              if (total.over) {
                matchData.markets.totals.push({
                  bet: 'Over',
                  teamType: 'Over',
                  price: total.over.toFixed(3),
                  line: line,
                  status: oddsStatus,
                  period: periodDesc
                });
              }
              if (total.under) {
                matchData.markets.totals.push({
                  bet: 'Under',
                  teamType: 'Under',
                  price: total.under.toFixed(3),
                  line: line,
                  status: oddsStatus,
                  period: periodDesc
                });
              }
            });
          }

          // Team Totals
          if (period.team_total) {
            if (period.team_total.home) {
              Object.entries(period.team_total.home).forEach(([line, odds]) => {
                if (odds.over) {
                  matchData.markets.teamTotals.push({
                    bet: `${matchData.homeTeam} Over`,
                    teamType: 'Home',
                    price: odds.over.toFixed(3),
                    line: line,
                    status: oddsStatus,
                    period: periodDesc
                  });
                }
                if (odds.under) {
                  matchData.markets.teamTotals.push({
                    bet: `${matchData.homeTeam} Under`,
                    teamType: 'Home',
                    price: odds.under.toFixed(3),
                    line: line,
                    status: oddsStatus,
                    period: periodDesc
                  });
                }
              });
            }
            if (period.team_total.away) {
              Object.entries(period.team_total.away).forEach(([line, odds]) => {
                if (odds.over) {
                  matchData.markets.teamTotals.push({
                    bet: `${matchData.awayTeam} Over`,
                    teamType: 'Away',
                    price: odds.over.toFixed(3),
                    line: line,
                    status: oddsStatus,
                    period: periodDesc
                  });
                }
                if (odds.under) {
                  matchData.markets.teamTotals.push({
                    bet: `${matchData.awayTeam} Under`,
                    teamType: 'Away',
                    price: odds.under.toFixed(3),
                    line: line,
                    status: oddsStatus,
                    period: periodDesc
                  });
                }
              });
            }
          }
        });
      } else {
        // No periods - will try The-Odds-API and special markets
        // No verbose logging
      }

      // Count total markets extracted from periods
      const marketsExtracted = Object.values(matchData.markets).reduce((sum, arr) => sum + (Array.isArray(arr) ? arr.length : 0), 0);
      if (marketsExtracted === 0 && event.periods && Object.keys(event.periods).length > 0) {
        // No verbose logging
      } else if (marketsExtracted > 0) {
        const marketBreakdown = Object.entries(matchData.markets)
          .filter(([_, arr]) => Array.isArray(arr) && arr.length > 0)
          .map(([type, arr]) => `${type}:${arr.length}`)
          .join(', ');
        // No verbose logging
      }

      matches.push(matchData);
    });

    // Debug logging for transformation
    if (events.length > 0) {
      const totalMarkets = matches.reduce((sum, m) => sum + Object.values(m.markets).reduce((s, arr) => s + (Array.isArray(arr) ? arr.length : 0), 0), 0);
      // No verbose logging
    }

    return matches;
  };

  // Helper function to count Pinnacle markets (from periods + special markets)
  // Excludes The-Odds-API markets by checking for 'source' field
  const countPinnacleMarkets = (match) => {
    let count = 0;
    Object.values(match.markets).forEach(marketArray => {
      if (Array.isArray(marketArray)) {
        marketArray.forEach(market => {
          // Pinnacle markets have 'period' field and don't have 'source: the-odds-api'
          // The-Odds-API markets have 'source: the-odds-api' field
          // Special markets have period === 'Special' and no source field
          // Also count markets without period field if they don't have source (legacy support)
          const hasPeriod = market.period && market.period !== '' && market.period !== undefined;
          const isNotOddsApi = !market.source || market.source !== 'the-odds-api';

          if (hasPeriod && isNotOddsApi) {
            count++;
          } else if (!hasPeriod && isNotOddsApi && !market.source) {
            // Count markets without period if they're not from The-Odds-API (likely special markets)
            count++;
          }
        });
      }
    });
    return count;
  };

  // Merge The-Odds-API markets into matches (SUPPLEMENTAL ONLY - requires Pinnacle markets)
  const mergeTheOddsApiMarkets = (matches, theOddsApiMarkets) => {
    if (!theOddsApiMarkets || Object.keys(theOddsApiMarkets).length === 0) {
      return matches;
    }

    let mergedCount = 0;
    let skippedNoPinnacle = 0;
    const mergedMatches = matches.map(match => {
      // Count Pinnacle markets before merging The-Odds-API
      const pinnacleMarketsCount = countPinnacleMarkets(match);

      // Only merge The-Odds-API if match already has Pinnacle markets
      if (pinnacleMarketsCount === 0) {
        skippedNoPinnacle++;
        return match;
      }

      const oddsApiEventData = theOddsApiMarkets[match.eventId];
      if (!oddsApiEventData) {
        return match;
      }

      try {
        // Transform The-Odds-API data to our format
        const oddsApiMarkets = transformTheOddsApiToPinnacleFormat(oddsApiEventData, match.sportId);

        const beforeCount = Object.values(match.markets).reduce((sum, arr) => sum + (Array.isArray(arr) ? arr.length : 0), 0);

        // Merge Player Props (The-Odds-API has better coverage) - SUPPLEMENTAL ONLY
        if (oddsApiMarkets.playerProps.length > 0) {
          match.markets.playerProps = [
            ...match.markets.playerProps,
            ...oddsApiMarkets.playerProps
          ];
          match.hasTheOddsApiData = true;
        }

        // Merge Team Props (BTTS, Double Chance, Draw No Bet) - SUPPLEMENTAL ONLY
        if (oddsApiMarkets.teamProps.length > 0) {
          match.markets.teamProps = [
            ...match.markets.teamProps,
            ...oddsApiMarkets.teamProps
          ];
          match.hasTheOddsApiData = true;
        }

        // Merge Money Line - SUPPLEMENTAL ONLY (only if Pinnacle already has markets)
        if (oddsApiMarkets.moneyLine.length > 0 && match.markets.moneyLine.length > 0) {
          // Only merge for comparison if Pinnacle already has money line
          match.markets.moneyLine = [
            ...match.markets.moneyLine,
            ...oddsApiMarkets.moneyLine
          ];
          match.hasTheOddsApiData = true;
        }

        // Merge Spreads - SUPPLEMENTAL ONLY (only if Pinnacle already has markets)
        if (oddsApiMarkets.spreads.length > 0 && match.markets.spreads.length > 0) {
          // Only merge for comparison if Pinnacle already has spreads
          match.markets.spreads = [
            ...match.markets.spreads,
            ...oddsApiMarkets.spreads
          ];
          match.hasTheOddsApiData = true;
        }

        // Merge Totals - SUPPLEMENTAL ONLY (only if Pinnacle already has markets)
        if (oddsApiMarkets.totals.length > 0 && match.markets.totals.length > 0) {
          // Only merge for comparison if Pinnacle already has totals
          match.markets.totals = [
            ...match.markets.totals,
            ...oddsApiMarkets.totals
          ];
          match.hasTheOddsApiData = true;
        }

        // Merge Team Totals if available - SUPPLEMENTAL ONLY
        if (oddsApiMarkets.teamTotals.length > 0) {
          match.markets.teamTotals = [
            ...match.markets.teamTotals,
            ...oddsApiMarkets.teamTotals
          ];
          match.hasTheOddsApiData = true;
        }

        // Note: The-Odds-API markets are SUPPLEMENTAL ONLY
        // We only merge them if match already has Pinnacle markets

        const afterCount = Object.values(match.markets).reduce((sum, arr) => sum + (Array.isArray(arr) ? arr.length : 0), 0);
        if (afterCount > beforeCount) {
          mergedCount++;
          // No verbose logging
        }
      } catch (err) {
        error(`Error merging external odds markets for event ${match.eventId}:`, err);
      }

      return match;
    });

    // No verbose logging
    return mergedMatches;
  };

  // Map Pinnacle sport IDs to API-Sports endpoints
  const SPORT_TO_API_ENDPOINT = {
    1: 'football',      // Soccer
    2: 'tennis',        // Tennis
    3: 'basketball',    // Basketball
    4: 'hockey',        // Hockey
    5: 'volleyball',    // Volleyball
    6: 'handball',      // Handball
    7: 'americanfootball', // American Football (NFL)
    8: 'mma',           // MMA
    9: 'baseball',      // Baseball
    // Note: Cricket (11) and E-Sports (10) may not have API-Sports coverage
  };

  // Enrich matches with API-Sports data (for all supported sports)
  // Uses database mappings via match-service API endpoint when available (soccer only)
  const enrichWithApiFootballData = async (matches, useCache = true) => {
    try {
      // Filter matches by supported sports and exclude "Corners" matches
      const supportedMatches = matches.filter(m => {
        const hasApiSupport = SPORT_TO_API_ENDPOINT[m.sportId];
        const isNotCorners = !m.league?.toLowerCase().includes('corners') &&
          !m.homeTeam?.toLowerCase().includes('corners') &&
          !m.awayTeam?.toLowerCase().includes('corners');
        return hasApiSupport && isNotCorners;
      });

      if (supportedMatches.length === 0) return matches;

      // For soccer matches, try to use match-service API endpoint first (uses database mappings)
      const soccerMatches = supportedMatches.filter(m => m.sportId === 1);
      if (soccerMatches.length > 0) {
        try {
          // Convert Pinnacle league IDs to database league IDs
          const pinnacleLeagueIds = [...new Set(soccerMatches.map(m => m.leagueId).filter(Boolean))];
          const leagueIdMap = new Map(); // Map<PinnacleLeagueId, DatabaseLeagueId>

          if (pinnacleLeagueIds.length > 0) {
            try {
              const leagueParams = pinnacleLeagueIds.map(id => `leagueId=${id}`).join('&');
              const leaguesResponse = await fetch(`/api/reference/leagues?${leagueParams}&sportId=1`).catch(() => null);
              if (leaguesResponse?.ok) {
                const leagues = await leaguesResponse.json();
                leagues.forEach(league => {
                  if (league.pinnacleId && league.id) {
                    leagueIdMap.set(league.pinnacleId, league.id);
                  }
                });
              }
            } catch (err) {
              // Continue without league ID mapping
            }
          }

          const matchServiceResponse = await fetch('/api/match/api-football', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-API-Key': API_KEY,
            },
            body: JSON.stringify({
              events: soccerMatches.map(m => ({
                eventId: m.eventId,
                homeTeam: m.homeTeam,
                awayTeam: m.awayTeam,
                startTime: m.startTime,
                leagueId: leagueIdMap.get(m.leagueId) || m.leagueId, // Use database ID if available
                sportId: m.sportId,
                liveScore: m.liveScore,
              })),
            }),
          });

          if (matchServiceResponse.ok) {
            const matchData = await matchServiceResponse.json();
            if (matchData.matches && matchData.matches.length > 0) {
              if (process.env.NODE_ENV === 'development') {
                console.log(`[Enrichment] ✅ Using match-service: ${matchData.matchedCount}/${matchData.soccerEvents} matches found`);
              }

              // Process matched fixtures from match-service
              const matchMap = new Map();
              for (const match of matchData.matches) {
                matchMap.set(match.pinnacleEvent.eventId, match.apiFootballFixture);
              }

              // Enrich matches with API-Football data from match-service
              const enrichedPromises = soccerMatches.map(async (match) => {
                const matchedFixture = matchMap.get(match.eventId);
                if (!matchedFixture) return match;

                // Fetch events/incidents for this fixture
                try {
                  const eventsResponse = await fetch(`/api/api-football/fixtures/events?fixture=${matchedFixture.fixture.id}`, {
                    headers: { 'X-API-Key': API_KEY }
                  });

                  if (eventsResponse.ok) {
                    const eventsData = await eventsResponse.json();
                    const incidents = eventsData.response || [];

                    // Extract cards and incidents
                    const yellowCards = incidents.filter(i => i.type === 'Card' && i.detail === 'Yellow Card');
                    const redCards = incidents.filter(i => i.type === 'Card' && i.detail === 'Red Card');

                    const enrichedMatch = {
                      ...match,
                      apiFootballData: {
                        fixtureId: matchedFixture.fixture.id,
                        yellowCards,
                        redCards,
                        incidents,
                        elapsedTime: matchedFixture.fixture.status?.elapsed,
                        extraTime: matchedFixture.fixture.status?.extra,
                        status: matchedFixture.fixture.status,
                      },
                    };

                    // Cache the enrichment
                    if (useCache) {
                      const cacheKey = `${match.eventId}_${match.eventType}`;
                      enrichmentCache.current.set(cacheKey, {
                        data: enrichedMatch.apiFootballData,
                        timestamp: Date.now(),
                      });
                    }

                    return enrichedMatch;
                  }
                } catch (error) {
                  if (process.env.NODE_ENV === 'development') {
                    console.warn(`[Enrichment] Error fetching events for fixture ${matchedFixture.fixture.id}:`, error);
                  }
                }

                return match;
              });

              const enrichedMatches = await Promise.all(enrichedPromises);

              // Merge enriched soccer matches with other matches
              const enrichedMap = new Map();
              enrichedMatches.forEach(m => enrichedMap.set(m.eventId, m));

              // Return matches with enrichment applied - THIS IS THE NEW SYSTEM
              const enrichedResult = matches.map(m => {
                if (m.sportId === 1 && enrichedMap.has(m.eventId)) {
                  return enrichedMap.get(m.eventId);
                }
                return m;
              });

              if (process.env.NODE_ENV === 'development') {
                console.log(`[Enrichment] ✅ Match-service enriched ${enrichedMap.size} matches - using new system`);
              }

              // Return early - don't fall through to old logic
              return enrichedResult;
            } else {
              if (process.env.NODE_ENV === 'development') {
                console.log(`[Enrichment] ⚠️ Match-service returned 0 matches (${matchData.matchedCount || 0}/${matchData.soccerEvents || 0}), falling back to fuzzy matching`);
              }
            }
          } else {
            if (process.env.NODE_ENV === 'development') {
              console.warn(`[Enrichment] ⚠️ Match-service API failed: ${matchServiceResponse.status}, falling back to fuzzy matching`);
            }
          }
        } catch (error) {
          if (process.env.NODE_ENV === 'development') {
            console.warn(`[Enrichment] ⚠️ Match-service API error, falling back to fuzzy matching:`, error.message);
          }
          // Fall through to existing matching logic
        }
      }

      // OLD SYSTEM: Fallback to fuzzy matching (only runs if match-service didn't find matches)

      // Helper function for fuzzy team name matching
      const fuzzyMatch = (str1, str2) => {
        const s1 = (str1 || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        const s2 = (str2 || '').toLowerCase().replace(/[^a-z0-9]/g, '');

        if (s1 === s2) return true;

        if (s1.length > 3 && s2.length > 3) {
          const longer = s1.length >= s2.length ? s1 : s2;
          const shorter = s1.length < s2.length ? s1 : s2;
          const minMatchLength = Math.ceil(shorter.length * 0.7);
          if (longer.includes(shorter) && shorter.length >= minMatchLength) {
            return true;
          }
        }

        const minLength = Math.min(s1.length, s2.length);
        if (minLength >= 6) {
          const prefixLength = Math.min(6, minLength);
          const prefix1 = s1.substring(0, prefixLength);
          const prefix2 = s2.substring(0, prefixLength);
          if (prefix1 === prefix2) {
            const lengthDiff = Math.abs(s1.length - s2.length);
            if (lengthDiff <= Math.max(3, minLength * 0.3)) {
              return true;
            }
          }
        }

        return false;
      };

      // Group matches by sport to fetch fixtures efficiently
      const matchesBySport = {};
      supportedMatches.forEach(match => {
        const sportEndpoint = SPORT_TO_API_ENDPOINT[match.sportId];
        if (!matchesBySport[sportEndpoint]) {
          matchesBySport[sportEndpoint] = [];
        }
        matchesBySport[sportEndpoint].push(match);
      });

      // Extract unique league IDs from matches
      const leagueIds = [...new Set(supportedMatches.map(m => m.leagueId).filter(Boolean))];

      // Fetch league mappings from database to get API-Football league IDs
      let leagueMappings = {};
      if (leagueIds.length > 0) {
        try {
          const leagueParams = leagueIds.map(id => `leagueId=${id}`).join('&');
          const leaguesResponse = await fetch(`/api/reference/leagues?${leagueParams}&sportId=${supportedMatches[0]?.sportId || 1}`).catch(() => null);
          if (leaguesResponse?.ok) {
            const leagues = await leaguesResponse.json();
            leagues.forEach(league => {
              if (league.apiFootballId) {
                leagueMappings[league.pinnacleId] = league.apiFootballId;
              }
            });
            if (process.env.NODE_ENV === 'development' && Object.keys(leagueMappings).length > 0) {
              console.log(`[Enrichment] Found ${Object.keys(leagueMappings).length} league mappings with API-Football IDs`);
            }
          }
        } catch (err) {
          // Fallback to date-only queries if league lookup fails
        }
      }

      // Fetch fixtures for today and tomorrow for each sport
      const today = new Date();
      const tomorrow = new Date(today);
      tomorrow.setDate(tomorrow.getDate() + 1);

      const todayStr = today.toISOString().split('T')[0];
      const tomorrowStr = tomorrow.toISOString().split('T')[0];

      let allFixtures = [];
      // Store fixtures per sport endpoint to avoid cross-sport matching
      let fixturesBySport = {};

      try {
        // Group matches by league to query more accurately
        const matchesByLeague = {};
        supportedMatches.forEach(match => {
          const sportEndpoint = SPORT_TO_API_ENDPOINT[match.sportId];
          const leagueKey = `${sportEndpoint}_${match.leagueId || 'no_league'}`;
          if (!matchesByLeague[leagueKey]) {
            matchesByLeague[leagueKey] = {
              sportEndpoint,
              leagueId: match.leagueId,
              apiFootballLeagueId: match.leagueId ? leagueMappings[match.leagueId] : null,
              matches: []
            };
          }
          matchesByLeague[leagueKey].matches.push(match);
        });

        // Fetch fixtures - prefer league-specific queries when available
        const fetchPromises = [];
        Object.keys(matchesByLeague).forEach(leagueKey => {
          const { sportEndpoint, apiFootballLeagueId, matches } = matchesByLeague[leagueKey];

          // Get match dates (parse from startTime)
          const matchDates = new Set();
          matches.forEach(match => {
            if (match.startTime && match.startTime !== 'TBD') {
              const dateMatch = match.startTime.match(/(\d{2})\/(\d{2})\/(\d{4})/);
              if (dateMatch) {
                const [, day, month, year] = dateMatch;
                matchDates.add(`${year}-${month}-${day}`);
              }
            }
          });

          // Use today/tomorrow as fallback if no dates found
          const datesToFetch = matchDates.size > 0 ? Array.from(matchDates) : [todayStr, tomorrowStr];

          datesToFetch.forEach(dateStr => {
            // Build query: prefer league-specific if available, otherwise date-only
            let queryUrl = `/api/api-football/${sportEndpoint}/fixtures?date=${dateStr}`;
            if (apiFootballLeagueId) {
              queryUrl += `&league=${apiFootballLeagueId}`;
            }

            fetchPromises.push({
              url: queryUrl,
              sportEndpoint,
              date: dateStr,
              leagueId: matchesByLeague[leagueKey].leagueId
            });
          });
        });

        // Execute all fetch requests
        const responses = await Promise.all(
          fetchPromises.map(({ url }) =>
            fetch(url, { headers: { 'X-API-Key': API_KEY } })
              .catch(() => ({ ok: false }))
          )
        );

        // Process responses
        for (let i = 0; i < responses.length; i++) {
          const { sportEndpoint } = fetchPromises[i];
          const response = responses[i];

          if (!fixturesBySport[sportEndpoint]) {
            fixturesBySport[sportEndpoint] = [];
          }

          if (response.ok) {
            const responseData = await response.json();
            if (responseData.response && Array.isArray(responseData.response)) {
              fixturesBySport[sportEndpoint].push(...responseData.response);
              if (process.env.NODE_ENV === 'development') {
                const { date, leagueId } = fetchPromises[i];
                const leagueInfo = leagueId && leagueMappings[leagueId] ? ` (league: ${leagueMappings[leagueId]})` : '';
                if (responseData.response.length > 0) {
                  console.log(`[Enrichment] ✅ Fetched ${responseData.response.length} fixtures for ${sportEndpoint} on ${date}${leagueInfo}`);
                } else {
                  console.log(`[Enrichment] ⚠️ API-Football returned 0 fixtures for ${sportEndpoint} on ${date}${leagueInfo} (status: ${response.status})`);
                  if (responseData.errors && responseData.errors.length > 0) {
                    console.warn(`[Enrichment] API-Football errors:`, responseData.errors);
                  }
                }
              }
            } else if (process.env.NODE_ENV === 'development') {
              const { date } = fetchPromises[i];
              console.warn(`[Enrichment] Unexpected response format for ${sportEndpoint} on ${date}:`, Object.keys(responseData || {}));
            }
          } else {
            const errorText = await response.text().catch(() => 'Unable to read error');
            if (process.env.NODE_ENV === 'development') {
              const { date, leagueId } = fetchPromises[i];
              const leagueInfo = leagueId && leagueMappings[leagueId] ? ` (league: ${leagueMappings[leagueId]})` : '';
              console.error(`[Enrichment] ❌ Failed to fetch fixtures for ${sportEndpoint} on ${date}${leagueInfo}: ${response.status}`, errorText.substring(0, 300));
            }
          }
        }

        // Combine all fixtures for backward compatibility
        allFixtures = Object.values(fixturesBySport).flat();

        if (allFixtures.length === 0 && process.env.NODE_ENV === 'development') {
          console.warn(`[Enrichment] No fixtures fetched for ${Object.keys(matchesBySport).join(', ')} (fetched for ${todayStr} and ${tomorrowStr})`);
          // Log sample match dates to help debug
          if (supportedMatches.length > 0) {
            const sampleMatch = supportedMatches[0];
            console.log(`[Enrichment] Sample match date: ${sampleMatch.startTime} (sport: ${sampleMatch.sportId})`);
            console.log(`[Enrichment] Total matches to enrich: ${supportedMatches.length}`);
          }
          // Log fixture counts per sport
          Object.keys(fixturesBySport).forEach(sport => {
            console.log(`[Enrichment] Fixtures for ${sport}: ${fixturesBySport[sport]?.length || 0}`);
          });
        } else if (process.env.NODE_ENV === 'development' && allFixtures.length > 0) {
          console.log(`[Enrichment] Fetched ${allFixtures.length} total fixtures for ${Object.keys(matchesBySport).join(', ')}`);
          // Log fixture counts per sport and sample fixture
          Object.keys(fixturesBySport).forEach(sport => {
            const count = fixturesBySport[sport]?.length || 0;
            if (count > 0) {
              const sample = fixturesBySport[sport][0];
              console.log(`[Enrichment] ${sport}: ${count} fixtures. Sample: ${sample.teams?.home?.name} vs ${sample.teams?.away?.name} (${sample.fixture?.date})`);
            }
          });
        }
      } catch (err) {
        error('Error fetching sports data fixtures:', err);
        return matches;
      }

      // Process each match to find matching API-Sports fixture
      const enrichedPromises = supportedMatches.map(async (match) => {
        // Check cache first
        if (useCache) {
          const cacheKey = `${match.eventId}_${match.eventType}`;
          const cached = enrichmentCache.current.get(cacheKey);

          if (cached) {
            const cacheAge = Date.now() - cached.timestamp;
            const cacheDuration = match.eventType === 'live' ? 10 * 1000 :
              match.eventType === 'prematch' ? 60 * 1000 : 5 * 60 * 1000;

            if (cacheAge < cacheDuration) {
              return { ...match, apiFootballData: cached.data };
            }
          }
        }

        try {
          // Parse Pinnacle match start time
          let pinnacleDate = null;
          try {
            if (match.startTime && match.startTime !== 'TBD') {
              // Handle European date format (DD/MM/YYYY) - Pinnacle uses DD/MM/YYYY format
              // Extract date and time parts: "06/12/2025, 15:00:00"
              const dateTimeMatch = match.startTime.match(/(\d{2})\/(\d{2})\/(\d{4})(?:,\s*(\d{2}):(\d{2}):(\d{2}))?/);

              if (dateTimeMatch) {
                // Parse as DD/MM/YYYY (European format)
                const [, day, month, year, hour = '00', minute = '00', second = '00'] = dateTimeMatch;
                // Create date in ISO format: YYYY-MM-DDTHH:mm:ss
                const isoDateString = `${year}-${month}-${day}T${hour}:${minute}:${second}`;
                pinnacleDate = new Date(isoDateString);

                if (isNaN(pinnacleDate.getTime())) {
                  pinnacleDate = null;
                } else if (process.env.NODE_ENV === 'development' && match.sportId === 1) {
                  const parsedDateStr = pinnacleDate.toISOString().split('T')[0];
                  const expectedDateStr = `${year}-${month}-${day}`;
                  if (parsedDateStr !== expectedDateStr) {
                    console.warn(`[Enrichment] Date parsing mismatch: "${match.startTime}" -> ${parsedDateStr} (expected ${expectedDateStr})`);
                  }
                }
              } else {
                // Fallback: try standard Date parsing
                pinnacleDate = new Date(match.startTime);
                if (isNaN(pinnacleDate.getTime())) {
                  pinnacleDate = null;
                }
              }
            }
          } catch (e) {
            // Ignore date parsing errors
            if (process.env.NODE_ENV === 'development' && match.sportId === 1) {
              console.warn(`[Enrichment] Date parsing error for ${match.startTime}:`, e.message);
            }
          }

          // Filter fixtures by sport - API-Sports returns sport-specific data per endpoint
          const sportEndpoint = SPORT_TO_API_ENDPOINT[match.sportId];

          // Get fixtures for this specific sport only (to avoid cross-sport matching)
          const sportFixtures = fixturesBySport[sportEndpoint] || [];

          // Find matching fixture using hybrid approach
          const matchingFixture = sportFixtures.find(fixture => {
            const apiHome = fixture.teams?.home?.name || '';
            const apiAway = fixture.teams?.away?.name || '';
            const matchHome = match.homeTeam || '';
            const matchAway = match.awayTeam || '';

            // Normalize for exact matching
            const normalizeExact = (str) => (str || '').toLowerCase().trim();
            const apiHomeNorm = normalizeExact(apiHome);
            const apiAwayNorm = normalizeExact(apiAway);
            const matchHomeNorm = normalizeExact(matchHome);
            const matchAwayNorm = normalizeExact(matchAway);

            // Check for exact matches
            const homeExact = apiHomeNorm === matchHomeNorm;
            const awayExact = apiAwayNorm === matchAwayNorm;
            const homeAwaySwappedExact = apiHomeNorm === matchAwayNorm && apiAwayNorm === matchHomeNorm;

            // Check for fuzzy matches
            const homeMatch = fuzzyMatch(apiHome, matchHome);
            const awayMatch = fuzzyMatch(apiAway, matchAway);
            const homeAwaySwappedMatch = fuzzyMatch(apiHome, matchAway) && fuzzyMatch(apiAway, matchHome);

            // Date verification (within 2 hours) - REQUIRED for all matches
            let dateMatches = true;
            if (pinnacleDate && fixture.fixture?.date) {
              const fixtureDate = new Date(fixture.fixture.date);
              const dateDiff = Math.abs(pinnacleDate.getTime() - fixtureDate.getTime());
              const twoHours = 2 * 60 * 60 * 1000;

              if (dateDiff > twoHours) {
                dateMatches = false;
              }
            }

            // If date doesn't match, reject immediately
            if (!dateMatches) {
              return false;
            }

            // PRIMARY: One team exact match + time match
            // Check home→home, away→away OR home→away, away→home (accounting for swapped teams)
            if ((homeExact || awayExact || homeAwaySwappedExact) && dateMatches) {
              // One exact match + time match is sufficient
              // But verify score for live matches if available
              if (match.eventType === 'live' && match.liveScore !== '-') {
                try {
                  const scoreMatch = match.liveScore.match(/(\d+)[\s-]+(\d+)/);
                  if (scoreMatch) {
                    const pinnacleHome = parseInt(scoreMatch[1], 10);
                    const pinnacleAway = parseInt(scoreMatch[2], 10);
                    const apiHomeScore = fixture.goals?.home ?? fixture.score?.fulltime?.home ?? null;
                    const apiAwayScore = fixture.goals?.away ?? fixture.score?.fulltime?.away ?? null;

                    if (apiHomeScore !== null && apiAwayScore !== null) {
                      // Check both normal and swapped order
                      const homeDiff = Math.abs(pinnacleHome - apiHomeScore);
                      const awayDiff = Math.abs(pinnacleAway - apiAwayScore);
                      const swappedHomeDiff = Math.abs(pinnacleHome - apiAwayScore);
                      const swappedAwayDiff = Math.abs(pinnacleAway - apiHomeScore);

                      // Accept if either order matches (within 1 goal tolerance)
                      const normalMatch = homeDiff <= 1 && awayDiff <= 1;
                      const swappedMatch = swappedHomeDiff <= 1 && swappedAwayDiff <= 1;

                      if (!normalMatch && !swappedMatch) {
                        return false; // Score mismatch
                      }
                    }
                  }
                } catch (e) {
                  // Ignore score comparison errors, continue with match
                }
              }
              return true; // One exact match + time match = MATCH
            }

            // FALLBACK: Both teams fuzzy match + time match (current logic)
            if ((homeMatch && awayMatch) || homeAwaySwappedMatch) {
              // Score verification for live matches
              if (match.eventType === 'live' && match.liveScore !== '-') {
                try {
                  const scoreMatch = match.liveScore.match(/(\d+)[\s-]+(\d+)/);
                  if (scoreMatch) {
                    const pinnacleHome = parseInt(scoreMatch[1], 10);
                    const pinnacleAway = parseInt(scoreMatch[2], 10);
                    const apiHomeScore = fixture.goals?.home ?? fixture.score?.fulltime?.home ?? null;
                    const apiAwayScore = fixture.goals?.away ?? fixture.score?.fulltime?.away ?? null;

                    if (apiHomeScore !== null && apiAwayScore !== null) {
                      // Check both normal and swapped order
                      const homeDiff = Math.abs(pinnacleHome - apiHomeScore);
                      const awayDiff = Math.abs(pinnacleAway - apiAwayScore);
                      const swappedHomeDiff = Math.abs(pinnacleHome - apiAwayScore);
                      const swappedAwayDiff = Math.abs(pinnacleAway - apiHomeScore);

                      const normalMatch = homeDiff <= 1 && awayDiff <= 1;
                      const swappedMatch = swappedHomeDiff <= 1 && swappedAwayDiff <= 1;

                      if (!normalMatch && !swappedMatch) {
                        return false; // Score mismatch
                      }
                    }
                  }
                } catch (e) {
                  // Ignore score comparison errors
                }
              }
              return true; // Both teams fuzzy match + time match = MATCH
            }

            return false; // No match found
          });

          if (!matchingFixture) {
            if (process.env.NODE_ENV === 'development' && match.sportId === 1) {
              // Only log for soccer matches to avoid spam
              const today = new Date().toISOString().split('T')[0];
              const tomorrow = new Date(Date.now() + 86400000).toISOString().split('T')[0];
              const matchDateStr = pinnacleDate ? pinnacleDate.toISOString().split('T')[0] : 'unknown';

              // Log if match is today/tomorrow OR if we have fixtures but no match
              if (match.startTime && (match.startTime.includes(today) || match.startTime.includes(tomorrow) || matchDateStr === today || matchDateStr === tomorrow)) {
                console.log(`[Enrichment] No match found for: ${match.homeTeam} vs ${match.awayTeam} (${match.startTime}, league: ${match.league || 'unknown'})`, {
                  matchDate: matchDateStr,
                  fetchedDates: `${todayStr}, ${tomorrowStr}`,
                  fixturesAvailable: sportFixtures.length,
                  dateMatch: matchDateStr === todayStr || matchDateStr === tomorrowStr
                });
              }
            }
            return match;
          }

          // Fetch incidents for live matches only
          let yellowCards = [];
          let redCards = [];
          let incidents = [];

          const isLiveMatch = match.eventType === 'live' ||
            (matchingFixture.fixture.status?.short &&
              ['1H', 'HT', '2H', 'ET', 'P', 'LIVE'].includes(matchingFixture.fixture.status.short.toUpperCase()));

          if (isLiveMatch) {
            try {
              const sportEndpoint = SPORT_TO_API_ENDPOINT[match.sportId];
              const eventsUrl = `/api/api-football/${sportEndpoint}/fixtures/events?fixture=${matchingFixture.fixture.id}`;

              const incidentsResponse = await fetch(eventsUrl, {
                headers: { 'X-API-Key': API_KEY }
              });

              if (incidentsResponse.ok) {
                const incidentsData = await incidentsResponse.json();
                const incidentsList = incidentsData.response || [];

                incidentsList.forEach(event => {
                  if (event.type === 'Card' && event.detail === 'Yellow Card') {
                    yellowCards.push({
                      minute: event.time?.elapsed || 0,
                      player: event.player?.name || 'Unknown',
                      team: event.team?.name || '',
                      reason: event.comments || '',
                      type: 'Yellow'
                    });
                  }

                  if (event.type === 'Card' && event.detail === 'Red Card') {
                    redCards.push({
                      minute: event.time?.elapsed || 0,
                      player: event.player?.name || 'Unknown',
                      team: event.team?.name || '',
                      reason: event.comments || '',
                      type: 'Red'
                    });
                  }

                  incidents.push({
                    minute: event.time?.elapsed || 0,
                    type: event.type?.toLowerCase() === 'subst' ? 'substitution' : (event.type?.toLowerCase() || 'unknown'),
                    player: event.player?.name || null,
                    team: event.team?.name || null,
                    cardType: event.detail === 'Yellow Card' ? 'Yellow' : (event.detail === 'Red Card' ? 'Red' : null),
                    reason: event.comments || null,
                    detail: event.detail || null
                  });
                });
              }
            } catch (err) {
              // Ignore events fetch errors
            }
          }

          // Map API-Football status to period description and determine if match is finished
          let apiFootballPeriodDescription = null;
          let isMatchFinished = false;
          const statusShort = matchingFixture.fixture.status?.short?.toUpperCase() || '';
          const statusLong = (matchingFixture.fixture.status?.long || '').toLowerCase();

          // Check if match is finished (check both short and long status)
          if (statusShort === 'FT' || statusShort === 'FT_PEN' || statusShort === 'AET' ||
            statusLong.includes('finished') || statusLong.includes('match finished') ||
            statusLong === 'ft' || statusLong === 'after extra time') {
            isMatchFinished = true;
            apiFootballPeriodDescription = 'Full Time';
          } else if (statusLong.includes('first half') || statusLong === '1h' || statusShort === '1H') {
            apiFootballPeriodDescription = '1st Half';
          } else if (statusLong.includes('second half') || statusLong === '2h' || statusShort === '2H') {
            apiFootballPeriodDescription = '2nd Half';
          } else if (statusLong.includes('halftime') || statusLong === 'ht' || statusShort === 'HT') {
            apiFootballPeriodDescription = 'Halftime';
          } else if (statusLong.includes('extra time') || statusShort === 'ET') {
            apiFootballPeriodDescription = 'Extra Time';
          } else if (statusLong.includes('penalties') || statusShort === 'PEN') {
            apiFootballPeriodDescription = 'Penalties';
          } else if (statusLong.includes('not started') || statusLong === 'ns' || statusShort === 'NS') {
            apiFootballPeriodDescription = null; // Not started
          } else if (statusLong || statusShort) {
            // For other statuses, use the status directly
            apiFootballPeriodDescription = matchingFixture.fixture.status.long || statusShort;
          }

          // Get API-Football score (more accurate for live matches)
          const apiFootballScore = matchingFixture.goals?.home !== null && matchingFixture.goals?.away !== null
            ? `${matchingFixture.goals.home} - ${matchingFixture.goals.away}`
            : (matchingFixture.score?.fulltime?.home !== null && matchingFixture.score?.fulltime?.away !== null
              ? `${matchingFixture.score.fulltime.home} - ${matchingFixture.score.fulltime.away}`
              : null);

          // Enrich match with API-Football data
          const apiFootballData = {
            fixtureId: matchingFixture.fixture.id,
            yellowCards,
            redCards,
            incidents,
            yellowCardCount: yellowCards.length,
            redCardCount: redCards.length,
            elapsed: matchingFixture.fixture.status?.elapsed || null,
            extra: matchingFixture.fixture.status?.extra || null,
            status: matchingFixture.fixture.status || null,
            score: apiFootballScore
          };

          // Update startTime from API-Football fixture date (more accurate)
          let updatedStartTime = match.startTime;
          if (matchingFixture.fixture?.date) {
            try {
              const fixtureDate = new Date(matchingFixture.fixture.date);
              if (!isNaN(fixtureDate.getTime())) {
                updatedStartTime = fixtureDate.toLocaleString();
              }
            } catch (e) {
              // Keep original startTime if date parsing fails
            }
          }

          const enrichedMatch = {
            ...match,
            // Update startTime from API-Football (more accurate)
            startTime: updatedStartTime,
            // Update eventType if match is finished (API-Football is more accurate)
            eventType: isMatchFinished ? 'finished' : match.eventType,
            // Update liveScore with API-Football score if available (more accurate)
            liveScore: apiFootballScore || match.liveScore,
            // Update periodDescription with API-Football data if available
            periodDescription: apiFootballPeriodDescription || match.periodDescription,
            apiFootballData
          };

          // Cache the enrichment
          const cacheKey = `${match.eventId}_${match.eventType}`;
          enrichmentCache.current.set(cacheKey, {
            timestamp: Date.now(),
            data: apiFootballData
          });

          return enrichedMatch;
        } catch (err) {
          error(`Error enriching match ${match.eventId}:`, err);
          return match;
        }
      });

      // Wait for all enrichment requests
      const enrichedMatches = await Promise.all(enrichedPromises);

      // Verify enrichment worked
      const enrichedCount = enrichedMatches.filter(m => m.apiFootballData).length;
      if (process.env.NODE_ENV === 'development') {
        if (enrichedCount > 0) {
          const sample = enrichedMatches.find(m => m.apiFootballData);
          if (sample) {
            console.log(`[Enrichment] ✅ ${enrichedCount}/${enrichedMatches.length} matches enriched. Sample: ${sample.homeTeam} vs ${sample.awayTeam}`, {
              elapsed: sample.apiFootballData.elapsed,
              yellowCards: sample.apiFootballData.yellowCardCount,
              redCards: sample.apiFootballData.redCardCount
            });
          }
        } else {
          console.warn(`[Enrichment] ❌ 0/${enrichedMatches.length} matches enriched. Total fixtures available: ${allFixtures.length}`);
          if (allFixtures.length > 0 && enrichedMatches.length > 0) {
            // Log why matches aren't matching
            const sampleMatch = enrichedMatches[0];
            const sampleFixture = allFixtures[0];
            console.log(`[Enrichment] Sample match: ${sampleMatch.homeTeam} vs ${sampleMatch.awayTeam} (${sampleMatch.startTime})`);
            console.log(`[Enrichment] Sample fixture: ${sampleFixture.teams?.home?.name} vs ${sampleFixture.teams?.away?.name} (${sampleFixture.fixture?.date})`);
          }
        }
      }

      // Merge with unsupported matches (sports without API-Sports coverage)
      const unsupportedMatches = matches.filter(m => {
        const hasApiSupport = SPORT_TO_API_ENDPOINT[m.sportId];
        return !hasApiSupport ||
          m.league?.toLowerCase().includes('corners') ||
          m.homeTeam?.toLowerCase().includes('corners') ||
          m.awayTeam?.toLowerCase().includes('corners');
      });
      return [...enrichedMatches, ...unsupportedMatches];
    } catch (err) {
      error('Error enriching with sports data:', err);
      return matches;
    }
  };

  // Fetch reference data on mount
  useEffect(() => {
    if (!isAuthenticated) {
      return;
    }

    const fetchReferenceData = async () => {
      try {
        setLoading(true);

        // Fetch sports
        const sportsRes = await fetch('/api/reference/sports');
        if (sportsRes.ok) {
          const sportsData = await sportsRes.json();
          setSports(sportsData);
        } else {
          const errorData = await sportsRes.json().catch(() => ({ error: 'Unknown error' }));
          warn(`Failed to fetch sports: ${sportsRes.status} - ${JSON.stringify(errorData)}`);
          setSports([]);
        }

        setLoading(false);
      } catch (error) {
        error('Error fetching reference data:', error);
        setSports([]);
        setLoading(false);
      }
    };

    fetchReferenceData();
  }, [isAuthenticated]);

  // Fetch leagues when sport is selected
  useEffect(() => {
    if (!selectedSportId || !isAuthenticated) {
      setLeagues([]);
      setTeams([]);
      return;
    }

    const fetchLeagues = async () => {
      try {
        const res = await fetch(`/api/reference/leagues?sportId=${selectedSportId}`);
        if (res.ok) {
          const data = await res.json();
          setLeagues(data);
        } else {
          const errorData = await res.json().catch(() => ({ error: 'Unknown error' }));
          warn(`Failed to fetch leagues: ${res.status} - ${JSON.stringify(errorData)}`);
          setLeagues([]);
        }
      } catch (error) {
        error('Error fetching leagues:', error);
        setLeagues([]);
      }
    };

    fetchLeagues();
  }, [selectedSportId, isAuthenticated]);

  // Fetch teams when leagues are selected (on-demand from API)
  useEffect(() => {
    if (!selectedSportId || selectedLeagues.length === 0 || !isAuthenticated) {
      setTeams([]);
      return;
    }

    const fetchTeams = async () => {
      try {
        setLoadingTeams(true);

        // Fetch teams from API (will fetch matches and extract teams, then save to DB)
        const leagueIds = selectedLeagues.map(l => l.pinnacleId);
        const res = await fetch('/api/reference/teams-for-leagues', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            sportId: selectedSportId,
            leagueIds: leagueIds,
          }),
        });

        if (res.ok) {
          const data = await res.json();
          // Remove duplicates by team ID
          const uniqueTeams = Array.from(new Map(data.teams.map(t => [t.id, t])).values());
          setTeams(uniqueTeams);

          // Log summary
          if (data.matchesFound > 0) {
            info(`Found ${data.matchesFound} matches, ${uniqueTeams.length} teams (${data.teamsCreated} created, ${data.teamsUpdated} updated)`);
          } else {
            warn(`No active matches found for selected leagues`);
          }
        } else {
          const errorData = await res.json().catch(() => ({}));
          error('Error fetching teams:', res.status, errorData);
          setTeams([]);
        }
      } catch (error) {
        error('Error fetching teams:', error);
        setTeams([]);
      } finally {
        setLoadingTeams(false);
      }
    };

    fetchTeams();
  }, [selectedSportId, selectedLeagues, isAuthenticated]);

  // Fetch bet types when sport is selected
  useEffect(() => {
    if (!selectedSportId || !isAuthenticated) return;

    const fetchBetTypes = async () => {
      try {
        const res = await fetch(`/api/reference/bet-types?sportId=${selectedSportId}`);
        if (res.ok) {
          const data = await res.json();
          setBetTypes(data);
        }
      } catch (error) {
        error('Error fetching bet types:', error);
      }
    };

    fetchBetTypes();
  }, [selectedSportId, isAuthenticated]);

  // Fetch leagues for modal when sport is selected in modal
  useEffect(() => {
    if (!modalSelectedSport || !showSportsModal) {
      setModalLeagues([]);
      return;
    }

    const fetchModalLeagues = async () => {
      try {
        setLoadingModalLeagues(true);
        const res = await fetch(`/api/reference/leagues?sportId=${modalSelectedSport.pinnacleId}`);
        if (res.ok) {
          const data = await res.json();
          setModalLeagues(data);
        } else {
          setModalLeagues([]);
        }
      } catch (error) {
        error('Error fetching modal leagues:', error);
        setModalLeagues([]);
      } finally {
        setLoadingModalLeagues(false);
      }
    };

    fetchModalLeagues();
  }, [modalSelectedSport, showSportsModal]);


  // Track rate limit cooldown
  const [rateLimitCooldown, setRateLimitCooldown] = useState(null);

  // Refs for polling mechanism
  const previousFiltersRef = useRef(null);
  const previousMatchesRef = useRef([]);
  const pollingIntervalRef = useRef(null);
  const isPollingRef = useRef(false);
  // Track 422 errors per event type to avoid repeated failed requests
  const error422CacheRef = useRef(new Map()); // eventType -> timestamp of last 422
  // Track page visibility to pause polling when tab is inactive
  // Initialize to true (assume visible) - will be updated by visibility API
  const [isPageVisible, setIsPageVisible] = useState(() => {
    if (typeof document !== 'undefined') {
      return !document.hidden;
    }
    return true;
  });

  // Extract fetchMatches to be reusable for both filter changes and polling
  const fetchMatchesData = async (signal, skipLoadingState = false) => {
    if (!selectedSportId || !isAuthenticated) {
      return [];
    }

    // Check if we're in rate limit cooldown
    if (rateLimitCooldown && Date.now() < rateLimitCooldown) {
      const remainingSeconds = Math.ceil((rateLimitCooldown - Date.now()) / 1000);
      warn(`Rate limit cooldown active. Waiting ${remainingSeconds} more seconds...`);
      return [];
    }

    if (!skipLoadingState) {
      setLoadingMatches(true);
    }

    try {
      if (viewMode === 'all') {
        // Fetch all matches for the sport (no league/team filtering)
        const eventTypes = eventTypeFilter === 'both'
          ? ['prematch', 'live']
          : [eventTypeFilter];

        // For 'both', space out the requests to respect 5/sec limit
        const allMatches = [];
        const allEvents = []; // Store raw events to avoid duplicate API calls
        const allSpecialMarkets = {}; // Store special markets by event_id

        // Fetch special markets for all event types
        const specialMarketsPromises = eventTypes.map(async (eventType) => {
          try {
            const sinceParam = eventType === 'live' ? `&since=${Math.floor(Date.now() / 1000) - 3600}` : '';
            const url = `https://pinnacle-odds.p.rapidapi.com/kit/v1/special-markets?sport_id=${selectedSportId}&event_type=${eventType}${sinceParam}`;
            const res = await fetch(url, {
              headers: {
                'x-rapidapi-key': '1136d969acmsh09f0b7708001d5fp182010jsn7447ede24aae',
                'x-rapidapi-host': 'pinnacle-odds.p.rapidapi.com',
              },
              signal,
            });
            if (res.ok) {
              const data = await res.json();
              const specials = data.specials || data || [];
              // No verbose logging
              return specials;
            }
            return [];
          } catch (err) {
            warn(`Failed to fetch special markets for ${eventType}:`, err);
            return [];
          }
        });

        // Fetch special markets in parallel
        const specialMarketsResults = await Promise.all(specialMarketsPromises);
        const allSpecials = specialMarketsResults.flat();

        // Group special markets by event_id (normalize to string for consistent matching)
        allSpecials.forEach(special => {
          if (special.event_id) {
            const eventIdKey = String(special.event_id);
            if (!allSpecialMarkets[eventIdKey]) {
              allSpecialMarkets[eventIdKey] = [];
            }
            allSpecialMarkets[eventIdKey].push(special);
          }
        });

        // Summary only - no verbose logging

        for (let i = 0; i < eventTypes.length; i++) {
          const eventType = eventTypes[i];

          // Skip event types that recently returned 422 (during polling only)
          // Retry after 5 minutes (300000ms) to check if matches became available
          if (skipLoadingState) {
            const last422Time = error422CacheRef.current.get(eventType);
            if (last422Time && Date.now() - last422Time < 300000) {
              // Skip this event type - it returned 422 recently
              continue;
            }
          }

          // Add delay between requests (200ms = 5 requests per second max)
          if (i > 0) {
            await new Promise(resolve => setTimeout(resolve, 200));
          }

          try {
            // For live matches, add 'since' parameter to avoid rate limits
            // Use timestamp from 1 hour ago to get all live matches (not just recently updated ones)
            // Note: Removed is_have_odds filter - it's unreliable. Filtering happens in transformation based on actual market data.
            const sinceParam = eventType === 'live' ? `&since=${Math.floor(Date.now() / 1000) - 3600}` : '';
            const url = `https://pinnacle-odds.p.rapidapi.com/kit/v1/markets?sport_id=${selectedSportId}&event_type=${eventType}${sinceParam}`;
            const res = await fetch(url, {
              headers: {
                'x-rapidapi-key': '1136d969acmsh09f0b7708001d5fp182010jsn7447ede24aae',
                'x-rapidapi-host': 'pinnacle-odds.p.rapidapi.com',
              },
              signal,
            });

            if (signal.aborted) {
              break;
            }

            if (res.ok) {
              const data = await res.json();
              let events = [];
              if (Array.isArray(data)) {
                events = data;
              } else if (data && Array.isArray(data.events)) {
                events = data.events;
              } else if (data && Array.isArray(data.matches)) {
                events = data.matches;
              }

              // Store raw events for The-Odds-API matching (avoid duplicate API calls)
              allEvents.push(...events);

              const transformedMatches = transformApiEventsToMatches(events, eventType, selectedSportId);

              allMatches.push(...transformedMatches);
            } else if (res.status === 422) {
              // 422 Unprocessable Entity - API doesn't accept the request format
              // This can happen when there are no live matches for a sport, or invalid parameters
              // Cache this error to avoid repeated requests during polling
              error422CacheRef.current.set(eventType, Date.now());

              // Only log warning if not in polling mode (skipLoadingState indicates polling)
              if (!skipLoadingState) {
                try {
                  const errorData = await res.json().catch(() => null);
                  warn(`422 error for ${eventType} matches (sport_id=${selectedSportId}):`, errorData || 'No matches available');
                } catch (e) {
                  warn(`422 error for ${eventType} matches (sport_id=${selectedSportId}): Request format invalid or no matches available`);
                }
              }
              // Continue to next event type instead of breaking - don't treat as fatal error
              continue;
            } else if (res.status === 429) {
              // Check if it's a quota exceeded error vs rate limiting
              try {
                const errorData = await res.json().catch(() => null);
                const errorMessage = errorData?.message || '';
                if (errorMessage.includes('quota') || errorMessage.includes('MONTHLY')) {
                  error(`API quota exceeded for ${eventType} matches. Please upgrade your RapidAPI plan or wait for quota reset.`);
                  setRateLimitCooldown(Date.now() + 3600000); // 1 hour cooldown for quota exceeded
                } else {
                  warn(`Rate limited for ${eventType} matches - entering cooldown period`);
                  setRateLimitCooldown(Date.now() + 60000); // 1 minute cooldown for rate limiting
                }
              } catch (e) {
                warn(`Rate limited for ${eventType} matches - entering cooldown period`);
                setRateLimitCooldown(Date.now() + 60000);
              }
              break;
            } else {
              // Log other errors for debugging
              const errorText = await res.text().catch(() => '');
              warn(`API error ${res.status} for ${eventType} matches:`, errorText);
            }
          } catch (error) {
            if (error.name === 'AbortError') {
              break;
            }
            error(`Error fetching ${eventType} matches:`, error);
          }
        }

        if (!signal.aborted) {
          // allEvents already populated from first loop - no need for duplicate API calls

          // During polling, if we skipped all event types due to 422 cache and got no matches,
          // return null to indicate we should preserve existing matches
          if (skipLoadingState && allMatches.length === 0) {
            // Check if we skipped all event types due to 422
            const allSkipped = eventTypes.every(eventType => {
              const last422Time = error422CacheRef.current.get(eventType);
              return last422Time && Date.now() - last422Time < 300000;
            });

            if (allSkipped) {
              // All event types were skipped due to 422 cache - return null to preserve existing matches
              return null;
            }
          }

          // Fetch The-Odds-API data for all events (we'll use it after filtering)
          const theOddsApiMarkets = await fetchTheOddsApiData(selectedSportId, allEvents);

          // Create matches from special markets that don't exist in /markets endpoint
          // Some events only appear in /special-markets endpoint
          const existingEventIds = new Set(allMatches.map(m => String(m.eventId)));
          const matchesFromSpecials = [];

          Object.keys(allSpecialMarkets).forEach(eventIdKey => {
            // Skip if we already have a match for this event
            if (existingEventIds.has(eventIdKey)) {
              return;
            }

            const specials = allSpecialMarkets[eventIdKey];
            if (!specials || specials.length === 0) {
              return;
            }

            // Extract event info from first special market
            const firstSpecial = specials[0];
            const eventId = parseInt(eventIdKey);

            // Check if any specials are open
            const hasOpenSpecials = specials.some(s => {
              const isOpen = s.open === true || s.status === 'O';
              return isOpen && s.lines && Object.keys(s.lines).length > 0;
            });

            // Only create match if there are open special markets
            if (!hasOpenSpecials) {
              return;
            }

            // Extract team names from event object in special market
            const eventInfo = firstSpecial.event || {};
            const homeTeam = eventInfo.home || 'Unknown Team';
            const awayTeam = eventInfo.away || 'Unknown Team';

            // Determine event type from live_status_id
            const liveStatusId = firstSpecial.live_status_id || 2;
            const eventType = liveStatusId === 1 ? 'live' : (liveStatusId === 3 ? 'finished' : 'prematch');

            // Format start time
            let startTime = 'TBD';
            if (firstSpecial.starts) {
              try {
                startTime = new Date(firstSpecial.starts).toLocaleString();
              } catch (e) {
                startTime = firstSpecial.starts;
              }
            }

            // Create a minimal match object from special markets
            const matchFromSpecial = {
              eventId: eventId,
              homeTeam: homeTeam,
              awayTeam: awayTeam,
              league: 'Unknown League', // Special markets don't include league name
              leagueId: firstSpecial.league_id || null,
              sportId: selectedSportId,
              eventType: eventType,
              startTime: startTime,
              liveScore: '-',
              matchStatus: liveStatusId,
              periodDescription: null,
              hasOpenMarkets: true,
              markets: {
                moneyLine: [],
                spreads: [],
                totals: [],
                teamTotals: [],
                playerProps: [],
                futures: [],
                teamProps: [],
                gameProps: []
              }
            };

            matchesFromSpecials.push(matchFromSpecial);
          });

          // Combine matches from /markets endpoint with matches from special markets
          const allMatchesIncludingSpecials = [...allMatches, ...matchesFromSpecials];

          if (matchesFromSpecials.length > 0) {
          }

          // Process and merge special markets into matches (BEFORE The-Odds-API merge)
          // This ensures we count Pinnacle markets (periods + special markets) correctly
          const matchesWithSpecials = allMatchesIncludingSpecials.map(match => {
            // Match special markets by event_id (handle both string and number types)
            const eventIdKey = String(match.eventId);
            const specials = allSpecialMarkets[match.eventId] || allSpecialMarkets[eventIdKey] || [];
            const beforeCount = Object.values(match.markets).reduce((sum, arr) => sum + (Array.isArray(arr) ? arr.length : 0), 0);

            if (specials.length === 0) {
              return match;
            }

            let specialsAdded = 0;
            let skippedClosed = 0;
            let skippedNoLines = 0;

            specials.forEach(special => {
              // Check if special is open (can be 'open' boolean or 'status' === 'O')
              const isOpen = special.open === true || special.status === 'O';
              if (!isOpen) {
                skippedClosed++;
                return;
              }
              if (!special.lines) {
                skippedNoLines++;
                return;
              }

              Object.values(special.lines).forEach(line => {
                if (!line.price) return; // Skip lines without odds

                const specialMarket = {
                  bet: `${special.name} - ${line.name}`,
                  teamType: line.name,
                  price: line.price?.toFixed(3) || 'N/A',
                  line: line.handicap ? line.handicap.toString() : '',
                  status: 'Open',
                  period: 'Special', // Mark as Pinnacle special market
                  specialId: special.special_id,
                  betType: special.bet_type,
                  category: special.category
                };

                // Categorize special markets
                if (special.category === 'Player Props') {
                  match.markets.playerProps.push(specialMarket);
                  specialsAdded++;
                } else if (special.category === 'Team Props') {
                  match.markets.teamProps.push(specialMarket);
                  specialsAdded++;
                } else if (special.category === 'Game Props') {
                  match.markets.gameProps.push(specialMarket);
                  specialsAdded++;
                } else if (special.category === 'Futures') {
                  match.markets.futures.push(specialMarket);
                  specialsAdded++;
                }
              });
            });

            const afterCount = Object.values(match.markets).reduce((sum, arr) => sum + (Array.isArray(arr) ? arr.length : 0), 0);
            // No verbose logging per match

            return match;
          });

          // Filter: Only show matches that have at least one Pinnacle market (from periods OR special markets)
          const matchesWithPinnacleOdds = matchesWithSpecials.filter(match => {
            const pinnacleMarketsCount = countPinnacleMarkets(match);
            return pinnacleMarketsCount > 0;
          });

          // Summary only (no per-match logging)
          if (matchesFromSpecials.length > 0) {
            const matchesFromSpecialsIncluded = matchesWithPinnacleOdds.filter(m =>
              matchesFromSpecials.some(ms => ms.eventId === m.eventId)
            );
          }

          // Now merge The-Odds-API markets (supplemental only - requires Pinnacle markets)
          const matchesWithOddsApi = mergeTheOddsApiMarkets(matchesWithPinnacleOdds, theOddsApiMarkets);

          // Enrich matches with API-Sports data
          const enriched = await enrichWithApiFootballData(matchesWithOddsApi, true);
          return enriched;
        }
        return [];
      } else {
        // Filtered mode: fetch matches for selected leagues
        if (selectedLeagues.length === 0) {
          return [];
        }

        const leagueIds = selectedLeagues.map(l => l.pinnacleId);
        const eventTypes = eventTypeFilter === 'both'
          ? ['prematch', 'live']
          : [eventTypeFilter];

        const fetchPromises = eventTypes.map(async (eventType) => {
          try {
            // For live matches, add 'since' parameter to avoid rate limits
            const sinceParam = eventType === 'live' ? `&since=${Math.floor(Date.now() / 1000) - 60}` : '';
            const leagueParams = leagueIds.map(id => `league_id=${id}`).join('&');
            const res = await fetch(`https://pinnacle-odds.p.rapidapi.com/kit/v1/markets?sport_id=${selectedSportId}&event_type=${eventType}&${leagueParams}${sinceParam}`, {
              headers: {
                'x-rapidapi-key': '1136d969acmsh09f0b7708001d5fp182010jsn7447ede24aae',
                'x-rapidapi-host': 'pinnacle-odds.p.rapidapi.com',
              },
              signal,
            });

            if (signal.aborted) {
              return { matches: [], events: [] };
            }

            if (res.ok) {
              const data = await res.json();
              let events = [];
              if (Array.isArray(data)) {
                events = data;
              } else if (data && Array.isArray(data.events)) {
                events = data.events;
              } else if (data && Array.isArray(data.matches)) {
                events = data.matches;
              }

              const transformedMatches = transformApiEventsToMatches(events, eventType, selectedSportId);
              // Return both matches and raw events to avoid duplicate API calls
              return { matches: transformedMatches, events: events };
            } else if (res.status === 422) {
              // 422 Unprocessable Entity - API doesn't accept the request format
              // This can happen when there are no live matches for a sport, or invalid parameters
              const errorText = await res.text().catch(() => '');
              warn(`422 error for ${eventType} matches (sport_id=${selectedSportId}):`, errorText);
              return { matches: [], events: [] };
            } else if (res.status === 429) {
              // Check if it's a quota exceeded error vs rate limiting
              try {
                const errorData = await res.json().catch(() => null);
                const errorMessage = errorData?.message || '';
                if (errorMessage.includes('quota') || errorMessage.includes('MONTHLY')) {
                  error(`API quota exceeded for ${eventType} matches. Please upgrade your RapidAPI plan or wait for quota reset.`);
                  setRateLimitCooldown(Date.now() + 3600000); // 1 hour cooldown for quota exceeded
                } else {
                  warn(`Rate limited for ${eventType} matches - will retry or use cached data`);
                }
              } catch (e) {
                warn(`Rate limited for ${eventType} matches - will retry or use cached data`);
              }
              return { matches: [], events: [] };
            } else {
              // Log other errors for debugging
              const errorText = await res.text().catch(() => '');
              warn(`API error ${res.status} for ${eventType} matches:`, errorText);
              return { matches: [], events: [] };
            }
          } catch (error) {
            if (error.name === 'AbortError') {
              return { matches: [], events: [] };
            }
            error(`Error fetching ${eventType} matches:`, error);
            return { matches: [], events: [] };
          }
        });

        // Fetch special markets for filtered mode too
        const specialMarketsPromises = eventTypes.map(async (eventType) => {
          try {
            const sinceParam = eventType === 'live' ? `&since=${Math.floor(Date.now() / 1000) - 3600}` : '';
            const url = `https://pinnacle-odds.p.rapidapi.com/kit/v1/special-markets?sport_id=${selectedSportId}&event_type=${eventType}${sinceParam}`;
            const res = await fetch(url, {
              headers: {
                'x-rapidapi-key': '1136d969acmsh09f0b7708001d5fp182010jsn7447ede24aae',
                'x-rapidapi-host': 'pinnacle-odds.p.rapidapi.com',
              },
              signal,
            });
            if (res.ok) {
              const data = await res.json();
              const specials = data.specials || data || [];
              return specials;
            }
            return [];
          } catch (err) {
            return [];
          }
        });

        const [fetchResults, specialMarketsResults] = await Promise.all([
          Promise.all(fetchPromises),
          Promise.all(specialMarketsPromises)
        ]);

        const allSpecialMarketsFiltered = {};
        specialMarketsResults.flat().forEach(special => {
          if (special.event_id) {
            const eventIdKey = String(special.event_id);
            if (!allSpecialMarketsFiltered[eventIdKey]) {
              allSpecialMarketsFiltered[eventIdKey] = [];
            }
            allSpecialMarketsFiltered[eventIdKey].push(special);
          }
        });

        // No verbose logging

        if (!signal.aborted) {
          const allMatches = fetchResults.flatMap(r => r.matches);
          const allEvents = fetchResults.flatMap(r => r.events); // Reuse events from first fetch

          const filteredMatches = allMatches.filter(match => {
            return match.leagueId && leagueIds.includes(match.leagueId);
          });

          // Create matches from special markets that don't exist in /markets endpoint
          const existingEventIds = new Set(filteredMatches.map(m => String(m.eventId)));
          const matchesFromSpecials = [];

          Object.keys(allSpecialMarketsFiltered).forEach(eventIdKey => {
            // Skip if we already have a match for this event
            if (existingEventIds.has(eventIdKey)) {
              return;
            }

            const specials = allSpecialMarketsFiltered[eventIdKey];
            if (!specials || specials.length === 0) {
              return;
            }

            // Extract event info from first special market
            const firstSpecial = specials[0];
            const eventId = parseInt(eventIdKey);
            const leagueId = firstSpecial.league_id;

            // Only create match if it's in the selected leagues
            if (!leagueId || !leagueIds.includes(leagueId)) {
              return;
            }

            // Check if any specials are open
            const hasOpenSpecials = specials.some(s => {
              const isOpen = s.open === true || s.status === 'O';
              return isOpen && s.lines && Object.keys(s.lines).length > 0;
            });

            // Only create match if there are open special markets
            if (!hasOpenSpecials) {
              return;
            }

            // Extract team names from event object in special market
            const eventInfo = firstSpecial.event || {};
            const homeTeam = eventInfo.home || 'Unknown Team';
            const awayTeam = eventInfo.away || 'Unknown Team';

            // Determine event type from live_status_id
            const liveStatusId = firstSpecial.live_status_id || 2;
            const eventType = liveStatusId === 1 ? 'live' : (liveStatusId === 3 ? 'finished' : 'prematch');

            // Format start time
            let startTime = 'TBD';
            if (firstSpecial.starts) {
              try {
                startTime = new Date(firstSpecial.starts).toLocaleString();
              } catch (e) {
                startTime = firstSpecial.starts;
              }
            }

            // Create a minimal match object from special markets
            const matchFromSpecial = {
              eventId: eventId,
              homeTeam: homeTeam,
              awayTeam: awayTeam,
              league: 'Unknown League',
              leagueId: leagueId,
              sportId: selectedSportId,
              eventType: eventType,
              startTime: startTime,
              liveScore: '-',
              matchStatus: liveStatusId,
              periodDescription: null,
              hasOpenMarkets: true,
              markets: {
                moneyLine: [],
                spreads: [],
                totals: [],
                teamTotals: [],
                playerProps: [],
                futures: [],
                teamProps: [],
                gameProps: []
              }
            };

            matchesFromSpecials.push(matchFromSpecial);
            // No verbose logging
          });

          // Combine matches from /markets endpoint with matches from special markets
          const allMatchesIncludingSpecials = [...filteredMatches, ...matchesFromSpecials];
          // No verbose logging

          // Process and merge special markets into matches (BEFORE The-Odds-API merge)
          const matchesWithSpecials = allMatchesIncludingSpecials.map(match => {
            // Match special markets by event_id (handle both string and number types)
            const eventIdKey = String(match.eventId);
            const specials = allSpecialMarketsFiltered[match.eventId] || allSpecialMarketsFiltered[eventIdKey] || [];
            if (specials.length === 0) return match;

            let specialsAdded = 0;
            specials.forEach(special => {
              // Check if special is open (can be 'open' boolean or 'status' === 'O')
              const isOpen = special.open === true || special.status === 'O';
              if (!special.lines || !isOpen) return;

              Object.values(special.lines).forEach(line => {
                if (!line.price) return;

                const specialMarket = {
                  bet: `${special.name} - ${line.name}`,
                  teamType: line.name,
                  price: line.price?.toFixed(3) || 'N/A',
                  line: line.handicap ? line.handicap.toString() : '',
                  status: 'Open',
                  period: 'Special', // Mark as Pinnacle special market
                  specialId: special.special_id,
                  betType: special.bet_type,
                  category: special.category
                };

                if (special.category === 'Player Props') {
                  match.markets.playerProps.push(specialMarket);
                  specialsAdded++;
                } else if (special.category === 'Team Props') {
                  match.markets.teamProps.push(specialMarket);
                  specialsAdded++;
                } else if (special.category === 'Game Props') {
                  match.markets.gameProps.push(specialMarket);
                  specialsAdded++;
                } else if (special.category === 'Futures') {
                  match.markets.futures.push(specialMarket);
                  specialsAdded++;
                }
              });
            });

            if (specialsAdded > 0) {
              // No verbose logging
            }

            return match;
          });

          // Filter: Only show matches that have at least one Pinnacle market (from periods OR special markets)
          const matchesWithPinnacleOdds = matchesWithSpecials.filter(match => {
            const pinnacleMarketsCount = countPinnacleMarkets(match);
            if (pinnacleMarketsCount === 0) {
              // No verbose logging
              return false;
            }
            return true;
          });

          // No verbose logging

          // Fetch The-Odds-API data for all events
          const theOddsApiMarkets = await fetchTheOddsApiData(selectedSportId, allEvents);

          // Merge The-Odds-API markets (supplemental only - requires Pinnacle markets)
          const matchesWithOddsApi = mergeTheOddsApiMarkets(matchesWithPinnacleOdds, theOddsApiMarkets);

          // Enrich matches with API-Football data (for all supported sports)
          const enriched = await enrichWithApiFootballData(matchesWithOddsApi, true);
          return enriched;
        }
        return [];
      }
    } catch (error) {
      if (error.name !== 'AbortError') {
        error('Error fetching matches:', error);
      }
      return [];
    } finally {
      if (!skipLoadingState && !signal.aborted) {
        setLoadingMatches(false);
      }
    }
  };

  // Fetch matches based on view mode (for filter changes)
  useEffect(() => {
    if (!selectedSportId || !isAuthenticated) {
      setMatches([]);
      previousMatchesRef.current = [];
      previousFiltersRef.current = null;
      return;
    }

    // Store current filters for stability check
    const currentFilters = {
      selectedSportId,
      selectedLeagues: selectedLeagues.map(l => l.pinnacleId).sort().join(','),
      viewMode,
      eventTypeFilter
    };
    previousFiltersRef.current = currentFilters;

    // Clear 422 cache when filters change (new filters might have matches)
    // This ensures initial fetches always try, even if previous filters had 422 errors
    error422CacheRef.current.clear();

    // Use AbortController to cancel previous requests
    const abortController = new AbortController();
    const signal = abortController.signal;

    const fetchMatches = async () => {
      const fetchedMatches = await fetchMatchesData(signal, false);

      if (!signal.aborted && fetchedMatches.length >= 0) {
        // Store previous matches for change detection (use enriched matches if available)
        const oldMatches = previousMatchesRef.current || [];

        // Detect changes and merge timestamps
        const changes = detectOddsChanges(oldMatches, fetchedMatches);
        const enrichedMatches = mergeUpdateTimestamps(oldMatches, fetchedMatches, changes);

        // Update ref AFTER enrichment so next comparison uses enriched data with timestamps
        previousMatchesRef.current = enrichedMatches;

        setMatches(enrichedMatches);
      }
    };

    // Increased debounce to 1000ms to prevent rapid successive requests
    const timeoutId = setTimeout(() => {
      fetchMatches();
    }, 1000); // 1000ms debounce (increased from 500ms)

    return () => {
      clearTimeout(timeoutId);
      abortController.abort(); // Cancel any in-flight requests
    };
  }, [selectedSportId, selectedLeagues, viewMode, eventTypeFilter, isAuthenticated]);

  // Page Visibility API - pause polling when tab is inactive
  useEffect(() => {
    const handleVisibilityChange = () => {
      if (typeof document === 'undefined') return;

      const isVisible = !document.hidden;
      setIsPageVisible(isVisible);

      // Debug logging for visibility changes (only in development)
      if (process.env.NODE_ENV === 'development') {
        // No verbose logging
      }

      // If page becomes visible and we have matches, resume polling
      if (isVisible && matches.length > 0 && selectedSportId && isAuthenticated) {
        // Polling will resume automatically via the polling useEffect
      } else if (!isVisible) {
        // Page is hidden - stop polling to save on rate limits
        if (pollingIntervalRef.current) {
          clearInterval(pollingIntervalRef.current);
          pollingIntervalRef.current = null;
          isPollingRef.current = false;
        }
      }
    };

    // Check initial visibility state (ensure it's correct on mount)
    const initialVisibility = typeof document !== 'undefined' ? !document.hidden : true;
    setIsPageVisible(initialVisibility);

    // Debug logging for visibility (only in development)
    if (process.env.NODE_ENV === 'development') {
      // No verbose logging
    }

    // Listen for visibility changes
    if (typeof document !== 'undefined') {
      document.addEventListener('visibilitychange', handleVisibilityChange);

      return () => {
        document.removeEventListener('visibilitychange', handleVisibilityChange);
      };
    }
  }, [matches.length, selectedSportId, isAuthenticated]);

  // Polling mechanism - poll every 5 seconds when filters are stable (background, no loading indicator)
  useEffect(() => {
    // Debug logging (only in development)
    if (process.env.NODE_ENV === 'development') {
      // No verbose logging
    }

    if (!selectedSportId || !isAuthenticated || matches.length === 0 || !isPageVisible) {
      // Clear polling if conditions not met OR page is not visible
      if (pollingIntervalRef.current) {
        if (process.env.NODE_ENV === 'development') {
          // No verbose logging
        }
        clearInterval(pollingIntervalRef.current);
        pollingIntervalRef.current = null;
        isPollingRef.current = false;
      }
      return;
    }

    // Check if filters are stable (same as previous)
    const currentFilters = {
      selectedSportId,
      selectedLeagues: selectedLeagues.map(l => l.pinnacleId).sort().join(','),
      viewMode,
      eventTypeFilter
    };

    const filtersChanged = !previousFiltersRef.current ||
      JSON.stringify(currentFilters) !== JSON.stringify(previousFiltersRef.current);

    if (filtersChanged) {
      // Filters changed - stop polling (will be restarted by filter change useEffect)
      if (pollingIntervalRef.current) {
        clearInterval(pollingIntervalRef.current);
        pollingIntervalRef.current = null;
        isPollingRef.current = false;
      }
      return;
    }

    // Filters are stable - start/continue polling
    if (isPollingRef.current) {
      return; // Already polling
    }

    isPollingRef.current = true;

    const pollMatches = async () => {
      // Skip if already fetching or in rate limit cooldown
      if (rateLimitCooldown && Date.now() < rateLimitCooldown) {
        return;
      }

      // Debug logging for polling (only in development)
      if (process.env.NODE_ENV === 'development') {
        // No verbose logging
      }

      try {
        const abortController = new AbortController();
        // Use skipLoadingState=true to prevent loading indicator during polling
        const fetchedMatches = await fetchMatchesData(abortController.signal, true);

        // During polling, handle different scenarios:
        // 1. Got matches: update normally
        // 2. Got null: preserve existing matches (all event types skipped due to 422)
        // 3. Got empty array: only clear if we don't have existing matches (filter change)
        if (fetchedMatches === null) {
          // All event types were skipped due to 422 cache - preserve existing matches
          // Don't update state, just return
          return;
        } else if (fetchedMatches && fetchedMatches.length > 0) {
          // Get current matches for comparison
          const oldMatches = previousMatchesRef.current || matches;

          // Debug logging for polling (only in development)
          if (oldMatches.length > 0 && fetchedMatches.length > 0) {
            const oldSample = oldMatches[0];
            const newSample = fetchedMatches[0];
            if (oldSample.eventId === newSample.eventId) {
              // Same match - compare values
              const oldSpread = oldSample.markets?.spreads?.[0];
              const newSpread = newSample.markets?.spreads?.[0];
              if (oldSpread && newSpread) {
                // Comparing spreads
              }
            }
          }

          // Detect changes
          const changes = detectOddsChanges(oldMatches, fetchedMatches);

          // Debug: Check if matches marked as updated actually have different values
          if (process.env.NODE_ENV === 'development' && changes.updatedMatches.size > 0) {
            const sampleEventId = Array.from(changes.updatedMatches.keys())[0];
            const oldMatch = oldMatches.find(m => m.eventId === sampleEventId);
            const newMatch = fetchedMatches.find(m => m.eventId === sampleEventId);
            if (oldMatch && newMatch) {
              // Compare a sample market (first money line)
              const oldML = oldMatch.markets?.moneyLine?.[0];
              const newML = newMatch.markets?.moneyLine?.[0];
              if (oldML && newML) {
                const oldPriceNorm = normalizePrice(oldML.price);
                const newPriceNorm = normalizePrice(newML.price);
              }
            }
          }

          // Merge timestamps
          const enrichedMatches = mergeUpdateTimestamps(oldMatches, fetchedMatches, changes);

          if (enrichedMatches.length > 0) {
            const enrichedSample = enrichedMatches[0];
          }

          // Update state silently (no loading indicator)
          setMatches(enrichedMatches);

          // Update ref for next comparison
          previousMatchesRef.current = enrichedMatches;
        } else if (fetchedMatches && fetchedMatches.length === 0 && matches.length === 0) {
          // Only update if we explicitly got empty array AND we don't have existing matches
          // This handles the case where filters change and there truly are no matches
          setMatches([]);
          previousMatchesRef.current = [];
        }
        // If fetchedMatches is empty but we have existing matches, don't update (likely 422 error)
      } catch (err) {
        error('Error during polling:', err);
        // On error, pause polling temporarily
        if (pollingIntervalRef.current) {
          clearInterval(pollingIntervalRef.current);
          pollingIntervalRef.current = null;
          isPollingRef.current = false;
        }
      }
    };

    // Start polling immediately, then every 5 seconds
    pollMatches();
    pollingIntervalRef.current = setInterval(pollMatches, 5000);

    // Debug logging (only in development)
    if (process.env.NODE_ENV === 'development') {
      // No verbose logging
    }

    return () => {
      if (pollingIntervalRef.current) {
        clearInterval(pollingIntervalRef.current);
        pollingIntervalRef.current = null;
        isPollingRef.current = false;
      }
    };
  }, [selectedSportId, selectedLeagues, viewMode, eventTypeFilter, isAuthenticated, matches.length, rateLimitCooldown, isPageVisible]);

  // Fetch matches based on view mode (for filter changes)
  useEffect(() => {
    if (!selectedSportId || !isAuthenticated) {
      setMatches([]);
      previousMatchesRef.current = [];
      previousFiltersRef.current = null;
      return;
    }

    // Store current filters for stability check
    const currentFilters = {
      selectedSportId,
      selectedLeagues: selectedLeagues.map(l => l.pinnacleId).sort().join(','),
      viewMode,
      eventTypeFilter
    };
    previousFiltersRef.current = currentFilters;

    // Clear 422 cache when filters change (new filters might have matches)
    // This ensures initial fetches always try, even if previous filters had 422 errors
    error422CacheRef.current.clear();

    // Use AbortController to cancel previous requests
    const abortController = new AbortController();
    const signal = abortController.signal;

    const fetchMatches = async () => {
      const fetchedMatches = await fetchMatchesData(signal, false);

      if (!signal.aborted && fetchedMatches.length >= 0) {
        // Store previous matches for change detection (use enriched matches if available)
        const oldMatches = previousMatchesRef.current || [];

        // Detect changes and merge timestamps
        const changes = detectOddsChanges(oldMatches, fetchedMatches);
        const enrichedMatches = mergeUpdateTimestamps(oldMatches, fetchedMatches, changes);

        // Update ref AFTER enrichment so next comparison uses enriched data with timestamps
        previousMatchesRef.current = enrichedMatches;

        setMatches(enrichedMatches);
      }
    };

    // Increased debounce to 1000ms to prevent rapid successive requests
    const timeoutId = setTimeout(() => {
      fetchMatches();
    }, 1000); // 1000ms debounce (increased from 500ms)

    return () => {
      clearTimeout(timeoutId);
      abortController.abort(); // Cancel any in-flight requests
    };
  }, [selectedSportId, selectedLeagues, viewMode, eventTypeFilter, isAuthenticated]);

  // Close dropdowns when clicking outside
  useEffect(() => {
    if (!showLeagueDropdown && !showTeamDropdown) {
      return;
    }

    const handleClickOutside = (event) => {
      if (!event.target.closest('.relative')) {
        setShowLeagueDropdown(false);
        setShowTeamDropdown(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [showLeagueDropdown, showTeamDropdown]);

  // Common league abbreviations mapping with priority scoring
  const leagueAbbreviations = {
    'epl': {
      variations: ['english premier league', 'england - premier league', 'england premier league', 'premier league'],
      priorityKeywords: ['england', 'english'],
      oddsApiKey: 'soccer_epl'
    },
    'nba': {
      variations: ['national basketball association', 'nba'],
      priorityKeywords: ['nba'],
      oddsApiKey: 'basketball_nba'
    },
    'nfl': {
      variations: ['national football league', 'nfl'],
      priorityKeywords: ['nfl'],
      oddsApiKey: 'americanfootball_nfl'
    },
    'mlb': {
      variations: ['major league baseball', 'mlb'],
      priorityKeywords: ['mlb'],
      oddsApiKey: 'baseball_mlb'
    },
    'nhl': {
      variations: ['national hockey league', 'nhl'],
      priorityKeywords: ['nhl'],
      oddsApiKey: 'icehockey_nhl'
    },
    'la liga': {
      variations: ['spain la liga', 'spanish la liga', 'laliga', 'spain - la liga'],
      priorityKeywords: ['spain', 'spanish'],
      oddsApiKey: 'soccer_spain_la_liga'
    },
    'serie a': {
      variations: ['italy serie a', 'italian serie a', 'italy - serie a'],
      priorityKeywords: ['italy', 'italian'],
      oddsApiKey: 'soccer_italy_serie_a'
    },
    'bundesliga': {
      variations: ['germany bundesliga', 'german bundesliga', 'germany - bundesliga'],
      priorityKeywords: ['germany', 'german'],
      oddsApiKey: 'soccer_germany_bundesliga'
    },
    'ligue 1': {
      variations: ['france ligue 1', 'french ligue 1', 'france - ligue 1'],
      priorityKeywords: ['france', 'french'],
      oddsApiKey: 'soccer_france_ligue_one'
    },
    'champions league': {
      variations: ['uefa champions league', 'champions league'],
      priorityKeywords: ['uefa', 'champions'],
      oddsApiKey: 'soccer_uefa_champions_league'
    },
    'europa league': {
      variations: ['uefa europa league', 'europa league'],
      priorityKeywords: ['uefa', 'europa'],
      oddsApiKey: 'soccer_uefa_europa_league'
    },
  };

  // Score league match for ranking (higher = better match)
  const scoreLeagueMatch = (league, searchTerm) => {
    if (!searchTerm) return 0;

    const search = searchTerm.toLowerCase().trim();
    const leagueName = league.name.toLowerCase();
    const oddsApiKey = (league.oddsApiKey || '').toLowerCase();
    let score = 0;

    // Exact match in name (highest priority)
    if (leagueName === search) return 1000;

    // Exact match in oddsApiKey (very high priority)
    if (oddsApiKey === search) return 900;

    // Check if search matches an abbreviation
    for (const [abbr, config] of Object.entries(leagueAbbreviations)) {
      const abbrLower = abbr.toLowerCase();
      if (search === abbrLower || search === abbrLower.replace(' ', '')) {
        // Check oddsApiKey match (highest priority for abbreviations)
        if (config.oddsApiKey && oddsApiKey === config.oddsApiKey) {
          return 800;
        }

        // Check if league name contains priority keywords
        const hasPriorityKeyword = config.priorityKeywords.some(keyword =>
          leagueName.includes(keyword.toLowerCase())
        );

        // Check if league name contains any variation
        const hasVariation = config.variations.some(variation =>
          leagueName.includes(variation.toLowerCase())
        );

        if (hasPriorityKeyword && hasVariation) {
          return 700; // High score for abbreviation match with priority keywords
        } else if (hasVariation) {
          return 500; // Medium score for abbreviation match
        }
      }
    }

    // Check oddsApiKey contains search (high priority)
    if (oddsApiKey && oddsApiKey.includes(search)) {
      score += 600;
    }

    // Name starts with search (high priority)
    if (leagueName.startsWith(search)) {
      score += 400;
    }

    // Name contains search (medium priority)
    if (leagueName.includes(search)) {
      score += 200;
    }

    // Word-based matching
    const searchWords = search.split(/\s+/).filter(w => w.length > 0);
    if (searchWords.length > 0) {
      const allWordsMatch = searchWords.every(word => {
        if (leagueName.includes(word)) return true;

        // Check abbreviation variations
        for (const [abbr, config] of Object.entries(leagueAbbreviations)) {
          if (word === abbr.toLowerCase() || word === abbr.toLowerCase().replace(' ', '')) {
            return config.variations.some(variation => leagueName.includes(variation.toLowerCase()));
          }
        }
        return false;
      });

      if (allWordsMatch) {
        score += 100;
      }
    }

    // Partial word matching
    const leagueWords = leagueName.split(/\s+/);
    if (leagueWords.some(word => word.startsWith(search))) {
      score += 50;
    }

    return score;
  };

  // Enhanced league search function
  const matchesLeagueSearch = (league, searchTerm) => {
    return scoreLeagueMatch(league, searchTerm) > 0;
  };

  // Enhanced team search function
  const matchesTeamSearch = (team, searchTerm) => {
    if (!searchTerm) return true;

    const search = searchTerm.toLowerCase().trim();
    const teamName = team.name.toLowerCase();
    const pinnacleName = (team.pinnacleName || '').toLowerCase();
    const oddsApiName = (team.oddsApiName || '').toLowerCase();
    const apiFootballName = (team.apiFootballName || '').toLowerCase();

    // Check all name variations
    if (teamName.includes(search)) return true;
    if (pinnacleName.includes(search)) return true;
    if (oddsApiName.includes(search)) return true;
    if (apiFootballName.includes(search)) return true;

    // Word-based matching
    const searchWords = search.split(/\s+/).filter(w => w.length > 0);
    if (searchWords.length > 0) {
      const allNames = [teamName, pinnacleName, oddsApiName, apiFootballName].join(' ');
      const allWordsMatch = searchWords.every(word => allNames.includes(word));
      if (allWordsMatch) return true;
    }

    // Partial word matching
    const allNames = [teamName, pinnacleName, oddsApiName, apiFootballName].join(' ');
    const nameWords = allNames.split(/\s+/);
    if (nameWords.some(word => word.startsWith(search))) return true;

    return false;
  };

  // Filter and sort leagues based on enhanced search with ranking
  const filteredLeagues = leagues
    .filter(league => matchesLeagueSearch(league, leagueSearch))
    .sort((a, b) => {
      const scoreA = scoreLeagueMatch(a, leagueSearch);
      const scoreB = scoreLeagueMatch(b, leagueSearch);
      return scoreB - scoreA; // Sort by score descending (highest first)
    });

  // Filter teams based on enhanced search
  const filteredTeams = teams.filter(team =>
    matchesTeamSearch(team, teamSearch)
  );

  // Handle league selection
  const handleLeagueSelect = (league) => {
    if (selectedLeagues.find(l => l.id === league.id)) {
      setSelectedLeagues(selectedLeagues.filter(l => l.id !== league.id));
    } else {
      setSelectedLeagues([...selectedLeagues, league]);
      // Switch to filtered mode when a league is selected
      setViewMode('filtered');
    }
    setLeagueSearch('');
    setShowLeagueDropdown(false);
  };

  // Handle team selection
  const handleTeamSelect = (team) => {
    if (selectedTeams.find(t => t.id === team.id)) {
      setSelectedTeams(selectedTeams.filter(t => t.id !== team.id));
    } else {
      setSelectedTeams([...selectedTeams, team]);
    }
    setTeamSearch('');
    setShowTeamDropdown(false);
  };

  // Handle remove team
  const handleRemoveTeam = (teamId) => {
    setSelectedTeams(selectedTeams.filter(t => t.id !== teamId));
  };

  // Handle clear all
  const handleClearAll = () => {
    setSelectedLeagues([]);
    setSelectedTeams([]);
    setLeagueSearch('');
    setTeamSearch('');
    setViewMode('filtered');
    setEventTypeFilter('both');
  };

  // Show dropdown on focus
  const handleLeagueFocus = () => {
    if (selectedSportId && leagues.length > 0) {
      setShowLeagueDropdown(true);
    }
  };

  const handleTeamFocus = () => {
    if (selectedLeagues.length > 0 && teams.length > 0) {
      setShowTeamDropdown(true);
    }
  };

  return (
    <>
      {!isAuthenticated ? (
        <div className="min-h-screen bg-gray-50 flex items-center justify-center">
          <Head>
            <title>Sports Feed - Access Required</title>
          </Head>

          <div className="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">
            <div className="text-center mb-6">
              <h1 className="text-2xl font-bold text-gray-900 mb-2">Sports Feed Dashboard</h1>
              <p className="text-gray-600">Enter password to access live odds</p>
            </div>

            <form onSubmit={handleLogin} className="space-y-4">
              <div>
                <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-1">
                  Password
                </label>
                <input
                  type="password"
                  id="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Enter dashboard password"
                  required
                />
              </div>

              <button type="submit" className="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                Access Dashboard
              </button>
            </form>
          </div>
        </div>
      ) : (
        <div className="min-h-screen bg-gray-50">
          <Head>
            <title>Sports Feed - Filter Dashboard</title>
            <style jsx global>{`
          select {
            background-color: white !important;
            color: black !important;
          }
          select option {
            background-color: white !important;
            color: black !important;
          }
          select:focus {
            background-color: white !important;
            color: black !important;
          }
        `}</style>
          </Head>

          <div className="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
            {/* Header */}
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
              <div className="px-6 py-6 border-b border-gray-200">
                <div className="flex items-center justify-between">
                  <div>
                    <h1 className="text-2xl font-bold text-gray-900">Sports Feed</h1>
                    <p className="text-sm text-gray-600 mt-1">Filter by Sport and League to view live odds</p>
                  </div>
                  <div className="flex gap-3">
                    <button
                      onClick={() => setShowSportsModal(true)}
                      className="px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors"
                    >
                      View All Sports
                    </button>
                    <button
                      onClick={() => setShowBetTypesModal(true)}
                      className="px-4 py-2 text-sm font-medium text-purple-600 bg-purple-50 border border-purple-200 rounded-lg hover:bg-purple-100 transition-colors"
                    >
                      View All Bet Types
                    </button>
                  </div>
                </div>
              </div>

              {/* Filter Section */}
              <div className="px-6 py-6 bg-gray-50">
                {/* View Mode Selection - Show when sport is selected */}
                {selectedSportId && (
                  <div className="mb-6 p-4 bg-white rounded-lg border border-gray-200">
                    <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-3">
                      View Options
                    </label>
                    <div className="flex flex-wrap gap-3">
                      <button
                        onClick={() => {
                          setViewMode('filtered');
                          setSelectedLeagues([]);
                          setSelectedTeams([]);
                        }}
                        className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${viewMode === 'filtered'
                          ? 'bg-purple-600 text-white hover:bg-purple-700'
                          : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                          }`}
                      >
                        Filter by League
                      </button>
                      <button
                        onClick={() => {
                          setViewMode('all');
                          setEventTypeFilter('live');
                          setSelectedLeagues([]);
                          setSelectedTeams([]);
                        }}
                        className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${viewMode === 'all' && eventTypeFilter === 'live'
                          ? 'bg-red-600 text-white hover:bg-red-700'
                          : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                          }`}
                      >
                        Show All Live Matches
                      </button>
                      <button
                        onClick={() => {
                          setViewMode('all');
                          setEventTypeFilter('prematch');
                          setSelectedLeagues([]);
                          setSelectedTeams([]);
                        }}
                        className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${viewMode === 'all' && eventTypeFilter === 'prematch'
                          ? 'bg-green-600 text-white hover:bg-green-700'
                          : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                          }`}
                      >
                        Show All Prematch Matches
                      </button>
                      <button
                        onClick={() => {
                          setViewMode('all');
                          setEventTypeFilter('both');
                          setSelectedLeagues([]);
                          setSelectedTeams([]);
                        }}
                        className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${viewMode === 'all' && eventTypeFilter === 'both'
                          ? 'bg-blue-600 text-white hover:bg-blue-700'
                          : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                          }`}
                      >
                        Show All Matches (Live + Prematch)
                      </button>
                    </div>
                    {viewMode === 'all' && (
                      <p className="mt-3 text-xs text-gray-600">
                        Showing all matches for {sports.find(s => s.pinnacleId === selectedSportId)?.name || 'selected sport'} across all leagues
                      </p>
                    )}
                  </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                  {/* Sport Filter - Dropdown */}
                  <div className="relative">
                    <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-2">
                      Sport
                    </label>
                    <select
                      value={selectedSportId || ''}
                      onChange={(e) => {
                        const newSportId = e.target.value ? parseInt(e.target.value) : null;
                        setSelectedSportId(newSportId);
                        setSelectedLeagues([]);
                        setSelectedTeams([]);
                        // Reset to 'filtered' mode when sport changes
                        if (newSportId) {
                          setViewMode('filtered');
                          setEventTypeFilter('both');
                        }
                      }}
                      className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm text-black bg-white appearance-none"
                      style={{ backgroundColor: 'white', color: 'black' }}
                      disabled={loading || !isAuthenticated}
                    >
                      <option value="" style={{ backgroundColor: 'white', color: 'black' }}>
                        {loading ? 'Loading sports...' : !isAuthenticated ? 'Please authenticate first' : 'Select a sport'}
                      </option>
                      {sports.map((sport) => (
                        <option key={sport.id} value={sport.pinnacleId} style={{ backgroundColor: 'white', color: 'black' }}>
                          {sport.name}
                        </option>
                      ))}
                    </select>
                  </div>

                  {/* League Filter - Search + Multi-select */}
                  <div className="relative">
                    <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-2">
                      League {viewMode === 'filtered' && <span className="text-gray-500">(Required for filtering)</span>}
                    </label>
                    <input
                      type="text"
                      value={leagueSearch}
                      onChange={(e) => {
                        setLeagueSearch(e.target.value);
                        setShowLeagueDropdown(true);
                      }}
                      onFocus={handleLeagueFocus}
                      className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm text-black placeholder-gray-400 bg-white disabled:bg-gray-100 disabled:cursor-not-allowed"
                      style={{ backgroundColor: 'white', color: 'black' }}
                      placeholder={viewMode === 'all' ? "Not needed - showing all matches" : "Search and add leagues"}
                      disabled={!selectedSportId || viewMode === 'all'}
                    />
                    {showLeagueDropdown && (
                      filteredLeagues.length > 0 ? (
                        <div className="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-64 overflow-y-auto" style={{ backgroundColor: 'white' }}>
                          {filteredLeagues.map((league) => (
                            <div
                              key={league.id}
                              onClick={() => handleLeagueSelect(league)}
                              className={`px-4 py-2 hover:bg-gray-100 cursor-pointer ${selectedLeagues.find(l => l.id === league.id) ? 'bg-blue-50' : 'bg-white'
                                }`}
                              style={{
                                backgroundColor: selectedLeagues.find(l => l.id === league.id) ? '#eff6ff' : 'white',
                                color: 'black'
                              }}
                            >
                              <span style={{ color: 'black' }}>{league.name}</span>
                            </div>
                          ))}
                        </div>
                      ) : (
                        <div className="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg p-4" style={{ backgroundColor: 'white' }}>
                          {leagues.length === 0 ? (
                            <p className="text-sm text-gray-500">No leagues found. {selectedSportId ? 'Loading...' : 'Select a sport first.'}</p>
                          ) : (
                            <p className="text-sm text-gray-500">No leagues match "{leagueSearch}"</p>
                          )}
                        </div>
                      )
                    )}
                    <div className="mt-2 flex flex-wrap gap-2">
                      {selectedLeagues.map((league) => (
                        <span
                          key={league.id}
                          className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800"
                        >
                          {league.name}
                          <button
                            onClick={() => setSelectedLeagues(selectedLeagues.filter(l => l.id !== league.id))}
                            className="ml-2 text-blue-600 hover:text-blue-800"
                          >
                            ×
                          </button>
                        </span>
                      ))}
                    </div>
                  </div>

                </div>
              </div>
            </div>

            {/* Matches Section */}
            <div className="bg-white rounded-lg shadow-sm border border-gray-200">
              <div className="px-6 py-4 border-b border-gray-200">
                <div className="flex items-center justify-between">
                  <div>
                    <h2 className="text-lg font-semibold text-gray-900">Matches</h2>
                    <p className="text-sm text-gray-600 mt-1">
                      {loadingMatches ? (
                        'Loading matches...'
                      ) : viewMode === 'all' ? (
                        `${matches.length} ${eventTypeFilter === 'both' ? 'total' : eventTypeFilter} matches found across all leagues`
                      ) : (
                        `${matches.length} matches found${selectedTeams.length > 0 ? ` for selected teams` : ''}`
                      )}
                    </p>
                  </div>
                </div>
              </div>

              <div className="p-6">
                {loadingMatches ? (
                  <div className="text-center py-12">
                    <div className="text-gray-400 mb-3">
                      <svg className="animate-spin h-8 w-8 mx-auto" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                    </div>
                    <p className="text-sm font-medium text-gray-900">Loading matches...</p>
                  </div>
                ) : matches.length === 0 ? (
                  <div className="text-center py-12">
                    <div className="text-gray-400 mb-3">
                      <svg className="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                      </svg>
                    </div>
                    <p className="text-sm font-medium text-gray-900">No matches found</p>
                    <p className="text-xs text-gray-500 mt-1">
                      {viewMode === 'all'
                        ? `No ${eventTypeFilter === 'both' ? '' : eventTypeFilter} matches available for ${sports.find(s => s.pinnacleId === selectedSportId)?.name || 'this sport'} right now. Try selecting a different event type or sport.`
                        : 'Select leagues above to view their matches and odds'}
                    </p>
                  </div>
                ) : (
                  <div className="space-y-4">
                    {matches && matches.length > 0 ? (
                      matches.map((match) => (
                        <MatchCard key={match.eventId || match.id || Math.random()} match={match} />
                      ))
                    ) : (
                      <p className="text-sm text-gray-500 text-center py-8">No matches found</p>
                    )}
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* Sports Modal */}
          {showSportsModal && (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" onClick={() => setShowSportsModal(false)}>
              <div className="bg-white rounded-lg shadow-xl w-full max-w-6xl max-h-[90vh] mx-4 flex flex-col" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between p-6 border-b border-gray-200">
                  <h2 className="text-xl font-semibold text-gray-900">All Available Sports</h2>
                  <button
                    onClick={() => setShowSportsModal(false)}
                    className="text-gray-400 hover:text-gray-600 text-2xl font-bold leading-none"
                  >
                    ×
                  </button>
                </div>
                <div className="flex-1 overflow-hidden p-6">
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6 h-full">
                    <div className="overflow-y-auto pr-2">
                      <h3 className="font-semibold mb-3 text-gray-700">Sports</h3>
                      <div className="space-y-1">
                        {loading ? (
                          <p className="text-sm text-gray-500">Loading sports...</p>
                        ) : sports.length > 0 ? (
                          sports.map((sport) => (
                            <div
                              key={sport.id}
                              onClick={() => {
                                setSelectedSportId(sport.pinnacleId);
                                setModalSelectedSport(sport);
                                setSelectedLeagues([]);
                                setSelectedTeams([]);
                                setViewMode('filtered');
                                setModalLeagueSearch(''); // Reset search when sport changes
                              }}
                              className={`p-3 rounded-lg cursor-pointer transition-colors ${selectedSportId === sport.pinnacleId
                                ? 'bg-blue-100 text-blue-900 font-medium'
                                : 'hover:bg-gray-100 text-gray-700'
                                }`}
                            >
                              {sport.name}
                            </div>
                          ))
                        ) : (
                          <div>
                            <p className="text-sm text-gray-500">No sports available</p>
                            {!isAuthenticated && (
                              <p className="text-xs text-red-500 mt-1">Please authenticate first</p>
                            )}
                          </div>
                        )}
                      </div>
                    </div>
                    <div className="flex flex-col h-full min-h-0">
                      <div className="mb-3 flex-shrink-0">
                        <h3 className="font-semibold mb-2 text-gray-700">
                          Leagues
                          {loadingModalLeagues && <span className="ml-2 text-xs text-gray-500">(Loading...)</span>}
                        </h3>
                        {modalSelectedSport && (
                          <input
                            type="text"
                            value={modalLeagueSearch}
                            onChange={(e) => setModalLeagueSearch(e.target.value)}
                            placeholder="Search leagues..."
                            className="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-gray-900 placeholder-gray-400"
                          />
                        )}
                      </div>
                      <div className="flex-1 overflow-y-auto pr-2 min-h-0" style={{ maxHeight: 'calc(90vh - 300px)' }}>
                        {modalSelectedSport ? (
                          <div className="space-y-1">
                            {loadingModalLeagues ? (
                              <p className="text-sm text-gray-500">Loading leagues...</p>
                            ) : (() => {
                              const filteredLeagues = modalLeagueSearch
                                ? modalLeagues.filter(league =>
                                  league.name.toLowerCase().includes(modalLeagueSearch.toLowerCase())
                                )
                                : modalLeagues;

                              return filteredLeagues.length > 0 ? (
                                filteredLeagues.map((league) => (
                                  <div
                                    key={league.id}
                                    onClick={() => {
                                      setModalSelectedLeague(league);
                                      // Also add to selected leagues if not already selected
                                      if (!selectedLeagues.find(l => l.pinnacleId === league.pinnacleId)) {
                                        setSelectedLeagues([...selectedLeagues, league]);
                                      }
                                    }}
                                    className={`p-3 rounded-lg cursor-pointer transition-colors ${selectedLeagues.find(l => l.pinnacleId === league.pinnacleId)
                                      ? 'bg-blue-100 text-blue-900 font-medium'
                                      : modalSelectedLeague?.pinnacleId === league.pinnacleId
                                        ? 'bg-gray-200 text-gray-900'
                                        : 'hover:bg-gray-100 text-gray-700'
                                      }`}
                                  >
                                    {league.name}
                                  </div>
                                ))
                              ) : modalLeagueSearch ? (
                                <p className="text-sm text-gray-500">No leagues match "{modalLeagueSearch}"</p>
                              ) : (
                                <p className="text-sm text-gray-500">Off season currently - no active leagues available</p>
                              );
                            })()}
                          </div>
                        ) : (
                          <p className="text-sm text-gray-500">Select a sport to view leagues</p>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
                <div className="p-6 border-t border-gray-200 flex justify-end">
                  <button
                    onClick={() => {
                      setShowSportsModal(false);
                      setModalSelectedSport(null);
                      setModalSelectedLeague(null);
                      setModalLeagues([]);
                      setModalLeagueSearch('');
                    }}
                    className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                  >
                    Done
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Bet Types Modal */}
          {showBetTypesModal && (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" onClick={() => setShowBetTypesModal(false)}>
              <div className="bg-white rounded-lg shadow-xl w-full max-w-6xl max-h-[90vh] mx-4 flex flex-col" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between p-6 border-b border-gray-200">
                  <h2 className="text-xl font-semibold text-gray-900">All Available Bet Types</h2>
                  <button
                    onClick={() => setShowBetTypesModal(false)}
                    className="text-gray-400 hover:text-gray-600 text-2xl font-bold leading-none"
                  >
                    ×
                  </button>
                </div>
                <div className="flex-1 overflow-hidden p-6 flex flex-col min-h-0">
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6 flex-1 min-h-0">
                    <div className="overflow-y-auto pr-2 border-r border-gray-200 min-h-0">
                      <h3 className="font-semibold mb-3 text-gray-700 sticky top-0 bg-white pb-2">Select Sport</h3>
                      <div className="space-y-1">
                        {loading ? (
                          <p className="text-sm text-gray-500">Loading sports...</p>
                        ) : sports.length > 0 ? (
                          sports.map((sport) => (
                            <div
                              key={sport.id}
                              onClick={() => {
                                setSelectedSportId(sport.pinnacleId);
                                setModalSelectedSport(sport);
                              }}
                              className={`p-3 rounded-lg cursor-pointer transition-colors ${selectedSportId === sport.pinnacleId
                                ? 'bg-blue-100 text-blue-900 font-medium'
                                : 'hover:bg-gray-100 text-gray-700'
                                }`}
                            >
                              {sport.name}
                            </div>
                          ))
                        ) : (
                          <div>
                            <p className="text-sm text-gray-500">No sports available</p>
                            {!isAuthenticated && (
                              <p className="text-xs text-red-500 mt-1">Please authenticate first</p>
                            )}
                          </div>
                        )}
                      </div>
                    </div>
                    <div className="overflow-y-auto pr-2 min-h-0 flex flex-col">
                      <h3 className="font-semibold mb-2 text-gray-700 text-sm sticky top-0 bg-white pb-2 z-10">
                        Bet Types
                        {selectedSportId && (
                          <span className="ml-2 text-xs font-normal text-gray-500">
                            for {sports.find(s => s.pinnacleId === selectedSportId)?.name || 'selected sport'}
                          </span>
                        )}
                      </h3>
                      <div className="flex-1 overflow-y-auto">
                        {selectedSportId ? (
                          betTypes.categories && Object.keys(betTypes.categories).length > 0 ? (
                            <div className="space-y-3">
                              {Object.entries(betTypes.categories).map(([category, types]) => (
                                <div key={category}>
                                  <h4 className="font-semibold mb-2 text-gray-700 text-sm">{category}</h4>
                                  <div className="space-y-1">
                                    {types.map((type) => (
                                      <div
                                        key={type.id}
                                        className="p-2 bg-gray-50 rounded border border-gray-200 hover:bg-gray-100 transition-colors"
                                      >
                                        <div className="font-medium text-gray-900 text-sm">{type.name}</div>
                                        {type.description && (
                                          <div className="text-xs text-gray-600 mt-0.5">{type.description}</div>
                                        )}
                                      </div>
                                    ))}
                                  </div>
                                </div>
                              ))}
                            </div>
                          ) : (
                            <div className="text-center py-8">
                              <p className="text-sm text-gray-500">
                                No bet types available for {sports.find(s => s.pinnacleId === selectedSportId)?.name || 'the selected sport'}
                              </p>
                            </div>
                          )
                        ) : (
                          <div className="text-center py-8">
                            <p className="text-sm text-gray-500">
                              Select a sport to view its bet types
                            </p>
                            <p className="text-xs text-gray-400 mt-2">
                              Bet types are specific to each sport
                            </p>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
                <div className="p-6 border-t border-gray-200 flex justify-end">
                  <button
                    onClick={() => {
                      setShowBetTypesModal(false);
                      setModalSelectedSport(null);
                    }}
                    className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                  >
                    Close
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      )}
    </>
  );
}

