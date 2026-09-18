<?php

use App\Http\Controllers\CeritaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RiwayatController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Halaman depan: etalase untuk yang belum masuk, dasbor untuk yang sudah.
 */
Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('dashboard')
        : Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ---- Rancang Pertandingan (dari cerita) ----
    Route::get('rancang', [CeritaController::class, 'halaman'])->name('rancang');
    Route::post('rancang/baca', [CeritaController::class, 'baca'])->name('rancang.baca');
    Route::post('rancang/susun', [CeritaController::class, 'susun'])->name('rancang.susun');
    Route::post('rancang/simpan', [CeritaController::class, 'simpan'])->name('rancang.simpan');
    Route::get('rancang/buka/{id}', [CeritaController::class, 'buka'])->whereNumber('id')->name('rancang.buka');

    // ---- Riwayat ----
    Route::get('riwayat', [RiwayatController::class, 'index'])->name('riwayat');
    Route::get('riwayat/{id}', [RiwayatController::class, 'show'])->whereNumber('id')->name('riwayat.show');
    Route::delete('riwayat/{id}', [RiwayatController::class, 'destroy'])->whereNumber('id')->name('riwayat.hapus');

    // ---- Alat yang belum pindah ----
    // Jujur saja daripada memberi halaman kosong: daftarnya ada di sini,
    // tombolnya membuka versi lama yang masih berjalan penuh.
    Route::get('alat-lama', fn () => Inertia::render('AlatLama', [
        'basisLama' => config('app.legacy_url'),
    ]))->name('alat-lama');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
