<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->alterTypeEnum(['manga', 'anime', 'novel', 'movie', 'tv']);
    }

    public function down(): void
    {
        // Converte registros com tipos removidos antes de restringir o enum
        DB::table('contents')->whereIn('type', ['movie', 'tv'])->update(['type' => 'anime']);

        $this->alterTypeEnum(['manga', 'anime', 'novel']);
    }

    private function alterTypeEnum(array $values): void
    {
        $driver = DB::connection()->getDriverName();
        $list = implode("','", $values);

        match ($driver) {
            'mysql', 'mariadb' => DB::statement(
                "ALTER TABLE contents MODIFY COLUMN type ENUM('{$list}') NOT NULL"
            ),
            'sqlite' => $this->recreateSqlite($list),
            'pgsql'  => $this->alterPostgres($list),
            default  => null,
        };
    }

    /**
     * SQLite não suporta ALTER COLUMN — recria a tabela preservando os dados.
     */
    private function recreateSqlite(string $enumList): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement("
            CREATE TABLE contents_new (
                id                INTEGER  PRIMARY KEY AUTOINCREMENT NOT NULL,
                name              VARCHAR  NOT NULL,
                alternative_names TEXT     NULL,
                cover             VARCHAR  NULL,
                type              VARCHAR  CHECK(type IN ('{$enumList}')) NOT NULL,
                status            VARCHAR  CHECK(status IN ('ongoing','completed','hiatus','cancelled')) NOT NULL DEFAULT 'ongoing',
                last_unit_update  DATETIME NULL,
                total_units       INTEGER  NULL,
                created_at        DATETIME NULL,
                updated_at        DATETIME NULL
            )
        ");

        DB::statement('
            INSERT INTO contents_new
                (id, name, alternative_names, cover, type, status, last_unit_update, total_units, created_at, updated_at)
            SELECT
                id, name, alternative_names, cover, type, status, last_unit_update, total_units, created_at, updated_at
            FROM contents
        ');

        DB::statement('DROP TABLE contents');
        DB::statement('ALTER TABLE contents_new RENAME TO contents');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function alterPostgres(string $enumList): void
    {
        DB::statement('ALTER TABLE contents DROP CONSTRAINT IF EXISTS contents_type_check');
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_type_check CHECK (type IN ('{$enumList}'))");
    }
};
