# Manhwas — de onde vêm os dados e como são entregues

Este documento descreve o caminho completo de um manhwa: a requisição feita à
AniList, os campos que ela devolve, como eles viram uma linha em `contents`, o
formato exato em que a API entrega esses dados ao front e, à parte, como o
último capítulo chega do ToonLivre pelo bookmarklet.

> Levantamento feito em **2026-09-30** contra a AniList, o ToonLivre e o banco
> de produção (Railway). Os números das seções 7 e 10 são desse dia.

## 1. Visão geral

Manhwa **não é um `type`**. No banco ele é `type = manga` + `origin_type = manhwa`,
derivado de `countryOfOrigin = KR` na AniList (manhua = `CN`, mangá = `JP`).

```
AniList (GraphQL)
   │  php artisan content:import --type=manga --origin=manhwa ...
   ▼
tabela contents  ◀── php artisan content:sync-updates   (só itens na biblioteca; manual)
   │
   │  GET /api/contents?type=manga&origin_type=manhwa ...
   ▼
front (Discover / Catálogo / Detalhe)

Site de leitura ── bookmarklet ──▶ POST /api/user/sync-chapters ──▶ user_contents.site_last_chapter
```

| Peça | Arquivo |
|---|---|
| Comando de import | [app/Console/Commands/ImportContentsCommand.php](../app/Console/Commands/ImportContentsCommand.php) |
| Cliente GraphQL (query, retry, rate limit) | [app/Services/AniListClient.php](../app/Services/AniListClient.php) |
| Normalização + upsert | [app/Services/AniListContentService.php](../app/Services/AniListContentService.php) |
| Sync incremental | [app/Console/Commands/SyncContentUpdatesCommand.php](../app/Console/Commands/SyncContentUpdatesCommand.php) |
| Listagem / filtros da API | [app/Services/ContentService.php](../app/Services/ContentService.php), [app/Http/Controllers/ContentController.php](../app/Http/Controllers/ContentController.php) |
| Formato de saída | [app/Http/Resources/ContentResource.php](../app/Http/Resources/ContentResource.php) |

## 2. Atualizando os manhwas (`content:import`)

```bash
php artisan content:import --type=manga --origin=manhwa --format=MANGA --pages=100 --force
```

| Flag | O que muda na query da AniList | Observação |
|---|---|---|
| `--type=manga` | `type: MANGA` | Sem ela o comando importa também anime, filmes e séries (TMDb). |
| `--origin=manhwa` | `countryOfOrigin: "KR"` | `manga` = JP, `manhua` = CN. Sem ela vêm todas as origens. |
| `--format=MANGA` | `format: MANGA` | Exclui `ONE_SHOT`. Sem a flag, one-shots também entram (111 hoje). `--format=NOVEL` grava `type = novel`. |
| *(sem `--adult`)* | `isAdult: false` | Padrão: só conteúdo não adulto. |
| `--adult` | `isAdult: true` | Traz **somente** +18 (não "inclui"). Para cobrir tudo, rode duas vezes: com e sem `--adult`. |
| `--pages=N` | páginas 1..N, 50 itens cada | Ordem sempre `POPULARITY_DESC`. Padrão `1`. |
| `--force` | — | Atualiza os registros existentes (ver seção 5). Sem ela, existentes são pulados. |
| `--details` | — | Só TMDb; ignorada para mangá. |

Atualização completa dos manhwas (as duas metades):

```bash
php artisan content:import --type=manga --origin=manhwa --pages=100 --force
php artisan content:import --type=manga --origin=manhwa --pages=100 --force --adult
```

> ⚠️ Com o `.env` apontando para produção, esses comandos gravam direto no
> banco de produção. E **não rode com `--force` antes de corrigir a colisão
> por nome** (seção 5): hoje ela sobrescreveria 6 obras com dados de outras.

### Limites da AniList

- **Teto de 5.000 resultados por consulta.** `pageInfo` devolve
  `total: 5000, lastPage: 100`, e a página 101 responde **HTTP 400**
  `"Page depth exceeds maximum allowed for API requests (5000 entries)"`. O
  import trata isso como erro e para (`[AVISO] AniList página 101`). Como a
  ordem é `POPULARITY_DESC`, em cada metade (sem e com `--adult`) os manhwas
  além do 5.000º mais popular nunca são alcançados. Para pegar os lançamentos mais novos é preciso outra
  ordenação (ex.: `ID_DESC` ou `START_DATE_DESC`), que o import não oferece hoje.
