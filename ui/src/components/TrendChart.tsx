import { Line } from 'react-chartjs-2';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js';
import type { TrendItem } from '../types';
import { format } from 'date-fns';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

interface Props {
  data: TrendItem[];
  title?: string;
}

export function TrendChart({ data, title = 'Flight Trend' }: Props) {
  const chartData = {
    labels: data.map((d) => format(new Date(d.date), 'MMM dd')),
    datasets: [
      {
        label: 'Total Flights',
        data: data.map((d) => d.total_flights),
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59, 130, 246, 0.1)',
        fill: true,
        tension: 0.3,
      },
      {
        label: 'VIE-Related',
        data: data.map((d) => d.vie_related),
        borderColor: '#f59e0b',
        backgroundColor: 'rgba(245, 158, 11, 0.1)',
        fill: true,
        tension: 0.3,
      },
    ],
  };

  const options = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'top' as const },
      title: { display: true, text: title },
    },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 } },
    },
  };

  return (
    <div className="card">
      <div className="h-72">
        <Line data={chartData} options={options} />
      </div>
    </div>
  );
}
