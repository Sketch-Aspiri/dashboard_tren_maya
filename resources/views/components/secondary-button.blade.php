<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex min-h-[44px] items-center justify-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
