{{-- Mensaje de éxito de la última acción y errores de validación, igual en
     todas las vistas de insumos. --}}
@if (session('status'))
    <div class="ins-aviso-ok" role="status">
        <span aria-hidden="true" class="mt-0.5 font-bold">✓</span>
        <p>{{ session('status') }}</p>
    </div>
@endif

@if ($errors->any())
    <div class="ins-aviso-error" role="alert">
        <span aria-hidden="true" class="mt-0.5 font-bold">!</span>
        <div>
            <p class="font-semibold">{{ trans_choice('Revisa este punto antes de continuar:|Revisa estos puntos antes de continuar:', $errors->count()) }}</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
