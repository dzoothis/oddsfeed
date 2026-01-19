import { createRouter, createWebHistory } from 'vue-router'
import Login from '../views/Login.vue'
import FilterDashboard from '../views/FilterDashboard.vue'
import BetTypes from '../components/BetTypes.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'login',
      component: Login
    },
    {
      path: '/filter-dashboard',
      name: 'dashboard',
      component: FilterDashboard,
      meta: { requiresAuth: true }
    },
    {
      path: '/bet-types',
      name: 'BetTypes',
      component: BetTypes
    }
  ]
})

router.beforeEach((to, from, next) => {
  const isAuthenticated = localStorage.getItem('isAuthenticated') === 'true';
  if (to.meta.requiresAuth && !isAuthenticated) {
    next('/');
  } else if (to.path === '/' && isAuthenticated) {
    next('/filter-dashboard');
  } else {
    next();
  }
})

export default router
