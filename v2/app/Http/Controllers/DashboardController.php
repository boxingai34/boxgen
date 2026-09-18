<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Riwayat;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = (int) $request->user()->id;

        $daftar = Riwayat::daftar($userId, 1);

        $mode = DB::table('generations')
            ->select('mode', DB::raw('COUNT(*) as jumlah'))
            ->where('user_id', $userId)
            ->groupBy('mode')
            ->pluck('jumlah', 'mode');

        return Inertia::render('Dashboard', [
            'statistik' => [
                'total'   => (int) $daftar['total'],
                'minggu'  => (int) DB::table('generations')
                    ->where('user_id', $userId)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
                'cerita'  => (int) ($mode['cerita'] ?? 0),
                'video'   => (int) (($mode['reverse_video'] ?? 0) + ($mode['pertandingan'] ?? 0)),
            ],
            'terbaru' => collect($daftar['items'])->take(6)->map(fn (array $r) => [
                'id'      => (int) $r['id'],
                'judul'   => $r['title'] ?: 'Tanpa judul',
                'mode'    => $r['mode'],
                'target'  => $r['target'],
                'waktu'   => $r['created_at'],
                'cuplik'  => mb_substr((string) $r['output'], 0, 160),
            ])->values(),
        ]);
    }
}
