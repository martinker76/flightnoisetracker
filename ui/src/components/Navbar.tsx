import { Link, NavLink } from 'react-router-dom';
import { useHealth } from '../hooks/useApi';
import { useDarkMode } from '../hooks/useDarkMode';
import { format } from 'date-fns';

export function Navbar() {
  const [dark, toggleDark] = useDarkMode();
  const { data: health } = useHealth();

  const linkClass = ({ isActive }: { isActive: boolean }) =>
    `px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
      isActive
        ? 'bg-blue-600 text-white'
        : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700'
    }`;

  const lastPoll = health?.last_poll?.at;

  return (
    <nav className="sticky top-0 z-50 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-14">
          <div className="flex items-center gap-4">
            <Link to="/" className="flex items-center gap-2 font-bold text-lg">
              <span>🦅</span>
              <span className="hidden sm:inline">FlightNoiseTracker</span>
              <span className="sm:hidden">FNT</span>
            </Link>
            <div className="hidden md:flex items-center gap-1">
              <NavLink to="/" end className={linkClass}>Dashboard</NavLink>
              <NavLink to="/flights" className={linkClass}>Flights</NavLink>
              <NavLink to="/stats" className={linkClass}>Statistics</NavLink>
              <NavLink to="/about" className={linkClass}>About</NavLink>
            </div>
          </div>

          <div className="flex items-center gap-3">
            {lastPoll && (
              <span className="text-xs text-slate-400 dark:text-slate-500 hidden lg:block">
                Last poll: {format(new Date(lastPoll), 'HH:mm:ss')}
              </span>
            )}
            <button
              onClick={toggleDark}
              className="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors"
              title={dark ? 'Light mode' : 'Dark mode'}
            >
              {dark ? '☀️' : '🌙'}
            </button>
          </div>
        </div>

        {/* Mobile nav */}
        <div className="md:hidden flex gap-1 pb-2 overflow-x-auto">
          <NavLink to="/" end className={linkClass}>Dashboard</NavLink>
          <NavLink to="/flights" className={linkClass}>Flights</NavLink>
          <NavLink to="/stats" className={linkClass}>Statistics</NavLink>
          <NavLink to="/about" className={linkClass}>About</NavLink>
        </div>
      </div>
    </nav>
  );
}
