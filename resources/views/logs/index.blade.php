<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Viewer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'SF Mono', 'Fira Code', 'Fira Mono', 'Roboto Mono', monospace; }
        pre  { white-space: pre-wrap; word-break: break-all; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #111827; }
        ::-webkit-scrollbar-thumb { background: #374151; border-radius: 3px; }
    </style>
</head>
<body class="h-full flex flex-col bg-gray-950 text-gray-300 text-sm overflow-hidden">

{{-- ── HEADER ──────────────────────────────────────────────────────────────── --}}
<header class="shrink-0 bg-gray-900 border-b border-gray-800 px-5 py-3 flex items-center gap-3">
    <span class="text-white font-semibold text-base tracking-tight">⬛ Log Viewer</span>

    @if($selectedFile)
        <span class="text-gray-700">›</span>
        <span class="text-gray-400 text-xs">{{ $selectedFile['key'] }}.log</span>
        <span class="text-gray-600 text-xs">({{ $selectedFile['size_human'] }})</span>
    @endif

    @if($truncated)
        <span class="text-amber-600 text-xs bg-amber-950/40 border border-amber-900/50 px-2 py-0.5 rounded">
            ↑ exibindo últimas {{ 2000 }} linhas
        </span>
    @endif

    <div class="ml-auto flex items-center gap-2">
        @if($selectedFile && $selectedFile['size'] > 0)
            <form method="POST" action="{{ route('logs.clear', $selectedKey) }}">
                @csrf @method('DELETE')
                <button type="submit"
                        onclick="return confirm('Limpar o conteúdo de {{ $selectedKey }}.log?')"
                        class="px-3 py-1.5 text-xs bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-white rounded transition-colors">
                    Limpar arquivo
                </button>
            </form>
        @endif
        <form method="POST" action="{{ route('logs.clear-all') }}">
            @csrf @method('DELETE')
            <button type="submit"
                    onclick="return confirm('Limpar TODOS os arquivos de log? Esta ação não pode ser desfeita.')"
                    class="px-3 py-1.5 text-xs bg-red-950 hover:bg-red-900 text-red-400 hover:text-red-200 rounded border border-red-900/70 transition-colors">
                Limpar todos
            </button>
        </form>
    </div>
</header>

{{-- ── TOAST ───────────────────────────────────────────────────────────────── --}}
@if(session('toast'))
    <div class="shrink-0 bg-emerald-950 border-b border-emerald-900 px-5 py-2 text-emerald-400 text-xs flex items-center gap-2">
        ✓ {{ session('toast') }}
    </div>
@endif

{{-- ── FILE TABS ────────────────────────────────────────────────────────────── --}}
<nav class="shrink-0 bg-gray-900/80 border-b border-gray-800 flex overflow-x-auto">
    @forelse($files as $file)
        @php
            $isActive  = $file['key'] === $selectedKey;
            $tabParams = array_filter(['file' => $file['key'], 'level' => $levelFilter, 'search' => $search]);
        @endphp
        <a href="{{ route('logs.index', $tabParams) }}"
           class="flex items-center gap-2 px-5 py-2.5 text-xs border-b-2 whitespace-nowrap transition-colors
                  {{ $isActive
                      ? 'border-blue-500 text-white bg-gray-800/60'
                      : 'border-transparent text-gray-500 hover:text-gray-300 hover:bg-gray-800/30' }}">
            {{ $file['key'] }}.log
            <span class="text-gray-600">{{ $file['size_human'] }}</span>
        </a>
    @empty
        <span class="px-5 py-2.5 text-xs text-gray-600 italic">Nenhum arquivo encontrado em storage/logs/</span>
    @endforelse
</nav>

{{-- ── FILTER BAR ──────────────────────────────────────────────────────────── --}}
<div class="shrink-0 bg-gray-900/50 border-b border-gray-800 px-5 py-2.5">
    <form method="GET" action="{{ route('logs.index') }}" class="flex items-center gap-3 flex-wrap">
        <input type="hidden" name="file" value="{{ $selectedKey }}">

        {{-- Level pills --}}
        <div class="flex gap-1 flex-wrap">
            @foreach(['' => 'TODOS', 'DEBUG' => 'DEBUG', 'INFO' => 'INFO', 'NOTICE' => 'NOTICE', 'WARNING' => 'WARN', 'ERROR' => 'ERROR', 'CRITICAL' => 'CRIT'] as $val => $lbl)
                <button type="submit" name="level" value="{{ $val }}"
                        class="px-2.5 py-1 text-xs rounded transition-colors
                               {{ $levelFilter === $val
                                   ? 'bg-blue-600 text-white'
                                   : 'bg-gray-800 text-gray-500 hover:text-gray-200 hover:bg-gray-700' }}">
                    {{ $lbl }}
                </button>
            @endforeach
        </div>

        {{-- Search --}}
        <div class="flex gap-2 flex-1 min-w-52">
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="Buscar em mensagens e contexto..."
                   class="flex-1 bg-gray-800 border border-gray-700 focus:border-blue-600 rounded px-3 py-1 text-xs text-gray-200 placeholder-gray-600 outline-none transition-colors">
            <button type="submit"
                    class="px-4 py-1 bg-blue-700 hover:bg-blue-600 text-white rounded text-xs transition-colors">
                Buscar
            </button>
            @if($search || $levelFilter)
                <a href="{{ route('logs.index', ['file' => $selectedKey]) }}"
                   class="px-3 py-1 bg-gray-800 hover:bg-gray-700 text-gray-500 hover:text-gray-300 rounded text-xs transition-colors">
                    ✕
                </a>
            @endif
        </div>

        {{-- Stats --}}
        @if(!empty($stats))
            <div class="ml-auto flex items-center gap-3 text-xs flex-wrap">
                <span class="text-gray-600">
                    {{ $total }} {{ $total === 1 ? 'entrada' : 'entradas' }}
                </span>
                <span class="text-gray-700">|</span>
                @foreach($stats as $lvl => $count)
                    @php
                        $color = match($lvl) {
                            'EMERGENCY', 'CRITICAL', 'ERROR' => 'text-red-400',
                            'ALERT', 'WARNING'               => 'text-amber-400',
                            'NOTICE', 'INFO'                 => 'text-blue-400',
                            default                          => 'text-gray-600',
                        };
                    @endphp
                    <span class="{{ $color }}">{{ $lvl }}: {{ $count }}</span>
                @endforeach
            </div>
        @endif
    </form>
</div>

{{-- ── LOG ENTRIES ──────────────────────────────────────────────────────────── --}}
<main class="flex-1 overflow-y-auto">
    @forelse($entries as $entry)
        <div x-data="{ open: false }" class="border-b border-gray-800/50 {{ $entry['row_class'] }}">

            {{-- Row --}}
            <div @click="open = !open"
                 class="flex items-center gap-3 px-5 py-2 hover:bg-white/[0.025] cursor-pointer select-none group">

                <span class="text-gray-600 shrink-0 w-28 tabular-nums text-xs">
                    {{ $entry['datetime_formatted'] }}
                </span>

                <span class="shrink-0 w-[5.5rem] text-center text-xs font-medium px-2 py-0.5 rounded {{ $entry['level_class'] }}">
                    {{ $entry['level_name'] }}
                </span>

                <span class="text-gray-600 shrink-0 w-14 truncate text-xs">
                    {{ $entry['channel'] }}
                </span>

                <span class="text-gray-200 flex-1 truncate" title="{{ $entry['message'] }}">
                    {{ $entry['message'] }}
                </span>

                @if($entry['has_context'])
                    <svg class="shrink-0 w-3.5 h-3.5 text-gray-600 group-hover:text-gray-400 transition-all duration-150"
                         :class="{ 'rotate-90': open }"
                         fill="currentColor" viewBox="0 0 20 20">
                        <path d="M6.293 4.293a1 1 0 011.414 0L14 10.586l-6.293 6.293a1 1 0 01-1.414-1.414L11.586 10 6.293 4.707a1 1 0 010-1.414z"/>
                    </svg>
                @else
                    <span class="w-3.5 shrink-0"></span>
                @endif
            </div>

            {{-- Expanded context --}}
            @if($entry['has_context'])
                <div x-show="open" x-cloak
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     class="px-5 pb-4 pt-1 space-y-2">

                    @if($entry['exception'])
                        @php $exc = $entry['exception']; @endphp
                        <div class="bg-red-950/50 border border-red-900/60 rounded-lg p-3.5">
                            <div class="text-red-300 font-semibold text-xs mb-1.5">
                                {{ $exc['class'] ?? 'Exception' }}
                            </div>
                            <div class="text-red-200 text-xs mb-2 leading-relaxed">
                                {{ $exc['message'] ?? '' }}
                            </div>
                            @if(!empty($exc['file']))
                                <div class="text-red-700 text-xs mb-2">
                                    {{ $exc['file'] }}:{{ $exc['line'] ?? '' }}
                                </div>
                            @endif
                            @if(!empty($exc['trace']))
                                <pre class="text-red-500/70 text-xs leading-5 max-h-48 overflow-y-auto bg-black/20 rounded p-2">{{ $exc['trace'] }}</pre>
                            @endif
                        </div>
                    @endif

                    @if(!empty($entry['context_extra']))
                        <pre class="text-xs text-gray-400 bg-gray-950 border border-gray-800 rounded-lg p-3.5 max-h-48 overflow-y-auto leading-5">{{ $entry['context_extra_json'] }}</pre>
                    @endif
                </div>
            @endif
        </div>
    @empty
        <div class="flex flex-col items-center justify-center h-64 text-gray-700 gap-3">
            <svg class="w-14 h-14 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1"
                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="text-sm">Nenhuma entrada encontrada</p>
            @if($search || $levelFilter)
                <a href="{{ route('logs.index', ['file' => $selectedKey]) }}"
                   class="text-blue-600 hover:text-blue-400 text-xs transition-colors">
                    ← Limpar filtros
                </a>
            @endif
        </div>
    @endforelse
</main>

{{-- ── PAGINATION ───────────────────────────────────────────────────────────── --}}
@if($totalPages > 1)
    @php
        $qBase = array_filter(['file' => $selectedKey, 'level' => $levelFilter, 'search' => $search]);
        $pStart = max(1, $currentPage - 2);
        $pEnd   = min($totalPages, $currentPage + 2);
    @endphp
    <footer class="shrink-0 bg-gray-900 border-t border-gray-800 px-5 py-3 flex items-center justify-between text-xs text-gray-600">
        <span>Página {{ $currentPage }} de {{ $totalPages }} — {{ $total }} entradas</span>
        <div class="flex gap-1">
            @if($currentPage > 1)
                <a href="{{ route('logs.index', array_merge($qBase, ['page' => $currentPage - 1])) }}"
                   class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-400 rounded transition-colors">
                    ← Ant.
                </a>
            @endif

            @for($i = $pStart; $i <= $pEnd; $i++)
                <a href="{{ route('logs.index', array_merge($qBase, ['page' => $i])) }}"
                   class="px-3 py-1.5 rounded transition-colors
                          {{ $i === $currentPage ? 'bg-blue-700 text-white' : 'bg-gray-800 hover:bg-gray-700 text-gray-400' }}">
                    {{ $i }}
                </a>
            @endfor

            @if($currentPage < $totalPages)
                <a href="{{ route('logs.index', array_merge($qBase, ['page' => $currentPage + 1])) }}"
                   class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-400 rounded transition-colors">
                    Próx. →
                </a>
            @endif
        </div>
    </footer>
@endif

</body>
</html>
