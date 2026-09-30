<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use App\Models\UserContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Filtro +18 único, vindo do perfil (Content::scopeForAudience):
 * desligado → nada adulto em lugar nenhum; ligado → só adulto.
 */
class AdultContentFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Content $sfw;

    private Content $adult;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        // A busca usa JSON_SEARCH (MySQL); equivalente mínimo para o SQLite dos testes.
        DB::connection()->getPdo()->sqliteCreateFunction('JSON_SEARCH', function ($doc, $mode, $pattern) {
            $needle = trim((string) $pattern, '%');
            foreach ((array) json_decode((string) $doc, true) as $v) {
                if (is_string($v) && str_contains(mb_strtolower($v), mb_strtolower($needle))) {
                    return '$';
                }
            }

            return null;
        }, 3);

        $this->sfw = Content::create(['name' => 'Tower Hunter', 'type' => 'manga', 'is_adult' => false, 'cover' => 'x', 'popularity' => 10]);
        $this->adult = Content::create(['name' => 'Tower Nights', 'type' => 'manga', 'is_adult' => true, 'cover' => 'y', 'popularity' => 20]);

        foreach ([$this->sfw, $this->adult] as $c) {
            UserContent::create(['user_id' => $this->user->id, 'content_id' => $c->id, 'status' => 'reading']);
        }
    }

    private function adultMode(bool $on): void
    {
        $this->user->update(['show_adult_content' => $on]);
    }

    private function listNames(array $query = []): array
    {
        return collect($this->getJson('/api/contents?'.http_build_query($query))->assertOk()->json('data.items'))
            ->pluck('name')->all();
    }

    private function libraryNames(): array
    {
        return collect($this->getJson('/api/user-contents')->assertOk()->json('data'))
            ->pluck('content.name')->all();
    }

    public function test_default_is_off(): void
    {
        $this->assertFalse((bool) User::factory()->create()->fresh()->show_adult_content);
    }

    public function test_off_hides_adult_everywhere(): void
    {
        $this->assertSame(['Tower Hunter'], $this->listNames());
        $this->assertSame(['Tower Hunter'], $this->listNames(['search' => 'tower']));
        $this->assertSame([], $this->listNames(['search' => 'nights']));
        $this->assertSame(['Tower Hunter'], $this->listNames(['is_adult' => 1]));

        $this->getJson('/api/contents/'.$this->adult->id)->assertNotFound();
        $this->getJson('/api/contents/'.$this->sfw->id)->assertOk();

        $this->assertSame(['Tower Hunter'], $this->libraryNames());

        $home = $this->getJson('/api/discover/home')->assertOk()->json('data');
        $this->assertSame(['Tower Hunter'], collect($home['trending'])->pluck('name')->all());
    }

    public function test_on_shows_only_adult(): void
    {
        $this->adultMode(true);

        $this->assertSame(['Tower Nights'], $this->listNames());
        $this->assertSame(['Tower Nights'], $this->listNames(['search' => 'tower']));
        $this->assertSame(['Tower Nights'], $this->listNames(['is_adult' => 0]));

        $this->getJson('/api/contents/'.$this->sfw->id)->assertNotFound();
        $this->getJson('/api/contents/'.$this->adult->id)->assertOk();

        $this->assertSame(['Tower Nights'], $this->libraryNames());

        $home = $this->getJson('/api/discover/home')->assertOk()->json('data');
        $this->assertSame(['Tower Nights'], collect($home['trending'])->pluck('name')->all());
    }
}
