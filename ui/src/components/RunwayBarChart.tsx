import { Bar } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend } from 'chart.js';
import type { RunwayItem } from '../types';
import { format } from 'date-fns';

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

interface Props {
  data: RunwayItem[];
}

export function RunwayBarChart({ data }: Props) {
  const chartData = {
    labels: data.map((d) => format(new Date(d.date), 'MMM dd')),
    datasets: [
      {
        label: '11/29',
        data: data.map((d) => d.runway_11_29),
        backgroundColor: '#3b82f6',
      },
      {
        label: '16/34',
        data: data.map((d) => d.runway_16_34),
        backgroundColor: '#22c55e',
      },
      {
        // VIE-related but unclassified. Overflights are tracked separately and
        // not stacked here — see the 'Overflights' row in the StatsCard summary.
        label: 'VIE UNK',
        data: data.map((d) => d.runway_unknown),
        backgroundColor: '#94a3b8',
      },
    ],
  };

  const options = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      title: { display: true, text: 'Runway Usage by Day' },
      legend: { position: 'top' as const },
    },
    scales: {
      x: { stacked: true },
      y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } },
    },
  };

  return (
    <div className="card">
      <div className="h-72">
        <Bar data={chartData} options={options} />
      </div>
    </div>
  );
}
