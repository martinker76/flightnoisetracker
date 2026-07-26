import { useParams, Link } from 'react-router-dom';
import { useFlight, useFlightTrack } from '../hooks/useApi';
import { FlightTrack } from '../components/FlightTrack';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { ErrorAlert } from '../components/ErrorAlert';
import { Badge } from '../components/Badge';
import { format } from 'date-fns';

export default function FlightDetail() {
  const { id } = useParams<{ id: string }>();
  const flightId = id ? parseInt(id) : undefined;
  const flight = useFlight(flightId);
  const track = useFlightTrack(flightId);

  if (flight.isLoading) return <LoadingSpinner />;
  if (flight.isError) return <ErrorAlert error={flight.error} />;
  if (!flight.data?.data) return <ErrorAlert message="Flight not found" />;

  const f = flight.data.data;

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Link to="/flights" className="btn-secondary text-sm">
          ← Back
        </Link>
        <h1 className="text-2xl font-bold">
          {f.callsign || f.icao24}
          {f.is_vie_related && <Badge variant="blue" className="ml-2">VIE</Badge>}
        </h1>
      </div>

      {/* Flight info */}
      <div className="card">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div>
            <p className="text-xs text-slate-500">ICAO24</p>
            <p className="font-mono font-medium">{f.icao24}</p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Callsign</p>
            <p className="font-medium">{f.callsign || '—'}</p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Aircraft Type</p>
            <p className="font-mono">{f.aircraft_type || '—'}</p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Origin Country</p>
            <p>{f.origin_country || '—'}</p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Runway</p>
            <p>
              {f.runway_used === '11/29' && <Badge variant="blue">11/29</Badge>}
              {f.runway_used === '16/34' && <Badge variant="green">16/34</Badge>}
              {(!f.runway_used || f.runway_used === 'UNKNOWN') && <Badge variant="gray">UNKNOWN</Badge>}
            </p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Entering Airspace</p>
            <p className="font-medium">{format(new Date(f.first_seen), 'PPpp')}</p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Leaving Airspace</p>
            <p className="font-medium">{format(new Date(f.last_seen), 'PPpp')}</p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Duration Over Airspace</p>
            <p className="font-medium">{(() => {
              const diffMs = new Date(f.last_seen).getTime() - new Date(f.first_seen).getTime();
              if (diffMs < 1000) return 'Instantaneous';
              const secs = Math.floor(diffMs / 1000) % 60;
              const mins = Math.floor(diffMs / 60000) % 60;
              const hours = Math.floor(diffMs / 3600000);
              if (hours > 0) return `${hours}h ${mins}m ${secs}s`;
              if (mins > 0) return `${mins}m ${secs}s`;
              return `${secs}s`;
            })()}</p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Max Altitude</p>
            <p>{f.max_altitude_m ? `${f.max_altitude_m} m` : '—'}</p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Min Altitude</p>
            <p>{f.min_altitude_m ? `${f.min_altitude_m} m` : '—'}</p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Closest Point to Mannersdorf</p>
            <p className="font-medium">{f.closest_distance_km != null ? `${Number(f.closest_distance_km).toFixed(2)} km` : '—'}</p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Est. Noise</p>
            <p className="font-medium">{f.estimated_db != null ? `${Number(f.estimated_db).toFixed(1)} dBA` : '—'}</p>
          </div>
        </div>
      </div>

      {/* Flight track map */}
      {track.isLoading ? (
        <LoadingSpinner />
      ) : (
        <FlightTrack flight={f} track={track.data} />
      )}
    </div>
  );
}
