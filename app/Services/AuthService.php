<?php

namespace App\Services;

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

class AuthService
{
    public function handleGoogleToken(string $token): array
    {
        $googleUser = Socialite::driver('google')->userFromToken($token);

        $user = User::updateOrCreate(
            ['google_id' => $googleUser->getId()],
            [
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'avatar' => $googleUser->getAvatar(),
                'email_verified_at' => now(),
            ]
        );

        $accessToken = $user->createToken('auth_token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $accessToken,
        ];
    }
}