- **Rate limit.** Em 2026-09-30 a API respondia `X-RateLimit-Limit: 30`
  (modo degradado; o normal é 90/min). O import espera só 700 ms entre
  páginas (calibrado para 90/min), então com 30/min ele fica no limite e pode
  tomar 429. Os retries do `Http::retry(3, 500)` também contam no limite.
- **429 não é tratado de verdade.** O cliente usa `Http::retry(3, 500)`, que
  lança exceção depois da 3ª resposta 429. O bloco que lê `Retry-After` e
  espera nunca é alcançado. O `importMedia` captura a exceção, loga
  `[AVISO] AniList página N: ...` e **interrompe o import** naquela página.

## 3. A requisição à AniList

`POST https://graphql.anilist.co` com JSON `{ "query": ..., "variables": { "page": 1, "type": "MANGA" } }`.

Query exata montada por `AniListClient::fetchPage()` com todos os filtros de
manhwa (`--type=manga --origin=manhwa --format=MANGA`, sem `--adult`):

```graphql
query ($page: Int, $type: MediaType) {
  Page(page: $page, perPage: 50) {
    media(type: $type, sort: POPULARITY_DESC, isAdult: false, countryOfOrigin: "KR", format: MANGA) {
      id
      idMal
      title { romaji english native }
      synonyms
      type
      format
      status
      description
      startDate { year month day }
      endDate { year month day }
      season
      seasonYear
      episodes
      chapters
      volumes
      duration
      countryOfOrigin
      isAdult
      coverImage { extraLarge }
      bannerImage
      genres
      tags { name category isGeneralSpoiler }
      averageScore
      popularity
      favourites
      studios { nodes { name isAnimationStudio } }
      trailer { id site }
      source
    }
  }
}
```

A resposta vem em `data.Page.media[]` (a query não pede `pageInfo`; o import
para quando uma página volta vazia).

## 4. O que a AniList devolve por item

Exemplo real (Solo Leveling, 1º da lista), com o comportamento observado nos
manhwas:

| Campo | Exemplo | Como vem para manhwa |
|---|---|---|
| `id` | `105398` | Sempre presente. Vira `anilist_id` e `external_id`. |
| `idMal` | `121496` | Falta em ~29% dos manhwas (1.986 de 6.855). |
| `title.romaji` | `"Na Honjaman Level Up"` | Sempre presente. |
| `title.english` | `"Solo Leveling"` | Pode ser `null`; aí o nome usa o romaji. |
| `title.native` | `"나 혼자만 레벨업"` | Em hangul. |
| `synonyms` | `["I Level Up Alone", ...]` | Lista livre, vários idiomas. |
| `type` | `"MANGA"` | Sempre `MANGA`. |
| `format` | `"MANGA"` | `MANGA` ou `ONE_SHOT`. |
| `status` | `"FINISHED"` | `FINISHED`, `RELEASING`, `NOT_YET_RELEASED`, `CANCELLED`, `HIATUS`. |
| `description` | `"In a world where..."` | HTML (`<br>`, `<i>`). Quase sempre termina com `(Source: ...)`. |
| `startDate` / `endDate` | `{year: 2018, month: 3, day: 4}` | Em andamento: `endDate` com os três campos `null`. |
| `chapters` | `201` | **`null` em obras em andamento.** A AniList só preenche quando termina. |
| `volumes` | `15` | Quase sempre `null`. |
| `season`, `seasonYear`, `episodes`, `duration` | `null` | Sempre `null` para mangá. |
| `countryOfOrigin` | `"KR"` | Define `origin_type`. |
| `isAdult` | `false` | |
| `coverImage.extraLarge` | URL `s4.anilist.co` | Sempre presente. |
| `bannerImage` | URL ou `null` | Falta em ~2/3 dos manhwas. |
| `genres` | `["Action", "Adventure", "Fantasy"]` | Lista fixa de gêneros da AniList. |
| `tags[]` | `{name: "Dungeon", category: "Setting-Scene", isGeneralSpoiler: false}` | Categorias `Theme-*`, `Setting-*`, `Cast-*`, `Technical` (ex.: `Full Color`, `Long Strip`), `Demographic`. |
| `averageScore` | `84` | Escala 0–100. `null` em obras com poucas notas. |
| `popularity` | `286014` | Nº de usuários com a obra na lista (muda todo dia). |
| `favourites` | `33440` | Pedido, mas **descartado** na normalização. |
| `studios.nodes` | `[]` | Sempre vazio para mangá. |
| `trailer` | `{id: "JRXcbcEnqEs", site: "youtube"}` | Alguns manhwas têm PV no YouTube. |
| `source` | `"OTHER"` | `ORIGINAL`, `OTHER`, `WEB_NOVEL`, `LIGHT_NOVEL`, `VIDEO_GAME`... |

