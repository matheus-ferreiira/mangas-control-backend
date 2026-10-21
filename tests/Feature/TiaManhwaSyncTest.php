<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use App\Models\UserContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sync de capítulos com `site = tiamanhwa`. A listagem do site só traz o título
 * em português; o vínculo vem da busca do site, que manda os "Outros Títulos"
 * da página da obra em `alternativeTitles`. Títulos e IDs de casos reais
 * (simulação de 2026-10-20).
 */
class TiaManhwaSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Site $tia;

    private Site $toon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        // A migration do TiaManhwa roda o SitesSeeder.
        $this->tia = Site::where('url', 'like', '%tiamanhwa%')->firstOrFail();
        $this->toon = Site::where('url', 'like', '%toonlivre%')->firstOrFail();
        Sanctum::actingAs($this->user);
        $this->withoutDefer();
        Http::preventStrayRequests();
    }

    private function libraryItem(string $name, ?int $year, array $attrs = [], array $contentAttrs = []): UserContent
    {
        $content = Content::create(array_merge([
            'name' => $name,
            'type' => 'manga',
            'origin_type' => 'manhwa',
            'release_year' => $year,
            'is_adult' => true,
            'alternative_names' => [$name],
        ], $contentAttrs));

        return UserContent::create(array_merge([
            'user_id' => $this->user->id,
            'content_id' => $content->id,
            'current_units' => 10,
            'status' => 'reading',
        ], $attrs));
    }

    /** Obra vinda da listagem (só título PT) ou, com $alts/$year, da página da obra. */
    private function release(string $id, string $title, string $chapter, array $alts = [], ?string $year = null): array
    {
        return ['id' => $id, 'title' => $title, 'alternativeTitles' => $alts, 'releaseYear' => $year, 'chapter' => $chapter];
    }

    private function sync(array $payload): array
    {
        return $this->postJson('/api/user/sync-chapters', array_merge(['site' => 'tiamanhwa'], $payload))
            ->assertOk()
            ->json('data');
    }

    public function test_search_result_links_by_exact_alternative_title(): void
    {
        $uc = $this->libraryItem('Cleaning Service', 2024, ['current_units' => 18]);

        $data = $this->sync(['releases' => [
            $this->release('2070', 'Serviço de Limpeza', '38', ['청소 서비스', 'Cleaning Service'], '2024'),
        ]]);

        $uc->refresh();
        $this->assertSame($this->tia->id, (int) $uc->site_id);
        $this->assertSame('2070', $uc->site_work_id);
        $this->assertSame('Serviço de Limpeza', $uc->site_title);
        $this->assertSame('38', $uc->site_last_chapter);
        $this->assertSame('tiamanhwa', $data['site']);
        $this->assertSame(1, $data['linked']);
        $this->assertSame(1, $data['total_linked']);
        $this->assertSame('38', $data['new_chapters'][0]['available']);
    }

    public function test_feed_entry_updates_linked_item_by_id_without_retitling(): void
    {
        $uc = $this->libraryItem('Moby Dick', 2023, [
            'site_id' => null, 'site_work_id' => null, 'current_units' => 98,
        ]);
        $this->sync(['releases' => [$this->release('2015', 'Moby Dick', '106', ['Moby Dick'], '2023')]]);
        $this->assertSame('2015', $uc->refresh()->site_work_id);

        // Próximo sync: só a listagem (sem outros títulos nem ano).
        $data = $this->sync(['releases' => [$this->release('2015', 'Moby Dick', '107')]]);

        $this->assertSame('107', $uc->refresh()->site_last_chapter);
        $this->assertSame(0, $data['linked']);
        $this->assertSame(0, $data['retitled']);
    }

    public function test_feed_and_search_entries_of_same_work_are_merged(): void
    {
        $uc = $this->libraryItem('Magic-Girl Service', 2024, ['current_units' => 55]);

        $this->sync(['releases' => [
            $this->release('1899', 'Serviço de Garota Mágica', '55.5'),
            $this->release('1899', 'Serviço de Garota Mágica', '55.5', ['Magic-Girl Service', 'Magic Girl Service'], '2024'),
        ]]);

        $uc->refresh();
        $this->assertSame('1899', $uc->site_work_id);
        $this->assertSame('55.5', $uc->site_last_chapter);
    }

    public function test_loose_search_result_with_other_title_and_year_is_not_linked(): void
    {
        // A busca do site devolve "Grupo de Sextudo" (2022) para "Study Group" (2019).
        $uc = $this->libraryItem('Study Group', 2019, ['current_units' => 0], ['is_adult' => false]);

        $data = $this->sync(['releases' => [
            $this->release('2326', 'Grupo de Sextudo', '110', ['섹터디그룹', 'Sex Study Group', 'Sextudy Group'], '2022'),
        ]]);

        $this->assertNull($uc->refresh()->site_work_id);
        $this->assertNull($uc->site_id);
        $this->assertContains('Study Group', $data['unmatched']);
    }

    public function test_items_of_another_site_are_never_relinked(): void
    {
        $toonItem = $this->libraryItem('Childhood Friend', 2022, [
            'site_id' => $this->toon->id, 'site_work_id' => 'obra-amiga', 'site_title' => 'Childhood Friend', 'site_last_chapter' => '58',
        ]);
        $tiaItem = $this->libraryItem('Slave Code', 2026, [
            'site_id' => $this->tia->id, 'site_work_id' => '23569', 'site_title' => 'Código de Escravo',
        ]);

        $data = $this->sync(['releases' => [
            $this->release('2318', 'Amiga de Infância', '60', ['Childhood Friend'], '2022'),
            $this->release('23569', 'Código de Escravo', '27'),
        ]]);

        $toonItem->refresh();
        $this->assertSame($this->toon->id, (int) $toonItem->site_id);
        $this->assertSame('obra-amiga', $toonItem->site_work_id);
        $this->assertSame('58', $toonItem->site_last_chapter);
        $this->assertNotContains('Childhood Friend', $data['unmatched']);
        $this->assertSame(1, $data['checked']);

        // O sync do ToonLivre também não toca no item do TiaManhwa.
        $this->postJson('/api/user/sync-chapters', ['releases' => [
            ['id' => 'obra-escravo', 'alternativeTitle' => 'Slave Code', 'title' => 'Código Escravo', 'releaseYear' => '2026', 'chapter' => '30'],
        ]])->assertOk();

        $tiaItem->refresh();
        $this->assertSame($this->tia->id, (int) $tiaItem->site_id);
        $this->assertSame('23569', $tiaItem->site_work_id);
        $this->assertSame('27', $tiaItem->site_last_chapter);
    }

    public function test_history_advances_progress_and_bookmark_alone_keeps_plan_to_read(): void
    {
        $read = $this->libraryItem('My Kingdom', 2018, [
            'site_id' => $this->tia->id, 'site_work_id' => '2400', 'current_units' => 150, 'status' => 'plan_to_read',
        ]);
        $bookmarked = $this->libraryItem('Got A Room?', 2020, [
            'site_id' => $this->tia->id, 'site_work_id' => '3376', 'current_units' => 0, 'status' => 'plan_to_read',
        ]);

        $data = $this->sync(['reading' => [
            ['id' => '2400', 'title' => 'Meu Reino', 'lastRead' => '160', 'available' => '160.5'],
            ['id' => '3376', 'title' => 'Tem Quarto Vazio?', 'lastRead' => null, 'available' => '64', 'listStatus' => 'plan_to_read'],
        ]]);

        $read->refresh();
        $this->assertSame(160, $read->current_units);
        $this->assertSame('reading', $read->status);
        $this->assertSame('160.5', $read->site_last_chapter);

        $bookmarked->refresh();
        $this->assertSame('plan_to_read', $bookmarked->status);
        $this->assertSame(0, $bookmarked->current_units);
        $this->assertSame('64', $bookmarked->site_last_chapter);
        $this->assertSame(1, $data['progressed']);
    }

    public function test_history_work_in_library_via_another_site_only_gets_progress(): void
    {
        $uc = $this->libraryItem('Drama Queens', 2020, [
            'site_id' => $this->toon->id, 'site_work_id' => 'obra-drama', 'current_units' => 200,
        ]);

        $this->sync(['reading' => [[
            'id' => '2139', 'title' => 'Clube', 'alternativeTitles' => ['Drama Queens'], 'releaseYear' => '2020',
            'type' => 'Pornhwa', 'lastRead' => '215', 'available' => '215.5',
        ]]]);

        $uc->refresh();
        $this->assertSame(215, $uc->current_units);
        $this->assertSame($this->toon->id, (int) $uc->site_id);
        $this->assertSame('obra-drama', $uc->site_work_id);
    }

    public function test_work_missing_from_anilist_is_created_as_adult_from_tiamanhwa(): void
    {
        Http::fake(['graphql.anilist.co' => Http::response(['data' => ['Page' => ['media' => []]]])]);

        $data = $this->sync(['reading' => [[
            'id' => '54206', 'title' => 'Primeira Classe da Ruína', 'alternativeTitles' => ['First Class of Ruin'],
            'releaseYear' => '2026', 'type' => 'Pornhwa', 'status' => 'Em lançamento',
            'cover' => 'https://tiamanhwa.com/wp-content/uploads/capa.webp', 'lastRead' => '5', 'available' => '6',
        ]]]);

        $this->assertSame(['Primeira Classe da Ruína'], $data['importing']);

        $content = Content::where('source', 'tiamanhwa')->where('external_id', '54206')->firstOrFail();
        $this->assertTrue($content->is_adult);
        $this->assertSame('manhwa', $content->origin_type);
        $this->assertSame('ongoing', $content->status);
        $this->assertContains('First Class of Ruin', $content->alternative_names);

        $uc = UserContent::where('content_id', $content->id)->firstOrFail();
        $this->assertSame($this->tia->id, (int) $uc->site_id);
        $this->assertSame(5, $uc->current_units);

        // A AniList foi consultada pelo título em inglês antes do português.
        Http::assertSent(fn ($req) => ($req->data()['variables']['search'] ?? null) === 'First Class of Ruin');

        $last = $this->getJson('/api/user/sync-chapters/last-import?site=tiamanhwa')->assertOk()->json('data');
        $this->assertSame('tiamanhwa', $last['results'][0]['via']);
        $this->assertNull($this->getJson('/api/user/sync-chapters/last-import')->json('data'));
    }

    public function test_candidates_lists_unlinked_manga_and_linked_ids(): void
    {
        $this->libraryItem("Don't Tell Mom!", 2021, [], ['alternative_names' => ["Don't Tell Mom!", 'Eommahante Bimil', '엄마한텐 비밀']]);
        $this->libraryItem('Moby Dick', 2023, ['site_id' => $this->tia->id, 'site_work_id' => '2015']);
        $this->libraryItem('Pick Me Up', 2022, ['site_id' => $this->toon->id, 'site_work_id' => 'obra-pick']);
        $this->libraryItem('Some Show', 2020, [], ['type' => 'tv']);

        $data = $this->getJson('/api/user/sync-chapters/candidates?site=tiamanhwa')->assertOk()->json('data');

        $this->assertSame(['2015'], $data['linked']);
        $this->assertCount(1, $data['unlinked']);
        $this->assertSame("Don't Tell Mom!", $data['unlinked'][0]['title']);
        $this->assertSame(["Don't Tell Mom!", 'Eommahante Bimil', '엄마한텐 비밀'], $data['unlinked'][0]['names']);
        $this->assertSame(2021, $data['unlinked'][0]['year']);
        $this->assertTrue($data['unlinked'][0]['adult']);
    }

    public function test_unknown_site_is_rejected(): void
    {
        $this->postJson('/api/user/sync-chapters', ['site' => 'outro', 'releases' => [$this->release('1', 'X', '1')]])
            ->assertStatus(422);
    }
}
