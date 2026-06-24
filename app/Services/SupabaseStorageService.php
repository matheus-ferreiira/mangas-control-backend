<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SupabaseStorageService
{
    /**
     * Faz upload de um arquivo para um bucket público do Supabase Storage
     * (opcionalmente dentro de uma pasta) e retorna a URL pública.
     */
    public function upload(UploadedFile $file, string $bucket, string $folder = ''): string
    {
        $baseUrl = rtrim((string) config('services.supabase.url'), '/');
        $key = (string) config('services.supabase.key');

        if ($baseUrl === '' || $key === '') {
            throw new RuntimeException('Supabase não configurado (SUPABASE_URL/SUPABASE_KEY).');
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'bin'));
        $name = Str::uuid()->toString().'.'.$ext;
        $path = $folder !== '' ? trim($folder, '/').'/'.$name : $name;
        $contentType = $file->getMimeType() ?: 'application/octet-stream';

        $response = Http::withToken($key)
            ->withBody((string) file_get_contents($file->getRealPath()), $contentType)
            ->post("{$baseUrl}/storage/v1/object/{$bucket}/{$path}");

        if (! $response->successful()) {
            throw new RuntimeException('Falha no upload para o Supabase (HTTP '.$response->status().').');
        }

        return "{$baseUrl}/storage/v1/object/public/{$bucket}/{$path}";
    }
}
