<template>
  <span v-if="shouldShow" class="text-sm font-semibold text-red-500 ml-2">
    ⏱️ {{ formattedTime }}
  </span>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({
  match: {
    type: Object,
    required: true
  }
});

const elapsed = ref(null);
const extra = ref(null);
const isStopped = ref(false);
const intervalId = ref(null);
const secondsCounter = ref(0);

const shouldShow = computed(() => {
  return elapsed.value !== null && props.match.eventType === 'live';
});

const formattedTime = computed(() => {
  if (elapsed.value === null) return null;
  const elapsedMinutes = Math.floor(elapsed.value);
  const displayExtra = extra.value && extra.value > 0 ? `+${Math.floor(extra.value)}` : '';
  return `${elapsedMinutes}'${displayExtra}`;
});

const initializeClock = () => {
  if (props.match.eventType !== 'live') {
    elapsed.value = null;
    extra.value = null;
    isStopped.value = true;
    return;
  }

  if (props.match.apiFootballData) {
    const apiElapsed = props.match.apiFootballData.elapsed;
    const apiExtra = props.match.apiFootballData.extra;
    const status = props.match.apiFootballData.status;

    if (status?.long === 'Match Finished' || status?.long === 'Finished' || status?.short === 'FT') {
      isStopped.value = true;
      elapsed.value = apiElapsed || 90;
      extra.value = apiExtra || 0;
      return;
    }

    if (apiElapsed !== null && apiElapsed !== undefined) {
      elapsed.value = apiElapsed;
      extra.value = apiExtra || 0;
      isStopped.value = false;
      return;
    }
  }

  if (props.match.startTime) {
    try {
      const startTime = new Date(props.match.startTime);
      if (!isNaN(startTime.getTime())) {
        const now = new Date();
        const diffMinutes = Math.floor((now - startTime) / (1000 * 60));
        
        if (diffMinutes >= 0 && diffMinutes <= 120) {
          elapsed.value = Math.max(0, diffMinutes);
          extra.value = 0;
          isStopped.value = false;
          return;
        }
      }
    } catch (e) {}
  }

  elapsed.value = null;
  extra.value = null;
  isStopped.value = true;
};

const startTimer = () => {
  if (isStopped.value || elapsed.value === null) {
    if (intervalId.value) {
      clearInterval(intervalId.value);
      intervalId.value = null;
    }
    return;
  }

  secondsCounter.value = 0;
  intervalId.value = setInterval(() => {
    secondsCounter.value += 1;
    if (secondsCounter.value >= 60) {
      secondsCounter.value = 0;
      if (elapsed.value !== null) {
        elapsed.value += 1;
      }
    }
  }, 1000);
};

watch(() => props.match, () => {
  initializeClock();
  startTimer();
}, { deep: true });

onMounted(() => {
  initializeClock();
  startTimer();
});

onUnmounted(() => {
  if (intervalId.value) clearInterval(intervalId.value);
});
</script>
