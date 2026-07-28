import { Routes, Route } from 'react-router-dom';
import { Layout } from './components/Layout';
import { lazy, Suspense } from 'react';
import { LoadingSpinner } from './components/LoadingSpinner';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const Flights = lazy(() => import('./pages/Flights'));
const FlightDetail = lazy(() => import('./pages/FlightDetail'));
const Stats = lazy(() => import('./pages/Stats'));
const About = lazy(() => import('./pages/About'));

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Layout />}>
        <Route
          index
          element={
            <Suspense fallback={<LoadingSpinner />}>
              <Dashboard />
            </Suspense>
          }
        />
        <Route
          path="flights"
          element={
            <Suspense fallback={<LoadingSpinner />}>
              <Flights />
            </Suspense>
          }
        />
        <Route
          path="flights/:id"
          element={
            <Suspense fallback={<LoadingSpinner />}>
              <FlightDetail />
            </Suspense>
          }
        />
        <Route
          path="stats"
          element={
            <Suspense fallback={<LoadingSpinner />}>
              <Stats />
            </Suspense>
          }
        />
        <Route
          path="about"
          element={
            <Suspense fallback={<LoadingSpinner />}>
              <About />
            </Suspense>
          }
        />
      </Route>
    </Routes>
  );
}
