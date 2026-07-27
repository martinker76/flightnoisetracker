import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import App from './App';
import 'leaflet/dist/leaflet.css';
import './index.css';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 2,
      staleTime: 30000,
      refetchOnWindowFocus: false,
    },
  },
});

// Router basename — Vite auto-populates import.meta.env.BASE_URL from the
// `base` config option in vite.config.ts. For openclaw.kersch.at/flightnoisetracker
// (base: '/flightnoisetracker/'), this resolves to '/flightnoisetracker/'.
// Falls back to '' for dedicated-domain deployments (base: '/').
// IMPORTANT: must use BASE_URL (no VITE_ prefix) — Vite doesn't auto-populate
// VITE_BASE_URL; that's only set when explicitly defined. Earlier code used
// VITE_BASE_URL which silently resolved to '' and broke subpath routing.
const routerBasename = import.meta.env.BASE_URL ?? '';

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter basename={routerBasename}>
        <App />
      </BrowserRouter>
    </QueryClientProvider>
  </React.StrictMode>
);
