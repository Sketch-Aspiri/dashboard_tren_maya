@php
    /** @var \App\Models\PtarHallazgo|null $h */
    /** @var string $id */
    /** @var array<string, string> $equipos */
    $h ??= null;
    $valor = fn (string $campo, $porDefecto = null) => old($campo, $h?->{$campo} ?? $porDefecto);
    $fecha = fn (string $campo) => old($campo, $h?->{$campo}?->toDateString());
@endphp

<div class="grid grid-cols-2 gap-3 lg:grid-cols-12">
    <div class="lg:col-span-3">
        <label for="{{ $id }}-tipo" class="ins-etiqueta">{{ __('Tipo') }}</label>
        <select id="{{ $id }}-tipo" name="tipo" class="ins-campo mt-1">
            @foreach (['problematica' => __('Problemática'), 'propuesta' => __('Propuesta')] as $v => $t)
                <option value="{{ $v }}" @selected($valor('tipo', 'problematica') === $v)>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div class="lg:col-span-3">
        <label for="{{ $id }}-equipo" class="ins-etiqueta">{{ __('Equipo (opcional)') }}</label>
        <select id="{{ $id }}-equipo" name="equipo" class="ins-campo mt-1">
            <option value="">—</option>
            @foreach ($equipos as $v => $t)
                <option value="{{ $v }}" @selected($valor('equipo') === $v)>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div class="lg:col-span-3">
        <label for="{{ $id }}-prioridad" class="ins-etiqueta">{{ __('Prioridad') }}</label>
        <select id="{{ $id }}-prioridad" name="prioridad" class="ins-campo mt-1">
            @foreach (['alta' => __('Alta'), 'media' => __('Media'), 'baja' => __('Baja')] as $v => $t)
                <option value="{{ $v }}" @selected($valor('prioridad', 'media') === $v)>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div class="lg:col-span-3">
        <label for="{{ $id }}-estado" class="ins-etiqueta">{{ __('Estado') }}</label>
        <select id="{{ $id }}-estado" name="estado" class="ins-campo mt-1">
            @foreach (['abierto' => __('Abierto'), 'en_proceso' => __('En proceso'), 'cerrado' => __('Cerrado')] as $v => $t)
                <option value="{{ $v }}" @selected($valor('estado', 'abierto') === $v)>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-span-2 lg:col-span-12">
        <label for="{{ $id }}-descripcion" class="ins-etiqueta">{{ __('Descripción (problemática o texto de la propuesta)') }}</label>
        <textarea id="{{ $id }}-descripcion" name="descripcion" rows="3" maxlength="2000" required class="ins-campo mt-1">{{ $valor('descripcion') }}</textarea>
        <x-input-error :messages="$errors->get('descripcion')" class="mt-1" />
    </div>
    <div class="col-span-2 lg:col-span-12">
        <label for="{{ $id }}-propuesta" class="ins-etiqueta">{{ __('Propuesta o acción (para una problemática)') }}</label>
        <textarea id="{{ $id }}-propuesta" name="propuesta" rows="2" maxlength="2000" class="ins-campo mt-1">{{ $valor('propuesta') }}</textarea>
    </div>
    <div class="lg:col-span-4">
        <label for="{{ $id }}-compromiso" class="ins-etiqueta">{{ __('Fecha compromiso') }}</label>
        <input id="{{ $id }}-compromiso" name="fecha_compromiso" type="date" class="ins-campo mt-1" value="{{ $fecha('fecha_compromiso') }}">
        <x-input-error :messages="$errors->get('fecha_compromiso')" class="mt-1" />
    </div>
    <div class="lg:col-span-4">
        <label for="{{ $id }}-cierre" class="ins-etiqueta">{{ __('Fecha de cierre (si ya se atendió)') }}</label>
        <input id="{{ $id }}-cierre" name="fecha_cierre" type="date" max="{{ now('America/Cancun')->toDateString() }}" class="ins-campo mt-1" value="{{ $fecha('fecha_cierre') }}">
        <x-input-error :messages="$errors->get('fecha_cierre')" class="mt-1" />
    </div>
    <div class="col-span-2 lg:col-span-4">
        <label for="{{ $id }}-tarjeta" class="ins-etiqueta">{{ __('Tarjeta de seguimiento') }}</label>
        <input id="{{ $id }}-tarjeta" name="tarjeta" type="text" maxlength="60" class="ins-campo mt-1" value="{{ $valor('tarjeta') }}" placeholder="27_FCP_TARJETA_436">
    </div>
</div>
