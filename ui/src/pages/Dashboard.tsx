import { Link } from 'react-router-dom';
import { useStatsSummary, useStatsTrend, useFlights } from '../hooks/useApi';
import { StatsCard } from '../components/StatsCard';
import { TrendChart } from '../components/TrendChart';
import { RunwayChart } from '../components/RunwayChart';
import { MapView } from '../components/MapView';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { ErrorAlert } from '../components/ErrorAlert';
import { Badge } from '../components/Badge';
import { format } from 'date-fns';

export default function Dashboard() {
  const summary = useStatsSummary();
  const trend = useStatsTrend(30);
  const recent = useFlights({ per_page: 10, sort: 'first_seen', order: 'desc' });

  if (summary.isLoading) return <LoadingSpinner />;
  if (summary.isError) return <ErrorAlert error={summary.error} onRetry={() => summary.refetch()} />;

  const today = summary.data?.data?.today;
  const runwayData = today
    ? { runway_11_29: today.runway_11_29, runway_16_34: today.runway_16_34, runway_unknown: today.runway_unknown }
    : null;

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">📊 Dashboard</h1>

      {/* Summary cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatsCard label="Today's Flights" value={today?.total_flights ?? 0} icon="✈️" color="blue" />
        <StatsCard label="VIE-Related" value={today?.vie_related ?? 0} icon="🇦🇹" color="yellow" />
        <StatsCard label="Runway 11/29" value={today?.runway_11_29 ?? 0} icon="🔵" color="blue" />
        <StatsCard label="Overflights" value={today?.overflights ?? 0} icon="🔴" color="red" />
      </div>

      {/* Charts row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2">
          {trend.data?.data && <TrendChart data={trend.data.data} title="Flights — Last 30 Days" />}
          {trend.isLoading && <LoadingSpinner />}
        </div>
        <div>
          {runwayData && <RunwayChart {...runwayData} />}
        </div>
      </div>

      {/* Map + Recent flights */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <MapView height="350px" recentFlights={recent.data?.data} />

        <div className="card">
          <h3 className="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Recent Flights</h3>
          {recent.isLoading ? (
            <LoadingSpinner className="p-4" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 dark:border-slate-700">
                    <th className="text-left py-2 px-2 text-slate-500">Callsign</th>
                    <th className="text-left py-2 px-2 text-slate-500">Enter</th>
                    <th className="text-left py-2 px-2 text-slate-500">Leave</th>
                    <th className="text-left py-2 px-2 text-slate-500">Runway</th>
                    <th className="text-right py-2 px-2 text-slate-500">Alt</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                  {recent.data?.data?.slice(0, 8).map((f) => (
                    <tr key={f.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/30">
                      <td className="py-2 px-2">
                        <Link to={`/flights/${f.id}`} className="text-blue-600 dark:text-blue-400 hover:underline font-mono">
                          {f.callsign || f.icao24}
                        </Link>
                        {f.is_vie_related && <Badge variant="blue" className="ml-1">VIE</Badge>}
                      </td>
                      <td className="py-2 px-2 text-xs text-slate-500">
                        {f.first_seen ? format(new Date(f.first_seen), 'HH:mm') : '—'}
                      </td>
                      <td className="py-2 px-2 text-xs text-slate-500">
                        {f.last_seen ? format(new Date(f.last_seen), 'HH:mm') : '—'}
                      </td>
                      <td className="py-2 px-2">
                        {f.runway_used === '11/29' && <Badge variant="blue">11/29</Badge>}
                        {f.runway_used === '16/34' && <Badge variant="green">16/34</Badge>}
                        {(!f.runway_used || f.runway_used === 'UNKNOWN') && <Badge variant="gray">UNK</Badge>}
                      </td>
                      <td className="py-2 px-2 text-right">{f.max_altitude_m ? `${f.max_altitude_m}m` : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {/* Week / Month summary */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatsCard label="This Week" value={summary.data?.data?.week?.total_flights ?? 0} icon="📅" color="green" />
        <StatsCard label="This Month" value={summary.data?.data?.month?.total_flights ?? 0} icon="📆" color="slate" />
        <StatsCard label="All Time" value={summary.data?.data?.all_time?.total_flights ?? 0} icon="🏛️" color="yellow" />
      </div>
    </div>
  );
}