## 5. Como vira uma linha em `contents`

`AniListContentService::normalizeAniListItem($item, 'manga')`:

| Coluna | Origem | Transformação |
|---|---|---|
| `anilist_id` | `id` | — |
| `mal_id` | `idMal` | — |
| `external_id` | `id` | Cast para string. |
| `source` | — | Fixo `"anilist"`. |
| `name` | `title.english` ?? `title.romaji` | — |
| `alternative_names` | romaji + english + native + `synonyms` | Normalizado e deduplicado (`NameHelper::normalizeList`). Inclui o próprio `name`. |
| `type` | — | `"manga"` (ou `"novel"` com `--format=NOVEL`). |
| `format` | `format` | Minúsculo: `manga`, `one_shot`. |
| `origin_type` | `countryOfOrigin` | `KR` → `manhwa`, `CN` → `manhua`, `JP`/outros → `manga`. |
| `origin_source` | `source` | Valor cru da AniList (`OTHER`, `ORIGINAL`...). |
| `status` | `status` | `FINISHED` → `completed`, `RELEASING` → `ongoing`, `NOT_YET_RELEASED` → `upcoming`, `CANCELLED` → `cancelled`, `HIATUS` → `hiatus`. |
| `is_adult` | `isAdult` | Booleano. |
| `age_rating` | — | Sempre `null` (Jikan só enriquece anime). |
| `cover` | `coverImage.extraLarge` | URL absoluta da AniList. |
| `banner_image` | `bannerImage` | URL ou `null`. |
| `trailer_url` | `trailer` | `https://www.youtube.com/watch?v={id}` quando `site = youtube`; senão `null`. |
| `total_units` | `episodes` ?? `chapters` | Nº de capítulos. `null` para quase todo manhwa em andamento. |
| `total_seasons`, `duration`, `networks`, `season_episodes` | — | `null` para mangá. |
| `release_date` | `startDate` | `Y-m-d`; mês/dia ausentes viram `01`. |
| `end_date` | `endDate` | Idem; `null` se não tiver ano. |
| `synopsis` | `description` | `strip_tags()` + `trim()`. O `(Source: ...)` permanece e entidades HTML não são decodificadas. |
| `genres` | `genres` | Lista. |
| `themes` | `tags` | Nomes das tags que não são `Genre` e não são spoiler (inclui `Technical` e `Demographic`). |
| `demographics` | `tags` | Nomes das tags `Demographic` (quase sempre vazio para manhwa). |
| `studios` | `studios.nodes` | Só `isAnimationStudio`; vazio para mangá. |
| `release_year` | `seasonYear` ?? `startDate.year` | — |
| `original_language` | `countryOfOrigin` | `KR` → `ko`. |
| `country` | `countryOfOrigin` | `"KR"`. |
| `rating` | `averageScore` | `averageScore / 10`, 2 casas (84 → `8.4`). `null` se ausente. |
| `score` | `averageScore` | Mesmo valor de `rating`. |
| `popularity` | `popularity` | — |
| `votes_count`, `mal_rank`, `mal_popularity_rank` | — | Sempre `null` para mangá. |

### Regras do upsert

1. Procura o registro existente, nesta ordem: `anilist_id` → `mal_id` + `type`
   → nome normalizado + `type`.
2. **Não existe** → `INSERT` com todos os campos acima.
3. **Existe, sem `--force`** → pulado (`[SKIP]`).
4. **Existe, com `--force`** → `UPDATE` de todos os campos **exceto**
   `name`, `alternative_names`, `type`, `source`, `external_id`, `anilist_id`,
   `mal_id`. Troca de título na AniList nunca chega ao banco. Valores `null`
   da API sobrescrevem o que estiver gravado.

> ⚠️ **Colisão por nome.** O 3º critério (nome + `type`) ignora
> `anilist_id` e país. Um manhwa com o mesmo título de um mangá já gravado é
> tratado como o mesmo registro. Em 2026-09-30, `--origin=manhwa --force`
> sobrescreveria **Monster** (Urasawa, 1994, `#174`) e **Happiness**
> (Oshimi, 2015, `#297`) com os dados dos manhwas coreanos homônimos (2021):
> `origin_type`, `country`, capa, sinopse e capítulos, mantendo o `anilist_id`
> japonês. Com `--adult`, o mesmo aconteceria com 4 manhuas: Glory Days
> (`#11173`), Caught in the Act (`#7827`), Mimi (`#9603`) e My Way
> (`#10562`). Os manhwas em si nunca seriam inseridos. Corrigir `findExisting`
> antes de rodar com `--force`: não aceitar o match por nome quando os dois
> lados têm `anilist_id` diferentes.

