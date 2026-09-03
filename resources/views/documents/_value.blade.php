{{--
    Renders an extracted value of unknown shape.
    Vars: $value, $path (dot path for evidence lookup), $evidence (full map).
    Handles: scalar, array-of-scalars, array-of-objects, nested object.
--}}
@php
    $ev = $evidence[$path] ?? null;
    $isMonoKey = \Illuminate\Support\Str::contains(strtolower($path), ['rfc', 'curp', 'folio', 'cuenta', 'numero', 'número']);
@endphp

@if (is_array($value))
    @php $isList = array_is_list($value); @endphp

    @if ($isList)
        @if (count($value) === 0)
            <span class="v empty">vacío</span>
        @elseif (is_array($value[0] ?? null))
            {{-- array of objects (e.g. parties) --}}
            @foreach ($value as $i => $item)
                <div class="party-card">
                    @foreach ($item as $k => $v)
                        <div class="prow">
                            <div class="kv" style="margin:0">
                                <div class="k">{{ ucfirst(str_replace('_', ' ', $k)) }}</div>
                                <div class="v {{ \Illuminate\Support\Str::contains(strtolower($k), ['rfc','curp','folio']) ? 'mono' : '' }} {{ ($v === null || $v === '') ? 'empty' : '' }}">
                                    {{ ($v === null || $v === '') ? '—' : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v) }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                    @php $iev = $evidence["{$path}.{$i}.nombre"] ?? null; @endphp
                    @if ($iev && ($iev['text'] ?? null))
                        <div class="evidence-note">
                            <span class="pg">Pág. {{ $iev['page'] }}</span> — “{{ \Illuminate\Support\Str::limit($iev['text'], 140) }}”
                        </div>
                    @endif
                </div>
            @endforeach
        @else
            {{-- array of scalars --}}
            <div class="v">{{ implode(', ', $value) }}</div>
        @endif
    @else
        {{-- nested object --}}
        @foreach ($value as $k => $v)
            <div class="kv">
                <div class="k">{{ ucfirst(str_replace('_', ' ', $k)) }}</div>
                <div class="v {{ (is_string($v) && $v !== '') ? '' : 'empty' }}">
                    {{ ($v === null || $v === '') ? '—' : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v) }}
                </div>
            </div>
        @endforeach
    @endif
@else
    <div class="v {{ $isMonoKey ? 'mono' : '' }} {{ ($value === null || $value === '') ? 'empty' : '' }}">
        {{ ($value === null || $value === '') ? '—' : $value }}
    </div>
    @if ($ev && ($ev['text'] ?? null))
        <div class="evidence-note">
            <span class="pg">Pág. {{ $ev['page'] }}</span> — “{{ \Illuminate\Support\Str::limit($ev['text'], 140) }}”
        </div>
    @endif
@endif
