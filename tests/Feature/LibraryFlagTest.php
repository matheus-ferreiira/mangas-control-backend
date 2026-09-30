<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** "Está na biblioteca" na listagem não pode vir do cache de 60 s. */
class LibraryFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_reflects_library_change_immediately(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $content = Content::create(['name' => 'Omniscient Reader', 'type' => 'manga', 'is_adult' => false]);

        $flag = fn () => $this->getJson('/api/contents')->assertOk()->json('data.items.0.is_in_library');

        $this->assertFalse($flag()); // popula o cache

        $id = $this->postJson('/api/user-contents', ['content_id' => $content->id, 'status' => 'reading'])
            ->assertCreated()->json('data.id');
        $this->assertTrue($flag());

        $this->deleteJson('/api/user-contents/'.$id)->assertOk();
        $this->assertFalse($flag());
    }
}
