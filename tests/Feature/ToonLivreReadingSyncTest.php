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
 * POST /api/user/sync-chapters com `reading` (histórico do ToonLivre) —
 * progresso, obra do catálogo fora da biblioteca e import (AniList / ToonLivre).
 */
class ToonLivreReadingSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Site $toon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->toon = Site::where('url', 'like', '%toonlivre%')->firstOrFail();
        Sanctum::actingAs($this->user);
        $this->withoutDefer();
        Http::preventStrayRequests();
    }

    private function content(string $name, ?int $year, array $attrs = []): Content
    {
        return Content::create(array_merge([
            'name' => $name,
            'type' => 'manga',
            'origin_type' => 'manhwa',
            'release_year' => $year,
            'alternative_names' => [$name],
        ], $attrs));
    }

    private function work(string $id, ?string $alt, ?string $title, ?string $year, ?string $lastRead, ?string $available): array
    {
        return [
            'id' => $id, 'alternativeTitle' => $alt, 'title' => $title, 'releaseYear' => $year,
            'type' => 'Manhwa', 'status' => 'Ongoing', 'cover' => 'https://cdn.toonlivre.net/covers/'.$id.'.webp',
            'lastRead' => $lastRead, 'available' => $available, 'readAt' => 1790785549793,
        ];
    }

    private function sync(array $reading): array
    {
        return $this->postJson('/api/user/sync-chapters', ['reading' => $reading])
            ->assertOk()
            ->json('data');
    }

    private function aniListMedia(int $id, string $english, int $year, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'idMal' => null,
            'title' => ['romaji' => $english, 'english' => $english, 'native' => null],
            'synonyms' => [], 'format' => 'MANGA', 'status' => 'RELEASING',
            'description' => 'Sinopse', 'startDate' => ['year' => $year, 'month' => 1, 'day' => 1],
            'endDate' => ['year' => null], 'seasonYear' => null, 'chapters' => null, 'countryOfOrigin' => 'KR',
            'isAdult' => false, 'coverImage' => ['extraLarge' => 'https://anilist/cover.jpg'], 'bannerImage' => null,
            'genres' => ['Action'], 'tags' => [], 'averageScore' => 80, 'popularity' => 1000,
            'studios' => ['nodes' => []], 'trailer' => null, 'source' => 'WEB_NOVEL',
        ], $extra);
    }

    public function test_linked_work_advances_progress_but_never_regresses(): void
    {
        $c = $this->content('Omniscient Reader', 2020);
        $ahead = $this->content('Nano Machine', 2020);
        $uc = UserContent::create(['user_id' => $this->user->id, 'content_id' => $c->id, 'current_units' => 10, 'status' => 'plan_to_read', 'site_work_id' => 'obra-1', 'site_id' => $this->toon->id]);
        $uc2 = UserContent::create(['user_id' => $this->user->id, 'content_id' => $ahead->id, 'current_units' => 300, 'status' => 'reading', 'site_work_id' => 'obra-2', 'site_id' => $this->toon->id]);

        $data = $this->sync([
            $this->work('obra-1', 'Omniscient Reader', 'Leitor Onisciente', '2020', '99', '108'),
            $this->work('obra-2', 'Nano Machine', null, '2020', '250', '260'),
        ]);

        $this->assertSame(1, $data['progressed']);
        $uc->refresh();
        $this->assertSame(99, $uc->current_units);
        $this->assertSame('reading', $uc->status);
        $this->assertSame('108', $uc->site_last_chapter);
        $this->assertSame(300, $uc2->refresh()->current_units);
        $this->assertCount(1, $data['new_chapters']);
    }

    public function test_catalog_work_is_added_to_library_with_toonlivre_titles(): void
    {
        $c = $this->content("The Regressed Mercenary's Machinations", 2024, ['alternative_names' => ['Hoegwihan Yongbyeong-ui Gyeryak']]);

        $data = $this->sync([$this->work('obra-f71d1b34', "The Regressed Mercenary's Machinations", 'Os Planos do Mercenário Regressado', '2024', '99', '108')]);

        $this->assertCount(1, $data['added']);
        $this->assertSame([], $data['importing']);

        $uc = UserContent::where('user_id', $this->user->id)->where('content_id', $c->id)->firstOrFail();
        $this->assertSame('reading', $uc->status);
        $this->assertSame(99, $uc->current_units);
        $this->assertSame('obra-f71d1b34', $uc->site_work_id);
        $this->assertSame($this->toon->id, (int) $uc->site_id);
        $this->assertSame('108', $uc->site_last_chapter);
        $this->assertContains('Os Planos do Mercenário Regressado', $c->refresh()->alternative_names);
    }

    public function test_catalog_match_respects_year_and_origin(): void
    {
        $this->content('Solo Leveling', 2018);
        $this->content('The Bride', 2022, ['origin_type' => 'manga']);
        Http::fake(['graphql.anilist.co' => Http::response(['data' => ['Page' => ['media' => []]]])]);

        $data = $this->sync([
            $this->work('obra-a', 'Solo Leveling', null, '2024', '5', '10'),
            $this->work('obra-b', 'The Bride', null, '2022', '5', '10'),
        ]);

        $this->assertSame([], $data['added']);
        $this->assertCount(2, $data['importing']);
    }

    public function test_missing_work_is_imported_from_anilist(): void
    {
        Http::fake(['graphql.anilist.co' => Http::response(['data' => ['Page' => ['media' => [
            $this->aniListMedia(999, 'The Extra of the Novel Solo', 2020),
            $this->aniListMedia(1000, 'The Novel’s Extra', 2020),
        ]]]])]);

        $data = $this->sync([$this->work('obra-140aa8ea', "The Novel's Extra", 'O Figurante da Novel', '2020', '174', '174')]);

        $this->assertSame(["The Novel's Extra"], $data['importing']);

        $content = Content::where('anilist_id', 1000)->firstOrFail();
        $this->assertSame('manhwa', $content->origin_type);
        $this->assertSame('Sinopse', $content->synopsis);
        $this->assertContains('O Figurante da Novel', $content->alternative_names);

        $uc = UserContent::where('content_id', $content->id)->firstOrFail();
        $this->assertSame(174, $uc->current_units);
        $this->assertSame('obra-140aa8ea', $uc->site_work_id);

        $last = $this->getJson('/api/user/sync-chapters/last-import')->assertOk()->json('data');
        $this->assertSame('done', $last['status']);
        $this->assertSame('anilist', $last['results'][0]['via']);
    }

    public function test_different_translation_is_accepted_when_only_same_year_result(): void
    {
        Http::fake(['graphql.anilist.co' => Http::response(['data' => ['Page' => ['media' => [
            $this->aniListMedia(182066, 'The Regressed Mercenary Has a Plan', 2024),
            $this->aniListMedia(5, 'Mercenary Enrollment', 2019),
        ]]]])]);

        $this->sync([$this->work('obra-f71d1b34', "The Regressed Mercenary's Machinations", 'Os Planos do Mercenário Regressado', '2024', '99', '108')]);

        $content = Content::where('anilist_id', 182066)->firstOrFail();
        $this->assertContains("The Regressed Mercenary's Machinations", $content->alternative_names);
        $this->assertSame(0, Content::where('source', 'toonlivre')->count());
    }

    public function test_work_missing_from_anilist_is_created_from_toonlivre(): void
    {
        Http::fake(['graphql.anilist.co' => Http::response(['data' => ['Page' => ['media' => []]]])]);

        $this->sync([$this->work('obra-x1', null, 'Obra Só Brasileira', '2025', '12', '15')]);

        $content = Content::where('source', 'toonlivre')->where('external_id', 'obra-x1')->firstOrFail();
        $this->assertSame('Obra Só Brasileira', $content->name);
        $this->assertSame('ongoing', $content->status);
        $this->assertSame(2025, $content->release_year);
        $this->assertStringContainsString('obra-x1', $content->cover);
        $this->assertSame(12, UserContent::where('content_id', $content->id)->value('current_units'));

        // Próximo sync: já vinculado por ID, não cria outra obra.
        $data = $this->sync([$this->work('obra-x1', null, 'Obra Só Brasileira', '2025', '13', '15')]);
        $this->assertSame(1, Content::where('external_id', 'obra-x1')->count());
        $this->assertSame([], $data['importing']);
        $this->assertSame(1, $data['progressed']);
    }

    public function test_anilist_failure_creates_nothing(): void
    {
        Http::fake(['graphql.anilist.co' => Http::response(['errors' => [['message' => 'boom']]], 500)]);

        $this->sync([$this->work('obra-y', 'Some Manhwa', null, '2021', '3', '4')]);

        $this->assertSame(0, Content::count());
        $last = $this->getJson('/api/user/sync-chapters/last-import')->json('data');
        $this->assertSame('failed', $last['results'][0]['via']);
    }

    public function test_empty_payload_is_rejected(): void
    {
        $this->postJson('/api/user/sync-chapters', ['reading' => [['foo' => 'bar']]])->assertStatus(422);
    }
}
