<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\GiveawayController;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

$routeStateMiddleware = [
    'tg.auth:strict',
    'throttle:api',
    StartSession::class,
    'csrf',
];

Route::get('/csrf-token', function (\Illuminate\Http\Request $request) {
    $request->session()->regenerateToken();

    return response()->json(['ok' => true, 'token' => csrf_token()]);
})->middleware(['throttle:api', 'tg.auth:strict', StartSession::class]);

Route::middleware(['tg.auth:strict', 'throttle:api'])->group(function () use ($routeStateMiddleware) {
    Route::prefix('giveaway')->group(function () use ($routeStateMiddleware) {
        Route::middleware($routeStateMiddleware)->group(function () {
            Route::post('/', [GiveawayController::class, 'store']);
            Route::post('/join', [GiveawayController::class, 'join'])->middleware('throttle:30,1');
            Route::post('/draw', [GiveawayController::class, 'draw']);
            Route::post('/check-subscription', [GiveawayController::class, 'checkSubscription'])->middleware('throttle:60,1');
            Route::post('/check-nickname', [GiveawayController::class, 'checkNickname'])->middleware('throttle:10,1');
            Route::post('/launch', [GiveawayController::class, 'launch']);
            Route::post('/update-date', [GiveawayController::class, 'updateDate']);
            Route::post('/broadcast', [GiveawayController::class, 'broadcast'])->middleware('throttle:3,1');
            Route::post('/attach-channel', [GiveawayController::class, 'attachChannel']);
            Route::post('/detach-channel', [GiveawayController::class, 'detachChannel']);
            Route::post('/upload-photo', [GiveawayController::class, 'uploadPhoto']);
            Route::post('/request-channel', [GiveawayController::class, 'requestChannelAdd']);
            Route::post('/update-draft', [GiveawayController::class, 'updateDraft']);
            Route::post('/refresh-posts', [GiveawayController::class, 'refreshPosts']);
            Route::post('/submit-task', [GiveawayController::class, 'submitTask']);
            Route::post('/task-decision', [GiveawayController::class, 'taskDecision']);
            Route::post('/delete', [GiveawayController::class, 'destroy']);
        });

        Route::get('/{publicId}', [GiveawayController::class, 'show']);
    });

    Route::get('/giveaways', [GiveawayController::class, 'userGiveaways']);
    Route::get('/giveaways/all', [GiveawayController::class, 'all']);

    Route::prefix('channels')->group(function () use ($routeStateMiddleware) {
        Route::get('/', [GiveawayController::class, 'listChannels']);

        Route::middleware($routeStateMiddleware)->group(function () {
            Route::post('/connect', [GiveawayController::class, 'connectChannel']);
            Route::post('/connect-request', [GiveawayController::class, 'requestChannelConnect']);
            Route::post('/disconnect', [GiveawayController::class, 'disconnectChannel']);
        });
    });
});

Route::get('/bot-info', [GiveawayController::class, 'botInfo']);

Route::get('/source-hash', function () {
    $files = [
        'Giveaway.php (алгоритм выбора)' => app_path('Models/Giveaway.php'),
        'GiveawayDrawAuditService.php (подпись)' => app_path('Services/GiveawayDrawAuditService.php'),
        'FinishExpiredGiveaways.php (автозавершение)' => app_path('Console/Commands/FinishExpiredGiveaways.php'),
    ];
    $hashes = [];
    foreach ($files as $label => $path) {
        $hashes[] = [
            'file' => $label,
            'sha256' => file_exists($path) ? hash_file('sha256', $path) : 'file_not_found',
        ];
    }
    return response()->json(['ok' => true, 'hashes' => $hashes]);
})->middleware('throttle:10,1');

Route::get('/giveaway/{publicId}/verify-integrity', [GiveawayController::class, 'verifyIntegrity']);

Route::post('/phone/verify', [GiveawayController::class, 'phoneVerify'])->middleware($routeStateMiddleware);
Route::get('/phone/check', [GiveawayController::class, 'phoneCheck'])->middleware(['throttle:api', 'tg.auth:strict']);

Route::prefix('analytics')->middleware(['throttle:api', 'tg.auth:strict'])->group(function () {
    Route::get('/overview', [AnalyticsController::class, 'overview']);
    Route::get('/admin/{publicId}', [AnalyticsController::class, 'statisticForAdmin']);
    Route::get('/{publicId}', [AnalyticsController::class, 'giveawayAnalytics']);
    Route::get('/{publicId}/csv-token', [AnalyticsController::class, 'csvToken']);
});

Route::get('/analytics/{publicId}/csv', [AnalyticsController::class, 'exportCsv'])->middleware('throttle:api');

Route::prefix('admin')->middleware(['throttle:5,1', StartSession::class, 'csrf'])->group(function () {
    Route::post('/login', [AdminController::class, 'login']);
    Route::get('/giveaways', [AdminController::class, 'giveaways']);
    Route::get('/giveaway/{publicId}/participants', [AdminController::class, 'participants']);
    Route::put('/participant/{id}', [AdminController::class, 'updateParticipant']);
    Route::delete('/participant/{id}', [AdminController::class, 'deleteParticipant']);
});
