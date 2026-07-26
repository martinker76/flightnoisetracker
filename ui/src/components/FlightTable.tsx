import { Link } from 'react-router-dom';
import type { Flight, PaginationMeta } from '../types';
import { Badge } from './Badge';
import { format } from 'date-fns';

interface Props {
  flights: Flight[];
  meta: PaginationMeta;
  sort: string;
  order: string;
  onSort: (col: string) => void;
  onPage: (page: number) => void;
  onPerPage: (perPage: number) => void;
}

function RunwayBadge({ runway }: { runway: string | null }) {
  if (!runway) return <Badge variant="gray">N/A</Badge>;
  if (runway === '11/29') return <Badge variant="blue">11/29</Badge>;
  if (runway === '16/34') return <Badge variant="green">16/34</Badge>;
  return <Badge variant="gray">UNKNOWN</Badge>;
}

export function FlightTable({ flights, meta, sort, order, onSort, onPage, onPerPage }: Props) {
  const sortIcon = (col: string) => {
    if (sort !== col) return ' ↕';
    return order === 'asc' ? ' ↑' : ' ↓';
  };

  const headerClass = (col: string) =>
    `px-3 py-2 text-left text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none`;

  return (
    <div className="card overflow-hidden p-0">
      <div className="overflow-x-auto">
        <table className="w-full">
          <thead className="bg-slate-50 dark:bg-slate-700/50">
            <tr>
              <th className={headerClass('callsign')} onClick={() => onSort('callsign')}>
                Callsign{sortIcon('callsign')}
              </th>
              <th className={headerClass('icao24')} onClick={() => onSort('icao24')}>
                ICAO24{sortIcon('icao24')}
              </th>
              <th className={headerClass('first_seen')} onClick={() => onSort('first_seen')}>
                First Seen{sortIcon('first_seen')}
              </th>
              <th className={headerClass('max_altitude_m')} onClick={() => onSort('max_altitude_m')}>
                Max Alt{sortIcon('max_altitude_m')}
              </th>
              <th className={headerClass('runway_used')} onClick={() => onSort('runway_used')}>
                Runway{sortIcon('runway_used')}
              </th>
              <th className="px-3 py-2 text-left text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">
                VIE
              </th>
              <th className="px-3 py-2 text-left text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">
                Country
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200 dark:divide-slate-700">
            {flights.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-3 py-8 text-center text-slate-500">
                  No flights found
                </td>
              </tr>
            ) : (
              flights.map((f) => (
                <tr key={f.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors">
                  <td className="px-3 py-2">
                    <Link to={`/flights/${f.id}`} className="text-blue-600 dark:text-blue-400 hover:underline font-mono text-sm">
                      {f.callsign || '—'}
                    </Link>
                  </td>
                  <td className="px-3 py-2 font-mono text-sm">{f.icao24}</td>
                  <td className="px-3 py-2 text-sm">{format(new Date(f.first_seen), 'MM/dd HH:mm:ss')}</td>
                  <td className="px-3 py-2 text-sm">{f.max_altitude_m ? `${f.max_altitude_m} m` : '—'}</td>
                  <td className="px-3 py-2"><RunwayBadge runway={f.runway_used} /></td>
                  <td className="px-3 py-2">
                    {f.is_vie_related && <Badge variant="blue">VIE</Badge>}
                  </td>
                  <td className="px-3 py-2 text-sm">{f.origin_country || '—'}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-700/30">
        <div className="flex items-center gap-2 text-sm">
          <span className="text-slate-500 dark:text-slate-400">Per page:</span>
          {[25, 50, 100].map((n) => (
            <button
              key={n}
              onClick={() => onPerPage(n)}
              className={`px-2 py-1 rounded text-sm ${
                meta.per_page === n
                  ? 'bg-blue-600 text-white'
                  : 'bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 hover:bg-slate-100 dark:hover:bg-slate-600'
              }`}
            >
              {n}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-1 text-sm">
          <span className="text-slate-500 dark:text-slate-400 mr-2">
            {meta.total} flights · Page {meta.page}/{meta.pages || 1}
          </span>
          <button
            onClick={() => onPage(Math.max(1, meta.page - 1))}
            disabled={meta.page <= 1}
            className="btn-secondary px-2 py-1 disabled:opacity-40"
          >
            ←
          </button>
          {Array.from({ length: Math.min(5, meta.pages) }, (_, i) => {
            const start = Math.max(1, meta.page - 2);
            const p = start + i;
            if (p > meta.pages) return null;
            return (
              <button
                key={p}
                onClick={() => onPage(p)}
                className={`px-2 py-1 rounded text-sm ${
                  meta.page === p
                    ? 'bg-blue-600 text-white'
                    : 'bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 hover:bg-slate-100 dark:hover:bg-slate-600'
                }`}
              >
                {p}
              </button>
            );
          })}
          <button
            onClick={() => onPage(Math.min(meta.pages, meta.page + 1))}
            disabled={meta.page >= meta.pages}
            className="btn-secondary px-2 py-1 disabled:opacity-40"
          >
            →
          </button>
        </div>
      </div>
    </div>
  );
}
