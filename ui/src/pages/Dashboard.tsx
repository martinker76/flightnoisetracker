import { Link } from 'react-router-dom';
import { useStatsSummary, useStatsTrend, useFlights } from '../hooks/useApi';
import { StatsCard } from '../components/StatsCard';
import { TrendChart } from '../components/TrendChart';
import { RunwayChart } from '../components/RunwayChart';
import { MapView } from '../components/MapView';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { ErrorAlert } from '../components/ErrorAlert';
import { Badge } from '../components/Badge';
import { TooltipIcon } from '../components/TooltipIcon';
import { format } from 'date-fns';

const TOOLTIPS = {
  today: 'Number of unique flights that crossed over the Mannersdorf tracking area today',
  vie: 'Flights arriving at or departing from Vienna International Airport (LOWW)',
  rwy: 'Flights using runway 11/29 (ESE–WNW orientation) based on heading and altitude analysis',
  overflights: 'Flights passing over Mannersdorf without a direct connection to VIE (overflights)',
  callsign: 'Aircraft callsign or ICAO 24-bit transponder code if no callsign available',
  enter: 'Time the aircraft first entered the Mannersdorf tracking area',
  leave: 'Time the aircraft was last detected within the Mannersdorf tracking area',
  runway: 'Inferred runway based on the aircraft\'s heading, altitude, and position relative to LOWW',
  alt: 'Maximum altitude reached by the aircraft while crossing the Mannersdorf area',
  closest: 'Closest distance the aircraft came to Mannersdorf town center (Mannersdorfer Schloss)',
  aircraft: 'ICAO aircraft type code (e.g. B738, A320) resolved via ADSB.lol public database',
  estDb: 'Estimated peak noise at Mannersdorf center based on slant distance \u2014 geometric model, not calibrated measurement',
  week: 'Total flights tracked this calendar week',
  month: 'Total flights tracked this calendar month',
  alltime: 'All flights tracked since the system started recording data',
};

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
        <StatsCard label="Today's Flights" value={today?.total_flights ?? 0} icon="✈️" color="blue" tooltip={TOOLTIPS.today} />
        <StatsCard label="VIE-Related" value={today?.vie_related ?? 0} icon="🇦🇹" color="yellow" tooltip={TOOLTIPS.vie} />
        <StatsCard label="Runway 11/29" value={today?.runway_11_29 ?? 0} icon="🔵" color="blue" tooltip={TOOLTIPS.rwy} />
        <StatsCard label="Overflights" value={today?.overflights ?? 0} icon="🔴" color="red" tooltip={TOOLTIPS.overflights} />
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
            <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 dark:border-slate-700">
                    <th className="text-left py-2 px-2 text-slate-500">
                      <span className="inline-flex items-center gap-0.5">
                        Callsign <TooltipIcon text={TOOLTIPS.callsign} position="left" />
                      </span>
                    </th>
                    <th className="text-left py-2 px-2 text-slate-500">
                      <span className="inline-flex items-center gap-0.5">
                        Aircraft <TooltipIcon text={TOOLTIPS.aircraft} />
                      </span>
                    </th>
                    <th className="text-left py-2 px-2 text-slate-500">
                      <span className="inline-flex items-center gap-0.5">
                        Enter <TooltipIcon text={TOOLTIPS.enter} />
                      </span>
                    </th>
                    <th className="text-left py-2 px-2 text-slate-500">
                      <span className="inline-flex items-center gap-0.5">
                        Runway <TooltipIcon text={TOOLTIPS.runway} />
                      </span>
                    </th>
                    <th className="text-right py-2 px-2 text-slate-500">
                      <span className="inline-flex items-center gap-0.5">
                        Alt <TooltipIcon text={TOOLTIPS.alt} />
                      </span>
                    </th>
                    <th className="text-right py-2 px-2 text-slate-500">
                      <span className="inline-flex items-center gap-0.5">
                        Est. dB <TooltipIcon text={TOOLTIPS.estDb} position="right" />
                      </span>
                    </th>
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
                      <td className="py-2 px-2 font-mono text-xs">
                        {f.aircraft_type || '—'}
                      </td>
                      <td className="py-2 px-2 text-xs text-slate-500">
                        {f.first_seen ? format(new Date(f.first_seen), 'HH:mm') : '—'}
                      </td>
                      <td className="py-2 px-2">
                        {f.runway_used === '11/29' && <Badge variant="blue">11/29</Badge>}
                        {f.runway_used === '16/34' && <Badge variant="green">16/34</Badge>}
                        {(!f.runway_used || f.runway_used === 'UNKNOWN') && <Badge variant="gray">UNK</Badge>}
                      </td>
                      <td className="py-2 px-2 text-right">{f.max_altitude_m ? `${f.max_altitude_m}m` : '—'}</td>
                      <td className="py-2 px-2 text-right font-mono text-xs">
                        {f.estimated_db != null ? `${Number(f.estimated_db).toFixed(1)} dBA` : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
          )}
        </div>
      </div>

      {/* Week / Month summary */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatsCard label="This Week" value={summary.data?.data?.week?.total_flights ?? 0} icon="📅" color="green" tooltip={TOOLTIPS.week} />
        <StatsCard label="This Month" value={summary.data?.data?.month?.total_flights ?? 0} icon="📆" color="slate" tooltip={TOOLTIPS.month} />
        <StatsCard label="All Time" value={summary.data?.data?.all_time?.total_flights ?? 0} icon="🏛️" color="yellow" tooltip={TOOLTIPS.alltime} />
      </div>
    </div>
  );
}
