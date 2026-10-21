<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roda antes do RefreshDatabase (migrate:fresh). Se a conexão de teste não
     * for SQLite em memória — ex.: DB_URL do .env de produção vazando para a
     * conexão sqlite —, aborta antes de apagar qualquer banco real.
     */
    protected function setUpTraits()
    {
        $name = config('database.default');
        $db = config("database.connections.{$name}");

        if (($db['driver'] ?? null) !== 'sqlite' || ! empty($db['url']) || ($db['database'] ?? null) !== ':memory:') {
            throw new \RuntimeException("Testes só rodam em SQLite em memória; a conexão '{$name}' aponta para outro banco. Confira DB_URL no .env.");
        }

        return parent::setUpTraits();
    }
}
