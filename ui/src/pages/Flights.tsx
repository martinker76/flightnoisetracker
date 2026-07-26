import { useState, useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useFlights } from '../hooks/useApi';
import { FlightTable } from '../components/FlightTable';
import { Filters } from '../components/Filters';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { ErrorAlert } from '../components/ErrorAlert';
import type { FlightParams } from '../types';

export default function Flights() {
  const [searchParams, setSearchParams] = useSearchParams();

  const getParam = (key: string, fallback: string) => searchParams.get(key) || fallback;

  const [params, setParams] = useState<FlightParams>({
    page: parseInt(getParam('page', '1')),
    per_page: parseInt(getParam('per_page', '25')),
    date_from: getParam('date_from', ''),
    date_to: getParam('date_to', ''),
    vie_only: getParam('vie_only', '') === 'true',
    runway: getParam('runway', ''),
    sort: getParam('sort', 'first_seen'),
    order: getParam('order', 'desc'),
  });

  const updateParams = useCallback(
    (updates: Partial<FlightParams>) => {
      setParams((prev) => {
        const next = { ...prev, ...updates };
        // Sync to URL
        const sp = new URLSearchParams();
        if (next.page && next.page > 1) sp.set('page', String(next.page));
        if (next.per_page && next.per_page !== 25) sp.set('per_page', String(next.per_page));
        if (next.date_from) sp.set('date_from', next.date_from);
        if (next.date_to) sp.set('date_to', next.date_to);
        if (next.vie_only) sp.set('vie_only', 'true');
        if (next.runway) sp.set('runway', next.runway);
        if (next.sort && next.sort !== 'first_seen') sp.set('sort', next.sort);
        if (next.order && next.order !== 'desc') sp.set('order', next.order);
        setSearchParams(sp);
        return next;
      });
    },
    [setSearchParams]
  );

  const query = useFlights(params);

  const handleSort = useCallback(
    (col: string) => {
      const newOrder = params.sort === col && params.order === 'asc' ? 'desc' : 'asc';
      updateParams({ sort: col, order: newOrder, page: 1 });
    },
    [params.sort, params.order, updateParams]
  );

  const handlePage = useCallback(
    (page: number) => updateParams({ page }),
    [updateParams]
  );

  const handlePerPage = useCallback(
    (per_page: number) => updateParams({ per_page, page: 1 }),
    [updateParams]
  );

  const handleFilterChange = useCallback(
    (filters: { dateFrom: string; dateTo: string; runway: string; vieOnly: boolean }) => {
      updateParams({
        date_from: filters.dateFrom,
        date_to: filters.dateTo,
        runway: filters.runway,
        vie_only: filters.vieOnly,
        page: 1,
      });
    },
    [updateParams]
  );

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">✈️ Flights</h1>

      <Filters
        dateFrom={params.date_from || ''}
        dateTo={params.date_to || ''}
        runway={params.runway || ''}
        vieOnly={params.vie_only || false}
        onChange={handleFilterChange}
      />

      {query.isLoading && <LoadingSpinner />}
      {query.isError && <ErrorAlert error={query.error} onRetry={() => query.refetch()} />}

      {query.data && (
        <FlightTable
          flights={query.data.data}
          meta={query.data.meta}
          sort={params.sort || 'first_seen'}
          order={params.order || 'desc'}
          onSort={handleSort}
          onPage={handlePage}
          onPerPage={handlePerPage}
        />
      )}
    </div>
  );
}
