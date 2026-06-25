<?php

namespace Database\Seeders;

use App\Models\Site;
use Illuminate\Database\Seeder;

class SitesSeeder extends Seeder
{
    public function run(): void
    {
        $sites = [
            [
                'name' => 'ToonLivre',
                'url' => 'https://toonlivre.net',
                'releases_api_url' => 'https://toonlivre.net/api/mangas/releases',
                'releases_api_type' => 'json',
                'releases_title_field' => 'alternativeTitle',
                'releases_chapter_field' => 'recentChapters.0.number',
            ],
            [
                'name' => 'MangaStop',
                'url' => 'https://mangastop.net',
                'releases_api_url' => null, // a configurar futuramente
                'releases_api_type' => 'json',
                'releases_title_field' => 'alternativeTitle',
                'releases_chapter_field' => 'recentChapters.0.number',
            ],
        ];

        foreach ($sites as $site) {
            Site::updateOrCreate(['url' => $site['url']], $site);
        }
    }
}
