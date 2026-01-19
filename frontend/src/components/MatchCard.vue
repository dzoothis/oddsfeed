<template>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-md transition-shadow duration-200">
    <!-- Header -->
    <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex justify-between items-center">
      <div class="flex items-center gap-2">
        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
          {{ match.leagueName || 'League' }}
        </span>
        <span v-if="match.eventType === 'live'" class="flex items-center gap-1 px-2 py-0.5 bg-red-100 text-red-700 text-xs font-bold rounded-full animate-pulse">
          <span class="w-1.5 h-1.5 bg-red-600 rounded-full"></span>
          LIVE
        </span>
      </div>
      <div class="text-xs text-gray-500 flex items-center">
        {{ formatDate(match.startTime) }}
        <RunningClock :match="match" />
      </div>
    </div>

    <!-- Match Content -->
    <div class="p-4">
      <div class="flex justify-between items-center mb-6">
        <!-- Home Team -->
        <div class="flex-1 text-center">
          <div class="font-bold text-gray-900 text-lg mb-1">{{ match.homeTeam }}</div>
          <div v-if="match.liveScore" class="text-3xl font-bold text-gray-800">
            {{ getScore(match.liveScore, 'home') }}
          </div>
        </div>

        <!-- VS / Time -->
        <div class="px-4 text-center">
          <span class="text-xs font-bold text-gray-400 uppercase">VS</span>
        </div>

        <!-- Away Team -->
        <div class="flex-1 text-center">
          <div class="font-bold text-gray-900 text-lg mb-1">{{ match.awayTeam }}</div>
          <div v-if="match.liveScore" class="text-3xl font-bold text-gray-800">
            {{ getScore(match.liveScore, 'away') }}
          </div>
        </div>
      </div>

      <!-- Expandable Markets -->
      <div class="border-t border-gray-100 pt-4">
        <button 
          @click="expanded = !expanded"
          class="w-full flex items-center justify-center gap-2 text-sm text-blue-600 hover:text-blue-800 font-medium transition-colors"
        >
          <span>{{ expanded ? 'Hide Markets' : 'Show Markets' }}</span>
          <svg 
            class="w-4 h-4 transition-transform duration-200"
            :class="{ 'rotate-180': expanded }"
            fill="none" 
            stroke="currentColor" 
            viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>

        <div v-if="expanded" class="mt-4">
          <MarketTabs :match="match" :sportId="match.sportId" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import RunningClock from './RunningClock.vue';
import MarketTabs from './MarketTabs.vue';

const props = defineProps({
  match: { type: Object, required: true }
});

const expanded = ref(false);

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return new Intl.DateTimeFormat('en-US', {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
  }).format(date);
};

const getScore = (scoreString, team) => {
  if (!scoreString) return '0';
  const parts = scoreString.split('-');
  if (parts.length !== 2) return '0';
  return team === 'home' ? parts[0].trim() : parts[1].trim();
};
</script>
