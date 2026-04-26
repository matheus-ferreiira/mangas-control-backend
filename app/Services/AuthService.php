<?php

namespace App\Services;

use App\Helpers\LogHelper;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class AuthService
{
    public function handleGoogleToken(string $token): array
    {
        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $token,
        ]);

        if (!$response->ok()) {
            LogHelper::error('Resposta inválida da API do Google', [
                'http_status' => $response->status(),
            ]);
            throw new \Exception('Token inválido');
        }

        $googleUser = $response->json();

        if ($googleUser['aud'] !== config('services.google.client_id')) {
            LogHelper::error('Client ID do Google não confere', [
                'received_aud' => $googleUser['aud'] ?? null,
            ]);
            throw new \Exception('Client ID inválido');
        }

        $isNew = !User::where('email', $googleUser['email'])->exists();

        $user = User::updateOrCreate(
            ['email' => $googleUser['email']],
            [
                'name'      => $googleUser['name'],
                'google_id' => $googleUser['sub'],
                'avatar'    => $googleUser['picture'] ?? null,
            ]
        );

        LogHelper::info($isNew ? 'Novo usuário registrado via Google' : 'Login via Google', [
            'user_id' => $user->id,
            'email'   => $user->email,
            'is_new'  => $isNew,
        ]);

        $authToken = $user->createToken('auth_token')->plainTextToken;

        return [
            'user'  => $user,
            'token' => $authToken,
        ];
    }
}
