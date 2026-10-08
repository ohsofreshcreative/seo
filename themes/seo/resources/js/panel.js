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

// Postęp przebiegu w tle (wyszukiwanie nowych fraz, pomiar pozycji): odpytywanie co 5 s tylko, gdy przebieg trwa
// (wykonuje go tło, nie przeglądarka).
const runProgress = (url, initial) => ({
  ...initial,
  timer: null,

  init() {
    if (this.active) {
      this.timer = setInterval(() => this.refresh(), 5000);
    }
  },

  async refresh() {
    try {
      const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

      if (!response.ok) {
        return;
      }

      Object.assign(this, await response.json());

      // Koniec przebiegu — przeładowanie pokazuje nowe frazy i podsumowanie.
      if (!this.active) {
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
});

Alpine.data('discoveryProgress', runProgress);
Alpine.data('runProgress', runProgress);

// Kopiowanie raportu AI do schowka (eksport bez zależności): tekst przygotowany po stronie serwera w ukrytym polu (`x-ref`).
const fallbackCopy = (text) => {
  const area = document.createElement('textarea');
  area.value = text;
  area.setAttribute('readonly', '');
  area.style.position = 'fixed';
  area.style.opacity = '0';
  document.body.appendChild(area);
  area.select();
  const copied = document.execCommand('copy');
  area.remove();

  return copied;
};

Alpine.data('copyText', () => ({
  copied: null,
  failed: false,

  async copy(name) {
    const text = this.$refs[name]?.value ?? '';
    this.failed = false;

    try {
      await navigator.clipboard.writeText(text);
    } catch {
      // Schowek niedostępny (np. połączenie bez HTTPS) — zaznaczenie tymczasowego pola.
      this.failed = !fallbackCopy(text);
    }

    if (!this.failed) {
      this.copied = name;
      setTimeout(() => {
        if (this.copied === name) {
          this.copied = null;
        }
      }, 2500);
    }
  },
}));

// Historia Pozycji SERP — Chart.js ładowany osobnym plikiem tylko na stronie frazy.
Alpine.data('rankChart', () => ({
  chart: null,

  async init() {
    const { renderRankChart } = await import('./panel/rank-chart.js');
    this.chart = renderRankChart(this.$refs.canvas);
  },

  destroy() {
    this.chart?.destroy();
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
