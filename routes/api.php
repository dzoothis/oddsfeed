<?php

use App\Http\Controllers\Api\PinnacleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BetTypesController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\MatchesController;

Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::get('/sports', [PinnacleController::class, 'getSports']);
    Route::get('/leagues', [PinnacleController::class, 'getLeagues']);
    Route::get('/markets', [PinnacleController::class, 'getMarkets']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);

    Route::get('/bet-types/sports', [BetTypesController::class, 'getSports']);
    Route::get('/bet-types/leagues/{sportId}', [BetTypesController::class, 'getLeagues']);
    Route::get('/bet-types', [BetTypesController::class, 'getBetTypesBySport']);
    Route::get('/bet-types/matches', [BetTypesController::class, 'getMatches']);
    Route::get('/bet-types/match/{matchId}', [BetTypesController::class, 'getBetTypes']);

    Route::get('/reference/bet-types', [ReferenceController::class, 'getBetTypes']);
    Route::get('/reference/sports', [ReferenceController::class, 'getSports']);
    Route::get('/reference/leagues/search', [ReferenceController::class, 'searchLeagues']);

    Route::post('/matches', [MatchesController::class, 'getMatches']);
    Route::post('/matches/refresh', [MatchesController::class, 'manualRefresh']);
    Route::get('/matches/{matchId}/details', [MatchesController::class, 'getMatchDetails']);
    Route::get('/matches/{matchId}/odds', [MatchesController::class, 'getMatchOdds']);
});
