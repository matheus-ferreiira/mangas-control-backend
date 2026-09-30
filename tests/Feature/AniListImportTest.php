<?php

namespace Tests\Feature;

use App\Models\Content;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * content:import --by-date (fatias por ano, sem o teto de 5.000), 429 com
 * espera e colisão por nome entre obras com anilist_id diferentes.
 */
class AniListImportTest extends TestCase
{
    use RefreshDatabase;

    private function media(int $id, string $title, int $year, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'idMal' => null,
            'title' => ['romaji' => $title, 'english' => $title, 'native' => null],
            'synonyms' => [], 'format' => 'MANGA', 'status' => 'RELEASING', 'description' => null,
            'startDate' => ['year' => $year, 'month' => 1, 'day' => 1], 'endDate' => ['year' => null],
            'seasonYear' => null, 'chapters' => null, 'countryOfOrigin' => 'KR', 'isAdult' => true,
            'coverImage' => ['extraLarge' => null], 'bannerImage' => null, 'genres' => [], 'tags' => [],
            'averageScore' => null, 'popularity' => 1, 'studios' => ['nodes' => []], 'trailer' => null, 'source' => null,
        ], $extra);
    }

    private function page(array $media, bool $next = false): array
    {
        return ['data' => ['Page' => ['pageInfo' => ['hasNextPage' => $next], 'media' => $media]]];
    }

    public function test_by_date_imports_each_year_slice(): void
    {
        Http::fake(function ($request) {
            $v = $request->data()['variables'];
            if ($v['page'] === 100) {
                return Http::response($this->page([]));
            }

            return Http::response($this->page(match ($v['g']) {
                20199999 => [$this->media(1, 'Obra 2020', 2020)],
                20209999 => [$this->media(2, 'Obra 2021', 2021, ['status' => 'NOT_YET_RELEASED'])],
                default => [],
            }));
        });

        $this->artisan('content:import', ['--type' => 'manga', '--origin' => 'manhwa', '--adult' => true, '--by-date' => true, '--from-year' => 2020, '--to-year' => 2021])
            ->assertSuccessful();

        $this->assertSame(2, Content::whereIn('anilist_id', [1, 2])->count());
        $this->assertSame('ongoing', Content::where('anilist_id', 2)->value('status'));
    }

    public function test_rate_limit_waits_and_continues(): void
    {
        Http::fakeSequence('graphql.anilist.co')
            ->push([], 429, ['Retry-After' => '1'])
            ->push($this->page([$this->media(7, 'Depois do 429', 2020)]));

        $this->artisan('content:import', ['--type' => 'manga', '--pages' => 1, '--adult' => true])->assertSuccessful();

        $this->assertSame(1, Content::where('anilist_id', 7)->count());
    }

    public function test_adult_boys_love_is_not_imported(): void
    {
        $bl = ['tags' => [['name' => "Boys' Love", 'category' => 'Theme-Romance', 'isGeneralSpoiler' => false]]];
        Http::fake(['graphql.anilist.co' => Http::response($this->page([
            $this->media(11, 'BL Adulto', 2024, $bl),
            $this->media(12, 'BL Livre', 2024, $bl + ['isAdult' => false]),
            $this->media(13, 'Outro Adulto', 2024),
        ]))]);

        $this->artisan('content:import', ['--type' => 'manga', '--pages' => 1, '--adult' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing([12, 13], Content::pluck('anilist_id')->all());
    }

    public function test_same_name_with_other_anilist_id_is_a_new_work(): void
    {
        Content::create(['name' => 'Monster', 'type' => 'manga', 'anilist_id' => 30001, 'origin_type' => 'manga']);
        Http::fake(['graphql.anilist.co' => Http::response($this->page([$this->media(999, 'Monster', 2021)]))]);

        $this->artisan('content:import', ['--type' => 'manga', '--pages' => 1, '--adult' => true])->assertSuccessful();

        $this->assertSame(2, Content::where('name', 'Monster')->count());
        $this->assertSame('manga', Content::where('anilist_id', 30001)->value('origin_type'));
    }
}
