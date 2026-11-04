<?php

namespace App\Services;

use App\Models\Content;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class NovelReaderService
{
    public function metadata(Content $content): ?array
    {
        if ($content->type !== 'novel') {
            return null;
        }

        $names = array_map($this->normalize(...), array_filter([
            $content->name, ...($content->alternative_names ?? []),
        ], 'is_string'));

        foreach (config('novels', []) as $slug => $edition) {
            if (array_intersect($names, array_map($this->normalize(...), $edition['aliases']))) {
                $index = $this->index($slug);

                return array_intersect_key($index, array_flip([
                    'slug', 'title', 'language', 'edition', 'first_chapter', 'last_chapter',
                ]));
            }
        }

        return null;
    }

    public function index(string $slug): array
    {
        abort_unless(array_key_exists($slug, config('novels', [])), 404);

        return $this->readJson(resource_path("novels/{$slug}/index.json"));
    }

    public function chapter(string $slug, int $number): array
    {
        $index = $this->index($slug);
        abort_unless(in_array($number, $index['chapters'], true), 404, 'Capítulo indisponível.');

        return $this->readJson(resource_path("novels/{$slug}/{$number}.json"));
    }

    public function browserUrl(string $slug, int $chapter, ?int $expires = null): string
    {
        $relative = URL::temporarySignedRoute('novel.read', $expires ? Carbon::createFromTimestamp($expires) : now()->addHours(12), [
            'slug' => $slug, 'chapter' => $chapter,
        ], absolute: false);

        return rtrim(config('app.url'), '/').$relative;
    }

    private function normalize(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', Str::lower($name));
    }

    private function readJson(string $path): array
    {
        abort_unless(is_file($path), 404, 'Capítulo indisponível.');

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
