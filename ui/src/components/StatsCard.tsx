import { TooltipIcon } from './TooltipIcon';

interface Props {
  label: string;
  value: string | number;
  icon?: string;
  color?: string;
  subtitle?: string;
  tooltip?: string;
}

export function StatsCard({ label, value, icon = '📊', color = 'blue', subtitle, tooltip }: Props) {
  const colorMap: Record<string, string> = {
    blue: 'border-l-blue-500',
    green: 'border-l-green-500',
    yellow: 'border-l-yellow-500',
    red: 'border-l-red-500',
    slate: 'border-l-slate-500',
  };
  const border = colorMap[color] || colorMap.blue;

  return (
    <div className={`card border-l-4 ${border}`}>
      <div className="flex items-center gap-3">
        <span className="text-2xl">{icon}</span>
        <div>
          <p className="text-sm text-slate-500 dark:text-slate-400 flex items-center">
            {label}
            {tooltip && <TooltipIcon text={tooltip} />}
          </p>
          <p className="text-2xl font-bold">{typeof value === 'number' ? value.toLocaleString() : value}</p>
          {subtitle && <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{subtitle}</p>}
        </div>
      </div>
    </div>
  );
}
