import { useEffect, useMemo } from 'react';
import { MapContainer, TileLayer, Polyline, CircleMarker, Popup, useMap } from 'react-leaflet';
import L from 'leaflet';
import type { Flight } from '../types';

const LOWW: [number, number] = [48.1103, 16.5697];

const airportIcon = L.divIcon({
  html: '✈️',
  className: 'text-2xl',
  iconSize: [30, 30],
  iconAnchor: [15, 15],
});

function altitudeColor(alt: number, min: number, max: number): string {
  if (max === min) return '#eab308';
  const ratio = (alt - min) / (max - min);
  if (ratio < 0.33) return '#22c55e'; // green
  if (ratio < 0.66) return '#eab308'; // yellow
  return '#ef4444'; // red
}

interface TrackPoint {
  lat: number;
  lon: number;
  alt: number;
}

function parseTrackPoints(track: GeoJSON.FeatureCollection | null | undefined): TrackPoint[] {
  if (!track || !track.features || track.features.length === 0) return [];
  const points: TrackPoint[] = [];

  for (const feature of track.features) {
    if (feature.geometry.type === 'LineString') {
      const coords = feature.geometry.coordinates as [number, number, number][];
      for (const coord of coords) {
        points.push({ lon: coord[0], lat: coord[1], alt: coord[2] || 0 });
      }
    } else if (feature.geometry.type === 'Point') {
      const coords = feature.geometry.coordinates as [number, number, number];
      points.push({ lon: coords[0], lat: coords[1], alt: coords[2] || 0 });
    }
  }

  return points;
}

function FitTrackBounds({ points }: { points: [number, number][] }) {
  const map = useMap();
  useEffect(() => {
    if (points.length > 1) {
      const bounds = L.latLngBounds(points);
      map.fitBounds(bounds, { padding: [50, 50] });
    }
  }, [map, points]);
  return null;
}

interface Props {
  flight: Flight;
  track: GeoJSON.FeatureCollection | null | undefined;
  height?: string;
}

export function FlightTrack({ flight, track, height = '500px' }: Props) {
  const points = useMemo(() => parseTrackPoints(track), [track]);
  const latlngs = useMemo(() => points.map((p) => [p.lat, p.lon] as [number, number]), [points]);

  const altitudes = points.map((p) => p.alt);
  const minAlt = Math.min(...altitudes, 0);
  const maxAlt = Math.max(...altitudes, 1);

  const segments = useMemo(() => {
    if (points.length < 2) return [];
    const segs: { positions: [number, number][]; color: string }[] = [];
    for (let i = 0; i < points.length - 1; i++) {
      const avgAlt = (points[i].alt + points[i + 1].alt) / 2;
      segs.push({
        positions: [
          [points[i].lat, points[i].lon],
          [points[i + 1].lat, points[i + 1].lon],
        ],
        color: altitudeColor(avgAlt, minAlt, maxAlt),
      });
    }
    return segs;
  }, [points, minAlt, maxAlt]);

  if (points.length === 0) {
    return (
      <div className="card p-8 text-center text-slate-500">
        No track data available for this flight.
      </div>
    );
  }

  return (
    <div className="card p-0 overflow-hidden" style={{ height }}>
      <MapContainer
        center={latlngs[0] || [47.97, 16.61]}
        zoom={12}
        scrollWheelZoom={true}
        style={{ height: '100%', width: '100%' }}
      >
        <TileLayer
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>'
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
        />

        {/* Track segments colored by altitude */}
        {segments.map((seg, i) => (
          <Polyline key={i} positions={seg.positions} pathOptions={{ color: seg.color, weight: 3 }} />
        ))}

        {/* Start marker (green) */}
        {points.length > 0 && (
          <CircleMarker
            center={[points[0].lat, points[0].lon]}
            radius={8}
            pathOptions={{ color: '#22c55e', fillColor: '#22c55e', fillOpacity: 1 }}
          >
            <Popup>Start: {points[0].alt}m</Popup>
          </CircleMarker>
        )}

        {/* End marker (red) */}
        {points.length > 1 && (
          <CircleMarker
            center={[points[points.length - 1].lat, points[points.length - 1].lon]}
            radius={8}
            pathOptions={{ color: '#ef4444', fillColor: '#ef4444', fillOpacity: 1 }}
          >
            <Popup>End: {points[points.length - 1].alt}m</Popup>
          </CircleMarker>
        )}

        {/* LOWW airport */}
        <CircleMarker
          center={LOWW}
          radius={6}
          pathOptions={{ color: '#6366f1', fillColor: '#6366f1', fillOpacity: 0.8 }}
        >
          <Popup>Vienna Airport (LOWW)</Popup>
        </CircleMarker>

        <FitTrackBounds points={latlngs} />
      </MapContainer>
    </div>
  );
}
