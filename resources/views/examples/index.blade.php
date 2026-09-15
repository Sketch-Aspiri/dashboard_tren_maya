<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Examples') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-md p-4">
                    {{ session('status') }}
                </div>
            @endif

            <div class="flex justify-between items-center">
                <input
                    type="search"
                    x-data
                    x-ref="search"
                    @input.debounce.400ms="$dispatch('examples-search', { q: $event.target.value })"
                    placeholder="{{ __('Buscar por nombre...') }}"
                    class="border-gray-300 rounded-md shadow-sm w-64 text-sm"
                >

                @can('create', \App\Models\Example::class)
                    <a href="{{ route('examples.create') }}"
                       class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        {{ __('Nuevo') }}
                    </a>
                @endcan
            </div>

            <div
                x-data="examplesTable('{{ route('examples.data') }}')"
                x-init="init()"
                @examples-search.window="search($event.detail.q)"
                class="bg-white overflow-hidden shadow-sm sm:rounded-lg"
            >
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nombre') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Valor') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Estado') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <template x-for="row in rows" :key="row.id">
                            <tr>
                                <td class="px-6 py-4 text-sm text-gray-900" x-text="row.name"></td>
                                <td class="px-6 py-4 text-sm text-gray-500" x-text="row.value"></td>
                                <td class="px-6 py-4 text-sm text-gray-500" x-text="row.status_label"></td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <a :href="`/examples/${row.id}`" class="text-gray-600 hover:text-gray-900">{{ __('Ver') }}</a>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="rows.length === 0">
                            <td colspan="4" class="px-6 py-4 text-sm text-gray-400 text-center">{{ __('Sin resultados.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function examplesTable(dataUrl) {
            return {
                rows: @json($initialRows),
                init() {},
                async search(query) {
                    const url = new URL(dataUrl, window.location.origin);
                    if (query) {
                        url.searchParams.set('q', query);
                    }

                    const response = await fetch(url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });

                    if (!response.ok) {
                        return;
                    }

                    const payload = await response.json();

                    if (payload.success) {
                        this.rows = payload.data;
                    }
                },
            };
        }
    </script>
    @endpush
</x-app-layout>
