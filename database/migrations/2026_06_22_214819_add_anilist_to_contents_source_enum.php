<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration extra (não prevista no prompt, descoberta na verificação do schema):
 * a coluna `contents.source` era enum('jikan','tmdb'). Como a fonte primária de
 * anime/mangá passa a ser a AniList (normalizeAniListItem grava source='anilist'),
 * o enum precisa aceitar 'anilist' — caso contrário o MySQL rejeitaria o insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->alterSourceEnum(['jikan', 'tmdb', 'anilist']);
    }

    public function down(): void
    {
        DB::table('contents')->where('source', 'anilist')->update(['source' => 'jikan']);
        $this->alterSourceEnum(['jikan', 'tmdb']);
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
            default => null,
        };
    }
};
