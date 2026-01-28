<?php

use Illuminate\Support\Facades\Route;

// No web routes - API only
Route::get('/', function () {
    return response()->json([
        'name' => 'POS API Stub',
        'status' => 'running',
    ]);
});
