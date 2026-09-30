<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Obras lidas no ToonLivre que não existem na AniList são criadas com os dados
 * do próprio site (ToonLivreLibraryService::createFromToonLivre), com
 * source='toonlivre' e external_id = ID da obra no site.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->alterSourceEnum(['jikan', 'tmdb', 'anilist', 'toonlivre']);
    }

    public function down(): void
    {
        DB::table('contents')->where('source', 'toonlivre')->update(['source' => null]);
        $this->alterSourceEnum(['jikan', 'tmdb', 'anilist']);
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
            // SQLite (testes): o enum vira CHECK constraint; recria como string livre.
            'sqlite' => Schema::table('contents', function (Blueprint $table) {
                $table->string('source', 20)->nullable()->change();
            }),
            default => null,
        };
    }
};
