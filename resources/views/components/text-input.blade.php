@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500']) }}>
