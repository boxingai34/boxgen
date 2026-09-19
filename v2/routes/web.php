<?php

use App\Http\Controllers\CeritaController;
use App\Http\Controllers\CmsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GambarController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\ReverseController;
use App\Http\Controllers\RiwayatController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Dua dunia di satu domain.
 *
 *   /            halaman depan publik — memperkenalkan BoxinGenerated
 *   /generator   alat prompt, tertutup, untuk pemilik dan teman dekatnya
 *
 * Nama rutenya sengaja TIDAK diberi awalan (tetap "dashboard", "login",
 * "riwayat") supaya seluruh halaman Vue yang sudah ada tidak perlu
 * disentuh: route('dashboard') sekarang menghasilkan /generator, dan
 * pengalihan bawaan Laravel untuk yang belum masuk (route('login')) ikut
 * pindah ke /generator/login dengan sendirinya.
 */

Route::get('/', [LandingController::class, 'show'])->name('home');

Route::prefix('generator')->group(function () {
    Route::middleware(['auth'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // ---- Prompt Generator (dari pilihan) ----
        Route::get('prompt', [PromptController::class, 'halaman'])->name('prompt');
        Route::post('prompt/susun', [PromptController::class, 'susun'])->name('prompt.susun');
        Route::post('prompt/isi-otomatis', [PromptController::class, 'isiOtomatis'])->name('prompt.isi');
        Route::get('prompt/judul', [PromptController::class, 'judul'])->name('prompt.judul');
        Route::get('prompt/karakter', [PromptController::class, 'cariKarakter'])->name('prompt.karakter');
        Route::get('prompt/tag', [PromptController::class, 'cariTag'])->name('prompt.tag');
        Route::get('prompt/bawaan', [PromptController::class, 'bawaanSlot'])->name('prompt.bawaan');
        Route::get('prompt/latar', [PromptController::class, 'latarSaran'])->name('prompt.latar');

        // ---- Dari Gambar/Video (reverse prompt) ----
        Route::get('reverse', [ReverseController::class, 'halaman'])->name('reverse');
        Route::post('reverse/ambil', [ReverseController::class, 'ambilUrl'])->name('reverse.ambil');
        Route::post('reverse/baca', [ReverseController::class, 'baca'])->name('reverse.baca');
        Route::post('reverse/susun', [ReverseController::class, 'susun'])->name('reverse.susun');

        // ---- Buat gambarnya (prompt langsung digambar) ----
        Route::post('gambar/tokoh', [GambarController::class, 'tokoh'])->name('gambar.tokoh');
        Route::post('gambar/latar', [GambarController::class, 'latar'])->name('gambar.latar');

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
        Route::get('alat-lama', fn () => Inertia::render('AlatLama', [
            'basisLama' => config('app.legacy_url'),
        ]))->name('alat-lama');

        // ---- CMS halaman depan (admin) ----
        Route::middleware('admin')->prefix('cms')->group(function () {
            Route::get('/', [CmsController::class, 'edit'])->name('cms');
            Route::post('/', [CmsController::class, 'update'])->name('cms.simpan');
            Route::post('unggah', [CmsController::class, 'unggah'])->name('cms.unggah');
            Route::delete('unggah', [CmsController::class, 'hapusUnggahan'])->name('cms.unggah.hapus');
            Route::get('youtube', [CmsController::class, 'youtube'])->name('cms.youtube');
            Route::get('patreon', [CmsController::class, 'patreon'])->name('cms.patreon');
            Route::get('deviantart', [CmsController::class, 'deviantart'])->name('cms.deviantart');
        });
    });

    require __DIR__.'/settings.php';
    require __DIR__.'/auth.php';
});
