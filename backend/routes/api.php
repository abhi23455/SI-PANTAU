<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserDirectoryController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/password/forgot', [PasswordResetController::class, 'sendLink']);
    Route::post('/password/reset', [PasswordResetController::class, 'reset']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::patch('/profile', [ProfileController::class, 'update']);
});

Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::get('/users', [UserDirectoryController::class, 'index']);

    Route::get('/dashboard/admin-stats', [DashboardController::class, 'adminStats']);
    Route::get('/dashboard/personal-stats', [DashboardController::class, 'personalStats']);
    Route::get('/dashboard/task-report', [DashboardController::class, 'taskReport']);
    Route::get('/dashboard/task-report/export', [DashboardController::class, 'exportTaskReport']);
    Route::get('/activity-logs', [DashboardController::class, 'activity']);
    Route::post('/activity-logs', [DashboardController::class, 'logActivity']);

    Route::apiResource('groups', GroupController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('/groups/{group}/members', [GroupController::class, 'addMember']);
    Route::delete('/groups/{group}/members/{member}', [GroupController::class, 'removeMember']);

    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::post('/tasks/{task}/submissions', [TaskController::class, 'submitEvidence']);
    Route::patch('/task-submissions/{submission}/review', [TaskController::class, 'reviewEvidence']);
    Route::get('/task-submissions/{submission}/file', [TaskController::class, 'downloadEvidence']);
    Route::patch('/tasks/{task}', [TaskController::class, 'update']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
    Route::post('/tasks/{task}/comments', [TaskController::class, 'addComment']);
    Route::post('/tasks/{task}/subtasks', [TaskController::class, 'addSubtask']);
    Route::post('/tasks/{task}/history', [TaskController::class, 'addHistory']);
    Route::patch('/subtasks/{subtask}', [TaskController::class, 'updateSubtask']);
    Route::delete('/subtasks/{subtask}', [TaskController::class, 'deleteSubtask']);

    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::post('/admin/users', [AdminController::class, 'storeUser']);
    Route::patch('/admin/users/{user}', [AdminController::class, 'updateUser']);
    Route::delete('/admin/users', [AdminController::class, 'deleteUsers']);
});
