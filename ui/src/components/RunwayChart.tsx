import { Doughnut } from 'react-chartjs-2';
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js';
import { TooltipIcon } from '../components/TooltipIcon';

ChartJS.register(ArcElement, Tooltip, Legend);

interface Props {
  runway_11_29: number;
  runway_16_34: number;
  runway_unknown: number;
}

export function RunwayChart({ runway_11_29, runway_16_34, runway_unknown }: Props) {
  const data = {
    // 'VIE UNK' = VIE-related flight that couldn't be classified to a specific runway.
    // Overflights are NOT included here — they have their own bucket in the StatsCard row.
    labels: ['11/29', '16/34', 'VIE UNK'],
    datasets: [
      {
        data: [runway_11_29, runway_16_34, runway_unknown],
        backgroundColor: ['#3b82f6', '#22c55e', '#94a3b8'],
        borderWidth: 2,
        borderColor: '#ffffff',
      },
    ],
  };

  const options = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom' as const },
    },
  };

  return (
    <div className="card">
      <h3 className="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">
        <span className="inline-flex items-center gap-1">
          Runway Distribution <TooltipIcon text="VIE-related flights by runway configuration. 'VIE UNK' = VIE-related flight that couldn't be classified to a specific runway. Overflights are not included here — see Overflights card." />
        </span>
      </h3>
      <div className="h-56">
        <Doughnut data={data} options={options} />
      </div>
    </div>
  );
}
