<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

/*
 * Masuk, daftar, keluar — itu saja.
 *
 * Pemulihan kata sandi lewat email sengaja tidak ada: aplikasi lama pun
 * tidak punya, akunnya disetujui dan diatur admin, dan surat keluar belum
 * disetel di hosting. Menyediakan tombol yang mengirim surat ke mana-mana
 * lebih buruk daripada tidak menyediakannya.
 */

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
