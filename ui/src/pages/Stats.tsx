import { useState } from 'react';
import { useStatsSummary, useStatsTrend, useStatsRunways, useStatsHourly, useAircraftTypes, useNoiseStats } from '../hooks/useApi';
import { StatsCard } from '../components/StatsCard';
import { TrendChart } from '../components/TrendChart';
import { RunwayChart } from '../components/RunwayChart';
import { RunwayBarChart } from '../components/RunwayBarChart';
import { HourlyChart } from '../components/HourlyChart';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { ErrorAlert } from '../components/ErrorAlert';
import { TooltipIcon } from '../components/TooltipIcon';
import { format, subDays } from 'date-fns';

const TOOLTIPS = {
  total: 'Total number of unique flights tracked since the system started recording',
  vie: 'Flights arriving at or departing from Vienna International Airport (LOWW)',
  overflights: 'Flights passing over Mannersdorf without a direct connection to VIE',
  mostUsedRwy: 'The runway configuration that has been used most frequently over the entire tracked period',
  trend: 'Daily flight counts over the selected time period, broken down by runway configuration',
  rwyByDay: 'Daily breakdown of flights showing which runway configuration each used',
  hourly: 'Flight activity distributed by hour of the day for the selected date',
  avgDay: 'Estimated average flights per day — total flights divided by the number of days tracked',
  mostActive: 'The hour of day with the highest volume of flights crossing the Mannersdorf area',
  avgNoise: 'Average estimated peak noise level across all tracked flights (geometric model, not calibrated)',
  minMaxNoise: 'Minimum and maximum estimated noise levels observed',
  noiseDataCount: 'Total flights for which noise estimation was calculated',
};

