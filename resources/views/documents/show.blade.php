@extends('layouts.app')
@section('title', 'Revisión')
@section('page-class', 'page-wide')

@section('content')
    <div class="page-head" style="display:flex; align-items:flex-start; gap:16px">
        <div style="flex:1">
            <h1>{{ $document->original_filename }}</h1>
            <div class="sub">
                {{ $document->template_id ?? 'sin tipo' }} ·
                subido {{ $document->created_at->format('d/m/Y H:i') }}
            </div>
        </div>
        <div>
            @if ($document->isProcessed())
                <span class="badge badge-ok">Procesado</span>
            @elseif ($document->isFailed())
                <span class="badge badge-danger">Error</span>
            @else
                <span class="badge badge-warn">{{ $document->status }}</span>
            @endif
        </div>
    </div>

    @if ($document->isFailed())
        <div class="alert alert-danger">
            {{ $document->error_message ?? 'El procesamiento falló.' }}
        </div>
    @endif

    <div class="review-grid">
        {{-- LEFT: PDF --}}
        <div class="pdf-pane">
            <embed src="{{ route('documents.pdf', $document) }}#toolbar=1"
                   type="application/pdf" />
        </div>

        {{-- RIGHT: extracted data --}}
        <div>
            <div class="card card-pad" style="margin-bottom:16px">
                <div class="meta-strip">
                    <div class="m"><div class="ml">Modelo</div><div class="mv mono" style="font-size:12px">{{ $document->model ?? '—' }}</div></div>
                    <div class="m"><div class="ml">Extracción</div><div class="mv">{{ $document->text_extraction ?? '—' }}</div></div>
                    <div class="m"><div class="ml">Páginas</div><div class="mv">{{ $document->pages ?? '—' }}</div></div>
                    <div class="m"><div class="ml">Tiempo</div><div class="mv">{{ $document->duration_ms ? number_format($document->duration_ms / 1000, 1) . ' s' : '—' }}</div></div>
                </div>
            </div>

            <div class="card card-pad">
                @php $data = $document->extracted_data ?? []; @endphp

                @if (empty($data))
                    <div class="empty">
                        <div class="big">Sin datos extraídos</div>
                        <div>El modelo no devolvió información para este documento.</div>
                    </div>
                @else
                    @foreach ($data as $key => $value)
                        <div class="field-group">
                            <div class="group-title">{{ ucfirst(str_replace('_', ' ', $key)) }}</div>
                            @include('documents._value', [
                                'value' => $value,
                                'path' => $key,
                                'evidence' => $document->evidence ?? [],
                            ])
                        </div>
                    @endforeach
                @endif
            </div>

            <div style="margin-top:16px; display:flex; gap:10px">
                <a href="{{ route('documents.index') }}" class="btn btn-ghost">Volver</a>
                <a href="{{ route('documents.create') }}" class="btn btn-primary">Subir otro</a>
            </div>
        </div>
    </div>
@endsection
