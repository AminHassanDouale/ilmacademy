<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Blade;
use Throwable;

class Handler extends ExceptionHandler
{
    // Probably you don't waana report these.
    protected $dontReport = [
        AppException::class,
        RequiresLoginException::class
    ];

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    // Exceptions that returns JSON pairs with `resources/js/app.js` logic
    public function render($request, Throwable $e)
    {
        $status = parent::render($request, $e)->getStatusCode();
        $isLivewire = $request->hasHeader('X-Livewire');

        // 419 error
        if ($status == 419) {
            return response()->json(['status' => 419], 500);
        }

        // If it requires inline login, redirect to login page
        if ($e instanceof RequiresLoginException) {
            return response()->json(['redirectTo' => "/login?redirect_url=$e->redirect_url"], 500);
        }

        // If it is a custom app exception, uses Toast
        if ($e instanceof AppException && $isLivewire) {
            $toast = [
                'title' => $e->getMessage(),
                'description' => $e->description,
                'position' => 'toast-top toast-end',
                'icon' => Blade::render("<x-mary-icon class='w-7 h-7' name='o-x-circle' />"),
                'timeout' => '3000',
                'css' => 'alert-error'
            ];

            return response()->json(['toast' => $toast], 500);
        }

        // If it is local environment, uses default error render
        // Or auth exception, to make default Laravel redirect to login happen
        if (app()->environment() == 'local' || $e instanceof AuthenticationException) {
            return parent::render($request, $e);
        }

        // Afterward this point it display a custom nice error view, for non-local environments

        $title = match ($status) {
            503 => "Come back soon.",
            500 => "Something went wrong on our side.",
            404 => "Page not found.",
            401 => "Not authenticated.",
            403 => "Permission denied asfsafsa.",
            default => "Unknown error."
        };

        return response()->view('errors.error', [
            'isLivewire' => $isLivewire,
            'title' => $title,
            'detail' => $e->getMessage()
        ], 500);
    }
}
