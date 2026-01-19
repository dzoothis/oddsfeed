<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center">
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
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';

const password = ref('');
const router = useRouter();
const DASHBOARD_PASSWORD = 'sportsfeed2025';

const handleLogin = () => {
  if (password.value === DASHBOARD_PASSWORD) {
    localStorage.setItem('isAuthenticated', 'true');
    router.push('/filter-dashboard');
  } else {
    alert('Invalid password');
  }
};
</script>
