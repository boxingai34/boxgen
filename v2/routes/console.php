<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Halaman depan mengambil sendiri video, angka, dan pos terbaru. Jadwal ini
// membuat servernya yang menunggu sumbernya, bukan pengunjung. Aktif hanya
// kalau `php artisan schedule:run` dipanggil cron/Task Scheduler tiap menit;
// kalau tidak, halaman depan tetap memperbarui dirinya waktu dikunjungi.
Schedule::command('landing:segarkan')->everyThirtyMinutes()->withoutOverlapping();

// DISCOVER: art boxing terbaru dari Pixiv, DeviantArt, dan booru. Satu
// putaran = ±15 pencarian + gambar kecil karya yang baru, jadi ringan.
// Tanpa schedule:run pun halaman DISCOVER memulainya sendiri waktu dibuka
// kalau crawl terakhir sudah lewat 30 menit.
Schedule::command('discover:crawl')->everyThirtyMinutes()->withoutOverlapping(60)->runInBackground();
