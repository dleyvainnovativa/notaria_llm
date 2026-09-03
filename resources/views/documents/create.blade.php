@extends('layouts.app')
@section('title', 'Subir PDF')

@section('content')
    <div class="page-head">
        <h1>Subir documento</h1>
        <div class="sub">Selecciona el PDF, el tipo de documento y el esquema de extracción.</div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="card card-pad" style="max-width:720px">
        <form method="POST" action="{{ route('documents.store') }}"
              enctype="multipart/form-data" data-upload-form>
            @csrf

            <div class="field">
                <label>Archivo PDF</label>
                <div class="dropzone" data-dropzone>
                    <div>Arrastra un PDF aquí o haz clic para seleccionar</div>
                    <div class="file-name" data-file-name></div>
                    <input type="file" name="file" accept="application/pdf"
                           hidden required>
                </div>
                <div class="hint">Solo PDF. Máximo 50 MB.</div>
            </div>

            <div class="field">
                <label for="template_id">Tipo de documento</label>
                <input class="input" type="text" id="template_id" name="template_id"
                       value="{{ old('template_id', 'compraventa') }}" required>
                <div class="hint">Identificador del tipo (p. ej. compraventa, donacion, hipoteca).</div>
            </div>

            <div class="field">
                <label for="system_prompt">Instrucción del sistema</label>
                <textarea class="textarea" id="system_prompt" name="system_prompt"
                          rows="2">{{ old('system_prompt', 'Eres un motor de extracción de información especializado en documentos notariales mexicanos. Extrae únicamente información contenida explícitamente en el documento. No inventes datos. Si un dato no aparece con claridad, devuelve null o un arreglo vacío según el esquema.

Distingue con cuidado los roles de las partes:
- "enajenantes": quienes transmiten o entregan el bien (vendedor, donante, cedente).
- "adquirientes": quienes reciben el bien (comprador, donatario, cesionario).

En una donación: el DONANTE es enajenante y el DONATARIO es adquiriente.

No confundas los datos del notario con los de las partes. El RFC del notario (frecuentemente en el encabezado de las páginas) NO pertenece a ninguna parte.

Formatos: el RFC de persona física tiene 13 caracteres (4 letras + 6 dígitos + 3 caracteres). La CURP tiene 18 caracteres. No coloques una CURP en el campo RFC ni viceversa.') }}</textarea>
            </div>

            <div class="field">
                <label for="extraction_prompt">Instrucción de extracción</label>
                <textarea class="textarea" id="extraction_prompt" name="extraction_prompt"
                          rows="3">{{ old('extraction_prompt', 'Extrae del documento: el número de escritura, la fecha de otorgamiento, el notario y su número de notaría, y las partes clasificadas como enajenantes y adquirientes. Para cada persona incluye nombre, rol, RFC y CURP tal como aparecen en el documento. Preserva nombres e identificadores exactamente como están escritos.') }}</textarea>
            </div>

            <div class="field">
                <label for="json_schema">Esquema JSON</label>
                <textarea class="textarea mono" id="json_schema" name="json_schema"
                          rows="14" required>{{ old('json_schema', $defaultSchema) }}</textarea>
                <div class="hint">Define qué información debe devolver el modelo.</div>
            </div>

            <div style="display:flex; gap:10px; margin-top:6px">
                <button type="submit" class="btn btn-primary" data-submit>Procesar documento</button>
                <a href="{{ route('documents.index') }}" class="btn btn-ghost">Cancelar</a>
            </div>
            <div class="hint" style="margin-top:10px">
                El procesamiento es sincrónico y puede tardar varios minutos en CPU.
                No cierres la pestaña.
            </div>
        </form>
    </div>
@endsection
