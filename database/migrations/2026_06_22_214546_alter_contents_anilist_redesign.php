<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration A — Redesign da tabela `contents` para a fonte AniList.
 *
 * Decisões tomadas (confirmadas com o usuário) e desvios do prompt original:
 *  - `is_adult` MANTIDO (não renomeado para `adult_content`): já existe, é usado
 *    pelo ContentResource/cast/frontend e o normalizeAniListItem escreve nele.
 *  - `cover` MANTIDO (não virou `cover_image`): a Migration A do prompt não pedia
 *    esse rename e ele teria cascata grande (TMDb, Resource, frontend).
 *  - `popularity` NÃO renomeada: TMDb e AniList são ambos "maior = melhor" e
 *    continuam nessa coluna. Criada apenas `mal_popularity_rank` para o rank do
 *    Jikan (menor = melhor). Assim não tocamos no caminho TMDb (regra do prompt)
 *    e o bug 5 fica resolvido — o rank do Jikan nunca mais entra em `popularity`.
 *  - `popularity_score` do prompt NÃO foi criada (consequência da decisão acima).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // Renomeações (nomes confusos identificados na auditoria)
            $table->renameColumn('last_unit_update', 'release_date'); // guardava data de estreia, não atualização
            $table->renameColumn('background', 'banner_image');       // guardava imagem, não texto

            // Identificadores
            $table->unsignedBigInteger('anilist_id')->nullable()->after('id');
            $table->unsignedBigInteger('mal_id')->nullable()->after('anilist_id');

            // Classificação / origem
            $table->string('format', 20)->nullable()->after('type');     // TV/MOVIE/OVA/ONA/SPECIAL/MANGA/NOVEL/ONE_SHOT
            $table->string('origin_source', 50)->nullable()->after('origin_type'); // MANGA/LIGHT_NOVEL/ORIGINAL/...

            // Ranks do MAL (preenchidos via fallback Jikan; ver enrichFromJikan)
            $table->unsignedInteger('mal_rank')->nullable()->after('score');             // rank de qualidade (Jikan `rank`)
            $table->unsignedInteger('mal_popularity_rank')->nullable()->after('mal_rank'); // rank de popularidade (Jikan `popularity`, menor=melhor)

            $table->index('anilist_id', 'idx_contents_anilist_id');
            $table->index('mal_id', 'idx_contents_mal_id');
        });

        // `end_date` adicionada em chamada separada porque depende do rename
        // de `last_unit_update` → `release_date` ter sido aplicado (after release_date).
        Schema::table('contents', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('release_date'); // término (aired.to / endDate)
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex('idx_contents_anilist_id');
            $table->dropIndex('idx_contents_mal_id');

            $table->dropColumn([
                'anilist_id',
                'mal_id',
                'end_date',
                'format',
                'origin_source',
                'mal_rank',
                'mal_popularity_rank',
            ]);

            $table->renameColumn('release_date', 'last_unit_update');
            $table->renameColumn('banner_image', 'background');
        });
    }
};
