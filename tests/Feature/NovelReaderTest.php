<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use App\Services\NovelReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NovelReaderTest extends TestCase
{
    use RefreshDatabase;

    private function novel(string $type = 'novel', string $name = "Swordmaster's Youngest Son"): Content
    {
        return Content::create(['name' => $name, 'type' => $type, 'status' => 'completed', 'total_units' => 1147]);
    }

    public function test_reader_requires_authentication(): void
    {
        $novel = $this->novel();
        $this->getJson("/api/contents/{$novel->id}/reader")->assertUnauthorized();
        $this->getJson("/api/contents/{$novel->id}/reader/401")->assertUnauthorized();
    }

    public function test_existing_novel_is_linked_without_database_changes(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $novel = $this->novel();
        $this->getJson("/api/contents/{$novel->id}")
            ->assertOk()->assertJsonPath('data.reader.first_chapter', 401);
        $this->getJson("/api/contents/{$novel->id}/reader")
            ->assertOk()->assertJsonCount(747, 'data.chapters');
        $response = $this->getJson("/api/contents/{$novel->id}/reader/401")
            ->assertOk()->assertJsonPath('data.number', 401)->assertJsonPath('data.language', 'en');
        $this->assertNotEmpty($response->json('data.paragraphs'));
        $this->get($response->json('data.browser_url'))->assertOk()->assertSee('Chapter 401');
        $this->assertDatabaseCount('user_contents', 0);
    }

    public function test_wrong_work_manga_and_unavailable_chapters_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        foreach ([$this->novel('manga'), $this->novel('novel', 'Another novel')] as $content) {
            $this->getJson("/api/contents/{$content->id}/reader")->assertNotFound();
        }
        $novel = $this->novel();
        foreach ([400, 1148] as $chapter) {
            $this->getJson("/api/contents/{$novel->id}/reader/{$chapter}")->assertNotFound();
        }
        $this->getJson("/api/contents/{$novel->id}/reader/not-a-number")->assertNotFound();
    }

    public function test_alternative_name_links_only_the_novel(): void
    {
        $novel = $this->novel('novel', 'Outro nome');
        $novel->alternative_names = ['검술명가 막내아들'];
        $this->assertNotNull(app(NovelReaderService::class)->metadata($novel));
    }

    public function test_browser_link_requires_valid_unexpired_signature(): void
    {
        $service = app(NovelReaderService::class);
        $url = $service->browserUrl('swordmasters-youngest-son', 401);
        $this->get('/read/swordmasters-youngest-son/401')->assertForbidden();
        $this->get(str_replace('/401?', '/402?', $url))->assertForbidden();
        $this->travel(13)->hours();
        $this->get($url)->assertForbidden();
    }

    public function test_browser_navigation_keeps_original_expiration(): void
    {
        $service = app(NovelReaderService::class);
        $expires = now()->addHours(2)->timestamp;
        $url = $service->browserUrl('swordmasters-youngest-son', 401, $expires);
        $this->get($url)->assertOk()
            ->assertSee('expires='.$expires, false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_prepared_corpus_is_complete_and_recovers_deleted_chapters(): void
    {
        $service = app(NovelReaderService::class);
        $index = $service->index('swordmasters-youngest-son');
        $this->assertSame(range(401, 1147), $index['chapters']);
        $hashes = [];
        foreach ($index['chapters'] as $number) {
            $chapter = $service->chapter($index['slug'], $number);
            $this->assertSame($number, $chapter['number']);
            $text = implode("\n", $chapter['paragraphs']);
            $this->assertGreaterThan(2000, mb_strlen($text), "Chapter {$number}");
            $this->assertStringNotContainsString('�', $text);
            $hashes[] = hash('sha256', $text);
        }
        $this->assertCount(747, array_unique($hashes));
    }
}
