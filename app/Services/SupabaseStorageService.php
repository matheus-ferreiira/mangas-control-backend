<?php

namespace App\Services;

use Supabase\CreateClient;

class SupabaseStorageService
{
    protected $client;
    protected $bucket = 'covers'; // ⚠️ use sem espaço!

    public function __construct()
    {
        $this->client = CreateClient(
            config('services.supabase.url'),
            config('services.supabase.key')
        );
    }

    public function upload($file)
    {
        $filename = 'covers/' . uniqid() . '.' . $file->getClientOriginalExtension();

        $this->client->storage->from($this->bucket)->upload(
            $filename,
            file_get_contents($file),
            ['contentType' => $file->getMimeType()]
        );

        return $this->client->storage
            ->from($this->bucket)
            ->getPublicUrl($filename);
    }
}