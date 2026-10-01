/*
 * Wykres dzienny dashboardu: bieżący okres (kolor marki) vs poprzedni okres (przygaszona linia przerywana),
 * jedna oś Y, przełącznik metryki (kliknięcia / wyświetlenia) zamiast drugiej osi.
 * Dane z atrybutu data-chart (JSON z serwera); tabela danych obok wykresu dla czytników ekranu.
 */
import {
  CategoryScale,
  Chart,
  Filler,
  LinearScale,
  LineController,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Filler);

const css = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

const number = new Intl.NumberFormat('pl-PL');

const label = (date) => {
  const [, month, day] = date.split('-');

  return `${day}.${month}`;
};

export function renderChart(canvas, metric) {
  const data = JSON.parse(canvas.dataset.chart);
  const accent = css('--color-brand-600', '#1f5285');
  const muted = '#94a3b8';
  const grid = '#e2e8f0';
  const ink = '#475569';

  return new Chart(canvas, {
    type: 'line',
    data: {
      labels: data.dates.map(label),
      datasets: [
        {
          label: 'Bieżący okres',
          data: data[metric],
          borderColor: accent,
          backgroundColor: accent,
          borderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 4,
          tension: 0.25,
        },
        {
          label: 'Poprzedni okres',
          data: data[`previous_${metric}`],
          borderColor: muted,
          backgroundColor: muted,
          borderWidth: 2,
          borderDash: [5, 4],
          pointRadius: 0,
          pointHoverRadius: 4,
          tension: 0.25,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: (items) => {
              const index = items[0].dataIndex;

              return `${label(data.dates[index])} (poprzedni okres: ${label(data.previous_dates[index])})`;
            },
            label: (item) => `${item.dataset.label}: ${number.format(item.parsed.y)}`,
          },
        },
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: ink, maxTicksLimit: 8, maxRotation: 0 } },
        y: {
          beginAtZero: true,
          grid: { color: grid, lineWidth: 1 },
          border: { display: false },
          ticks: { color: ink, callback: (value) => number.format(value) },
        },
      },
    },
  });
}
