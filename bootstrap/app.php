<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            \App\Http\Middleware\RequestLoggerMiddleware::class,
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Log unexpected exceptions to the structured errors channel
        $exceptions->report(function (\Throwable $e) {
            $expected = [
                AuthenticationException::class,
                ModelNotFoundException::class,
                NotFoundHttpException::class,
                AccessDeniedHttpException::class,
                \Illuminate\Validation\ValidationException::class,
            ];

            foreach ($expected as $class) {
                if ($e instanceof $class) {
                    return false;
                }
            }

            \App\Helpers\LogHelper::error($e->getMessage(), [
                'url'    => request()->fullUrl(),
                'method' => request()->method(),
                'ip'     => request()->ip(),
                'input'  => collect(request()->all())
                    ->except(['password', 'password_confirmation', 'token', 'secret'])
                    ->toArray(),
            ], $e);

            return false;
        });

        $exceptions->render(function (AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autenticado. Faça login para continuar.',
                ], 401);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Recurso não encontrado.',
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rota não encontrada.',
                ], 404);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado.',
                ], 403);
            }
        });
    })->create();
