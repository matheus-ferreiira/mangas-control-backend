<?php

return [
    'origin_detection' => [
        /*
         * Publishers associados a manhwa (coreano).
         * Consultados quando o tipo da API e os caracteres do título não são suficientes.
         */
        'korean_publishers' => [
            'KakaoPage',
            'Naver',
            'Naver Series',
            'Naver Webtoon',
            'KakaoWebtoon',
            'Lezhin Comics',
            'Tappytoon',
            'Webtoons.com',
        ],
    ],

    /*
     * Sites de leitura sincronizados pelo bookmarklet (POST /api/user/sync-chapters,
     * campo `site`). A chave também é o `contents.source` das obras criadas com os
     * dados do site quando a AniList não tem a obra.
     *
     *  - url_like: como achar o registro em `sites`;
     *  - adult:    obras criadas a partir do site são +18.
     */
    'reading_sites' => [
        'toonlivre' => ['url_like' => '%toonlivre%', 'adult' => false],
        'tiamanhwa' => ['url_like' => '%tiamanhwa%', 'adult' => true],
    ],
];
