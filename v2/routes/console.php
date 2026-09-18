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
