<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LogViewerController extends Controller
{
    private const PER_PAGE  = 50;
    private const MAX_LINES = 2000;

    private const LEVEL_BADGE = [
        'DEBUG'     => 'bg-slate-700 text-slate-300',
        'INFO'      => 'bg-blue-800 text-blue-200',
        'NOTICE'    => 'bg-cyan-800 text-cyan-200',
        'WARNING'   => 'bg-amber-800 text-amber-200',
        'ERROR'     => 'bg-red-800 text-red-200',
        'CRITICAL'  => 'bg-red-700 text-white',
        'ALERT'     => 'bg-orange-800 text-orange-200',
        'EMERGENCY' => 'bg-red-600 text-white',
    ];

    private const LEVEL_ROW = [
        'WARNING'   => 'bg-amber-950/20',
        'ALERT'     => 'bg-orange-950/25',
        'ERROR'     => 'bg-red-950/30',
        'CRITICAL'  => 'bg-red-950/50',
        'EMERGENCY' => 'bg-red-950/60',
    ];

    public function index(Request $request): View
    {
        $files       = $this->getLogFiles();
        $selectedKey = $request->query('file', $files->first()['key'] ?? 'laravel');
        $levelFilter = strtoupper($request->query('level', ''));
        $search      = $request->query('search', '');
        $page        = max(1, (int) $request->query('page', 1));

        $selectedFile = $files->firstWhere('key', $selectedKey) ?? $files->first();

        [
            'data'       => $entries,
            'total'      => $total,
            'totalPages' => $totalPages,
            'stats'      => $stats,
            'truncated'  => $truncated,
        ] = $this->parseFile($selectedFile, $levelFilter, $search, $page);

        return view('logs.index', compact(
            'files', 'selectedKey', 'selectedFile',
            'levelFilter', 'search',
            'entries', 'total', 'totalPages',
            'stats', 'truncated',
        ) + ['currentPage' => $page]);
    }

    public function destroy(string $file): RedirectResponse
    {
        $target = $this->getLogFiles()->firstWhere('key', $file);

        if ($target && file_exists($target['path'])) {
            file_put_contents($target['path'], '');
        }

        return redirect()
            ->route('logs.index', ['file' => $file])
            ->with('toast', "Log \"{$file}\" limpo com sucesso.");
    }

    public function destroyAll(): RedirectResponse
    {
        foreach (glob(storage_path('logs/*.log')) ?: [] as $path) {
            file_put_contents($path, '');
        }

        return redirect()
            ->route('logs.index')
            ->with('toast', 'Todos os logs foram limpos.');
    }

    // -------------------------------------------------------------------------

    private function getLogFiles(): Collection
    {
        return collect(glob(storage_path('logs/*.log')) ?: [])
            ->map(fn (string $path) => [
                'key'        => basename($path, '.log'),
                'path'       => $path,
                'size'       => filesize($path),
                'size_human' => $this->formatSize((int) filesize($path)),
                'modified'   => filemtime($path),
            ])
            ->sortByDesc('modified')
            ->values();
    }

    private function parseFile(?array $file, string $level, string $search, int $page): array
    {
        $empty = ['data' => [], 'total' => 0, 'totalPages' => 1, 'stats' => [], 'truncated' => false];

        if (!$file || !file_exists($file['path']) || filesize($file['path']) === 0) {
            return $empty;
        }

        ['lines' => $lines, 'truncated' => $truncated] = $this->readLastLines($file['path']);

        $stats    = [];
        $filtered = [];

        foreach (array_reverse($lines) as $raw) {
            $entry = $this->parseLine($raw);
            if (!$entry) continue;

            $lvl         = $entry['level_name'];
            $stats[$lvl] = ($stats[$lvl] ?? 0) + 1;

            if ($level && $lvl !== $level) continue;
            if ($search && !$this->matchesSearch($entry, $search)) continue;

            $filtered[] = $entry;
        }

        $total      = count($filtered);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = min($page, $totalPages);
        $data       = array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $order = ['EMERGENCY', 'CRITICAL', 'ALERT', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'];
        uksort($stats, fn ($a, $b) => array_search($a, $order) <=> array_search($b, $order));

        return compact('data', 'total', 'totalPages', 'stats', 'truncated');
    }

    private function readLastLines(string $path): array
    {
        $chunkSize = 4 * 1024 * 1024;
        $fileSize  = (int) filesize($path);
        $offset    = max(0, $fileSize - $chunkSize);

        $handle = fopen($path, 'r');
        if (!$handle) return ['lines' => [], 'truncated' => false];

        fseek($handle, $offset);
        $content = fread($handle, $chunkSize);
        fclose($handle);

        $lines = array_values(array_filter(
            explode("\n", $content),
            fn (string $l) => trim($l) !== ''
        ));

        $lines     = array_slice($lines, -self::MAX_LINES);
        $truncated = $offset > 0 || count($lines) >= self::MAX_LINES;

        return compact('lines', 'truncated');
    }

    private function parseLine(string $line): ?array
    {
        $data = json_decode(trim($line), true);

        if (!is_array($data) || !isset($data['message'])) {
            return null;
        }

        $levelName = strtoupper($data['level_name'] ?? 'INFO');
        $context   = $data['context'] ?? [];
        $exception = $context['exception'] ?? null;
        $ctxExtra  = collect($context)->except(['exception', 'env', 'timestamp'])->toArray();

        return [
            'datetime_formatted' => $this->formatDatetime($data['datetime'] ?? ''),
            'level_name'         => $levelName,
            'level_class'        => self::LEVEL_BADGE[$levelName] ?? 'bg-gray-700 text-gray-300',
            'row_class'          => self::LEVEL_ROW[$levelName] ?? '',
            'channel'            => $data['channel'] ?? '',
            'message'            => $data['message'] ?? '',
            'has_context'        => $exception !== null || !empty($ctxExtra),
            'exception'          => $exception,
            'context_extra'      => $ctxExtra,
            'context_extra_json' => !empty($ctxExtra)
                ? json_encode($ctxExtra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ];
    }

    private function matchesSearch(array $entry, string $search): bool
    {
        $needle = strtolower($search);

        return str_contains(strtolower($entry['message']), $needle)
            || (!empty($entry['context_extra']) && str_contains(strtolower(json_encode($entry['context_extra'])), $needle))
            || ($entry['exception'] && str_contains(strtolower(json_encode($entry['exception'])), $needle));
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes === 0)             return '0 B';
        if ($bytes < 1_024)           return $bytes.' B';
        if ($bytes < 1_048_576)       return round($bytes / 1_024, 1).' KB';
        return round($bytes / 1_048_576, 2).' MB';
    }

    private function formatDatetime(string $dt): string
    {
        if (!$dt) return '—';
        try {
            return Carbon::parse($dt)->format('d/m H:i:s');
        } catch (\Throwable) {
            return $dt;
        }
    }
}
