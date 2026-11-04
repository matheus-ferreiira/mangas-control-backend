<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use App\Models\UserContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * user_contents.chapter_url — link de leitura com `{n}` no lugar do capítulo
 * (o app troca pelo próximo capítulo).
 */
class ChapterUrlTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Content $novel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->novel = Content::create([
            'name' => "Swordmaster's Youngest Son",
            'type' => 'novel',
            'format' => 'novel',
            'status' => 'completed',
            'total_units' => 1147,
        ]);
        Sanctum::actingAs($this->user);
    }

    public function test_store_and_update_chapter_url(): void
    {
        $url = 'https://daotekno.com/series/swordmasters-youngest-son/{n}/';

        $id = $this->postJson('/api/user-contents', [
            'content_id' => $this->novel->id,
            'status' => 'reading',
            'current_units' => 448,
            'chapter_url' => $url,
        ])->assertCreated()->assertJsonPath('data.chapter_url', $url)->json('data.id');

        $this->patchJson("/api/user-contents/{$id}", ['chapter_url' => 'https://example.com/obra/{n}'])
            ->assertOk()
            ->assertJsonPath('data.chapter_url', 'https://example.com/obra/{n}');

        $this->patchJson("/api/user-contents/{$id}", ['chapter_url' => null])
            ->assertOk()
            ->assertJsonPath('data.chapter_url', null);

        $this->assertNull(UserContent::find($id)->chapter_url);
    }

    public function test_chapter_url_must_be_http(): void
    {
        $uc = UserContent::create([
            'user_id' => $this->user->id,
            'content_id' => $this->novel->id,
            'status' => 'reading',
            'current_units' => 448,
        ]);

        foreach (['javascript:alert(1)', 'daotekno.com/{n}', 'https://a b.com'] as $bad) {
            $this->patchJson("/api/user-contents/{$uc->id}", ['chapter_url' => $bad])->assertStatus(422);
        }

        $this->assertNull($uc->fresh()->chapter_url);
    }
}
