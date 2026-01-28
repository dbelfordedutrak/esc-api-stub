<?php

/**
 * ============================================================================
 * POS API Routes
 * ============================================================================
 *
 * API for Vue POS system.
 * Uses custom session tokens stored in ww_pos_station_sessions.
 *
 * ============================================================================
 */

use App\Http\Controllers\POSController;
use App\Http\Controllers\POSStudentController;
use App\Http\Controllers\POSMenuController;
use App\Http\Controllers\POSTransactionController;
use App\Http\Controllers\POSPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes (No Auth)
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return response()->json([
        'message' => 'POS API Stub',
        'status' => 'ok',
    ]);
});

// Health check (public)
Route::get('/pos/health', [POSController::class, 'health']);

// Login - returns session token
Route::post('/pos/login', [POSController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Protected Routes (Requires valid session token)
|--------------------------------------------------------------------------
| Token validation is handled in each controller method via StationSession::findByToken()
| TODO: Create middleware for cleaner auth handling
*/

// Logout
Route::post('/pos/logout', [POSController::class, 'logout']);

// Lines - list available lines for user
Route::get('/pos/lines', [POSController::class, 'lines']);

// Line settings - get full config for a specific line
Route::get('/pos/lines/{mealType}/{lineNum}/settings', [POSController::class, 'lineSettings']);

// Open a line - links session to line and marks line as open
Route::post('/pos/lines/{mealType}/{lineNum}/open', [POSController::class, 'openLine']);

// Menu items for a specific line
Route::get('/pos/lines/{mealType}/{lineNum}/menu', [POSMenuController::class, 'index']);

// Reference Data (Download to POS)
Route::get('/pos/lines/{mealType}/{lineNum}/students', [POSStudentController::class, 'index']);

// Transactional Data (Upload from POS)
Route::post('/pos/transactions', [POSTransactionController::class, 'store']);
Route::post('/pos/payments', [POSPaymentController::class, 'store']);
Route::post('/pos/deletions', [POSTransactionController::class, 'storeDeletions']);
