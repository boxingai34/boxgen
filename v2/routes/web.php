<?php

use App\Http\Controllers\AgentFbxController;
use App\Http\Controllers\CeritaController;
use App\Http\Controllers\CmsController;
use App\Http\Controllers\KatalogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscoverAdeganController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\FemboxController;
use App\Http\Controllers\GambarController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\MixedFightController;
use App\Http\Controllers\MonthlyShowdownController;
use App\Http\Controllers\ProduksiController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\ReverseController;
use App\Http\Controllers\RiwayatController;
use App\Http\Controllers\UbahController;
use App\Http\Controllers\WaifuCrushedController;
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

        // ---- Ubah Prompt (metadata gambar NovelAI disunting) ----
        Route::get('ubah', [UbahController::class, 'halaman'])->name('ubah');
        Route::post('ubah/ambil', [UbahController::class, 'ambil'])->name('ubah.ambil');
        Route::post('ubah/terapkan', [UbahController::class, 'terapkan'])->name('ubah.terapkan');

        // ---- FemBox Reference: tiga sub-menu ----
        // Female Fight = lembar acuan petinju wanita (NovelAI V5), alamatnya
        // tetap /fembox supaya tautan dan riwayat lama tidak putus.
        Route::get('fembox', [FemboxController::class, 'halaman'])->name('fembox');
        Route::post('fembox/susun', [FemboxController::class, 'susun'])->name('fembox.susun');

        // Waifu Crushed = templat 4 adegan (pose awal, dihajar, pukulan
        // terakhir, KO). Dirangkai kode, tanpa model.
        Route::get('fembox/waifu-crushed', [WaifuCrushedController::class, 'halaman'])->name('fembox.waifu');
        Route::post('fembox/waifu-crushed/susun', [WaifuCrushedController::class, 'susun'])->name('fembox.waifu.susun');

        // Mixed Fight = alur komik strip 3–5 panel dari cerita singkat;
        // pasangannya bisa wanita vs pria, wanita vs wanita, pria vs pria.
        Route::get('fembox/mixed-fight', [MixedFightController::class, 'halaman'])->name('fembox.mixed');
        Route::post('fembox/mixed-fight/susun', [MixedFightController::class, 'susun'])->name('fembox.mixed.susun');

        // Monthly Showdown = dua langkah. Kostum & Lawan: boxing attire tiap
        // karakter dirancang model, lawannya diundi di halaman. Referensi
        // Pose: satu laga dibawa ke katalog pose, dirangkai kode tanpa model.
        Route::get('fembox/monthly-showdown', [MonthlyShowdownController::class, 'halaman'])->name('fembox.showdown');
        Route::post('fembox/monthly-showdown/rancang', [MonthlyShowdownController::class, 'rancang'])->name('fembox.showdown.rancang');
        Route::get('fembox/monthly-showdown/pose', [MonthlyShowdownController::class, 'halamanPose'])->name('fembox.showdown.pose');
        Route::post('fembox/monthly-showdown/pose/susun', [MonthlyShowdownController::class, 'susunPose'])->name('fembox.showdown.pose.susun');

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

        // ---- DISCOVER: art boxing dari Pixiv, DeviantArt, booru, X (admin) ----
        // Admin saja: isinya termasuk R-18 (diburamkan di halaman), dan
        // gambarnya disimpan di luar public/.
        Route::middleware('admin')->prefix('discover')->group(function () {
            Route::get('/', [DiscoverController::class, 'halaman'])->name('discover');
            Route::get('daftar', [DiscoverController::class, 'daftar'])->name('discover.daftar');
            Route::get('gambar/{id}', [DiscoverController::class, 'gambar'])->whereNumber('id')->name('discover.gambar');
            Route::get('besar/{id}/{i}', [DiscoverController::class, 'besar'])->whereNumber(['id', 'i'])->name('discover.besar');
            Route::post('karya/{id}/status', [DiscoverController::class, 'status'])->whereNumber('id')->name('discover.status');
            Route::post('karya/{id}/ai', [DiscoverController::class, 'ai'])->whereNumber('id')->name('discover.ai');
            Route::post('crawl', [DiscoverController::class, 'crawl'])->name('discover.crawl');
            Route::get('jalan', [DiscoverController::class, 'jalan'])->name('discover.jalan');
            Route::post('tweet', [DiscoverController::class, 'tweet'])->name('discover.tweet');
            Route::post('kata', [DiscoverController::class, 'kata'])->name('discover.kata');

            // Boxing Scene: adegan tinju wanita di anime, manga, manhwa, manhua, kartun, komik.
            Route::get('scene', [DiscoverAdeganController::class, 'halaman'])->name('discover.adegan');
            Route::get('scene/daftar', [DiscoverAdeganController::class, 'daftar'])->name('discover.adegan.daftar');
            Route::get('scene/gambar/{id}', [DiscoverAdeganController::class, 'gambar'])->whereNumber('id')->name('discover.adegan.gambar');
            Route::post('scene/{id}/status', [DiscoverAdeganController::class, 'status'])->whereNumber('id')->name('discover.adegan.status');
            Route::post('scene/{id}/jenis', [DiscoverAdeganController::class, 'jenis'])->whereNumber('id')->name('discover.adegan.jenis');
            Route::post('scene/crawl', [DiscoverAdeganController::class, 'crawl'])->name('discover.adegan.crawl');
            Route::get('scene/jalan', [DiscoverAdeganController::class, 'jalan'])->name('discover.adegan.jalan');
        });

        // ---- AGENT FBX: hasil agent FEMBOX (admin) ----
        // Data bisnis pemilik studio — kandidat poll, roster, tren — jadi
        // hanya admin, bukan semua yang punya akun generator.
        Route::middleware('admin')->prefix('fbx')->group(function () {
            Route::get('/', [AgentFbxController::class, 'scout'])->name('fbx');
            Route::get('roster', [AgentFbxController::class, 'roster'])->name('fbx.roster');
            Route::get('tren', [AgentFbxController::class, 'tren'])->name('fbx.tren');
            Route::get('gambar/{nama}', [AgentFbxController::class, 'gambar'])
                ->where('nama', '[0-9]+-[kb]\.[a-z0-9]+')->name('fbx.gambar');
            Route::post('kandidat/{id}/status', [AgentFbxController::class, 'status'])->whereNumber('id')->name('fbx.status');
            Route::post('sinkron', [AgentFbxController::class, 'sinkron'])->name('fbx.sinkron');
        });

        // ---- PRODUKSI: dari prompt pack jadi sampai take terpilih (admin, PC produksi) ----
        // Generate lewat API Higgsfield (memakai saldo), jadi admin saja; dan
        // hanya di PC yang punya folder PRODUCTION — di tempat lain 404
        // (produksi.lokal). Kontraknya di RENCANA-PRODUKSI.md §7.
        Route::middleware(['admin', 'produksi.lokal'])->prefix('produksi')->group(function () {
            Route::get('/', [ProduksiController::class, 'papan'])->name('produksi');
            Route::get('antrian', [ProduksiController::class, 'antrian'])->name('produksi.antrian');
            Route::get('pengaturan', [ProduksiController::class, 'pengaturan'])->name('produksi.pengaturan');
            Route::post('uji-kunci', [ProduksiController::class, 'ujiKunci'])->name('produksi.uji');
            Route::post('impor', [ProduksiController::class, 'impor'])->name('produksi.impor');
            Route::get('status', [ProduksiController::class, 'status'])->name('produksi.status');
            Route::post('perkiraan', [ProduksiController::class, 'perkiraan'])->name('produksi.perkiraan');
            Route::post('kirim', [ProduksiController::class, 'kirim'])->name('produksi.kirim');
            Route::get('pack/{id}', [ProduksiController::class, 'pack'])->whereNumber('id')->name('produksi.pack');
            Route::post('pack/{id}/segarkan', [ProduksiController::class, 'segarkan'])->whereNumber('id')->name('produksi.pack.segarkan');
            Route::post('pack/{id}/umur', [ProduksiController::class, 'konfirmasiUmur'])->whereNumber('id')->name('produksi.pack.umur');
            Route::post('pack/{id}/buka-klip', [ProduksiController::class, 'bukaKlip'])->whereNumber('id')->name('produksi.pack.buka');
            Route::get('pack/{id}/kirim', [ProduksiController::class, 'halamanKirim'])->whereNumber('id')->name('produksi.pack.kirim');
            Route::get('pack/{id}/hasil', [ProduksiController::class, 'hasil'])->whereNumber('id')->name('produksi.pack.hasil');
            Route::post('pack/{id}/ekspor', [ProduksiController::class, 'ekspor'])->whereNumber('id')->name('produksi.pack.ekspor');
            Route::post('pack/{id}/serahkan', [ProduksiController::class, 'serahkan'])->whereNumber('id')->name('produksi.pack.serahkan');
            Route::post('pack/{id}/buka-folder', [ProduksiController::class, 'bukaFolder'])->whereNumber('id')->name('produksi.pack.folder');
            Route::get('pack/{id}/gambar/{nama}', [ProduksiController::class, 'gambar'])->whereNumber('id')
                ->where('nama', '[^/\\\\]+')->name('produksi.pack.gambar');
            Route::post('item/{id}/manual', [ProduksiController::class, 'tambahManual'])->whereNumber('id')->name('produksi.item.manual');
            Route::get('take/{id}', [ProduksiController::class, 'review'])->whereNumber('id')->name('produksi.take');
            Route::get('take/{id}/video', [ProduksiController::class, 'video'])->whereNumber('id')->name('produksi.take.video');
            Route::get('take/{id}/bingkai/{n}', [ProduksiController::class, 'bingkai'])->whereNumber(['id', 'n'])->name('produksi.take.bingkai');
            Route::post('take/{id}/nilai', [ProduksiController::class, 'nilai'])->whereNumber('id')->name('produksi.take.nilai');
            Route::post('take/{id}/putuskan', [ProduksiController::class, 'putuskan'])->whereNumber('id')->name('produksi.take.putuskan');
            Route::post('take/{id}/periksa', [ProduksiController::class, 'periksaLagi'])->whereNumber('id')->name('produksi.take.periksa');
        });

        // ---- CMS halaman depan (admin) ----
        Route::middleware('admin')->prefix('cms')->group(function () {
            Route::get('/', [CmsController::class, 'edit'])->name('cms');
            Route::post('/', [CmsController::class, 'update'])->name('cms.simpan');
            Route::post('unggah', [CmsController::class, 'unggah'])->name('cms.unggah');
            Route::delete('unggah', [CmsController::class, 'hapusUnggahan'])->name('cms.unggah.hapus');
            Route::get('youtube', [CmsController::class, 'youtube'])->name('cms.youtube');
            Route::get('patreon', [CmsController::class, 'patreon'])->name('cms.patreon');
            Route::get('deviantart', [CmsController::class, 'deviantart'])->name('cms.deviantart');

            // ---- Master katalog: menyunting modul tanpa menyentuh berkas data ----
            Route::get('katalog', [KatalogController::class, 'halaman'])->name('cms.katalog');
            Route::get('katalog/daftar', [KatalogController::class, 'daftar'])->name('cms.katalog.daftar');
            Route::post('katalog/simpan', [KatalogController::class, 'simpan'])->name('cms.katalog.simpan');
            Route::post('katalog/buka', [KatalogController::class, 'bukaKunci'])->name('cms.katalog.buka');
            Route::post('katalog/gambar', [KatalogController::class, 'gambar'])->name('cms.katalog.gambar');
            Route::delete('katalog/gambar', [KatalogController::class, 'hapusGambar'])->name('cms.katalog.gambar.hapus');
        });
    });

    require __DIR__.'/settings.php';
    require __DIR__.'/auth.php';
});
