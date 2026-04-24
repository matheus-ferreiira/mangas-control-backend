<?php

namespace Database\Seeders;

use App\Models\Content;
use Illuminate\Database\Seeder;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $contents = [
            // Mangas
            ['name' => 'One Piece',           'type' => 'manga',  'total_units' => 1110],
            ['name' => 'Naruto',              'type' => 'manga',  'total_units' => 700],
            ['name' => 'Bleach',              'type' => 'manga',  'total_units' => 686],
            ['name' => 'Attack on Titan',     'type' => 'manga',  'total_units' => 139],
            ['name' => 'Demon Slayer',        'type' => 'manga',  'total_units' => 205],
            ['name' => 'My Hero Academia',    'type' => 'manga',  'total_units' => 430],
            ['name' => 'Dragon Ball',         'type' => 'manga',  'total_units' => 519],
            ['name' => 'Hunter x Hunter',     'type' => 'manga',  'total_units' => null],
            ['name' => 'Fullmetal Alchemist', 'type' => 'manga',  'total_units' => 108],
            ['name' => 'Death Note',          'type' => 'manga',  'total_units' => 108],

            // Animes
            ['name' => 'Naruto Shippuden',     'type' => 'anime', 'total_units' => 500],
            ['name' => 'Dragon Ball Z',         'type' => 'anime', 'total_units' => 291],
            ['name' => 'Attack on Titan',       'type' => 'anime', 'total_units' => 87],
            ['name' => 'Demon Slayer',          'type' => 'anime', 'total_units' => 55],
            ['name' => 'Sword Art Online',      'type' => 'anime', 'total_units' => 25],
            ['name' => 'Re:Zero',               'type' => 'anime', 'total_units' => 50],
            ['name' => 'Fullmetal Alchemist: Brotherhood', 'type' => 'anime', 'total_units' => 64],
            ['name' => 'Death Note',            'type' => 'anime', 'total_units' => 37],

            // Novels
            ['name' => 'Overlord',             'type' => 'novel', 'total_units' => 17],
            ['name' => 'Re:Zero',              'type' => 'novel', 'total_units' => null],
            ['name' => 'Sword Art Online',     'type' => 'novel', 'total_units' => 27],
            ['name' => 'No Game No Life',      'type' => 'novel', 'total_units' => 12],
            ['name' => 'That Time I Got Reincarnated as a Slime', 'type' => 'novel', 'total_units' => 22],
        ];

        foreach ($contents as $data) {
            Content::firstOrCreate(['name' => $data['name'], 'type' => $data['type']], $data);
        }
    }
}
