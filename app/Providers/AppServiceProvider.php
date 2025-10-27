<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Throwable;
use App\Models\Transaction;
use App\Observers\TransactionObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Return structured JSON for API errors when request expects JSON
        $this->app->make('Illuminate\Foundation\Exceptions\Handler')->renderable(function (ValidationException $e, Request $request) {
            if ($request->wantsJson()) {
                $errors = $e->errors();
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'The given data was invalid.',
                    'errors' => $errors,
                ], 422);
            }
        });

        $this->app->make('Illuminate\Foundation\Exceptions\Handler')->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Unauthenticated.',
                    'errors' => null,
                ], 401);
            }
        });

        // Fallback for other exceptions when JSON requested
        $this->app->make('Illuminate\Foundation\Exceptions\Handler')->renderable(function (Throwable $e, Request $request) {
            if ($request->wantsJson()) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Server error',
                    'errors' => null,
                ], $status);
            }
        });

        // Register model observers
        Transaction::observe(TransactionObserver::class);
    }
}
