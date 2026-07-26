import { Outlet } from 'react-router-dom';
import { Navbar } from './Navbar';

export function Layout() {
  return (
    <div className="min-h-screen flex flex-col">
      <Navbar />
      <main className="flex-1 max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-6">
        <Outlet />
      </main>
      <footer className="border-t border-slate-200 dark:border-slate-700 py-4 text-center text-sm text-slate-500 dark:text-slate-400">
        FlightNoiseTracker — Mannersdorf am Leithagebirge
      </footer>
    </div>
  );
}
