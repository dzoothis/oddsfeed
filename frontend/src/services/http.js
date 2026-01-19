import axios from 'axios';

// Create axios instance with base configuration
const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
});

// Request interceptor for authorization token injection
http.interceptors.request.use(
  (config) => {
    // Add authorization header if token exists
    const token = localStorage.getItem('auth_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }

    // Log requests in development
    if (import.meta.env.DEV) {
      console.log('API Request:', config.method?.toUpperCase(), config.url);
    }

    return config;
  },
  (error) => {
    console.error('Request interceptor error:', error);
    return Promise.reject(error);
  }
);

// Response interceptor for error handling
http.interceptors.response.use(
  (response) => {
    // Log responses in development
    if (import.meta.env.DEV) {
      console.log('API Response:', response.status, response.config.url);
    }

    return response;
  },
  (error) => {
    // Handle different types of errors
    if (error.response) {
      // Server responded with error status
      const { status, data } = error.response;

      if (import.meta.env.DEV) {
        console.error('API Error Response:', status, data);
      }

      switch (status) {
        case 401:
          // Unauthorized - clear auth token and redirect to login
          localStorage.removeItem('auth_token');
          localStorage.removeItem('isAuthenticated');
          // You might want to redirect to login page here
          break;
        case 403:
          // Forbidden
          console.error('Access forbidden:', data.message || 'You do not have permission to access this resource');
          break;
        case 404:
          // Not found
          console.error('Resource not found:', data.message || 'The requested resource was not found');
          break;
        case 422:
          // Validation error
          console.error('Validation error:', data.errors || data.message);
          break;
        case 500:
          // Server error
          console.error('Server error:', data.message || 'An internal server error occurred');
          break;
        default:
          console.error(`HTTP ${status} error:`, data.message || 'An unexpected error occurred');
      }
    } else if (error.request) {
      // Network error
      console.error('Network error: No response received from server');
    } else {
      // Other error
      console.error('Request setup error:', error.message);
    }

    return Promise.reject(error);
  }
);

export default http;
