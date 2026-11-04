# Leitor de novels

## Especificação

Permitir ler Swordmaster’s Youngest Son, capítulos 401–1147, na novel já existente no catálogo. Usuários autenticados abrem capítulos em uma página responsiva e podem navegar, ajustar fonte, retomar posição e marcar capítulos concluídos. Uma página temporária assinada abre no navegador externo para tradução nativa, inclusive no celular.

Sem migrations ou alterações administrativas no banco. O vínculo usa nomes e nomes alternativos da novel, restrito ao tipo novel. Conteúdo de leitura fica em resources/novels, fora da pasta pública, entregue por endpoints Sanctum. O progresso usa a API existente e só muda por ação explícita do leitor. Ler capítulos antigos não reduz progresso.

## Preparação do texto

Origem: sete pastas Drive fornecidas pelo usuário; 747 DOCX preservados localmente fora dos repositórios. A preparação restaura a base anterior às sugestões destrutivas e aplica correções selecionadas, glossário e reparos de parágrafos. O resultado tem `edition=reviewed`; notas do tradutor ficam separadas da narrativa em `translator_notes`. Critérios, decisões e limites estão em [novel-editorial-review.md](novel-editorial-review.md). Não concatenar indiscriminadamente os runs do XML nem aceitar todas as sugestões.

## Componentes e contrato

- NovelReaderPage: carregamento, capítulo atual, navegação e persistência de posição/progresso.
- ReaderToolbar: fonte, tema, seleção de capítulo e abertura no navegador; props tipadas e eventos explícitos.
- NovelReaderService: vínculo configurado, manifesto e capítulos no filesystem.
- NovelReaderController: endpoints autenticados e geração de URL assinada para HTML de leitura.
- Página Blade: texto escapado, idioma inglês, navegação assinada com a mesma expiração. Sem token Sanctum na URL e sem escrita de progresso.

## Validação e rollout

Validar todos os capítulos preparados, ausência de lacunas, parágrafos e recuperação dos 13 capítulos sinalizados. Testar autenticação, vínculo exclusivo à novel, capítulo inexistente, URLs assinadas e HTML escapado. Executar testes backend apenas com .env.local e SQLite em memória, restaurando o ambiente ao final. Frontend: testes de links/navegação e build TypeScript/Vite.

Publicar backend antes do frontend. Não há escrita preparatória em produção; somente as ações normais do usuário atualizam seu histórico. O APK precisa ser recompilado para receber a nova tela. Rollback: reverter os commits da funcionalidade; os dados existentes permanecem compatíveis.

## API

- `GET /api/contents/{id}/reader`: Sanctum; manifesto com slug, título, idioma, edição, primeiro/último capítulo e lista de números.
- `GET /api/contents/{id}/reader/{chapter}`: Sanctum; número, título, idioma, edição, parágrafos, URL de origem e URL de leitura no navegador.
- `GET /read/{slug}/{chapter}?expires=...&signature=...`: HTML com assinatura relativa de 12 horas. Navegação mantém a expiração original. Cache privado sem armazenamento, sem referer e sem indexação. Quem receber a URL pode ler até expirar. Não permite alterar histórico.

Respostas API usam o envelope padrão. Obra não vinculada/tipo incorreto/capítulo ausente: 404; não autenticado: 401; assinatura inválida/expirada: 403. Arquivos ficam fora da pasta pública. Leitura autenticada respeita o filtro de audiência do catálogo.

## Reproduzir a preparação

Na raiz do backend: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/prepare-novel.ps1 -Source ../capitulos`. Gera a base e executa `novel-editorial.cjs`. A origem precisa conter manifesto e DOCX organizados por pasta Drive. Gera JSON, `index.json`, auditoria dos DOCX e auditoria editorial. Falha com capítulo vazio, caracteres inválidos, lacunas, duplicados ou âncoras editoriais divergentes. `-OriginalOnly` gera apenas a base sem revisão. DOCX, comentários e URLs temporárias de download não entram no Git.

## Implementação — Sumário

- **O que:** 747 capítulos preparados, recuperação das exclusões e leitor web/Android. Todos têm pelo menos 8.588 caracteres na validação. Vínculo confirmado para a novel de produção ID 13755, por título, sem alterar cadastro.
- **Como:** controller → service → JSON; `ContentResource.reader` informa disponibilidade; duas rotas API e Blade assinado. Aliases em `config/novels.php`, apenas tipo novel.
- **Por quê:** evitar migration/escrita preparatória e downloads do Drive durante cada leitura. A base recuperada evita sugestões destrutivas; a etapa editorial reaplica correções selecionadas e padronização documentadas em `novel-editorial-review.md`.
- **Arquivos:** `scripts/prepare-novel.ps1`, `resources/novels/swordmasters-youngest-son/*`, `config/novels.php`, `NovelReaderService`, `NovelReaderController`, `ContentResource`, rotas API/web, `resources/views/novel-reader.blade.php`, `tests/Feature/NovelReaderTest.php`. Frontend documentado em seu `docs/novel-reader.md`.
- **Validação:** 49 testes backend / 2.486 asserções, com `.env.local` e SQLite em memória; ambiente original restaurado. Build frontend e 14 testes aprovados. APK debug gerado. Consulta somente leitura confirmou novel e ausência de migrations pendentes. Nenhuma escrita preparatória em produção.
- **Limitações:** conferência estrutural e amostras, sem revisão editorial integral ou renderização de todos os DOCX. Requer conexão. Tradução depende do navegador e a aba externa não grava automaticamente o histórico.
