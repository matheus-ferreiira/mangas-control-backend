<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogViewerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = config('logging.viewer_password');

        if (!$password) {
            abort(503, 'Configure LOG_VIEWER_PASSWORD no .env para acessar o log viewer.');
        }

        $expectedUser = config('logging.viewer_user', 'admin');

        if ($request->getUser() !== $expectedUser || $request->getPassword() !== $password) {
            return response('Acesso restrito.', 401, [
                'WWW-Authenticate' => 'Basic realm="Log Viewer", charset="UTF-8"',
            ]);
        }

        return $next($request);
    }
}
