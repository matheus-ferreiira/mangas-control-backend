<?php

namespace App\Services;

use App\Helpers\LogHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class SupabaseStorageService
{
    protected Client $client;
    protected string $url;
    protected string $key;
    protected string $bucket = 'covers';

    public function __construct()
    {
        $this->client = new Client();
        $this->url    = config('services.supabase.url');
        $this->key    = config('services.supabase.key');
    }

    public function upload($file): string
    {
        $filename = uniqid().'.'.$file->getClientOriginalExtension();
        $path     = "covers/{$filename}";
        $endpoint = "{$this->url}/storage/v1/object/{$this->bucket}/{$path}";

        try {
            $this->client->post($endpoint, [
                'headers' => [
                    'Authorization' => "Bearer {$this->key}",
                    'Content-Type'  => $file->getMimeType(),
                ],
                'body' => fopen($file->getRealPath(), 'r'),
            ]);
        } catch (RequestException $e) {
            LogHelper::error('Falha no upload para o Supabase', [
                'filename'      => $filename,
                'original_name' => $file->getClientOriginalName(),
                'http_status'   => $e->getResponse()?->getStatusCode(),
            ], $e);

            throw $e;
        }

        $publicUrl = "{$this->url}/storage/v1/object/public/{$this->bucket}/{$path}";

        LogHelper::info('Upload concluído no Supabase', [
            'filename' => $filename,
            'url'      => $publicUrl,
        ]);

        return $publicUrl;
    }
}
