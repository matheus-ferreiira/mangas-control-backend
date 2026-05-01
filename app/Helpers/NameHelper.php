<?php

namespace App\Helpers;

class NameHelper
{
    /**
     * Normaliza um nome: trim + lowercase + colapsa espaços duplos.
     * Usado para comparações e deduplicação — não altera o valor exibido.
     */
    public static function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }

    /**
     * Recebe um array de strings e retorna somente os valores únicos (por forma
     * normalizada), limitados a $max entradas. Vazios são descartados.
     * O valor original (com capitalização) é mantido no resultado.
     */
    public static function normalizeList(array $names, int $max = 50): array
    {
        $seen   = [];
        $result = [];

        foreach ($names as $name) {
            if (count($result) >= $max) {
                break;
            }

            $name = trim((string) $name);
            if (! $name) {
                continue;
            }

            $key = self::normalize($name);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[]   = $name;
        }

        return array_values($result);
    }
}
