<?php

namespace App\Services;

use GuzzleHttp\Client;

class SupabaseStorageService
{
    protected $client;
    protected $url;
    protected $key;
    protected $bucket = 'covers'; 

    public function __construct()
    {
        $this->client = new Client();
        $this->url = config('services.supabase.url');
        $this->key = config('services.supabase.key');
    }

    public function upload($file)
    {
        $filename = uniqid() . '.' . $file->getClientOriginalExtension();

        $path = "covers/{$filename}";

        $this->client->post("{$this->url}/storage/v1/object/{$this->bucket}/{$path}", [
            'headers' => [
                'Authorization' => "Bearer {$this->key}",
                'Content-Type'  => $file->getMimeType(),
            ],
            'body' => fopen($file->getRealPath(), 'r'),
        ]);

        return "{$this->url}/storage/v1/object/public/{$this->bucket}/{$path}";
    }
}