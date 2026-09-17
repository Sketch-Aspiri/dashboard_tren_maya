@props(['label', 'value', 'hint' => null])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-brand-green/10 bg-white p-6 shadow-sm shadow-brand-green/5']) }}>
    <p class="text-sm font-medium text-gray-500">{{ $label }}</p>
    <p class="mt-2 font-heading text-3xl font-bold text-brand-green">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>
    @endif
</div>
