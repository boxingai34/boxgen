<?php

namespace App\Http\Controllers;

use App\Services\AgentMonitor;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MONITOR AGENT — peta kerja agent dan alat produksi kedua merek.
 *
 * Halamannya dibuka sekali dengan isi lengkap, lalu memanggil data()
 * tiap beberapa detik (polling) supaya simpul yang sedang bekerja
 * menyala tanpa memuat ulang halaman. Semua isinya dibaca dari berkas
 * di komputer pemilik studio — lihat App\Services\AgentMonitor.
 */
class AgentMonitorController extends Controller
{
    public function halaman(): Response
    {
        AgentMonitor::bersihkan();

        return Inertia::render('Monitor/Agent', [
            'awal'  => AgentMonitor::data(),
            'jeda'  => 4,
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json(AgentMonitor::data());
    }
}
