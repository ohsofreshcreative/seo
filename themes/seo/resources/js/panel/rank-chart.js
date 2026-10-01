/*
 * Historia Pozycji SERP frazy: projekt (kolor marki, grubsza linia) i aktywni konkurenci; oś Y odwrócona (#1 na górze),
 * przerwa w linii = poza sprawdzonym TOP. Dane z atrybutu data-chart (JSON z serwera); tabela obok wykresu.
 */
import {
  CategoryScale,
  Chart,
  Legend,
  LinearScale,
  LineController,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Legend);

const css = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

const palette = ['#d97706', '#be123c', '#7c3aed', '#0f766e', '#475569', '#a16207', '#9333ea', '#0369a1'];

export function renderRankChart(canvas) {
  const data = JSON.parse(canvas.dataset.chart);
  const accent = css('--color-brand-600', '#1f5285');
  const ink = '#475569';
  let competitor = 0;

  return new Chart(canvas, {
    type: 'line',
    data: {
      labels: data.labels,
      datasets: data.series.map((series) => {
        const color = series.project ? accent : palette[competitor++ % palette.length];

        return {
          label: series.label,
          data: series.data,
          borderColor: color,
          backgroundColor: color,
          borderWidth: series.project ? 3 : 1.5,
          pointRadius: series.data.length > 30 ? 0 : 3,
          pointHoverRadius: 5,
          spanGaps: false,
          tension: 0,
        };
      }),
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'bottom', labels: { color: ink, boxWidth: 12 } },
        tooltip: {
          callbacks: {
            label: (item) => `${item.dataset.label}: ${item.raw === null ? `poza TOP${data.depth}` : `#${item.raw}`}`,
          },
        },
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: ink, maxTicksLimit: 8, maxRotation: 0 } },
        y: {
          reverse: true,
          min: 1,
          suggestedMax: Math.min(data.depth, 20),
          max: data.depth,
          grid: { color: '#e2e8f0' },
          border: { display: false },
          ticks: { color: ink, precision: 0, callback: (value) => `#${value}` },
        },
      },
    },
  });
}
