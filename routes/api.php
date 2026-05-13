<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WorkoutController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth.api:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    
    Route::get('/workouts', [WorkoutController::class, 'index']);
    Route::post('/workouts/run', [WorkoutController::class, 'storeRun']);
    Route::post('/workouts/gym', [WorkoutController::class, 'storeGym']);
    Route::put('/workouts/{id}', [WorkoutController::class, 'update']);
    Route::delete('/workouts/{id}', [WorkoutController::class, 'destroy']);
    
    Route::get('/comparison', [WorkoutController::class, 'getCalorieComparison']);
});