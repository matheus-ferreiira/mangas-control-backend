<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class ChapterCheckController extends Controller
{
    use ApiResponse;

    public function checkChapters(): JsonResponse
    {
        // Apenas o dono do app (admin) pode disparar o scraping externo.
        if (! optional(auth()->user())->isAdmin()) {
            return $this->error('Acesso restrito.', [], 403);
        }

        // Executa de forma síncrona: não há worker de fila em produção (Railway),
        // então um job enfileirado nunca seria processado. O volume é pequeno
        // (poucos sites, com cap de páginas e early-stop), cabendo no request.
        Artisan::call('content:check-chapters', ['--user_id' => auth()->id()]);

        return $this->success(
            ['output' => trim(Artisan::output())],
            'Verificação concluída.'
        );
    }
}
