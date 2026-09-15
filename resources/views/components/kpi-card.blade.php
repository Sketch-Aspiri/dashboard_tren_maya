@props(['label', 'value', 'hint' => null])

<div {{ $attributes->merge(['class' => 'bg-white overflow-hidden shadow-sm sm:rounded-lg p-6']) }}>
    <p class="text-sm font-medium text-gray-500">{{ $label }}</p>
    <p class="mt-2 text-3xl font-semibold text-gray-900">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>
    @endif
</div>
