<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AppReleaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BankCardController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Payment\PaymentController;
use App\Http\Controllers\Payment\WebhookController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SystemAlertController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\VirtualBankController;
use App\Http\Resources\UserResource;


Route::prefix("v1")->group(function(){
    Route::any('/payments/webhook/{provider}', [WebhookController::class, 'handle']);

    Route::middleware('auth:sanctum')->group(function(){
        Route::apiResource("users", UsersController::class);
        Route::apiResource("investments", InvestmentController::class);
        Route::post('/investments/{i}/invest', [InvestmentController::class, 'invest']);
        Route::get('/investments/{i}/check', [InvestmentController::class, 'check']);

        Route::apiResource("groups", GroupController::class);
        Route::apiResource("invites", InviteController::class);
        Route::get("/groups/add-member/{group}/{memberId}", [GroupController::class, "addMember"]);
        Route::post('/groups/{group}/invite', [InviteController::class, 'inviteUser']);
        Route::post('/groups/{group}/request', [InviteController::class, 'requestToJoin']);
        Route::post('/groups/{group}/invites/{invite}/respond', [InviteController::class, 'respond']);
        Route::get('/groups/{group}/invites/pending', [InviteController::class, 'pendingForGroup']);
        Route::post('/groups/{group}/leave', [GroupController::class, 'leave']);
        Route::delete('/groups/{group}/members/{member}', [GroupController::class, 'removeMember']);
        Route::post('/groups/{group}/members/{member}/promote', [GroupController::class, 'promoteMember']);
        Route::post('/groups/{group}/members/{member}/demote', [GroupController::class, 'demoteMember']);


        Route::apiResource("banks", BankController::class);
        Route::apiResource("cards", BankCardController::class);

        Route::post('/images/base64', [ImageController::class, "storeBase64"]);
        // frontend payment endpoints
        Route::post('/payments/pay-contribution', [PaymentController::class, 'payContribution']);
        Route::post('/payments/deposit', [PaymentController::class, 'deposit']);
        Route::post('/payments/verify-card-payment', [PaymentController::class, 'verifyCardPayment']);
        Route::post('/payments/withdraw', [PaymentController::class, 'withdraw']);
        Route::get('/payments/banks-list', [PaymentController::class, 'banks']);
        Route::post('/payments/bank/look-up', [PaymentController::class, 'verifyBankAccount']);

        Route::resource('/transactions', TransactionController::class)->only(['index', 'store', 'show']);
        // Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/mark-read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/mark-unread', [NotificationController::class, 'markUnread']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

        Route::post("/virtual-banks/generate", [VirtualBankController::class, "generate"]);

        // referrals
        Route::get('/referral', [\App\Http\Controllers\ReferralController::class, 'index']);
        Route::post('/referral/generate', [\App\Http\Controllers\ReferralController::class, 'generate']);
        Route::post('/referral/payout', [\App\Http\Controllers\ReferralController::class, 'payout']);

        // Basic auth endpoints (session based)
        Route::prefix('auth')->middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout']);
            Route::get('/me', [\App\Http\Controllers\AuthController::class, 'me']);
            Route::post('/revoke-token', [\App\Http\Controllers\AuthController::class, 'revokeToken']);

        });
        Route::prefix("profile")->group(function(){
            Route::put('/update', [\App\Http\Controllers\ProfileController::class, 'update']);
        });
        // If you want it protected by auth:sanctum, wrap accordingly
        Route::post('/device-token', [\App\Http\Controllers\DeviceTokenController::class, 'store']);

        // routes/api.php (inside auth:sanctum middleware)
        Route::post('/admin/app/releases', [AppReleaseController::class, 'store']);
        Route::get('/admin/app/releases', [AppReleaseController::class, 'index']);
        Route::delete('/admin/app/releases', [AppReleaseController::class, 'destroy']);

        Route::prefix('admin')->group(function () {
            Route::get('/metrics', [AdminController::class, 'metrics']);
            Route::get('/transactions/recent', [AdminController::class, 'recentTransactions']);
            Route::get('/circles/top', [AdminController::class, 'topCircles']);
            Route::prefix('system-alerts')->controller(SystemAlertController::class)->group(function () {
                Route::get('/',                    'index');
                Route::get('/summary',             'summary');
                Route::post('/resolve-all',        'resolveAll');
                Route::post('/mark-all-read',      'markAllRead');
                Route::post('/{alert}/resolve',    'resolve');
                Route::post('/{alert}/read',       'markRead');
                Route::delete('/{alert}',          'destroy');
            });
        });

        Route::prefix('settings')->controller(SettingsController::class)->group(function(){
            Route::put('/notifications', 'updateNotifications');
            
        });


    });

    Route::prefix('auth')->group(function () {
            Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login']);
            Route::post('/register', [\App\Http\Controllers\AuthController::class, 'register']);
            Route::post('/token-login', [\App\Http\Controllers\AuthController::class, 'tokenLogin']);
            Route::post('/forgot-password', [\App\Http\Controllers\AuthController::class, 'forgotPassword']);
        });

    // payment webhooks (generic) - make public so external providers can reach it without auth
});


Route::get('/user', function (Request $request) {
    return new UserResource($request->user());
})->middleware('auth:sanctum');



    // routes/web.php or api.php (protect with auth middleware as needed)
    Route::get('/releases/{id}/download', [AppReleaseController::class, 'download'])->name('releases.download');
    Route::get('/app/releases/latest', [AppReleaseController::class, 'latest']);