### `content:sync-updates` (incremental)

```bash
php artisan content:sync-updates --type=manga --dry-run
```

Só olha conteúdos que estão na biblioteca de algum usuário, busca cada um por
`anilist_id` (ou `mal_id`) e atualiza apenas `total_units`, `status`,
`total_seasons`, `end_date`, `banner_image` e `cover`, ignorando `null`. Cada
mudança vai para `content_updates_log`. **Não está agendado** (linha comentada
em [routes/console.php](../routes/console.php)) e nunca rodou em produção
(`content_updates_log` vazio).

## 6. Como a API entrega para o front

### `GET /api/contents` (listagem paginada)

Requer `Authorization: Bearer {token}` (Sanctum). Todos os filtros:

| Parâmetro | Exemplo | Efeito |
|---|---|---|
| `type` | `manga` | `whereIn`; aceita array (`type[]=manga&type[]=novel`). |
| `origin_type` | `manhwa` | `whereIn`; aceita array. **É o filtro que separa manhwa.** |
| `format` | `manga` | `whereIn`; valores minúsculos (`manga`, `one_shot`, `novel`). |
| `status` | `status[]=ongoing` | `whereIn`: `ongoing`, `completed`, `cancelled`, `hiatus`, `upcoming`. |
| `genres` | `genres[]=Action&genres[]=Romance` | **OU** entre os gêneros (`JSON_CONTAINS`). |
| `search` | `solo` | Busca em `name`, `alternative_names` e `synopsis`; ordena por relevância e ignora `sort`. |
| `year` | `2020` | Ano exato (tem prioridade sobre `year_min`/`year_max`). |
| `year_min` / `year_max` | `2018` / `2024` | Faixa em `release_year`. |
| `rating_min` / `rating_max` | `7` | Faixa em `rating` (0–10). |
| `votes_min` | — | Não use com manhwa: `votes_count` é sempre `null`, então qualquer valor > 0 zera o resultado. |
| `language` | `ko` | `original_language`. |
| `country` | `KR` | `country`. |
| `is_adult` | `true` / `false` | Só é respeitado se o usuário tiver `show_adult_content = true`. Caso contrário, +18 é sempre escondido. |
| `recent` | `1` | Criados nos últimos 5 dias. |
| `sort` | `popularity` | `popularity` (padrão), `rating`, `score`, `votes_count`, `name`, `release_year`, `created_at`, `updated_at`. Métricas nulas vão para o fim. |
| `order` | `desc` | `desc` (padrão) ou `asc`. |
| `per_page` | `20` | Padrão 20, máximo 100. |
| `page` | `1` | — |

A resposta fica em cache por 60 s (por usuário + preferência de adulto + filtros + página).

Exemplo com todos os filtros de manhwa:

