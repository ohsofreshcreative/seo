@props(['name', 'label', 'value' => '', 'type' => 'text', 'error' => null, 'help' => null, 'required' => false])
<div>
  <label for="field-{{ $name }}" class="block text-sm font-medium text-slate-700">{{ $label }}@if ($required)<span class="text-red-600"> *</span>@endif</label>
  <input id="field-{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $value }}" @if ($required) required @endif
    @if ($error) aria-invalid="true" aria-describedby="field-{{ $name }}-error" @endif
    {{ $attributes->class([
      'mt-1 block w-full rounded-md text-sm shadow-sm',
      'border-red-400 focus:border-red-500 focus:ring-red-500' => $error,
      'border-slate-300 focus:border-brand-500 focus:ring-brand-500' => ! $error,
    ]) }}>
  @if ($error)
    <p id="field-{{ $name }}-error" class="mt-1 text-sm text-red-700">{{ $error }}</p>
  @elseif ($help)
    <p class="mt-1 text-xs text-slate-500">{{ $help }}</p>
  @endif
</div>
