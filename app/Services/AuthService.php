<?php

namespace App\Services;

use App\Helpers\LogHelper;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthService
{
    public function register(array $data): array
    {
        $user = User::create([
            'name'     => $data['name'],
            'username' => $data['username'],
            'email'    => $data['email'],
            'password' => $data['password'],
        ]);

        LogHelper::info('Novo usuário registrado', [
            'user_id'  => $user->id,
            'email'    => $user->email,
            'username' => $user->username,
        ]);

        $authToken = $user->createToken('auth_token')->plainTextToken;

        return ['user' => $user, 'token' => $authToken];
    }

    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            throw new \Exception('Credenciais inválidas');
        }

        LogHelper::info('Login via email', [
            'user_id' => $user->id,
            'email'   => $user->email,
        ]);

        $authToken = $user->createToken('auth_token')->plainTextToken;

        return ['user' => $user, 'token' => $authToken];
    }

    public function sendResetPasswordEmail(string $email): void
    {
        $status = Password::sendResetLink(['email' => $email]);

        if ($status !== Password::RESET_LINK_SENT) {
            LogHelper::warning('Falha ao enviar e-mail de recuperação de senha', [
                'email'  => $email,
                'status' => $status,
            ]);
            throw new \Exception(__($status));
        }

        LogHelper::info('E-mail de recuperação enviado', ['email' => $email]);
    }

    public function resetPassword(array $data): void
    {
        $status = Password::reset(
            [
                'email'                 => $data['email'],
                'password'              => $data['password'],
                'password_confirmation' => $data['password_confirmation'],
                'token'                 => $data['token'],
            ],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])
                     ->setRememberToken(Str::random(60));
                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new \Exception(__($status));
        }

        LogHelper::info('Senha redefinida com sucesso', ['email' => $data['email']]);
    }

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
                'username'  => $this->generateUsername($googleUser['name'], $googleUser['email']),
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

        return ['user' => $user, 'token' => $authToken];
    }

    private function generateUsername(string $name, string $email): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $name)));

        if (empty($base)) {
            $base = strtolower(explode('@', $email)[0]);
        }

        $username = $base;
        $counter  = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base.'_'.$counter;
            $counter++;
        }

        return $username;
    }
}
