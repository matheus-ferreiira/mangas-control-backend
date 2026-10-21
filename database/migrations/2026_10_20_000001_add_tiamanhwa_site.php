<?php

use App\Models\Site;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sync de capítulos do TiaManhwa (bookmarklet, como o ToonLivre): cadastra o
 * site e permite source='tiamanhwa' para obras lidas lá que não existem na
 * AniList (ToonLivreLibraryService::createFromSite).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->alterSourceEnum(['jikan', 'tmdb', 'anilist', 'toonlivre', 'tiamanhwa']);

        // Popula também em produção (o deploy roda migrate, não seed).
        Artisan::call('db:seed', ['--class' => 'SitesSeeder', '--force' => true]);
    }

    public function down(): void
    {
        DB::table('contents')->where('source', 'tiamanhwa')->update(['source' => null]);
        $this->alterSourceEnum(['jikan', 'tmdb', 'anilist', 'toonlivre']);

        $site = Site::where('url', 'like', '%tiamanhwa%')->first();
        if ($site) {
            DB::table('user_contents')->where('site_id', $site->id)->update(['site_id' => null, 'site_work_id' => null]);
            $site->delete();
        }
    }

    private function alterSourceEnum(array $values): void
    {
        $driver = DB::connection()->getDriverName();
        $list = implode("','", $values);

        match ($driver) {
            'mysql', 'mariadb' => DB::statement(
                "ALTER TABLE contents MODIFY COLUMN source ENUM('{$list}') NULL"
            ),
            'pgsql' => (function () use ($list) {
                DB::statement('ALTER TABLE contents DROP CONSTRAINT IF EXISTS contents_source_check');
                DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_source_check CHECK (source IN ('{$list}'))");
            })(),
            // SQLite (testes): já virou string livre na migration do ToonLivre.
            'sqlite' => Schema::table('contents', function (Blueprint $table) {
                $table->string('source', 20)->nullable()->change();
            }),
            default => null,
        };
    }
};
