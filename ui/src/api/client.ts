import type { FlightParams, NoiseParams, NoiseCreatePayload } from '../types';

const BASE = '/api';

async function request<T>(path: string, options?: RequestInit): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    headers: { 'Content-Type': 'application/json', ...options?.headers },
    ...options,
  });
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`API ${res.status}: ${body || res.statusText}`);
  }
  return res.json() as Promise<T>;
}

function qs(params: Record<string, string | number | boolean | undefined> | object): string {
  const entries = Object.entries(params).filter(
    ([, v]) => v !== undefined && v !== null && v !== ''
  ) as [string, string | number | boolean][];
  if (entries.length === 0) return '';
  return '?' + entries.map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`).join('&');
}

export const api = {
  flights: {
    list: (params: FlightParams = {}) =>
      request<import('../types').PaginatedResponse<import('../types').Flight>>(
        `/flights${qs(params)}`
      ),
    get: (id: number) =>
      request<import('../types').ApiResponse<import('../types').Flight>>(`/flights/${id}`),
    track: (id: number) =>
      request<GeoJSON.FeatureCollection>(`/flights/${id}/track`),
  },
  stats: {
    summary: () =>
      request<import('../types').ApiResponse<import('../types').StatsSummary>>('/stats/summary'),
    trend: (days: number) =>
      request<import('../types').ApiResponse<import('../types').TrendItem[]>>(
        `/stats/trend${qs({ days })}`
      ),
    runways: (date_from: string, date_to: string) =>
      request<import('../types').ApiResponse<import('../types').RunwayItem[]>>(
        `/stats/runways${qs({ date_from, date_to })}`
      ),
    hourly: (date: string) =>
      request<import('../types').ApiResponse<import('../types').HourlyItem[]>>(
        `/stats/hourly${qs({ date })}`
      ),
  },
  noise: {
    list: (params: NoiseParams = {}) =>
      request<import('../types').PaginatedResponse<import('../types').NoiseReading>>(
        `/noise${qs(params)}`
      ),
    create: (payload: NoiseCreatePayload) =>
      request<import('../types').ApiResponse<import('../types').NoiseReading>>('/noise', {
        method: 'POST',
        body: JSON.stringify(payload),
      }),
  },
  aircraft: {
    get: (icao24: string) =>
      request<import('../types').ApiResponse<import('../types').Aircraft>>(`/aircraft/${icao24}`),
  },
  health: () => request<import('../types').HealthStatus>('/health'),
};
