<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogController extends Controller
{
    use ApiResponse;

    private const MAX_CHARS      = 50_000;
    private const AVAILABLE_LOGS = ['laravel', 'errors', 'access'];

    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type', 'laravel');

        if (!in_array($type, self::AVAILABLE_LOGS, true)) {
            return $this->error(
                'Tipo de log inválido. Valores aceitos: '.implode(', ', self::AVAILABLE_LOGS)
            );
        }

        $path = storage_path('logs/'.$type.'.log');

        if (!file_exists($path)) {
            return $this->success([
                'type'    => $type,
                'content' => '',
                'size'    => 0,
                'lines'   => 0,
            ], 'Arquivo de log ainda não gerado.');
        }

        $limit   = min((int) $request->query('limit', self::MAX_CHARS), self::MAX_CHARS);
        $size    = filesize($path);
        $content = $this->tail($path, $limit);

        return $this->success([
            'type'      => $type,
            'content'   => $content,
            'size'      => $size,
            'truncated' => $size > $limit,
        ]);
    }

    private function tail(string $path, int $chars): string
    {
        $handle = fopen($path, 'r');

        if (!$handle) {
            return '';
        }

        $fileSize = filesize($path);
        $offset   = max(0, $fileSize - $chars);

        fseek($handle, $offset);
        $content = fread($handle, $chars);
        fclose($handle);

        // Drop partial first line if we seeked mid-file
        if ($offset > 0) {
            $newline = strpos($content, "\n");
            $content = $newline !== false ? substr($content, $newline + 1) : $content;
        }

        return $content;
    }
}
