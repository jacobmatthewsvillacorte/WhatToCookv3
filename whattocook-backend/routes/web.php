<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminRecipeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/admin/login', [AdminAuthController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [AdminAuthController::class, 'store'])->middleware('throttle:auth')->name('admin.login.store');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/recipes')->name('home');
    Route::get('/nutrition/search', [AdminRecipeController::class, 'nutritionSearch'])->name('nutrition.search');
    Route::resource('recipes', AdminRecipeController::class)->except('show');
    Route::post('/logout', [AdminAuthController::class, 'destroy'])->name('logout');
});
