<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Conexiones de nube') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div role="status" class="rounded-md border border-brand-teal/20 bg-brand-mist p-4 text-sm text-brand-green">
                    {{ session('status') }}
                </div>
            @endif
            @if (session('error'))
                <div role="alert" class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                    {{ session('error') }}
                </div>
            @endif

            <p class="text-sm text-gray-600">
                {{ __('Aquí se cargan las credenciales de las apps registradas por TI y se conectan las cuentas de OneDrive/SharePoint, Google Drive y Dropbox. El dashboard solo tiene permiso de lectura: no puede modificar ni borrar nada en la nube.') }}
            </p>

            @foreach ($tarjetas as $tarjeta)
                @php
                    $proveedor = $tarjeta['proveedor'];
                    $estado = $tarjeta['estado'];
                @endphp
                <section class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6"
                         aria-labelledby="nube-{{ $proveedor->value }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 id="nube-{{ $proveedor->value }}" class="font-heading text-lg font-semibold text-brand-green">{{ $proveedor->label() }}</h3>
                        @if ($estado['configurado'])
                            <span class="inline-flex rounded-full bg-brand-mist px-3 py-1 text-xs font-semibold text-brand-green">
                                {{ __('Configurado') }}
                                @if ($estado['origen'] === 'env') · {{ __('desde el servidor (.env)') }} @endif
                                · {{ __('Client ID …:fin', ['fin' => $estado['client_id_fin']]) }}
                            </span>
                        @else
                            <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">{{ __('Faltan credenciales') }}</span>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('nube.credenciales.update', $proveedor->value) }}" class="mt-4 grid gap-4 sm:grid-cols-2" autocomplete="off">
                        @csrf
                        @method('PUT')

                        <div>
                            <x-input-label for="client_id_{{ $proveedor->value }}" :value="__('Client ID')" />
                            <x-text-input id="client_id_{{ $proveedor->value }}" name="client_id" type="text" class="mt-1 block min-h-[44px] w-full"
                                :value="old('_proveedor') === $proveedor->value ? old('client_id') : ''"
                                :placeholder="$estado['origen'] === 'app' ? __('Vacío = conservar el actual') : __('Pega el Client ID')"
                                :required="$estado['origen'] !== 'app'" />
                            <x-input-error :messages="old('_proveedor') === $proveedor->value ? $errors->get('client_id') : []" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="client_secret_{{ $proveedor->value }}" :value="__('Client Secret')" />
                            <x-text-input id="client_secret_{{ $proveedor->value }}" name="client_secret" type="password" autocomplete="new-password"
                                class="mt-1 block min-h-[44px] w-full"
                                :placeholder="$estado['configurado'] ? __('Vacío = conservar el actual') : __('Pega el secreto')" />
                            <x-input-error :messages="old('_proveedor') === $proveedor->value ? $errors->get('client_secret') : []" class="mt-2" />
                        </div>

                        @if ($proveedor->usaTenant())
                            <div>
                                <x-input-label for="tenant_{{ $proveedor->value }}" :value="__('Tenant')" />
                                <x-text-input id="tenant_{{ $proveedor->value }}" name="tenant" type="text" class="mt-1 block min-h-[44px] w-full"
                                    :value="$estado['tenant']" />
                                <p class="mt-1 text-xs text-gray-500">{{ __('"common" permite cuentas institucionales y personales.') }}</p>
                                <x-input-error :messages="old('_proveedor') === $proveedor->value ? $errors->get('tenant') : []" class="mt-2" />
                            </div>
                        @endif

                        <div class="sm:col-span-2" x-data="{ copiado: false }">
                            <x-input-label for="redirect_{{ $proveedor->value }}" :value="__('Redirect URI (dásela a TI tal cual)')" />
                            <div class="mt-1 flex flex-col gap-2 sm:flex-row">
                                <input id="redirect_{{ $proveedor->value }}" x-ref="uri" type="text" readonly value="{{ $tarjeta['redirectUri'] }}"
                                       class="block min-h-[44px] w-full rounded-md border-gray-300 bg-gray-50 text-sm text-gray-700 shadow-sm">
                                <button type="button"
                                        class="inline-flex min-h-[44px] items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-teal"
                                        @click="navigator.clipboard.writeText($refs.uri.value).then(() => { copiado = true; setTimeout(() => copiado = false, 2000) })">
                                    <span x-show="!copiado">{{ __('Copiar') }}</span>
                                    <span x-show="copiado" x-cloak>{{ __('Copiada') }}</span>
                                </button>
                            </div>
                        </div>

                        <input type="hidden" name="_proveedor" value="{{ $proveedor->value }}">

                        <div class="flex flex-col gap-3 sm:col-span-2 sm:flex-row sm:justify-end">
                            <x-primary-button class="w-full sm:w-auto">{{ __('Guardar credenciales') }}</x-primary-button>
                        </div>
                    </form>

                    <div class="mt-6 border-t pt-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h4 class="text-sm font-semibold text-gray-800">{{ __('Cuentas conectadas') }}</h4>
                            @if ($estado['configurado'])
                                <a href="{{ route('nube.conectar', $proveedor->value) }}"
                                   class="inline-flex min-h-[44px] items-center justify-center rounded-md border border-transparent bg-brand-green px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-brand-green-dark focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2">
                                    {{ __('Conectar cuenta') }}
                                </a>
                            @else
                                <span class="text-xs text-gray-500">{{ __('Guarda las credenciales para poder conectar una cuenta.') }}</span>
                            @endif
                        </div>

                        <ul class="mt-3 divide-y divide-gray-200">
                            @forelse ($tarjeta['cuentas'] as $cuenta)
                                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-gray-900">{{ $cuenta->cuenta_email }}</p>
                                        <p class="text-xs text-gray-500">
                                            {{ $cuenta->cuenta_nombre }}
                                            <span class="ml-1 inline-flex rounded-full px-2 py-0.5 font-semibold {{ $cuenta->estaActiva() ? 'bg-brand-mist text-brand-green' : 'bg-amber-100 text-amber-800' }}">{{ $cuenta->estado->label() }}</span>
                                        </p>
                                        @if ($cuenta->ultimo_error)
                                            <p class="mt-1 text-xs text-red-700">{{ $cuenta->ultimo_error }}</p>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2">
                                        @unless ($cuenta->estaActiva())
                                            <a href="{{ route('nube.conectar', $proveedor->value) }}" class="inline-flex min-h-[44px] items-center px-3 text-sm font-medium text-brand-teal hover:text-brand-green-dark">{{ __('Reconectar') }}</a>
                                        @endunless
                                        <form method="POST" action="{{ route('nube.cuentas.destroy', $cuenta) }}"
                                              onsubmit="return confirm('{{ __('¿Desconectar esta cuenta? Los orígenes que la usan quedarán en pausa.') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex min-h-[44px] items-center px-3 text-sm font-medium text-red-600 hover:text-red-800">{{ __('Desconectar') }}</button>
                                        </form>
                                    </div>
                                </li>
                            @empty
                                <li class="py-3 text-sm text-gray-500">{{ __('Aún no hay cuentas conectadas.') }}</li>
                            @endforelse
                        </ul>
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</x-app-layout>