```
GET /api/contents?type=manga&origin_type=manhwa&format=manga&status[]=ongoing&genres[]=Action&rating_min=7&year_min=2018&sort=popularity&order=desc&per_page=2
```

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [
      {
        "id": 163,
        "external_id": "119257",
        "anilist_id": 119257,
        "mal_id": 132214,
        "source": "anilist",
        "name": "Omniscient Reader",
        "alternative_names": ["Jeonjijeok Dokja Sijeom", "Omniscient Reader", "전지적 독자 시점", "..."],
        "cover": "https://s4.anilist.co/file/anilistcdn/media/manga/cover/large/bx119257-Pi21aq3ey9GG.jpg",
        "background": "https://s4.anilist.co/file/anilistcdn/media/manga/banner/119257-RtxJMRCunHXc.jpg",
        "banner_image": "https://s4.anilist.co/file/anilistcdn/media/manga/banner/119257-RtxJMRCunHXc.jpg",
        "trailer_url": "https://www.youtube.com/watch?v=8OHzcTtoLo4",
        "trailer_embed_url": "https://www.youtube.com/embed/8OHzcTtoLo4",
        "type": "manga",
        "format": "manga",
        "origin_type": "manhwa",
        "origin_source": "OTHER",
        "status": "ongoing",
        "is_adult": false,
        "age_rating": null,
        "is_in_library": true,
        "total_units": null,
        "total_seasons": null,
        "season_episodes": null,
        "duration": null,
        "duration_formatted": null,
        "last_unit_update": "2020-05-26T00:00:00.000000Z",
        "release_date": "2020-05-26T00:00:00.000000Z",
        "end_date": null,
        "synopsis": "Back then, Dok-Ja had no idea. ...",
        "tagline": null,
        "genres": ["Action", "Adventure", "Fantasy"],
        "studios": [],
        "demographics": [],
        "themes": ["Survival", "Post-Apocalyptic", "Death Game", "Full Color", "..."],
        "networks": [],
        "release_year": 2020,
        "original_language": "ko",
        "country": "KR",
        "rating": 8.6,
        "votes_count": null,
        "popularity": 126433,
        "score": 8.6,
        "mal_rank": null,
        "mal_popularity_rank": null,
        "created_at": "2026-06-23T03:27:01.000000Z",
        "updated_at": "2026-06-23T03:27:01.000000Z"
      }
    ],
    "meta": { "current_page": 1, "last_page": 86, "per_page": 2, "total": 171, "from": 1, "to": 2 }
  }
}
```

O interceptor do front (`src/services/api.ts`) remove o envelope `success` /
`message`, então o front recebe direto `{ items, meta }`.

Pontos de atenção nos campos entregues:

- **`last_unit_update` não é a data do último capítulo.** É um alias de
  `release_date` (data de início da publicação), mantido por compatibilidade.
- `background` e `banner_image` são o mesmo valor (alias legado).
- `is_in_library` é calculado por usuário (subquery em `user_contents`).
- `trailer_embed_url` e `duration_formatted` são derivados no resource, não
  existem no banco.
- Datas saem em ISO 8601 UTC (`2020-05-26T00:00:00.000000Z`), inclusive `end_date`.
- `total_units = null` é o normal para manhwa em andamento. O último capítulo
  real vem do chapter tracker (`site_last_chapter`, abaixo).
- `studios`, `demographics`, `networks`: arrays (vazios para manhwa).
  `age_rating`, `votes_count`, `mal_rank`, `mal_popularity_rank`,
  `tagline`: sempre `null` para manhwa.

### `GET /api/contents/{id}`

Mesmo objeto de item, sem paginação: `{ "success": true, "message": "", "data": { ...item } }`.
404 → `{ "success": false, "message": "Conteúdo não encontrado" }`.

### Biblioteca: `GET /api/user-contents`

Filtros: `type`, `status`, `content_id`, `user_site_id`. Não existe filtro por
`origin_type` aqui; `type=manga` traz manga, manhwa e manhua juntos. Devolve
`data` como **lista simples** (sem `meta`; pagina com 9999 por página):

Item real (manhwa em andamento acompanhado pelo chapter tracker):

```json
{
  "id": 4,
  "user_id": 1,
  "content": { "...": "mesmo objeto de /api/contents", "name": "The Novel's Extra", "total_units": null },
  "site": { "id": 1, "name": "ToonLivre", "url": "https://toonlivre.net", "created_at": "...", "updated_at": "..." },
  "user_site": { "id": 1, "name": "Toon Livre", "url": "https://toonlivre.net", "logo_url": null, "type": "website", "is_favorite": true, "created_at": "...", "updated_at": "..." },
  "site_title": "The Novel's Extra",
  "site_last_chapter": "174",
  "site_work_id": null,
  "current_units": 174,
  "current_season": 1,
  "progress_percent": null,
  "last_unit_update": "2026-09-29T18:15:18.000000Z",
  "rating": 8,
  "status": "reading",
  "created_at": "2026-06-23T03:58:00.000000Z",
  "updated_at": "2026-09-29T18:15:18.000000Z"
}
```

- `site` e `user_site` vêm `null` quando o item não tem site vinculado.
- `progress_percent = current_units / content.total_units`, então fica `null`
  para praticamente todo manhwa em andamento.
- `site_last_chapter` é **string** (coluna `varchar`) e, junto com
  `site_title` e `site_work_id` (ID da obra no ToonLivre, preenchido no 1º
  vínculo), é preenchido pelo bookmarklet (`POST /api/user/sync-chapters`),
  não pela AniList. É a única fonte de "último capítulo" para obras em andamento.
- Aqui `last_unit_update` é da biblioteca (quando o usuário atualizou o
  progresso), diferente do `content.last_unit_update`.
- `GET /api/user-contents/with-updates` só lista itens cujo `contents.updated_at`
  mudou nos últimos 7 dias. Sem import nem sync rodando, ela volta sempre vazia.

## 7. Capítulos: ToonLivre (bookmarklet)

A AniList não informa capítulos de obras em andamento, então o "último
capítulo" vem do site de leitura. O código do bookmarklet é gerado em
`front/src/views/ProfilePage.vue` (`bookmarkletHref`, visível só para admin).
O endpoint que recebe é
[app/Http/Controllers/ChapterCheckController.php](../app/Http/Controllers/ChapterCheckController.php),
e o vínculo fica em
[app/Services/ChapterMatchService.php](../app/Services/ChapterMatchService.php).

Ao clicar no favorito com `toonlivre.net` aberto:

1. O navegador chama a API do próprio site (mesma origem):
   `GET https://toonlivre.net/api/mangas/releases?page=N&limit=48`, da página 1
   até `hasNextPage = false` (máx. 20 páginas).
