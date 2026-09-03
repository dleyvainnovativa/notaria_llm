<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // No auth in the prototype.
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf', 'max:51200'], // 50 MB
            'template_id' => ['required', 'string', 'max:100'],
            'system_prompt' => ['nullable', 'string', 'max:5000'],
            'extraction_prompt' => ['nullable', 'string', 'max:5000'],
            'json_schema' => ['required', 'string', 'json'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Selecciona un archivo PDF.',
            'file.mimes' => 'El archivo debe ser un PDF.',
            'file.max' => 'El PDF supera el tamaño máximo permitido (50 MB).',
            'json_schema.json' => 'El esquema JSON no es válido.',
            'template_id.required' => 'Indica el tipo de documento.',
        ];
    }
}
