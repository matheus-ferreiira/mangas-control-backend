<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\UserContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckChapterUpdates extends Command
{
    protected $signature = 'content:check-chapters {--user_id=} {--force}';

    protected $description = 'Verifica novos capítulos das obras acompanhadas comparando com o progresso do usuário';

    private const LIMIT = 48;

    private const MAX_PAGES = 20;

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    public function handle(): int
    {
        $query = UserContent::query()
            ->whereNotNull('site_title')
            ->where('site_title', '!=', '')
            ->with(['content', 'user', 'userSite', 'site']);

        if ($userId = $this->option('user_id')) {
            $query->where('user_id', (int) $userId);
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            $this->info('Nenhum item com site_title para verificar.');

            return self::SUCCESS;
        }

        // Sites globais com API de releases configurada
        $configured = Site::whereNotNull('releases_api_url')->get();
        if ($configured->isEmpty()) {
            $this->warn('Nenhum site com releases_api_url configurada.');

            return self::SUCCESS;
        }

        // Associa cada item ao site global (por host da fonte do usuário; fallback site_id; fallback site único)
        $bySite = []; // site_id => ['site' => Site, 'items' => UserContent[]]
        $onlySite = $configured->count() === 1 ? $configured->first() : null;

        foreach ($items as $uc) {
            $site = $this->resolveSite($uc, $configured, $onlySite);
            if (! $site) {
                continue;
            }
            $bySite[$site->id] ??= ['site' => $site, 'items' => []];
            $bySite[$site->id]['items'][] = $uc;
        }

        $checked = 0;
        $withUpdates = 0;

        foreach ($bySite as $group) {
            /** @var Site $site */
            $site = $group['site'];
            $groupItems = $group['items'];

            // Títulos necessários (lowercase) para permitir early-stop
            $needed = collect($groupItems)
                ->map(fn ($uc) => mb_strtolower(trim((string) $uc->site_title)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            try {
                $map = $this->fetchReleasesMap($site, $needed);
            } catch (\Throwable $e) {
                $this->warn("[{$site->name}] falha ao buscar releases: ".$e->getMessage());

                continue; // não interrompe os demais sites
            }

            foreach ($groupItems as $uc) {
                $checked++;
                $key = mb_strtolower(trim((string) $uc->site_title));
                $chapter = $map[$key] ?? null;
                if ($chapter === null) {
                    continue; // não encontrado — ignora silenciosamente
                }

                $uc->site_last_chapter = (string) $chapter;
                $uc->save();

                if ($this->toFloat($chapter) > $this->toFloat($uc->current_units)) {
                    $withUpdates++;
                    $this->line("  📬 {$uc->content?->name}: cap. {$chapter} disponível (você está em {$uc->current_units})");
                }
            }
        }

        $this->info("Verificados {$checked} itens. {$withUpdates} com novos capítulos disponíveis.");

        return self::SUCCESS;
    }

    /**
     * Busca as páginas de releases do site e monta o mapa título(lower) => capítulo.
     * Para cedo quando todos os títulos necessários foram encontrados.
     */
    private function fetchReleasesMap(Site $site, array $needed): array
    {
        $map = [];
        $remaining = array_flip($needed);

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = Http::withHeaders([
                'User-Agent' => self::UA,
                'Accept' => 'application/json',
            ])->timeout(10)->get($site->releases_api_url, [
                'page' => $page,
                'limit' => self::LIMIT,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException("HTTP {$response->status()} na página {$page}");
            }

            $list = $this->extractList($response->json());
            if (empty($list)) {
                break;
            }

            foreach ($list as $entry) {
                $title = data_get($entry, $site->releases_title_field);
                $chapter = data_get($entry, $site->releases_chapter_field);
                if (! is_string($title) && ! is_numeric($title)) {
                    continue;
                }
                $key = mb_strtolower(trim((string) $title));
                if ($key === '' || array_key_exists($key, $map)) {
                    continue; // mantém a primeira ocorrência (mais recente)
                }
                $map[$key] = (string) $chapter;
                unset($remaining[$key]);
            }

            // Early-stop: já achou tudo que precisava, ou chegou na última página
            if (empty($remaining) || count($list) < self::LIMIT) {
                break;
            }
        }

        return $map;
    }

    /** Normaliza diferentes formatos de resposta para uma lista de itens. */
    private function extractList(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        if (array_is_list($json)) {
            return $json;
        }
        foreach (['data', 'mangas', 'results', 'items'] as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                return $json[$k];
            }
        }

        return [];
    }

    /** Resolve o site global de releases para um user_content. */
    private function resolveSite(UserContent $uc, $configured, ?Site $onlySite): ?Site
    {
        // 1) host da fonte do usuário (user_sites.url)
        $host = $this->host($uc->userSite?->url);
        if ($host) {
            $match = $configured->first(fn (Site $s) => $this->host($s->url) === $host);
            if ($match) {
                return $match;
            }
        }

        // 2) site global vinculado diretamente (site_id)
        if ($uc->site && $uc->site->releases_api_url) {
            return $uc->site;
        }

        // 3) único site configurado
        return $onlySite;
    }

    private function host(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $url = str_contains($url, '://') ? $url : 'https://'.$url;
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }

    private function toFloat(mixed $v): float
    {
        return (float) str_replace(',', '.', (string) $v);
    }
}