2. De cada item guarda `id`, `alternativeTitle`, `title`, `releaseYear` e
   `recentChapters[0].number`. Itens sem nenhum título ou sem capítulo são
   descartados.
3. Envia `POST {VITE_API_URL}/user/sync-chapters` com o token Bearer
   **embutido no próprio favorito**:
   ```json
   { "releases": [
     { "id": "obra-be8e359f", "alternativeTitle": "Legendary Youngest Son of the Marquis House",
       "title": "Lendário Filho Mais Novo da Casa do Marquês", "releaseYear": "2022", "chapter": "176" }
   ] }
   ```
4. Mostra um `alert` com o resumo devolvido pela API.

Favoritos criados antes de 2026-09-30 mandam só `alternativeTitle` + `chapter`.
O back continua aceitando esse formato, mas aí só a camada de título exato em
inglês funciona. Para ter o vínculo completo, copie o favorito de novo no Perfil.

O back **não loga o payload**. Do clique só sobra o efeito em `user_contents`
(`site_id`, `site_title`, `site_work_id`, `site_last_chapter`, `updated_at`).

### O que o ToonLivre devolve

A API bloqueia requests vindos do servidor (Railway), mas responde normalmente
de um IP residencial. Em 2026-09-30 um `curl` da máquina de dev recebeu
HTTP 200. Envelope:

```json
{
  "mangas": [ { "...": "item" } ],
  "pagination": { "currentPage": 1, "totalPages": 16, "totalItems": 746, "itemsPerPage": 48, "hasNextPage": true, "hasPrevPage": false }
}
```

Item (real, com 2 dos 3 `recentChapters`):

```json
{
  "id": "obra-be8e359f",
  "title": "Lendário Filho Mais Novo da Casa do Marquês",
  "coverUrl": "https://cdn.toonlivre.net/covers/obra-be8e359f/cover-d033c4db689217b344254f2847fd93b5.webp",
  "type": "Manhwa",
  "uploadSlug": "obra-be8e359f",
  "status": "Ongoing",
  "releaseYear": "2022",
  "alternativeTitle": "Legendary Youngest Son of the Marquis House",
  "isRelease": true,
  "registeredUsersOnly": false,
  "rating": 4,
  "voteCount": 20,
  "viewCount": 4682,
  "recentChapters": [
    { "id": "cap-add50941-176", "number": "176", "title": "", "releaseDate": "Agora", "timestamp": 1790783581587 },
    { "id": "cap-add50941-175", "number": "175", "title": "", "releaseDate": "Agora", "timestamp": 1790783579127 }
  ]
}
```

| Campo | Observação |
|---|---|
| `id` / `uploadSlug` | ID estável da obra no site. Gravado em `site_work_id` no 1º vínculo; depois o match é por ele. |
| `title` | Título em português. Usado no match depois do inglês. |
| `alternativeTitle` | Título em inglês. Preferido no match e gravado em `site_title`. Vazio em 6 de 746 itens, que casam pelo título em português. |
| `releaseYear` | Ano como string. Usado na camada de título parecido (±2 anos). Das 39 obras vinculadas em 2026-09-30, 33 batiam o ano exato com a AniList e 3 vinham 2 anos antes (provavelmente o ano do web novel). |
| `type` | `Manhwa` (517), `Manhua` (176), `Webtoon` (46), `Manga` (7). |
| `status` | `Ongoing` (687), `Completed` (31), `Hiatus` (21), `Canceled` (7). |
| `recentChapters[]` | Sempre 3, do mais novo para o mais antigo. |
| `recentChapters[].number` | **String**: `"176"`, `"311.1"`, `"49 {FIM}"`, `"194 {S2 - FIM}"`. É gravada como veio em `site_last_chapter`. A comparação com `current_units` usa `(float)`, que lê só o número inicial. |
| `recentChapters[].releaseDate` | Sempre `"Agora"`, inútil. A data real está em `timestamp` (epoch em ms). |
| `rating` / `voteCount` / `viewCount` | Métricas do site (nota de 0 a 4.9 nesse dia). Não são usadas. |

