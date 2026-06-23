<?php

namespace App\Services;

use App\Http\Resources\ContentResource;
use App\Models\Content;
use App\Models\UserContent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class DiscoverService
{
    private const PUBLIC_CACHE_TTL = 300; // 5 minutes

    private const USER_CACHE_TTL = 60; // 1 minute

    public function getHome(int $userId): array
    {
        // Filtro global de conteúdo adulto (perfil). Dois buckets de cache público:
        // SFW (a0) e completo (a1) — evita explosão de cache por usuário.
        $showAdult = (bool) (optional(\App\Models\User::find($userId))->show_adult_content ?? false);
        $a = $showAdult ? 1 : 0;

        $publicSections = Cache::remember("discover.home.public.v2.a{$a}", self::PUBLIC_CACHE_TTL, fn () => $this->loadPublicSections($showAdult));

        $personalData = Cache::remember("discover.home.user.{$userId}.v2.a{$a}", self::USER_CACHE_TTL, fn () => $this->loadPersonalSections($userId, $showAdult));

        // Tag public catalog items with is_in_library per user
        $inLibrary = array_flip(UserContent::where('user_id', $userId)->pluck('content_id')->toArray());
        $publicTagged = $this->tagWithLibraryStatus($publicSections, $inLibrary);

        return array_merge($publicTagged, $personalData);
    }

    private function tagWithLibraryStatus(array $sections, array $inLibrary): array
    {
        $result = [];
        foreach ($sections as $key => $value) {
            if (is_array($value) && isset($value[0]) && is_array($value[0]) && array_key_exists('id', $value[0])) {
                $result[$key] = array_map(function (array $item) use ($inLibrary) {
                    $item['is_in_library'] = isset($inLibrary[$item['id']]);

                    return $item;
                }, $value);
            } elseif (is_array($value) && array_key_exists('id', $value)) {
                $value['is_in_library'] = isset($inLibrary[$value['id']]);
                $result[$key] = $value;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function loadPublicSections(bool $showAdult = false): array
    {
        // Builder-base: aplica o filtro global de adulto a TODAS as seções da home.
        $base = fn () => Content::query()
            ->when(! $showAdult, fn ($q) => $q->where('is_adult', false));

        // Featured: random from top 8 by score to add variety
        $topEight = $base()->whereNotNull('cover')
            ->orderByRaw('CASE WHEN score IS NULL THEN 1 ELSE 0 END, score DESC')
            ->limit(8)
            ->get();
        $featured = $topEight->isNotEmpty() ? $topEight->random() : null;

        // Trending: by popularity, mixed types
        $trending = $base()->whereNotNull('cover')
            ->orderByRaw('CASE WHEN popularity IS NULL THEN 1 ELSE 0 END, popularity DESC')
            ->limit(12)
            ->get();

        // Top anime by score
        $topAnime = $base()->where('type', 'anime')
            ->whereNotNull('cover')
            ->orderByRaw('CASE WHEN score IS NULL THEN 1 ELSE 0 END, score DESC')
            ->limit(12)
            ->get();

        // Popular manga by popularity
        $popularManga = $base()->where('type', 'manga')
            ->whereNotNull('cover')
            ->orderByRaw('CASE WHEN popularity IS NULL THEN 1 ELSE 0 END, popularity DESC')
            ->limit(12)
            ->get();

        // Movies & TV series
        $moviesAndTv = $base()->whereIn('type', ['movie', 'tv'])
            ->whereNotNull('cover')
            ->orderByRaw('CASE WHEN score IS NULL THEN 1 ELSE 0 END, score DESC')
            ->limit(12)
            ->get();

        // Top rated overall (score >= 7.5)
        $topRated = $base()->whereNotNull('cover')
            ->whereNotNull('score')
            ->where('score', '>=', 7.5)
            ->orderBy('score', 'desc')
            ->limit(12)
            ->get();

        // Recently updated chapters/episodes
        // (coluna `last_unit_update` de `contents` foi renomeada para `release_date`)
        $recentlyUpdated = $base()->whereNotNull('cover')
            ->whereNotNull('release_date')
            ->orderBy('release_date', 'desc')
            ->limit(12)
            ->get();

        // New to catalog
        $newAdditions = $base()->whereNotNull('cover')
            ->orderBy('created_at', 'desc')
            ->limit(12)
            ->get();

        // Top novels
        $topNovels = $base()->where('type', 'novel')
            ->whereNotNull('cover')
            ->orderByRaw('CASE WHEN score IS NULL THEN 1 ELSE 0 END, score DESC')
            ->limit(10)
            ->get();

        // Completed works (good for binge)
        $completedWorks = $base()->whereNotNull('cover')
            ->where('status', 'completed')
            ->orderByRaw('CASE WHEN score IS NULL THEN 1 ELSE 0 END, score DESC')
            ->limit(12)
            ->get();

        return [
            'featured' => $featured ? $this->toItem($featured) : null,
            'trending' => $this->toItems($trending),
            'top_anime' => $this->toItems($topAnime),
            'popular_manga' => $this->toItems($popularManga),
            'movies_and_tv' => $this->toItems($moviesAndTv),
            'top_rated' => $this->toItems($topRated),
            'recently_updated' => $this->toItems($recentlyUpdated),
            'new_additions' => $this->toItems($newAdditions),
            'top_novels' => $this->toItems($topNovels),
            'completed_works' => $this->toItems($completedWorks),
        ];
    }

    private function loadPersonalSections(int $userId, bool $showAdult = false): array
    {
        // Continue watching/reading
        $inProgress = UserContent::where('user_id', $userId)
            ->where('status', 'reading')
            ->when(! $showAdult, fn ($q) => $q->whereHas('content', fn ($c) => $c->where('is_adult', false)))
            ->with('content:id,name,cover,type,total_units')
            ->orderBy('updated_at', 'desc')
            ->limit(8)
            ->get();

        $continueItems = $inProgress
            ->filter(fn ($uc) => $uc->content)
            ->map(fn ($uc) => [
                'user_content_id' => $uc->id,
                'content_id' => $uc->content_id,
                'title' => $uc->content->name,
                'cover' => $this->resolveUrl($uc->content->cover),
                'type' => $uc->content->type,
                'current_units' => $uc->current_units,
                'total_units' => $uc->content->total_units,
                'progress_pct' => $uc->content->total_units
                    ? min((int) round(($uc->current_units / $uc->content->total_units) * 100), 100)
                    : 30,
            ])
            ->values()
            ->all();

        // Recommendations based on user's top genres, excluding already in library
        $topGenres = $this->getUserTopGenres($userId);
        $recommendations = [];

        if (! empty($topGenres)) {
            $userContentIds = UserContent::where('user_id', $userId)->pluck('content_id');
            $recs = Content::whereNotNull('cover')
                ->when(! $showAdult, fn ($q) => $q->where('is_adult', false))
                ->whereNotIn('id', $userContentIds)
                ->where(function ($q) use ($topGenres) {
                    foreach (array_slice($topGenres, 0, 3) as $genre) {
                        $q->orWhereJsonContains('genres', $genre);
                    }
                })
                ->orderByRaw('CASE WHEN score IS NULL THEN 1 ELSE 0 END, score DESC')
                ->limit(12)
                ->get();

            $recommendations = $this->toItems($recs);
        }

        return [
            'continue_items' => $continueItems,
            'recommendations' => $recommendations,
            'user_top_genres' => $topGenres,
        ];
    }

    private function getUserTopGenres(int $userId): array
    {
        $userContents = UserContent::where('user_id', $userId)
            ->whereIn('status', ['reading', 'completed'])
            ->with('content:id,genres')
            ->limit(50)
            ->get();

        $genreCount = [];
        foreach ($userContents as $uc) {
            foreach ($uc->content?->genres ?? [] as $genre) {
                $genreCount[$genre] = ($genreCount[$genre] ?? 0) + 1;
            }
        }

        arsort($genreCount);

        return array_keys(array_slice($genreCount, 0, 5, true));
    }

    private function toItem(Content $content): array
    {
        return (new ContentResource($content))->toArray(request());
    }

    private function toItems($collection): array
    {
        return $collection->map(fn ($c) => $this->toItem($c))->values()->all();
    }

    private function resolveUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http')
            ? $path
            : Storage::disk('public')->url($path);
    }
}
