<x-app-layout>
    <x-slot name="header">
        <h1 class="font-heading text-xl font-semibold text-brand-green leading-tight">{{ __('Nuevo equipo') }}</h1>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('equipos.store') }}" class="space-y-6 rounded-xl border border-brand-green/10 bg-white p-6 shadow-sm">
                @csrf
                @include('equipos._form')
                <div class="flex items-center gap-4">
                    <button type="submit" class="inline-flex min-h-[44px] items-center rounded-md bg-brand-green px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-brand-green-dark">{{ __('Crear y vincular con Tickets') }}</button>
                    <a href="{{ route('equipos.index') }}" class="text-sm text-gray-600 hover:underline">{{ __('Cancelar') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