A lista só traz obras com lançamento recente: a de 2026-09-30 ia até 2026-01-06.
Obras paradas ou terminadas há mais tempo não aparecem e sempre caem em
"Sem correspondência".

### Match e resposta de `POST /api/user/sync-chapters`

Os nomes do item são testados nesta ordem: `site_title`, `content.name` e
`content.alternative_names`. Todas as comparações normalizam o texto: tudo
minúsculo, sem acento, sem apóstrofo, só letras, números e espaços, com no
mínimo 3 caracteres. O vínculo tenta três camadas, em ordem:

1. **ID da obra.** Se o item já tem `site_work_id` e ele veio no payload, é
   esse. Se o ID não veio (obra sem lançamento recente), o item não entra na
   camada 3 e também não aparece em `unmatched`.
2. **Título exato.** Tenta primeiro o inglês, depois o português, e depois os
   dois sem artigo inicial (`the`, `a`, `an`, `o`, `os`, `as`), o que casa
   "The Legend of the Northern Blade" com "Legend of the Northern Blade". Se o
   mesmo título aparece em obras diferentes, desempata pelo ano mais próximo.
3. **Título parecido + ano.** Só para `type = manga` com `release_year`, e
   apenas contra lançamentos que ainda não casaram com outro item. O ano precisa
   estar a até 2 anos de distância. A semelhança é o maior valor entre Dice
   (palavras em comum, sem stopwords) e Levenshtein relativo, e precisa ser
   ≥ 0,85. O 1º colocado também precisa ficar ≥ 0,10 à frente do 2º; senão o
   item é marcado como ambíguo e não vincula. O ano é o que separa continuações:
   "Solo Leveling" (2018) × "Solo Leveling: Ragnarok" (2024).

No casamento o back grava `site_id` = ToonLivre, `site_work_id` = `id`,
`site_title` = título do site (inglês, ou português se não houver) e
`site_last_chapter` = `chapter`.

**Para corrigir um vínculo errado**, edite o `site_title` do item para o título
exato do ToonLivre. Editar o `site_title` apaga o `site_work_id`, e o próximo
sync procura de novo pelo título novo.

```json
{
  "success": true,
  "message": "Sincronização concluída.",
  "data": {
    "checked": 76,
    "updated": 41,
    "linked": 1,
    "total_linked": 41,
    "retitled": 2,
    "new_chapters": [
      { "title": "Pick Me Up", "site_title": "Pick Me Up", "current": 220, "available": "221" }
    ],
    "unmatched": ["Solo Leveling", "One Piece", "..."],
    "auto_linked": [
      { "title": "The Bastard of Swordborne", "site_title": "Regressing as the Bastard of the Sword Clan", "score": 0.89 }
    ],
    "ambiguous": []
  }
}
```

- `new_chapters` lista os itens em que `(float) chapter > current_units`.
- `auto_linked` lista só os vínculos feitos agora pela camada 3, para conferir.
  Nos syncs seguintes eles casam pelo ID.
- `ambiguous` traz `{ title, candidates[] }`: itens com mais de um candidato
  parecido, que não foram vinculados.

### Como inspecionar um clique

- **Ao vivo:** abra o DevTools (F12) → Network em `toonlivre.net` *antes* de
  clicar. As requisições `releases?page=...` mostram o que o site devolveu, e
  `sync-chapters` mostra o payload (aba Payload) e a resposta da API.
- **Sem clicar:** a mesma chamada ao ToonLivre funciona da máquina de dev, então
  dá para buscar as páginas, montar o payload e rodar a lógica de match em
  modo leitura (sem `save()`).

Simulação de 2026-09-30, biblioteca do usuário 1 (76 itens), com as 746 obras
reais da lista:

| | Regra antiga (só inglês exato) | Regra nova |
|---|---|---|
| Itens enviados no payload | 740 | 746 |
| Vinculados | 39 | 41: 40 por título, 1 por semelhança (*The Bastard of Swordborne*) |
| Ambíguos | — | 0 |
| Sem correspondência | 37 | 35 (fora da lista de lançamentos: mangá JP, TV, obras sem capítulo recente) |
| Tempo do match | — | 233 ms |

