import { useState } from 'react';

interface Props {
  dateFrom: string;
  dateTo: string;
  runway: string;
  vieOnly: boolean;
  onChange: (filters: { dateFrom: string; dateTo: string; runway: string; vieOnly: boolean }) => void;
}

export function Filters({ dateFrom, dateTo, runway, vieOnly, onChange }: Props) {
  const [collapsed, setCollapsed] = useState(false);

  return (
    <div className="card mb-4">
      <button
        onClick={() => setCollapsed(!collapsed)}
        className="flex items-center justify-between w-full text-left"
      >
        <span className="font-medium text-slate-700 dark:text-slate-300">🔍 Filters</span>
        <span className="text-slate-400">{collapsed ? '▼' : '▲'}</span>
      </button>

      {!collapsed && (
        <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <div>
            <label className="block text-xs text-slate-500 dark:text-slate-400 mb-1">From</label>
            <input
              type="date"
              value={dateFrom}
              onChange={(e) => onChange({ dateFrom: e.target.value, dateTo, runway, vieOnly })}
              className="input-field w-full"
            />
          </div>
          <div>
            <label className="block text-xs text-slate-500 dark:text-slate-400 mb-1">To</label>
            <input
              type="date"
              value={dateTo}
              onChange={(e) => onChange({ dateFrom, dateTo: e.target.value, runway, vieOnly })}
              className="input-field w-full"
            />
          </div>
          <div>
            <label className="block text-xs text-slate-500 dark:text-slate-400 mb-1">Runway</label>
            <select
              value={runway}
              onChange={(e) => onChange({ dateFrom, dateTo, runway: e.target.value, vieOnly })}
              className="input-field w-full"
            >
              <option value="">All</option>
              <option value="11/29">11/29</option>
              <option value="16/34">16/34</option>
              <option value="UNKNOWN">UNKNOWN</option>
            </select>
          </div>
          <div className="flex items-end">
            <label className="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                checked={vieOnly}
                onChange={(e) => onChange({ dateFrom, dateTo, runway, vieOnly: e.target.checked })}
                className="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
              />
              <span className="text-sm">VIE-related only</span>
            </label>
          </div>
        </div>
      )}
    </div>
  );
}
