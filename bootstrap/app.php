<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveBranchContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(AssignRequestId::class);
        $middleware->alias([
            'user.active' => EnsureUserIsActive::class,
            'branch.context' => ResolveBranchContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $requestId = $request->attributes->get('request_id');
            $status = 500;
            $code = 'INTERNAL_SERVER_ERROR';
            $message = 'An unexpected error occurred.';
            $errors = null;

            if ($exception instanceof ApiException) {
                $status = $exception->status;
                $code = $exception->errorCode;
                $message = $exception->getMessage();
            } elseif ($exception instanceof ValidationException) {
                $status = 422;
                $code = 'VALIDATION_ERROR';
                $message = 'Validation failed.';
                $errors = $exception->errors();
            } elseif ($exception instanceof AuthenticationException) {
                $status = 401;
                $code = 'AUTHENTICATION_REQUIRED';
                $message = 'Authentication is required.';
            } elseif ($exception instanceof AuthorizationException) {
                $status = 403;
                $code = 'FORBIDDEN';
                $message = 'You are not authorized to perform this action.';
            } elseif ($exception instanceof ModelNotFoundException || $exception instanceof MethodNotAllowedHttpException) {
                $status = $exception instanceof MethodNotAllowedHttpException ? 405 : 404;
                $code = $status === 405 ? 'METHOD_NOT_ALLOWED' : 'RESOURCE_NOT_FOUND';
                $message = $status === 405 ? 'Method not allowed.' : 'Resource not found.';
            } elseif ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                [$code, $message] = match ($status) {
                    400 => ['BAD_REQUEST', 'The request could not be understood.'],
                    401 => ['AUTHENTICATION_REQUIRED', 'Authentication is required.'],
                    403 => ['FORBIDDEN', 'You are not authorized to perform this action.'],
                    404 => ['RESOURCE_NOT_FOUND', 'Resource not found.'],
                    405 => ['METHOD_NOT_ALLOWED', 'Method not allowed.'],
                    429 => ['TOO_MANY_REQUESTS', 'Too many requests.'],
                    default => ['INTERNAL_SERVER_ERROR', 'An unexpected error occurred.'],
                };
            }

            $payload = [
                'message' => $message,
                'code' => $code,
                'request_id' => $requestId,
            ];

            if ($errors !== null) {
                $payload['errors'] = $errors;
            }

            return response()->json($payload, $status);
        });
    })->create();
