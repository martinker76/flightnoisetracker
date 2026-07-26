import { Bar } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend } from 'chart.js';
import type { HourlyItem } from '../types';

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

interface Props {
  data: HourlyItem[];
}

export function HourlyChart({ data }: Props) {
  const labels = Array.from({ length: 24 }, (_, i) => `${i.toString().padStart(2, '0')}:00`);
  const items = data.length > 0 ? data : Array.from({ length: 24 }, (_, i) => ({
    hour: i,
    total_flights: 0,
    vie_related: 0,
    runway_11_29: 0,
    runway_16_34: 0,
    runway_unknown: 0,
  }));

  const chartData = {
    labels,
    datasets: [
      {
        label: '11/29',
        data: items.map((d) => d.runway_11_29),
        backgroundColor: '#3b82f6',
      },
      {
        label: '16/34',
        data: items.map((d) => d.runway_16_34),
        backgroundColor: '#22c55e',
      },
      {
        label: 'UNKNOWN',
        data: items.map((d) => d.runway_unknown),
        backgroundColor: '#94a3b8',
      },
    ],
  };

  const options = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      title: { display: true, text: 'Hourly Flight Distribution' },
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