Nenhuma das 39 obras que já casavam mudou de obra. Na mesma simulação, com a
regra antiga, o clique gravaria 2 mudanças (`site_last_chapter`: Pick Me Up
220 → 221, Designated Bully 202 → 203), e 28 itens tinham `chapter > current_units`.

## 8. O que o front usa hoje

- `contentService.getAll()` (`src/services/contentService.ts`) **não envia
  `origin_type` nem `format`**. A API filtra manhwa, mas o Discover não tem
  como pedir "só manhwa". O tipo `Content.source` ainda lista
  `'jikan' | 'tmdb'`; o valor real é `'anilist'`.
- `origin_type` aparece no detalhe (`CatalogDetailPage.vue`) e no badge
  (`ContentTypeBadge.vue`, `MANHWA`).

## 9. Simulação de atualização (dry-run de 2026-09-30)

Mesmas requisições do import com todos os filtros de manhwa
(`--type=manga --origin=manhwa --format=MANGA --pages=101 --force`, sem e com
`--adult`), normalizadas pelo mesmo código e comparadas item a item com o banco
(mesma ordem de busca do upsert), **sem gravar nada**:

| | Sem +18 | +18 |
|---|---|---|
| Páginas com dados | 100 (página 101 → HTTP 400) | 100 (página 101 → HTTP 400) |
| Itens recebidos | 5.000 | 5.000 |
| **Novos** (não estão no banco) | **225** | **3.011** |
| Existentes que mudariam com `--force` | 4.756 | 1.988 |
| Existentes idênticos | 19 | 1 |
| Mudança de `status` | 147 (137 `ongoing` → `completed`) | 73 (52 `ongoing` → `completed`, 17 `completed` → `ongoing`) |
| Mudança de `total_units` / `end_date` | 164 / 166 | 147 / 147 |
| Colisões por nome (seção 5) | 2: Monster, Happiness (mangá JP) | 4: Glory Days, Caught in the Act, Mimi, My Way (manhua) |

- Quase todo existente muda `popularity` (a contagem da AniList cresce todo
  dia). Os outros campos que mais mudam são `rating`/`score`, `themes` e
  `synopsis`.
- Dos 225 novos não adultos, 159 estão em andamento. 78 têm `anilist_id`
  acima do maior já gravado (213323), ou seja, foram cadastrados na AniList
  depois do import de junho. Os outros 147 são cadastros antigos que não
  tinham entrado (subiram no ranking desde junho ou ficaram de fora do import).
  Os mais populares: *Dungeon-eul Geurineun Hwaga*,
  *Prologue-eseo 30-nyeoni Heulleotda*, *Naega Jugin Dragon-gwa
  Gyeolhonhaetda*, *Tomb Raider King: End Line*.
- Dos 3.011 novos +18, 2.307 são concluídos e só 136 são cadastros posteriores
  a junho. O banco tem 1.922 manhwas +18, o que indica que o import +18 de
  junho parou por volta da página 40.
- Na biblioteca do usuário 1 só 2 itens mudariam um campo crítico: Nano
  Machine (`end_date` → 2026-01-01) e Revenge of the Baskerville Bloodhound
  (`banner_image`).
- A AniList estava em modo degradado (`X-RateLimit-Limit: 30`). Com 2,3 s entre
  páginas não houve nenhum 429.

## 10. Retrato do catálogo (produção, 2026-09-30)

| | Quantidade |
|---|---|
| Manhwas (`origin_type = manhwa`) | 6.855 |
| ├ não adultos / adultos | 4.933 / 1.922 |
| ├ `completed` / `ongoing` / `cancelled` | 4.934 / 1.870 / 51 |
| ├ formato `manga` / `one_shot` | 6.744 / 111 |
| ├ sem `total_units` | 1.894 (1.868 dos 1.870 em andamento) |
| ├ sem `banner_image` | 4.581 |
| ├ sem `rating` | 1.698 |
| └ sinopse com `(Source: ...)` | 6.010 |
| Manhuas / mangás (JP) | 4.666 / 161 |
| Todo o `contents` | 13.746 |
| Último import (manhwa) | 2026-06-23 08:38 |
| Manhwas na biblioteca (`user_contents`, todos os usuários) | 67 |
| Obras vinculadas ao ToonLivre (usuário 1) | 40 |
| Na AniList e fora do banco (seção 9) | 225 não adultos + 3.011 +18 (até o teto de 5.000 cada) |
