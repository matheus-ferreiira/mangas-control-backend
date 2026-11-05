# Revisão editorial da edição em inglês

## Escopo e fontes

Foram comparados os parágrafos originais e as sugestões dos 747 DOCX fornecidos pelo usuário, capítulos 401–1147, junto dos 97 registros de comentários já extraídos. A comparação encontrou 23.454 parágrafos com diferenças propostas. Este número inclui exclusões, inserções, sugestões sobrepostas e alterações repetidas; não equivale a 23.454 correções legítimas.

O trabalho combina varredura integral do corpus, glossário explícito e intervenções ancoradas no texto exato. Não é uma nova tradução confrontada com o original coreano nem uma garantia de ausência de todo erro literário. Não foram inventadas cenas, diálogos ou capítulos para preencher dúvidas.

## Critérios de revisão

- Nomes com variantes de transliteração usam uma grafia única nesta edição, conforme `resources/novels/editorial-glossary.json`. É uma convenção editorial, não uma declaração de que todas as formas escolhidas são oficiais.
- Pronomes, parentesco e títulos são corrigidos quando a cena identifica o referente. Sugestões contraditórias ou ambíguas são preservadas como material de origem, sem aprovação automática. O imperativo inglês `Let` não é trocado globalmente pelo nome Lueth; termos comuns como spirit, soul, king e brother também não recebem substituição global de significado.
- Títulos de flagbearer só aproveitam a sugestão quando há um ordinal explícito; o número permanece inalterado.
- Quebras introduzidas no meio de frases e palavras são reparadas com as marcas de parágrafo e os runs dos DOCX. As junções preservam a presença ou ausência de espaço do texto original. Há ainda nove junções de continuações inequívocas conferidas por contexto.
- Sugestões que removem narrativa não são aceitas em massa. Comentários de leitores e respostas automáticas de tradução não entram no corpo da novel.
- Notas finais do tradutor em sete capítulos são preservadas em `translator_notes`, separadas de `paragraphs`. Rodapés de doação e mensagens de geração automática são retirados da leitura.

## Continuidade dos capítulos 803–806

O 803 distingue a destruição anterior da 7ª filial do novo ataque na 3ª filial. O 804 repetia a filial antiga em três referências; essas referências passam a indicar a 3ª filial.

O 804 termina com a convocação da frota e o início do bombardeio. O 805 começa com a reação de Rakiman ao bloqueio desse mesmo bombardeio, e o 806 segue a retirada até o local de lançamento. Não foram encontrados parágrafos longos idênticos entre 804 e 805. Os capítulos permanecem separados: os títulos agora identificam as partes 1 e 2 da mesma passagem sobre o 13º Battle King, em vez de o título do 805 sugerir treze guerreiros distintos.

## Arquivos e reprodução

- `resources/novels/editorial-glossary.json`: variantes e termos padronizados.
- `resources/novels/editorial/reviewed-patches.json`: texto anterior, texto corrigido e justificativa para cada intervenção contextual.
- `resources/novels/editorial/paragraph-repairs.json`: pares de fragmentos e regra de junção.
- `resources/novels/swordmasters-youngest-son/editorial-audit.json`: auditoria por capítulo, contagens, quantidade de parágrafos, hash da entrada e hash do resultado.
- `scripts/novel-editorial.cjs`: aplica as decisões, valida todos os capítulos antes de escrever e impede aplicação repetida sobre uma edição já revisada.
- `scripts/novel-editorial.test.cjs`: verifica limites das substituições, integridade dos 747 capítulos, hashes, cenas duplicadas, junções e regressões de continuidade.

`powershell -NoProfile -ExecutionPolicy Bypass -File scripts/prepare-novel.ps1` reconstrói a base a partir dos DOCX e aplica a revisão. `-OriginalOnly` permite gerar apenas a base, sem revisão. Em seguida, execute `node --test scripts/novel-editorial.test.cjs`.

Os DOCX originais permanecem preservados. Não há migração nem escrita administrativa no banco. O leitor recebe os capítulos revisados pela API existente; a posição salva anteriormente pode variar um pouco após junções de parágrafo.

