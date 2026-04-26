<?php

namespace App\Http\Controllers;

use App\Helpers\LogHelper;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return $this->success([
            'user'         => new UserResource($result['user']),
            'access_token' => $result['token'],
            'token_type'   => 'Bearer',
        ], 'Conta criada com sucesso', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login(
                $request->validated()['email'],
                $request->validated()['password']
            );

            return $this->success([
                'user'         => new UserResource($result['user']),
                'access_token' => $result['token'],
                'token_type'   => 'Bearer',
            ], 'Login realizado com sucesso');
        } catch (\Exception $e) {
            LogHelper::warning('Tentativa de login com credenciais inválidas', [
                'ip'    => $request->ip(),
                'email' => $request->email,
            ]);

            return $this->error('Credenciais inválidas', [], 401);
        }
    }

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

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->sendResetPasswordEmail($request->validated()['email']);

            return $this->success(null, 'E-mail de recuperação enviado, verifique sua caixa de entrada');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), [], 400);
        }
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->resetPassword($request->validated());

            return $this->success(null, 'Senha redefinida com sucesso');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), [], 400);
        }
    }
}
