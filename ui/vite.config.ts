import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  base: '/flightnoisetracker/',
  build: {
    outDir: '../public',
    sourcemap: false,
  },
  server: {
    proxy: {
      '/api': 'http://localhost:8080',
    },
  },
})
