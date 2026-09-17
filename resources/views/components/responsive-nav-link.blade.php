@props(['active'])

@php
$classes = ($active ?? false)
            ? 'flex items-center min-h-[44px] w-full ps-3 pe-4 py-2 border-l-4 border-brand-teal text-start text-base font-semibold text-brand-green bg-brand-mist focus:outline-none focus:text-brand-green-dark focus:bg-brand-mist focus:border-brand-green transition duration-150 ease-in-out'
            : 'flex items-center min-h-[44px] w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-brand-green hover:bg-brand-mist hover:border-brand-mint focus:outline-none focus:text-brand-green focus:bg-brand-mist focus:border-brand-mint transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
