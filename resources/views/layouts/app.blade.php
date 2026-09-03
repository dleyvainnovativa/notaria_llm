<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Notaría — Extracción')</title>

    {{-- Bundled locally via Vite (no external CDNs / font services). To use
         Inter + JetBrains Mono offline, install @fontsource packages and import
         them in app.js, or self-host the woff2 files. --}}
    @vite(['resources/js/app.js'])
</head>
<body>
    <nav class="app-nav">
        <span class="brand">
            <span class="mark">N</span>
            <span>Notaría · Extracción</span>
        </span>
        <span class="spacer"></span>
        <a href="{{ route('documents.index') }}" class="btn btn-ghost">Documentos</a>
        <a href="{{ route('documents.create') }}" class="btn btn-primary">Subir PDF</a>
        <button class="theme-toggle" data-theme-toggle title="Cambiar tema" aria-label="Cambiar tema">◐</button>
    </nav>

    <main class="page @yield('page-class')">
        @if (session('success'))
            <div class="alert alert-ok">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>
