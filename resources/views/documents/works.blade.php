@extends('layouts.app')
@section('title', 'Trabajos')

@php
    $pending = $documents->contains(fn ($d) => $d->isPending());
@endphp

{{-- Auto-refresh only while something is queued/processing, so a finished
     board sits still. Simple and dependency-free; swap for polling/websockets
     later if you want it snappier. --}}
@if ($pending)
    @push('head')
        <meta http-equiv="refresh" content="5">
    @endpush
@endif

@section('content')
    <div class="page-head">
        <h1>Trabajos</h1>
        <div class="sub">
            Estado de procesamiento de cada documento.
            @if ($pending)
                <span class="badge badge-warn" style="margin-left:8px">Actualizando cada 5 s…</span>
            @endif
        </div>
    </div>

    @if ($documents->isEmpty())
        <div class="card card-pad">
            <div class="empty">
                <div class="big">No hay trabajos</div>
                <div>Sube un PDF para encolar una extracción.</div>
                <div style="margin-top:16px">
                    <a href="{{ route('documents.create') }}" class="btn btn-primary">Subir PDF</a>
                </div>
            </div>
        </div>
    @else
        <div class="card">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th>Archivo</th>
                        <th>Tipo</th>
                        <th>Estado</th>
                        <th>Extracción</th>
                        <th>Tiempo</th>
                        <th>Actualizado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($documents as $doc)
                        <tr>
                            <td>{{ $doc->original_filename }}</td>
                            <td>{{ $doc->template_id ?? '—' }}</td>
                            <td>
                                @if ($doc->isProcessed())
                                    <span class="badge badge-ok">Procesado</span>
                                @elseif ($doc->isFailed())
                                    <span class="badge badge-danger">Error</span>
                                @elseif ($doc->isProcessing())
                                    <span class="badge badge-warn">Procesando…</span>
                                @elseif ($doc->isQueued())
                                    <span class="badge badge-warn">En cola</span>
                                @else
                                    <span class="badge badge-warn">{{ $doc->status }}</span>
                                @endif

                                @if ($doc->isFailed() && $doc->error_message)
                                    <div style="color:var(--text-muted); font-size:12px; margin-top:4px; max-width:340px">
                                        {{ \Illuminate\Support\Str::limit($doc->error_message, 160) }}
                                    </div>
                                @endif
                            </td>
                            <td>{{ $doc->text_extraction ?? '—' }}</td>
                            <td>
                                @if ($doc->duration_ms)
                                    {{ number_format($doc->duration_ms / 1000, 1) }} s
                                @else
                                    —
                                @endif
                            </td>
                            <td style="color:var(--text-muted)">{{ $doc->updated_at->format('d/m/Y H:i:s') }}</td>
                            <td style="text-align:right; white-space:nowrap">
                                @if ($doc->isProcessed())
                                    <a href="{{ route('documents.show', $doc) }}">Ver</a>
                                @endif
                                @if ($doc->canReprocess())
                                    <form method="POST"
                                          action="{{ route('documents.reprocess', $doc) }}"
                                          style="display:inline; margin-left:10px">
                                        @csrf
                                        <button type="submit" class="btn btn-ghost btn-sm">Reprocesar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
