/*
 * Panel OSF SEO — osobne wejście JS: tylko Alpine (bez jQuery, GSAP, Reacta i CDN).
 * Wykresy (Chart.js) ładowane dynamicznie tylko na stronach z [data-chart].
 */
import Alpine from 'alpinejs';

// Postęp synchronizacji: odpytywanie stanu co 10 s tylko, gdy synchronizacja trwa.
Alpine.data('syncStatus', (url, initial) => ({
  ...initial,
  timer: null,

  init() {
    if (this.active) {
      this.timer = setInterval(() => this.refresh(), 10000);
    }
  },

  async refresh() {
    try {
      const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

      if (!response.ok) {
        return;
      }

      const data = await response.json();
      this.label = data.label;
      this.lastSuccess = data.last_success_label;
      this.progress = data.backfill_progress;
      this.pending = data.pending_jobs;

      // Koniec synchronizacji — przeładowanie pokazuje nowe dane i historię zadań.
      if (!data.active) {
        clearInterval(this.timer);
        window.location.reload();
      }
    } catch {
      // Chwilowy brak sieci — kolejna próba przy następnym odświeżeniu.
    }
  },

  destroy() {
    clearInterval(this.timer);
  },
}));

// Wykres dashboardu — Chart.js ładowany osobnym plikiem tylko tam, gdzie jest wykres.
Alpine.data('trafficChart', () => ({
  metric: 'clicks',
  chart: null,

  async init() {
    const { renderChart } = await import('./panel/chart.js');
    this.chart = renderChart(this.$refs.canvas, this.metric);
  },

  async show(metric) {
    this.metric = metric;
    this.chart?.destroy();
    const { renderChart } = await import('./panel/chart.js');
    this.chart = renderChart(this.$refs.canvas, metric);
  },
}));

window.Alpine = Alpine;
Alpine.start();
