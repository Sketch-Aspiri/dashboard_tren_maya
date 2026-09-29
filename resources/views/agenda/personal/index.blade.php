<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('RR.HH.') }} — {{ __('Personal') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md border border-brand-teal/20 bg-brand-mist p-4 text-sm text-brand-green">
                    {{ session('status') }}
                </div>
            @endif

            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between" x-data>
                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <input
                        type="search"
                        x-ref="search"
                        @input.debounce.400ms="$dispatch('agenda-personal-search', { q: $event.target.value })"
                        placeholder="{{ __('Buscar por nombre, no. de empleado o puesto...') }}"
                        class="min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal sm:w-72"
                    >

                    <select
                        @change="$dispatch('agenda-personal-filter-estatus', { estatus: $event.target.value })"
                        class="min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal sm:w-auto"
                    >
                        <option value="">{{ __('Todos los estatus') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>

                @can('create', \App\Models\Empleado::class)
                    <a href="{{ route('agenda.personal.create') }}"
                       class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md border border-transparent bg-brand-green px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-brand-green-dark focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2">
                        {{ __('Nuevo') }}
                    </a>
                @endcan
            </div>

            <div
                x-data="agendaPersonalTable('{{ route('agenda.personal.data') }}')"
                x-init="init()"
                @agenda-personal-search.window="search($event.detail.q)"
                @agenda-personal-filter-estatus.window="filterEstatus($event.detail.estatus)"
                class="overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5"
            >
                <!-- Desktop / tablet table (>= sm) -->
                <div class="hidden overflow-x-auto sm:block">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-brand-mist">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('No. Empleado') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Nombre') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Puesto') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Estación / Plaza') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Teléfono') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Estatus') }}</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <template x-for="row in rows" :key="row.id">
                                <tr class="hover:bg-brand-mist/40">
                                    <td class="px-6 py-4 text-sm text-gray-900" x-text="row.no_empleado ?? '—'"></td>
                                    <td class="px-6 py-4 text-sm text-gray-900" x-text="row.nombre_completo ?? '—'"></td>
                                    <td class="px-6 py-4 text-sm text-gray-500" x-text="row.puesto ?? '—'"></td>
                                    <td class="px-6 py-4 text-sm text-gray-500" x-text="(row.estacion_codigo ?? '—') + ' / ' + (row.plaza_actual ?? '—')"></td>
                                    <td class="px-6 py-4 text-sm text-gray-500" x-text="row.telefono ?? '—'"></td>
                                    <td class="px-6 py-4 text-sm">
                                        <span
                                            class="inline-flex rounded-full px-2 py-1 text-xs font-semibold"
                                            :class="row.estatus === 'activo' ? 'bg-brand-mist text-brand-green' : 'bg-amber-100 text-amber-800'"
                                            x-text="row.estatus_label"
                                        ></span>
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm">
                                        <a :href="`/agenda/personal/${row.id}`" class="font-medium text-brand-teal hover:text-brand-green-dark">{{ __('Ver') }}</a>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="rows.length === 0">
                                <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-400">{{ __('Sin resultados.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile stacked cards (< sm) -->
                <div class="divide-y divide-gray-200 sm:hidden">
                    <template x-for="row in rows" :key="row.id">
                        <a :href="`/agenda/personal/${row.id}`" class="block px-4 py-4 active:bg-brand-mist/40">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-900" x-text="row.nombre_completo ?? '—'"></p>
                                    <p class="mt-0.5 text-xs text-gray-500" x-text="row.puesto ?? '—'"></p>
                                </div>
                                <span
                                    class="inline-flex shrink-0 rounded-full px-2 py-1 text-xs font-semibold"
                                    :class="row.estatus === 'activo' ? 'bg-brand-mist text-brand-green' : 'bg-amber-100 text-amber-800'"
                                    x-text="row.estatus_label"
                                ></span>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-gray-500">
                                <div>
                                    <dt class="text-gray-400">{{ __('No. Empleado') }}</dt>
                                    <dd class="text-gray-700" x-text="row.no_empleado ?? '—'"></dd>
                                </div>
                                <div>
                                    <dt class="text-gray-400">{{ __('Teléfono') }}</dt>
                                    <dd class="text-gray-700" x-text="row.telefono ?? '—'"></dd>
                                </div>
                                <div class="col-span-2">
                                    <dt class="text-gray-400">{{ __('Estación / Plaza') }}</dt>
                                    <dd class="text-gray-700" x-text="(row.estacion_codigo ?? '—') + ' / ' + (row.plaza_actual ?? '—')"></dd>
                                </div>
                            </dl>
                        </a>
                    </template>
                    <p x-show="rows.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">{{ __('Sin resultados.') }}</p>
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-200 bg-brand-mist/50 px-4 py-3 text-sm text-gray-600 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:px-6">
                    <span x-text="meta.total > 0
                        ? `{{ __('Mostrando') }} ${(meta.page - 1) * meta.per_page + 1}–${Math.min(meta.page * meta.per_page, meta.total)} {{ __('de') }} ${meta.total}`
                        : ''">
                    </span>

                    <div class="flex items-center justify-between gap-2 sm:justify-start" x-show="meta.last_page > 1">
                        <button
                            type="button"
                            @click="goToPage(meta.page - 1)"
                            :disabled="meta.page <= 1"
                            class="min-h-[44px] rounded-md border border-brand-green/20 px-3 py-1 text-brand-green hover:bg-white disabled:cursor-not-allowed disabled:opacity-40"
                        >{{ __('Anterior') }}</button>

                        <span x-text="`{{ __('Página') }} ${meta.page} {{ __('de') }} ${meta.last_page}`"></span>

                        <button
                            type="button"
                            @click="goToPage(meta.page + 1)"
                            :disabled="meta.page >= meta.last_page"
                            class="min-h-[44px] rounded-md border border-brand-green/20 px-3 py-1 text-brand-green hover:bg-white disabled:cursor-not-allowed disabled:opacity-40"
                        >{{ __('Siguiente') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function agendaPersonalTable(dataUrl) {
            return {
                rows: @json($initialRows),
                meta: @json($initialMeta),
                query: '',
                estatus: '',
                page: 1,
                init() {},
                async search(query) {
                    this.query = query;
                    this.page = 1;
                    await this.refresh();
                },
                async filterEstatus(estatus) {
                    this.estatus = estatus;
                    this.page = 1;
                    await this.refresh();
                },
                async goToPage(page) {
                    if (page < 1 || page > this.meta.last_page) {
                        return;
                    }

                    this.page = page;
                    await this.refresh();
                },
                async refresh() {
                    const url = new URL(dataUrl, window.location.origin);
                    if (this.query) {
                        url.searchParams.set('q', this.query);
                    }
                    if (this.estatus) {
                        url.searchParams.set('estatus', this.estatus);
                    }
                    url.searchParams.set('page', this.page);

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
                        this.meta = payload.meta;
                        this.page = payload.meta.page;
                    }
                },
            };
        }
    </script>
    @endpush
</x-app-layout>
