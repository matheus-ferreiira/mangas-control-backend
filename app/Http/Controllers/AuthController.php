<?php

namespace App\Http\Controllers;

use App\Helpers\LogHelper;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private AuthService $authService) {}

    public function googleLogin(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        try {
            $result = $this->authService->handleGoogleToken($request->token);

            return $this->success([
                'user'         => new UserResource($result['user']),
                'access_token' => $result['token'],
                'token_type'   => 'Bearer',
            ], 'Login realizado com sucesso');
        } catch (\Exception $e) {
            LogHelper::warning('Tentativa de login com token inválido', [
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
                'reason'     => $e->getMessage(),
            ]);

            return $this->error('Token do Google inválido ou expirado', [], 401);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->currentAccessToken()->delete();

        LogHelper::info('Logout realizado', ['user_id' => $user->id]);

        return $this->success(null, 'Logout realizado com sucesso');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(new UserResource($request->user()));
    }
}
