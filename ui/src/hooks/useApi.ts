import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import type { FlightParams, NoiseParams, NoiseCreatePayload } from '../types';

// Flights
export function useFlights(params: FlightParams = {}) {
  return useQuery({
    queryKey: ['flights', params],
    queryFn: () => api.flights.list(params),
    refetchInterval: 60000,
  });
}

export function useFlight(id: number | undefined) {
  return useQuery({
    queryKey: ['flight', id],
    queryFn: () => api.flights.get(id!),
    enabled: id !== undefined && id > 0,
  });
}

export function useFlightTrack(id: number | undefined) {
  return useQuery({
    queryKey: ['flight-track', id],
    queryFn: () => api.flights.track(id!),
    enabled: id !== undefined && id > 0,
  });
}

// Stats
export function useStatsSummary() {
  return useQuery({
    queryKey: ['stats-summary'],
    queryFn: () => api.stats.summary(),
    refetchInterval: 60000,
  });
}

export function useStatsTrend(days: number) {
  return useQuery({
    queryKey: ['stats-trend', days],
    queryFn: () => api.stats.trend(days),
  });
}

export function useStatsRunways(dateFrom: string, dateTo: string) {
  return useQuery({
    queryKey: ['stats-runways', dateFrom, dateTo],
    queryFn: () => api.stats.runways(dateFrom, dateTo),
    enabled: !!dateFrom && !!dateTo,
  });
}

export function useStatsHourly(date: string) {
  return useQuery({
    queryKey: ['stats-hourly', date],
    queryFn: () => api.stats.hourly(date),
    enabled: !!date,
  });
}

// Noise
export function useNoise(params: NoiseParams = {}) {
  return useQuery({
    queryKey: ['noise', params],
    queryFn: () => api.noise.list(params),
    refetchInterval: 120000,
  });
}

export function useNoiseCreate() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: NoiseCreatePayload) => api.noise.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['noise'] });
    },
  });
}

// Aircraft
export function useAircraft(icao24: string | undefined) {
  return useQuery({
    queryKey: ['aircraft', icao24],
    queryFn: () => api.aircraft.get(icao24!),
    enabled: !!icao24,
  });
}

// Health
export function useHealth() {
  return useQuery({
    queryKey: ['health'],
    queryFn: () => api.health(),
    refetchInterval: 60000,
  });
}
