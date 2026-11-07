<?php

namespace App\Support;

final class NovelTranslation
{
    // Wiki categories: Characters, Locations, Runcandel Family and Zipfel Family.
    // Include the spellings used in the edition where they differ from page titles.
    private const NAMES = [
        'Chiron Runcandel', 'Jin Runcandel', 'Rosa Runcandel', 'Luna Runcandel',
        'Kelliark Zipfel', 'Veradin Zipfel', 'Dante Hairan', 'Talaris Endorma',
        'Syris Endorma', 'Gilly Mcrolan', 'Margiella Ibriano', 'Bishkel Ibriano',
        'Alcaro Chenderer', 'Alicia Badger', 'Alisa Betzer', 'Alphen', 'Amir Vermont',
        'Andre Zipfel', 'Anne', 'Baba', 'Bale', 'Ballas', 'Baltirock', 'Beliz',
        'Bellop Shmitz', 'Berakt Sidlieker', 'Berakt', 'Beris', 'Bishkel', 'Boras',
        'Bubare Gaston', 'Bubare', 'Cember Bill', 'Chiron', 'Chukon Tolderer',
        'Cold Joe', 'Cosmos D. Roger', 'Dalpir', 'Dante', 'Daytona', 'Diana',
        'Dino Jaglun', 'Diphus', 'Dranax', 'Elona', 'Eltiot', 'Emma Neiltrow',
        'Enya', 'Frey', 'Garmund', 'Gilly', 'Gliek', 'Gramm', 'Haytona', 'Hedo',
        'Heinz', 'Heluram', 'Ibriano', 'Jet', 'Jetu', 'Jin', 'Jorden', 'Joshua',
        'Julia', 'Kadun', 'Kaio', 'Kajin Romero', 'Kalon', 'Karl Zipfel', 'Kashmir',
        'Kelliark', 'Keydart Hall', 'Keydart', 'Khan', 'Kial', 'Kuajito Truka',
        'Kullam', 'Kuzan', 'Lani Salomi', 'Lani', 'Laosa', 'Lata', 'Latika Mamutika',
        'Latree', 'Linpa', 'Lionel', 'Lingling', 'Lisham', 'Lokia Ganesto', 'Lokia',
        'Lucy Runcandel', 'Lueth Hister', 'Lueth', 'Lumora', 'Luna', 'Luntia',
        'Madurai', 'Margiella', 'Mary', 'Mcrolan', 'Mesa Milkano', 'Mesa', 'Midor',
        'Milkano', 'Misha', 'Morganil', 'Mort', 'Murakan', 'Mureka', 'Muron',
        'Myu', 'Nata', 'Nirgand', 'Octavia', 'Olta', 'Orgal Remilias', 'Orgal',
        'Owal', 'Padler', 'Palem', 'Peitel', 'Petro', 'Picon Minche', 'Picon',
        'Qwaul', 'Quikantel', 'Ran Runcandel', 'Rolt Joe', 'Ron Hairan', 'Rosa',
        'Rakiman', 'Sandra', 'Sarah', 'Senga', 'Seu', 'Sheenu', 'Shuri', 'Silderay',
        'Solderet', 'Suzanne Lilista', 'Syris', 'Tagar Marius', 'Taimyun Marius',
        'Talaris', 'Tasha', 'Temar', 'Tess', 'Teto', 'Uger Tuko', 'Vahn',
        'Valeria Hister', 'Valeria', 'Valkas', 'Vamel', 'Vanessa Olsen', 'Veradin',
        'Vigo', 'Vyollo', 'Vyuretta', 'Wratch', 'Yona', 'Yulian', 'Zed', 'Zephyrin', 'Zito',
        'Runcandels', 'Runcandel', 'Zipfels', 'Zipfel', 'Kinzelo', 'Ganesto',
        'Vermont', 'Hairan', 'Hister', 'Endorma', 'Marius', 'Yuta',
        'Black Sea', 'Demon Realm', 'Drakka', 'Draka', 'Fire Realm', 'Garden of Swords',
        'Hidden Palace', 'Huphester', 'Kollon Ruins', 'La Prarosa', 'Lafrarosa',
        'Mammit Lawless Zone', 'Mammit', 'Mitra Great Desert', 'Stormcastle',
        'Tikan City', 'Tikan', 'Vankella', 'Zhan Kingdom',
    ];

    public static function protect(string $text): string
    {
        static $pattern;

        if ($pattern === null) {
            $names = self::NAMES;
            usort($names, static fn (string $a, string $b) => strlen($b) <=> strlen($a));
            $alternatives = implode('|', array_map(static fn (string $name) => preg_quote($name, '/'), $names));
            // Keep adjacent spaces inside the protected span so translation cannot trim them.
            $pattern = '/(\h*(?<![\p{L}\p{N}\p{M}_])(?:'.$alternatives.')(?![\p{L}\p{N}\p{M}_])\h*)/u';
        }

        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $html = '';
        foreach ($parts as $index => $part) {
            $escaped = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= $index % 2 === 0 ? $escaped : '<span translate="no" class="notranslate">'.$escaped.'</span>';
        }

        return $html;
    }
}
