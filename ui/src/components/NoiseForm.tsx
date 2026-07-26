import { useState } from 'react';
import { format } from 'date-fns';

interface Props {
  onSubmit: (data: {
    db_level: number;
    measured_at: string;
    latitude: number | null;
    longitude: number | null;
    notes: string | null;
  }) => void;
  isSubmitting: boolean;
  isSuccess: boolean;
}

export function NoiseForm({ onSubmit, isSubmitting, isSuccess }: Props) {
  const now = format(new Date(), "yyyy-MM-dd'T'HH:mm");
  const [dbLevel, setDbLevel] = useState('');
  const [measuredAt, setMeasuredAt] = useState(now);
  const [latitude, setLatitude] = useState('');
  const [longitude, setLongitude] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const validate = (): boolean => {
    const errs: Record<string, string> = {};
    const db = parseFloat(dbLevel);
    if (isNaN(db) || db < 0 || db > 200) {
      errs.db_level = 'dB level must be between 0 and 200';
    }
    if (!measuredAt) {
      errs.measured_at = 'Date/time is required';
    }
    if (latitude && (isNaN(parseFloat(latitude)) || parseFloat(latitude) < -90 || parseFloat(latitude) > 90)) {
      errs.latitude = 'Latitude must be between -90 and 90';
    }
    if (longitude && (isNaN(parseFloat(longitude)) || parseFloat(longitude) < -180 || parseFloat(longitude) > 180)) {
      errs.longitude = 'Longitude must be between -180 and 180';
    }
    setErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!validate()) return;
    onSubmit({
      db_level: parseFloat(dbLevel),
      measured_at: new Date(measuredAt).toISOString(),
      latitude: latitude ? parseFloat(latitude) : null,
      longitude: longitude ? parseFloat(longitude) : null,
      notes: notes || null,
    });
  };

  return (
    <div className="card">
      <h3 className="text-lg font-semibold mb-4">📢 Submit Noise Reading</h3>

      {isSuccess && (
        <div className="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-3 mb-4 text-green-700 dark:text-green-300 text-sm">
          ✅ Noise reading submitted successfully!
        </div>
      )}

      <form onSubmit={handleSubmit} className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
            dB Level *
          </label>
          <input
            type="number"
            min="0"
            max="200"
            step="0.1"
            value={dbLevel}
            onChange={(e) => setDbLevel(e.target.value)}
            className={`input-field w-full ${errors.db_level ? 'border-red-500' : ''}`}
            placeholder="e.g. 65"
          />
          {errors.db_level && <p className="text-red-500 text-xs mt-1">{errors.db_level}</p>}
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
            Measured At *
          </label>
          <input
            type="datetime-local"
            value={measuredAt}
            onChange={(e) => setMeasuredAt(e.target.value)}
            className={`input-field w-full ${errors.measured_at ? 'border-red-500' : ''}`}
          />
          {errors.measured_at && <p className="text-red-500 text-xs mt-1">{errors.measured_at}</p>}
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
            Latitude (optional)
          </label>
          <input
            type="number"
            step="any"
            value={latitude}
            onChange={(e) => setLatitude(e.target.value)}
            className={`input-field w-full ${errors.latitude ? 'border-red-500' : ''}`}
            placeholder="47.97"
          />
          {errors.latitude && <p className="text-red-500 text-xs mt-1">{errors.latitude}</p>}
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
            Longitude (optional)
          </label>
          <input
            type="number"
            step="any"
            value={longitude}
            onChange={(e) => setLongitude(e.target.value)}
            className={`input-field w-full ${errors.longitude ? 'border-red-500' : ''}`}
            placeholder="16.61"
          />
          {errors.longitude && <p className="text-red-500 text-xs mt-1">{errors.longitude}</p>}
        </div>

        <div className="sm:col-span-2">
          <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
            Notes (optional)
          </label>
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={3}
            className="input-field w-full"
            placeholder="Any observations..."
          />
        </div>

        <div className="sm:col-span-2">
          <button type="submit" disabled={isSubmitting} className="btn-primary disabled:opacity-50">
            {isSubmitting ? 'Submitting...' : 'Submit Reading'}
          </button>
        </div>
      </form>
    </div>
  );
}