export default function Stats() {
  const summary = useStatsSummary();
  const [trendDays, setTrendDays] = useState(30);
  const [dateFrom, setDateFrom] = useState(format(subDays(new Date(), 7), 'yyyy-MM-dd'));
  const [dateTo, setDateTo] = useState(format(new Date(), 'yyyy-MM-dd'));
  const [hourlyDate, setHourlyDate] = useState(format(new Date(), 'yyyy-MM-dd'));

  const trend = useStatsTrend(trendDays);
  const runways = useStatsRunways(dateFrom, dateTo);
  const hourly = useStatsHourly(hourlyDate);
  const aircraftTypes = useAircraftTypes();
  const noiseStats = useNoiseStats();

  if (summary.isLoading) return <LoadingSpinner />;
  if (summary.isError) return <ErrorAlert error={summary.error} onRetry={() => summary.refetch()} />;

  const all = summary.data?.data?.all_time;
  const today = summary.data?.data?.today;

  // Calculate key numbers
  const totalFlights = all?.total_flights ?? 0;
  const avgPerDay = all?.total_flights ? Math.round(all.total_flights / Math.max(1, 30)) : 0;
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
        <StatsCard label="Total Flights" value={totalFlights} icon="✈️" color="blue" tooltip={TOOLTIPS.total} />
        <StatsCard label="VIE-Related" value={all?.vie_related ?? 0} icon="🇦🇹" color="yellow" tooltip={TOOLTIPS.vie} />
        <StatsCard label="Overflights" value={all?.overflights ?? 0} icon="🔴" color="red" tooltip={TOOLTIPS.overflights} />
        <StatsCard label="Most Used Runway" value={mostUsedRunway} icon="🛬" color="green" tooltip={TOOLTIPS.mostUsedRwy} />
      </div>

      {/* Trend chart */}
      <div className="card">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-sm font-medium text-slate-700 dark:text-slate-300">
            <span className="inline-flex items-center gap-1">
              Trend <TooltipIcon text={TOOLTIPS.trend} />
            </span>
          </h3>
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
              <h3 className="text-sm font-medium text-slate-700 dark:text-slate-300">
                <span className="inline-flex items-center gap-1">
                  Runway Usage by Day <TooltipIcon text={TOOLTIPS.rwyByDay} />
                </span>
              </h3>
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

      {/* Noise Level Statistics */}
      {noiseStats.data?.data && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <StatsCard label="Avg. Noise Level" value={noiseStats.data.data.avg_db != null ? `${Number(noiseStats.data.data.avg_db).toFixed(1)} dBA` : '—'} icon="🔊" color="blue" tooltip={TOOLTIPS.avgNoise} />
          <StatsCard label="Min/Max Noise" value={noiseStats.data.data.min_db != null && noiseStats.data.data.max_db != null ? `${Number(noiseStats.data.data.min_db).toFixed(1)} / ${Number(noiseStats.data.data.max_db).toFixed(1)} dBA` : '—'} icon="📊" color="yellow" tooltip={TOOLTIPS.minMaxNoise} />
          <StatsCard label="Flights w/ Noise Data" value={noiseStats.data.data.flights_with_noise} icon="✈️" color="green" tooltip={TOOLTIPS.noiseDataCount} />
        </div>
      )}

      {/* Noise Distribution */}
      {noiseStats.data?.data && (
        <div className="card">
          <h3 className="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">
            <span className="inline-flex items-center gap-1">
              Noise Distribution <TooltipIcon text="Distribution of estimated noise levels across all tracked flights" />
            </span>
          </h3>
          <div className="space-y-2">
            {[
              { label: '<45 dBA', value: noiseStats.data.data.bucket_under_45, color: 'bg-green-500' },
              { label: '45-50 dBA', value: noiseStats.data.data.bucket_45_50, color: 'bg-lime-500' },
              { label: '50-55 dBA', value: noiseStats.data.data.bucket_50_55, color: 'bg-yellow-500' },
              { label: '55-60 dBA', value: noiseStats.data.data.bucket_55_60, color: 'bg-orange-500' },
              { label: '60+ dBA', value: noiseStats.data.data.bucket_60_plus, color: 'bg-red-500' },
            ].map((bucket) => {
              const maxVal = Math.max(
                noiseStats.data.data.bucket_under_45,
                noiseStats.data.data.bucket_45_50,
                noiseStats.data.data.bucket_50_55,
                noiseStats.data.data.bucket_55_60,
                noiseStats.data.data.bucket_60_plus,
                1
              );
              return (
                <div key={bucket.label} className="flex items-center gap-2">
                  <span className="text-xs w-24 text-right">{bucket.label}</span>
                  <div className="flex-1 bg-slate-100 dark:bg-slate-700 rounded-full h-6">
                    <div
                      className={`${bucket.color} rounded-full h-6 text-xs text-white text-right pr-2 leading-6`}
                      style={{ width: `${(bucket.value / maxVal) * 100}%` }}
                    >
                      {bucket.value}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Aircraft Type Distribution */}
      <div className="card">
        <h3 className="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">
          <span className="inline-flex items-center gap-1">
            Aircraft Types <TooltipIcon text="Distribution of aircraft types tracked over Mannersdorf, sourced from ADSB.lol" />
          </span>
        </h3>
        {aircraftTypes.isLoading ? (
          <LoadingSpinner />
        ) : aircraftTypes.data?.data && aircraftTypes.data.data.length > 0 ? (
          <div className="space-y-2">
            {aircraftTypes.data.data.slice(0, 10).map((row) => (
              <div key={row.aircraft_type} className="flex items-center gap-2">
                <span className="font-mono text-sm w-16">{row.aircraft_type}</span>
                <div className="flex-1 bg-slate-100 dark:bg-slate-700 rounded-full h-5">
                  <div
                    className="bg-blue-500 rounded-full h-5 text-xs text-white text-right pr-2 leading-5"
                    style={{ width: `${Math.min(100, (row.count / aircraftTypes.data.data[0].count) * 100)}%` }}
                  >
                    {row.count}
                  </div>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p className="text-slate-500 text-sm text-center py-8">No aircraft type data yet</p>
        )}
      </div>

      {/* Hourly breakdown */}
      <div className="card">
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
          <h3 className="text-sm font-medium text-slate-700 dark:text-slate-300">
            <span className="inline-flex items-center gap-1">
              Hourly Breakdown <TooltipIcon text={TOOLTIPS.hourly} />
            </span>
          </h3>
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
          <p className="text-xs text-slate-500">
            <span className="inline-flex items-center gap-0.5">
              Total Flights <TooltipIcon text={TOOLTIPS.total} />
            </span>
          </p>
          <p className="text-2xl font-bold">{totalFlights.toLocaleString()}</p>
        </div>
        <div className="card text-center">
          <p className="text-xs text-slate-500">
            <span className="inline-flex items-center gap-0.5">
              Avg/Day (est.) <TooltipIcon text={TOOLTIPS.avgDay} />
            </span>
          </p>
          <p className="text-2xl font-bold">{avgPerDay}</p>
        </div>
        <div className="card text-center">
          <p className="text-xs text-slate-500">
            <span className="inline-flex items-center gap-0.5">
              Most Active Hour <TooltipIcon text={TOOLTIPS.mostActive} />
            </span>
          </p>
          <p className="text-2xl font-bold">
            {mostActiveHour ? `${mostActiveHour.hour.toString().padStart(2, '0')}:00` : '—'}
          </p>
          {mostActiveHour && (
            <p className="text-xs text-slate-400">{mostActiveHour.total_flights} flights</p>
          )}
        </div>
        <div className="card text-center">
          <p className="text-xs text-slate-500">
            <span className="inline-flex items-center gap-0.5">
              Most Used Runway <TooltipIcon text={TOOLTIPS.mostUsedRwy} />
            </span>
          </p>
          <p className="text-2xl font-bold">{mostUsedRunway}</p>
        </div>
      </div>
    </div>
  );
}
