import { useState } from 'react';
import { useNoise, useNoiseCreate } from '../hooks/useApi';
import { NoiseForm } from '../components/NoiseForm';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { ErrorAlert } from '../components/ErrorAlert';
import { format } from 'date-fns';

function DbLevelColor({ level }: { level: number }) {
  let color = 'text-green-600';
  if (level >= 80) color = 'text-red-600';
  else if (level >= 60) color = 'text-orange-600';
  else if (level >= 40) color = 'text-yellow-600';

  let bg = 'bg-green-100 dark:bg-green-900/30';
  if (level >= 80) bg = 'bg-red-100 dark:bg-red-900/30';
  else if (level >= 60) bg = 'bg-orange-100 dark:bg-orange-900/30';
  else if (level >= 40) bg = 'bg-yellow-100 dark:bg-yellow-900/30';

  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-sm font-bold ${color} ${bg}`}>
      {level.toFixed(1)} dB
    </span>
  );
}

export default function NoiseLog() {
  const [page, setPage] = useState(1);
  const noise = useNoise({ page, per_page: 50 });
  const createMutation = useNoiseCreate();

  const handleSubmit = (data: {
    db_level: number;
    measured_at: string;
    latitude: number | null;
    longitude: number | null;
    notes: string | null;
  }) => {
    createMutation.mutate(data);
  };

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">🔊 Noise Log</h1>

      {/* Submit form */}
      <NoiseForm
        onSubmit={handleSubmit}
        isSubmitting={createMutation.isPending}
        isSuccess={createMutation.isSuccess}
      />

      {/* Recent readings table */}
      <div className="card">
        <h3 className="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Recent Readings</h3>

        {noise.isLoading && <LoadingSpinner />}
        {noise.isError && <ErrorAlert error={noise.error} onRetry={() => noise.refetch()} />}

        {noise.data && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 dark:bg-slate-700/50">
                  <tr>
                    <th className="text-left py-2 px-3 text-xs text-slate-500 uppercase">Measured At</th>
                    <th className="text-left py-2 px-3 text-xs text-slate-500 uppercase">Level</th>
                    <th className="text-left py-2 px-3 text-xs text-slate-500 uppercase">Location</th>
                    <th className="text-left py-2 px-3 text-xs text-slate-500 uppercase">Notes</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                  {noise.data.data.length === 0 ? (
                    <tr>
                      <td colSpan={4} className="py-8 text-center text-slate-500">
                        No noise readings yet
                      </td>
                    </tr>
                  ) : (
                    noise.data.data.map((reading) => (
                      <tr key={reading.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/30">
                        <td className="py-2 px-3">
                          {format(new Date(reading.measured_at), 'PPpp')}
                        </td>
                        <td className="py-2 px-3">
                          <DbLevelColor level={reading.db_level} />
                        </td>
                        <td className="py-2 px-3 text-slate-500">
                          {reading.latitude && reading.longitude
                            ? `${reading.latitude.toFixed(4)}, ${reading.longitude.toFixed(4)}`
                            : '—'}
                        </td>
                        <td className="py-2 px-3 text-slate-500 max-w-xs truncate">
                          {reading.notes || '—'}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            {noise.data.meta.pages > 1 && (
              <div className="flex items-center justify-center gap-2 mt-4 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button
                  onClick={() => setPage(Math.max(1, page - 1))}
                  disabled={page <= 1}
                  className="btn-secondary text-sm disabled:opacity-40"
                >
                  ← Prev
                </button>
                <span className="text-sm text-slate-500">
                  Page {page} of {noise.data.meta.pages}
                </span>
                <button
                  onClick={() => setPage(Math.min(noise.data!.meta.pages, page + 1))}
                  disabled={page >= noise.data.meta.pages}
                  className="btn-secondary text-sm disabled:opacity-40"
                >
                  Next →
                </button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
