@extends('panel.layouts.app', ['active' => 'gaps'])

@section('title', $cluster['label'] . ' · Luki treści · ' . $project->name)

@php
  use App\Http\Controllers\Panel\GapContentController;
  use App\Http\Controllers\Panel\GapKeywordsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Gap\ContentGap;
  use OsfSeo\Gap\GapStatus;

  $base = PanelUrl::project($project->publicId, 'gaps');
  $safeUrl = static fn (?string $value): bool => $value !== null && preg_match('#^https?://#i', $value) === 1;
  $confidence = ['low' => 1, 'medium' => 2, 'high' => 3][$cluster['confidence'] ?? ''] ?? 1;
  $status = GapStatus::tryFrom((string) $cluster['status']);
  $sources = ['serp' => 'nasz pomiar SERP', 'gsc' => 'Google Search Console', 'labs' => 'punkt odniesienia DataForSEO Labs', 'slug' => 'adres strony pasujący do frazy'];
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ $base }}/content" class="text-brand-600 hover:underline">← Luki treści</a></p>

  <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
    <div>
      <h1 class="break-words text-2xl font-semibold tracking-tight text-slate-900">{{ $cluster['label'] }}</h1>
      <p class="mt-1 text-sm text-slate-500">Grupa {{ Format::number((int) $cluster['keywords_count']) }} fraz · wolumen luk {{ Format::number((int) $cluster['gap_volume']) }} z {{ Format::number((int) $cluster['total_volume']) }}@if ($cluster['active'] !== '1') · <span class="text-amber-700">nieaktualna</span>@endif</p>
    </div>
    <div class="flex items-center gap-3">
      <x-panel.score :value="$cluster['priority']" />
      <div class="text-xs text-slate-500">Priorytet grupy<br>(sygnał do sprawdzenia)</div>
    </div>
  </div>

  @php($clusterTopics = collect($strategyTopics)->unique('topic')->values())
  @if ($clusterTopics->isNotEmpty())
    <div class="-mt-4 mb-6">
      <p class="text-xs font-medium text-slate-600">Frazy tej grupy w Strategii ({{ $clusterTopics->count() }} {{ \OsfSeo\Opportunities\Text::plural($clusterTopics->count(), 'temat', 'tematy', 'tematów') }}):</p>
      <div class="mt-1 flex flex-wrap gap-2">
        @foreach ($clusterTopics->take(10) as $clusterTopic)
          @include('panel.strategy.partials.topic-link', ['topic' => $clusterTopic, 'projectId' => $project->publicId])
        @endforeach
      </div>
    </div>
  @endif

  <div class="grid gap-6 lg:grid-cols-3">
    <x-panel.card class="lg:col-span-2">
      <h2 class="text-base font-semibold text-slate-900">Luka treści (heurystyka)</h2>
      <div class="mt-2 flex flex-wrap items-center gap-2">
        <x-panel.content-gap :value="$cluster['content_gap']" />
        <x-panel.confidence :level="$confidence" />
      </div>
      <p class="mt-3 text-sm text-slate-700">{{ ucfirst(ContentGap::reasonLabel($cluster['content_reason'])) }}.</p>
      <dl class="mt-3 divide-y divide-slate-100 text-sm">
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Strona projektu</dt>
          <dd class="text-right">
            @if ($safeUrl($cluster['target_url']))
              <a href="{{ $cluster['target_url'] }}" rel="noopener noreferrer" target="_blank" class="break-all text-brand-600 hover:underline">{{ $cluster['target_url'] }}</a>
              <span class="block text-xs text-slate-500">źródło: {{ $sources[$cluster['target_source']] ?? $cluster['target_source'] }}</span>
            @else
              <span class="text-slate-500">Brak przypisanej strony</span>
            @endif
          </dd>
        </div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Konkurenci z podstronami dla grupy</dt><dd class="tabular-nums">{{ $cluster['competitor_pages'] }} <span class="text-xs text-slate-500">(dedykowane adresy: {{ $cluster['dedicated_pages'] }})</span></dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Najlepszy konkurent</dt><dd>{{ $cluster['competitor_name'] ?? '—' }}@if ($cluster['best_competitor_rank'] !== null) <span class="tabular-nums text-slate-500">#{{ $cluster['best_competitor_rank'] }} (Labs)</span>@endif</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Widoczność projektu (fraza wiodąca)</dt><dd><x-panel.project-visibility :value="$cluster['visibility']" /></dd></div>
      </dl>
      <p class="mt-3 text-xs text-slate-500">Heurystyka bez AI: sprawdź treść strony projektu i stron konkurentów, zanim zdecydujesz o nowej stronie albo rozbudowie istniejącej.</p>
    </x-panel.card>

    @if ($canManage)
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Praca nad grupą</h2>
        <form method="post" action="{{ GapContentController::clusterUrl($project->publicId, $cluster['public_id']) }}" class="mt-3 space-y-3">
          <x-panel.nonce />
          <div>
            <label for="status" class="block text-sm font-medium text-slate-700">Status</label>
            <select id="status" name="status" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
              @foreach (GapStatus::cases() as $option)
                <option value="{{ $option->value }}" @selected($status === $option)>{{ $option->label() }}</option>
              @endforeach
            </select>
            @if (isset($errors['status']))
              <p class="mt-1 text-sm text-red-700">{{ $errors['status'] }}</p>
            @endif
          </div>
          <div>
            <label for="note" class="block text-sm font-medium text-slate-700">Notatka</label>
            <textarea id="note" name="note" rows="3" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">{{ $cluster['note'] }}</textarea>
          </div>
          <x-panel.button type="submit">Zapisz</x-panel.button>
        </form>
      </x-panel.card>
    @else
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Status</h2>
        <p class="mt-2"><x-panel.candidate-status :status="$cluster['status']" /></p>
        @if ($cluster['note'] !== null)
          <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $cluster['note'] }}</p>
        @endif
      </x-panel.card>
    @endif
  </div>

  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">Frazy grupy</h2>
    <div class="mt-3 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
            <th class="py-2 pr-4 font-medium">Fraza</th>
            <th class="py-2 pr-4 text-center font-medium">Priorytet</th>
            <th class="py-2 pr-4 font-medium">Luka</th>
            <th class="py-2 pr-4 text-right font-medium">Wolumen</th>
            <th class="py-2 pr-4 text-right font-medium">Trudność SEO</th>
            <th class="py-2 pr-4 font-medium">Konkurent (Labs)</th>
            <th class="py-2 font-medium">Projekt</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @foreach ($keywords as $row)
            <tr>
              <td class="max-w-xs py-2 pr-4"><a href="{{ GapKeywordsController::keywordUrl($project->publicId, $row['public_id']) }}" class="break-words font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $row['keyword'] }}</a></td>
              <td class="py-2 pr-4 text-center"><x-panel.score :value="$row['priority']" size="sm" /></td>
              <td class="py-2 pr-4"><x-panel.gap-type :value="$row['gap_type']" /></td>
              <td class="py-2 pr-4 text-right tabular-nums">{{ Format::number($row['search_volume'] === null ? null : (int) $row['search_volume']) }}</td>
              <td class="py-2 pr-4 text-right tabular-nums">{{ Format::number($row['keyword_difficulty'] === null ? null : (int) $row['keyword_difficulty']) }}</td>
              <td class="py-2 pr-4 text-xs text-slate-600">{{ $row['competitor_name'] ?? '—' }}@if ($row['best_competitor_rank'] !== null) <span class="tabular-nums">#{{ $row['best_competitor_rank'] }}</span>@endif</td>
              <td class="py-2"><x-panel.project-visibility :value="$row['visibility']" :source="$row['visibility_source']" :position="$row['project_position']" /></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </x-panel.card>

  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">Strony konkurentów dla tej grupy</h2>
    <p class="mt-1 text-xs text-slate-500">Adresy, którymi konkurenci rankują na frazy grupy (DataForSEO Labs) — punkt wyjścia do porównania treści.</p>
    @if ($urls === [])
      <p class="mt-3 text-sm text-slate-500">Brak adresów konkurentów w progu znaczącej pozycji.</p>
    @else
      <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
              <th class="py-2 pr-4 font-medium">Konkurent</th>
              <th class="py-2 pr-4 font-medium">Strona</th>
              <th class="py-2 pr-4 text-right font-medium">Frazy grupy</th>
              <th class="py-2 text-right font-medium">Najlepsza pozycja (Labs)</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($urls as $url)
              <tr>
                <td class="py-2 pr-4 text-slate-700">{{ $url['competitor_name'] ?? '—' }}</td>
                <td class="max-w-md py-2 pr-4">
                  @if ($url['title'] !== null)<span class="block truncate text-slate-700">{{ $url['title'] }}</span>@endif
                  @if ($safeUrl($url['url']))<a href="{{ $url['url'] }}" rel="noopener noreferrer" target="_blank" class="block break-all text-xs text-brand-600 hover:underline">{{ $url['url'] }}</a>@endif
                </td>
                <td class="py-2 pr-4 text-right tabular-nums">{{ $url['keywords'] }}</td>
                <td class="py-2 text-right tabular-nums">#{{ $url['best_rank'] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </x-panel.card>
@endsection
