<?php

use App\Http\Controllers\AkunController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Nama rutenya dipertahankan (profile.edit) karena bilah atas dan
    // beberapa komponen bawaan Inertia menunjuk ke situ.
    Route::get('akun', [AkunController::class, 'edit'])->name('profile.edit');
    Route::patch('akun', [AkunController::class, 'update'])->name('profile.update');
    Route::put('akun/sandi', [AkunController::class, 'sandi'])->name('password.update');
});
