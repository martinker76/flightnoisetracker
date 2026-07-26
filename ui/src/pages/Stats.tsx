import { useState } from 'react';
import { useStatsSummary, useStatsTrend, useStatsRunways, useStatsHourly } from '../hooks/useApi';
import { StatsCard } from '../components/StatsCard';
import { TrendChart } from '../components/TrendChart';
import { RunwayChart } from '../components/RunwayChart';
import { RunwayBarChart } from '../components/RunwayBarChart';
import { HourlyChart } from '../components/HourlyChart';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { ErrorAlert } from '../components/ErrorAlert';
import { format, subDays } from 'date-fns';

export default function Stats() {
  const summary = useStatsSummary();
  const [trendDays, setTrendDays] = useState(30);
  const [dateFrom, setDateFrom] = useState(format(subDays(new Date(), 7), 'yyyy-MM-dd'));
  const [dateTo, setDateTo] = useState(format(new Date(), 'yyyy-MM-dd'));
  const [hourlyDate, setHourlyDate] = useState(format(new Date(), 'yyyy-MM-dd'));

  const trend = useStatsTrend(trendDays);
  const runways = useStatsRunways(dateFrom, dateTo);
  const hourly = useStatsHourly(hourlyDate);

  if (summary.isLoading) return <LoadingSpinner />;
  if (summary.isError) return <ErrorAlert error={summary.error} onRetry={() => summary.refetch()} />;

  const all = summary.data?.data?.all_time;
  const today = summary.data?.data?.today;

  // Calculate key numbers
  const totalFlights = all?.total_flights ?? 0;
  const avgPerDay = all?.total_flights ? Math.round(all.total_flights / Math.max(1, 30)) : 0; // rough
  const mostUsedRunway = all
    ? all.runway_11_29 >= all.runway_16_34
      ? '11/29'
      : '16/34'
    : '—';

  // Find most active hour
  const mostActiveHour = hourly.data?.data
    ? hourly.data.data.reduce(
        (max, item) => (item.total_flights > max.total_flights ? item : max),
        hourly.data.data[0]
      )
    : null;

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">📈 Statistics</h1>

      {/* Summary cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatsCard label="Total Flights" value={totalFlights} icon="✈️" color="blue" />
        <StatsCard label="VIE-Related" value={all?.vie_related ?? 0} icon="🇦🇹" color="yellow" />
        <StatsCard label="Overflights" value={all?.overflights ?? 0} icon="🔴" color="red" />
        <StatsCard label="Most Used Runway" value={mostUsedRunway} icon="🛬" color="green" />
      </div>

      {/* Trend chart */}
      <div className="card">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-sm font-medium text-slate-700 dark:text-slate-300">Trend</h3>
          <div className="flex gap-1">
            {[7, 14, 30, 90].map((d) => (
              <button
                key={d}
                onClick={() => setTrendDays(d)}
                className={`px-3 py-1 rounded text-sm ${
                  trendDays === d
                    ? 'bg-blue-600 text-white'
                    : 'bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600'
                }`}
              >
                {d}d
              </button>
            ))}
          </div>
        </div>
        {trend.isLoading ? <LoadingSpinner /> : trend.data?.data && <TrendChart data={trend.data.data} title={`Flights — Last ${trendDays} Days`} />}
      </div>

      {/* Runway breakdown */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2">
          <div className="card">
            <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
              <h3 className="text-sm font-medium text-slate-700 dark:text-slate-300">Runway Usage by Day</h3>
              <div className="flex items-center gap-2">
                <input
                  type="date"
                  value={dateFrom}
                  onChange={(e) => setDateFrom(e.target.value)}
                  className="input-field text-sm"
                />
                <span className="text-slate-400">to</span>
                <input
                  type="date"
                  value={dateTo}
                  onChange={(e) => setDateTo(e.target.value)}
                  className="input-field text-sm"
                />
              </div>
            </div>
            {runways.isLoading ? (
              <LoadingSpinner />
            ) : runways.data?.data && runways.data.data.length > 0 ? (
              <RunwayBarChart data={runways.data.data} />
            ) : (
              <p className="text-slate-500 text-sm text-center py-8">No data for this range</p>
            )}
          </div>
        </div>
        <div>
          {summary.data?.data?.today && (
            <RunwayChart
              runway_11_29={today?.runway_11_29 ?? 0}
              runway_16_34={today?.runway_16_34 ?? 0}
              runway_unknown={today?.runway_unknown ?? 0}
            />
          )}
        </div>
      </div>

      {/* Hourly breakdown */}
      <div className="card">
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
          <h3 className="text-sm font-medium text-slate-700 dark:text-slate-300">Hourly Breakdown</h3>
          <input
            type="date"
            value={hourlyDate}
            onChange={(e) => setHourlyDate(e.target.value)}
            className="input-field text-sm"
          />
        </div>
        {hourly.isLoading ? (
          <LoadingSpinner />
        ) : hourly.data?.data ? (
          <HourlyChart data={hourly.data.data} />
        ) : (
          <p className="text-slate-500 text-sm text-center py-8">No data</p>
        )}
      </div>

      {/* Key numbers */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div className="card text-center">
          <p className="text-xs text-slate-500">Total Flights</p>
          <p className="text-2xl font-bold">{totalFlights.toLocaleString()}</p>
        </div>
        <div className="card text-center">
          <p className="text-xs text-slate-500">Avg/Day (est.)</p>
          <p className="text-2xl font-bold">{avgPerDay}</p>
        </div>
        <div className="card text-center">
          <p className="text-xs text-slate-500">Most Active Hour</p>
          <p className="text-2xl font-bold">
            {mostActiveHour ? `${mostActiveHour.hour.toString().padStart(2, '0')}:00` : '—'}
          </p>
          {mostActiveHour && (
            <p className="text-xs text-slate-400">{mostActiveHour.total_flights} flights</p>
          )}
        </div>
        <div className="card text-center">
          <p className="text-xs text-slate-500">Most Used Runway</p>
          <p className="text-2xl font-bold">{mostUsedRunway}</p>
        </div>
      </div>
    </div>
  );
}