## Referências auxiliares

A principal evidência são as cenas dos próprios documentos. Para resolver dúvidas de identificação, foram também consultadas referências auxiliares: [Vahn](https://swordmasters-youngest-son.fandom.com/wiki/Vahn), [discussão sobre Lata e Fey](https://www.reddit.com/r/SwordmastersSon/comments/1rx1dyl/is_lata_porch_a_female_from_the_novel/) e [identificação de Lingling na tradução do capítulo 745](https://blackseatls.wordpress.com/2024/11/01/chapter-745/). Essas fontes não substituem o original coreano e não foram usadas para copiar capítulos externos para o corpus.

## Sumário da implementação

Resultado da preparação: 747 capítulos verificados, 738 com alterações no conteúdo ou estrutura; 15.987 parágrafos com ajustes, 19.650 ocorrências de nomes/termos padronizadas, 347 intervenções ancoradas (incluindo 128 ajustes de título de flagbearer com ordinal explícito), 129 junções e 24 parágrafos de metatexto/rodapé retirados da narrativa. As contagens se sobrepõem e não devem ser somadas como erros distintos. Os 747 arquivos passam na verificação de integridade e hashes, e a varredura não encontra parágrafos longos exatamente duplicados nem as continuações truncadas identificadas. Onomatopeias e risadas repetidas foram preservadas.

Validação executada: seis testes editoriais, cobrindo todos os capítulos; 14 testes de leitor/frontend; build TypeScript/Vite. Não foram executados comandos de banco para esta revisão de arquivos.

**O que foi feito:** seleção de correções das sugestões, padronização de nomes e títulos, correções de referentes identificados, reparo de palavras/frases partidas, remoção de metatexto da narrativa e conferência da sequência 803–806.

**Como e por quê:** decisões explícitas, verificáveis e reproduzíveis, mantendo a narrativa recuperada em vez de aceitar todas as revisões. Cada mudança contextual possui uma âncora exata; uma mudança de origem faz a preparação falhar em vez de aplicar a correção a outro trecho.

**Limites:** a fonte inglesa já contém tradução automática. Expressões cujo sentido exige consulta ao original coreano não foram reescritas com significado presumido. A varredura e as correções documentadas não equivalem a uma revisão humana frase a frase de toda a obra.

## Conferência de nomes com a wiki — 2026-11-04

A lista de personagens e as páginas individuais da wiki fornecida pelo usuário foram comparadas com variantes encontradas nos 747 capítulos. Quando a navegação e a página divergem, esta edição prefere o título da página individual. Por isso, Shirone, Sirone, Shilon e Cyron passam a Chiron; Zipple passa a Zipfel; Gliyak a Gliek; Quicantel a Quikantel; Leuth a Lueth; Paitel a Peitel; Kidard a Keydart; Paddler a Padler. Também foram incorporadas variantes contextualmente identificadas de Bubare, Margiella, Ibriano, Morganil, Jorden, Alphen, Beliz, Frey, Dranax, Gramm, Sheenu, Kashmir, Latree, Garmund, Mcrolan, Ballas, Heluram e Silderay.

As referências estão em `resources/novels/editorial/wiki-name-reference.json`. A busca aproximada serviu apenas para localizar candidatos; não autoriza substituições automáticas por semelhança. Draka/Draca, Mitel, Rok, Locke, Luke, Mureka e os nomes comuns ambíguos permanecem separados. Alisa/Alisia e outros nomes sem decisão segura não são renomeados apenas porque existe um título parecido na wiki. Não há garantia de que todo nome ainda sem referência esteja correto.

A reconstrução conserva 347 intervenções contextuais e 129 junções anteriores. O resultado acumulado contém 17.599 parágrafos ajustados e 21.895 substituições de nomes/termos desde os DOCX originais. Sete testes editoriais conferem todos os capítulos, a normalização idempotente, os hashes e as regressões. Nenhum comando de banco é necessário.

Nesta rodada, 638 capítulos receberam alterações em 5168 parágrafos em relação à edição anterior.
