import { useEffect } from 'react';
import { MapContainer, TileLayer, Rectangle, Marker, Popup, Polyline, useMap } from 'react-leaflet';
import L from 'leaflet';
import type { Flight } from '../types';

// Fix default icon paths
delete (L.Icon.Default.prototype as unknown as Record<string, unknown>)._getIconUrl;
L.Icon.Default.mergeOptions({
  iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
  iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
  shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
});

const MANNERSDORF: [number, number] = [47.97, 16.61];
const LOWW: [number, number] = [48.1103, 16.5697];
const BOUNDS: L.LatLngBoundsExpression = [
  [47.93, 16.56],
  [48.01, 16.66],
];

const airportIcon = L.divIcon({
  html: '✈️',
  className: 'text-2xl',
  iconSize: [30, 30],
  iconAnchor: [15, 15],
});

function FitBounds({ flights }: { flights?: Flight[] }) {
  const map = useMap();
  useEffect(() => {
    if (flights && flights.length > 0) {
      // Keep current view unless map is at default
    } else {
      map.setView(MANNERSDORF, 12);
    }
  }, [map, flights]);
  return null;
}

interface Props {
  height?: string;
  recentFlights?: Flight[];
  className?: string;
}

export function MapView({ height = '400px', recentFlights, className = '' }: Props) {
  const flightPositions: [number, number][] = [];

  return (
    <div className={`card p-0 overflow-hidden ${className}`} style={{ height }}>
      <MapContainer
        center={MANNERSDORF}
        zoom={12}
        scrollWheelZoom={true}
        style={{ height: '100%', width: '100%' }}
      >
        <TileLayer
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>'
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
        />

        {/* Mannersdorf bounding box */}
        <Rectangle
          bounds={BOUNDS}
          pathOptions={{
            color: '#3b82f6',
            fillColor: '#3b82f6',
            fillOpacity: 0.08,
            weight: 2,
            dashArray: '5,5',
          }}
        />

        {/* LOWW airport */}
        <Marker position={LOWW} icon={airportIcon}>
          <Popup>
            <strong>Vienna Airport (LOWW)</strong>
          </Popup>
        </Marker>

        {/* Recent flight dots */}
        {recentFlights?.map((f) => {
          const pos = flightPositions;
          void pos; // placeholder – no position data in flight object
          return null;
        })}

        <FitBounds flights={recentFlights} />
      </MapContainer>
    </div>
  );
}
