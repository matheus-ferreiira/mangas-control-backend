<?php

namespace Database\Seeders;

use App\Models\Site;
use Illuminate\Database\Seeder;

class SiteSeeder extends Seeder
{
    public function run(): void
    {
        $sites = [
            ['name' => 'MangaDex',      'url' => 'https://mangadex.org'],
            ['name' => 'MangaPlus',     'url' => 'https://mangaplus.shueisha.co.jp'],
            ['name' => 'Crunchyroll',   'url' => 'https://www.crunchyroll.com'],
            ['name' => 'Funimation',    'url' => 'https://www.funimation.com'],
            ['name' => 'Netflix Anime', 'url' => 'https://www.netflix.com'],
            ['name' => 'Amazon Prime',  'url' => 'https://www.primevideo.com'],
            ['name' => 'Novel Updates', 'url' => 'https://www.novelupdates.com'],
            ['name' => 'Wuxiaworld',    'url' => 'https://www.wuxiaworld.com'],
        ];

        foreach ($sites as $data) {
            Site::firstOrCreate(['name' => $data['name']], $data);
        }
    }
}
