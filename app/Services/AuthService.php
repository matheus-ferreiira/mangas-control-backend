<?php

namespace App\Services;

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Http;

class AuthService
{
    public function handleGoogleToken(string $token)
    {
        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $token
        ]);

        if (!$response->ok()) {
            throw new \Exception('Token inválido');
        }

        $googleUser = $response->json();

        // Validação extra (muito importante)
        if ($googleUser['aud'] !== config('services.google.client_id')) {
            throw new \Exception('Client ID inválido');
        }

        // Buscar ou criar usuário
        $user = User::updateOrCreate(
            ['email' => $googleUser['email']],
            [
                'name' => $googleUser['name'],
                'google_id' => $googleUser['sub'],
                'avatar' => $googleUser['picture'] ?? null,
            ]
        );

        // Criar token Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }
}
