export interface Flight {
  id: number;
  icao24: string;
  callsign: string | null;
  origin_country: string | null;
  first_seen: string;
  last_seen: string;
  max_altitude_m: number | null;
  min_altitude_m: number | null;
  runway_used: string | null;
  is_vie_related: boolean;
  track_data: unknown | null;
  created_at: string;
  updated_at: string;
  closest_distance_km?: number | null;
}

export interface FlightPosition {
  timestamp: string;
  latitude: number;
  longitude: number;
  altitude_m: number | null;
  speed_knots: number | null;
  heading: number | null;
  vertical_rate: number | null;
}

export interface NoiseReading {
  id: number;
  db_level: number;
  measured_at: string;
  latitude: number | null;
  longitude: number | null;
  notes: string | null;
  created_at: string;
}

export interface Aircraft {
  icao24: string;
  registration: string | null;
  type_code: string | null;
  model: string | null;
  operator: string | null;
  operator_callsign: string | null;
  first_seen: string | null;
  last_seen: string | null;
}

export interface PeriodStats {
  total_flights: number;
  vie_related: number;
  overflights: number;
  runway_11_29: number;
  runway_16_34: number;
  runway_unknown: number;
}

export interface StatsSummary {
  today: PeriodStats;
  week: PeriodStats;
  month: PeriodStats;
  all_time: PeriodStats;
}

export interface TrendItem {
  date: string;
  total_flights: number;
  vie_related: number;
}

export interface RunwayItem {
  date: string;
  runway_11_29: number;
  runway_16_34: number;
  runway_unknown: number;
}

export interface HourlyItem {
  hour: number;
  total_flights: number;
  vie_related: number;
  runway_11_29: number;
  runway_16_34: number;
  runway_unknown: number;
}

export interface PaginationMeta {
  page: number;
  per_page: number;
  total: number;
  pages: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: PaginationMeta;
}

export interface ApiResponse<T> {
  data: T;
}

export interface HealthStatus {
  status: string;
  timestamp: string;
  database: string;
  last_poll: {
    at: string | null;
    status: string | null;
    flights_added: number | null;
  } | null;
}

export interface FlightParams {
  page?: number;
  per_page?: number;
  date_from?: string;
  date_to?: string;
  vie_only?: boolean;
  runway?: string;
  sort?: string;
  order?: string;
}

export interface NoiseParams {
  page?: number;
  per_page?: number;
}

export interface NoiseCreatePayload {
  db_level: number;
  measured_at: string;
  latitude?: number | null;
  longitude?: number | null;
  notes?: string | null;
}
