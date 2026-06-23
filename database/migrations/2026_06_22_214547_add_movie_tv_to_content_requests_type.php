<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration B — Adiciona 'movie' e 'tv' ao enum `content_requests.type`.
 * Antes: ('manga','anime','novel'). Depois: ('manga','anime','novel','movie','tv').
 * Mesma abordagem driver-aware usada em add_movie_tv_to_contents_type_enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->alterTypeEnum(['manga', 'anime', 'novel', 'movie', 'tv']);
    }

    public function down(): void
    {
        // Converte registros com tipos removidos antes de restringir o enum
        DB::table('content_requests')->whereIn('type', ['movie', 'tv'])->update(['type' => 'anime']);

        $this->alterTypeEnum(['manga', 'anime', 'novel']);
    }

    private function alterTypeEnum(array $values): void
    {
        $driver = DB::connection()->getDriverName();
        $list = implode("','", $values);

        match ($driver) {
            'mysql', 'mariadb' => DB::statement(
                "ALTER TABLE content_requests MODIFY COLUMN type ENUM('{$list}') NOT NULL"
            ),
            'pgsql' => $this->alterPostgres($list),
            // sqlite: enum é mapeado como CHECK; recriação omitida (não usado nos ambientes ativos — ambos MySQL)
            default => null,
        };
    }

    private function alterPostgres(string $enumList): void
    {
        DB::statement('ALTER TABLE content_requests DROP CONSTRAINT IF EXISTS content_requests_type_check');
        DB::statement("ALTER TABLE content_requests ADD CONSTRAINT content_requests_type_check CHECK (type IN ('{$enumList}'))");
    }
};
