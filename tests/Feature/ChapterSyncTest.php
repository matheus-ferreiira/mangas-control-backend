<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use App\Models\UserContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/user/sync-chapters — vínculo entre lançamentos do ToonLivre e a
 * biblioteca (ChapterMatchService). Os títulos vêm de casos reais observados.
 */
class ChapterSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Site $toon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        // A migration de sites já popula o ToonLivre (SitesSeeder).
        $this->toon = Site::where('url', 'like', '%toonlivre%')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    private function libraryItem(string $name, ?int $year, array $alts = [], array $attrs = []): UserContent
    {
        $content = Content::create([
            'name' => $name,
            'type' => 'manga',
            'origin_type' => 'manhwa',
            'release_year' => $year,
            'alternative_names' => array_merge([$name], $alts),
        ]);

        return UserContent::create(array_merge([
            'user_id' => $this->user->id,
            'content_id' => $content->id,
            'current_units' => 10,
            'status' => 'reading',
        ], $attrs));
    }

    private function release(string $id, ?string $alt, ?string $title, ?string $year, string $chapter): array
    {
        return ['id' => $id, 'alternativeTitle' => $alt, 'title' => $title, 'releaseYear' => $year, 'chapter' => $chapter];
    }

    private function sync(array $releases): array
    {
        return $this->postJson('/api/user/sync-chapters', ['releases' => $releases])
            ->assertOk()
            ->json('data');
    }

    public function test_exact_english_title_links_and_saves_work_id(): void
    {
        $uc = $this->libraryItem('Pick Me Up', 2022);

        $data = $this->sync([$this->release('obra-pick', 'Pick Me Up', 'Pick Me Up', '2022', '221')]);

        $uc->refresh();
        $this->assertSame('obra-pick', $uc->site_work_id);
        $this->assertSame('221', $uc->site_last_chapter);
        $this->assertSame($this->toon->id, $uc->site_id);
        $this->assertSame(1, $data['linked']);
        $this->assertSame('221', $data['new_chapters'][0]['available']);
        $this->assertSame([], $data['auto_linked']);
    }

    public function test_leading_article_is_ignored(): void
    {
        $uc = $this->libraryItem('The Legend of the Northern Blade', 2019);

        $this->sync([$this->release('obra-1eaae2fd', 'Legend of the Northern Blade', 'A Lenda da Lâmina do Norte', '2019', '203')]);

        $uc->refresh();
        $this->assertSame('obra-1eaae2fd', $uc->site_work_id);
        $this->assertSame('Legend of the Northern Blade', $uc->site_title);
    }

    public function test_portuguese_title_is_used_when_english_is_missing(): void
    {
        $uc = $this->libraryItem('Martial God', 2021, ['Deus das Artes Marciais']);

        $this->sync([$this->release('obra-deus', null, 'Deus das Artes Marciais', '2021', '95')]);

        $uc->refresh();
        $this->assertSame('obra-deus', $uc->site_work_id);
        $this->assertSame('Deus das Artes Marciais', $uc->site_title);
    }

    public function test_similar_title_with_same_year_links_and_is_reported(): void
    {
        $uc = $this->libraryItem('The Bastard of Swordborne', 2024, ['Regressing as the Reincarnated Bastard of the Sword Clan']);

        $data = $this->sync([
            $this->release('obra-bastard', 'Regressing as the Bastard of the Sword Clan', 'Regressando como o Bastardo do Clã da Espada', '2024', '88'),
        ]);

        $uc->refresh();
        $this->assertSame('obra-bastard', $uc->site_work_id);
        $this->assertCount(1, $data['auto_linked']);
        $this->assertSame('The Bastard of Swordborne', $data['auto_linked'][0]['title']);
        $this->assertGreaterThanOrEqual(0.85, $data['auto_linked'][0]['score']);
    }

    public function test_sequel_with_distant_year_is_not_linked(): void
    {
        $uc = $this->libraryItem('Solo Leveling', 2018);
        $tomb = $this->libraryItem('Tomb Raider King', 2019);

        $data = $this->sync([
            $this->release('obra-ragnarok', 'Solo Leveling: Ragnarok', 'Upando Sozinho: Ragnarok', '2024', '60'),
            $this->release('obra-endline', 'Tomb Raider King: End Line', 'Rei dos Saqueadores de Tumba: Fim da Linha', '2026', '27'),
        ]);

        $this->assertNull($uc->refresh()->site_work_id);
        $this->assertNull($tomb->refresh()->site_work_id);
        $this->assertContains('Solo Leveling', $data['unmatched']);
        $this->assertContains('Tomb Raider King', $data['unmatched']);
    }

    public function test_close_candidates_are_ambiguous_and_not_linked(): void
    {
        $uc = $this->libraryItem('Dungeon Reset Hero', 2022);

        $data = $this->sync([
            $this->release('obra-a', 'Dungeon Reset Heroes', null, '2022', '10'),
            $this->release('obra-b', 'Dungeon Reset Hera', null, '2022', '12'),
        ]);

        $this->assertNull($uc->refresh()->site_work_id);
        $this->assertCount(1, $data['ambiguous']);
        $this->assertSame('Dungeon Reset Hero', $data['ambiguous'][0]['title']);
    }

    public function test_saved_work_id_wins_over_title(): void
    {
        $uc = $this->libraryItem('Monster', 2021, [], ['site_work_id' => 'obra-monster-kr', 'site_title' => 'Monster']);

        $this->sync([
            $this->release('obra-monster-jp', 'Monster', 'Monster', '1994', '162'),
            $this->release('obra-monster-kr', 'Monster', 'Monstro', '2021', '73'),
        ]);

        $this->assertSame('73', $uc->refresh()->site_last_chapter);
    }

    public function test_linked_work_without_recent_release_is_not_reported_as_unmatched(): void
    {
        $this->libraryItem('Nano Machine', 2020, [], ['site_work_id' => 'obra-nano']);

        $data = $this->sync([$this->release('obra-outra', 'Other Work', null, '2020', '5')]);

        $this->assertNotContains('Nano Machine', $data['unmatched']);
    }

    public function test_legacy_payload_without_ids_still_matches_by_title(): void
    {
        $uc = $this->libraryItem('Lookism', 2014, [], ['site_work_id' => 'obra-lookism']);

        $data = $this->postJson('/api/user/sync-chapters', [
            'releases' => [['alternativeTitle' => 'Lookism', 'chapter' => '626']],
        ])->assertOk()->json('data');

        $uc->refresh();
        $this->assertSame('626', $uc->site_last_chapter);
        $this->assertSame('obra-lookism', $uc->site_work_id);
        $this->assertSame(1, $data['updated']);
    }

    public function test_editing_site_title_clears_work_id(): void
    {
        $uc = $this->libraryItem('Doom Breaker', 2022, [], ['site_work_id' => 'obra-errada', 'site_title' => 'Defense Breaker']);

        $this->patchJson("/api/user-contents/{$uc->id}", ['site_title' => 'Doom Breaker'])->assertOk();

        $uc->refresh();
        $this->assertNull($uc->site_work_id);
        $this->assertSame('Doom Breaker', $uc->site_title);
    }

    public function test_malformed_items_are_ignored(): void
    {
        $uc = $this->libraryItem('Pick Me Up', 2022);

        $this->sync([
            'lixo',
            ['id' => 'obra-x'],
            ['alternativeTitle' => ['array'], 'chapter' => '1'],
            $this->release('obra-pick', 'Pick Me Up', null, 'n/a', '221'),
        ]);

        $this->assertSame('221', $uc->refresh()->site_last_chapter);
    }
}
