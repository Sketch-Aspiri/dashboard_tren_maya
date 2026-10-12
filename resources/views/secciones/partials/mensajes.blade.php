@if (session('status'))
    <div class="rounded-md border border-brand-teal/20 bg-brand-mist p-4 text-sm text-brand-green" role="status">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
        <ul class="list-inside list-disc space-y-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
