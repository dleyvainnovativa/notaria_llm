@extends('layouts.app')
@section('title', 'Documentos')

@section('content')
    <div class="page-head">
        <h1>Documentos</h1>
        <div class="sub">Sube una escritura en PDF y revisa la información extraída.</div>
    </div>

    @if ($documents->isEmpty())
        <div class="card card-pad">
            <div class="empty">
                <div class="big">Aún no hay documentos</div>
                <div>Sube tu primer PDF para ver la extracción.</div>
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
                        <th>Modelo</th>
                        <th>Páginas</th>
                        <th>Fecha</th>
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
                                @else
                                    <span class="badge badge-warn">{{ $doc->status }}</span>
                                @endif
                            </td>
                            <td class="mono" style="font-size:12px">{{ $doc->model ?? '—' }}</td>
                            <td>{{ $doc->pages ?? '—' }}</td>
                            <td style="color:var(--text-muted)">{{ $doc->created_at->format('d/m/Y H:i') }}</td>
                            <td style="text-align:right">
                                <a href="{{ route('documents.show', $doc) }}">Ver</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
