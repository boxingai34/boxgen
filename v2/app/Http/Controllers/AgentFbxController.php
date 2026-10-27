<?php

namespace App\Http\Controllers;

use App\Services\AgentFbx;
use App\Services\FbxGambar;
use App\Services\FbxPoll;
use App\Services\FbxPollGambar;
use DomainException;
use GambarAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * AGENT FBX — rumah semua agent FEMBOX di halaman admin.
 *
 * Tiga halaman sekarang: Scout (kandidat mingguan), Roster (siapa yang
 * pernah tampil), Tren (sinyal Danbooru). Agent berikutnya (Penyusun Poll,
 * Kurator Showdown, Paket Artis, ...) masuk ke sini sebagai sub-menu baru.
 *
 * Datanya dibaca dari tabel fbx_*, yang diisi `php artisan fbx:sinkron`
 * dari folder Drive — lihat App\Services\AgentFbx.
 */
class AgentFbxController extends Controller
{
    public function scout(Request $request): Response
    {
        $mingguAda = DB::table('fbx_kandidat')->distinct()->pluck('minggu')
            ->merge(DB::table('fbx_riset')->pluck('minggu'))
            ->unique()->sortDesc()->values();

        $minggu = (string) $request->query('m', '');
        if ($minggu !== 'semua' && !$mingguAda->contains($minggu)) {
            $minggu = (string) ($mingguAda->first() ?? '');
        }

        $q = DB::table('fbx_kandidat');
        if ($minggu !== 'semua') {
            $q->where('minggu', $minggu);
        }
        // Diurutkan di PHP, bukan FIELD() MySQL, supaya tes di SQLite memakai jalur yang sama.
        $baris = $q->get()->sort(fn ($a, $b) => [self::urutan(AgentFbx::KATEGORI, $a->kategori), $a->id]
            <=> [self::urutan(AgentFbx::KATEGORI, $b->kategori), $b->id])->values();

        $gambar = FbxGambar::peta($baris->pluck('danbooru_tag')->merge($baris->pluck('pasangan_tag'))->filter()->all());
        $jejak = $this->jejakRoster();

        $kandidat = $baris->map(fn ($r) => [
            'id'         => (int) $r->id,
            'minggu'     => $r->minggu,
            'kategori'   => $r->kategori,
            'karakter'   => $r->karakter,
            'sumber'     => $r->sumber,
            'pasangan'   => $r->pasangan,
            'tag'        => $r->danbooru_tag,
            'tagPasangan' => $r->pasangan_tag,
            'tingkat'    => $r->tingkat_umur,
            'umur'       => $r->umur_canon,
            'tinggi'     => $r->tinggi_cm,
            'alasan'     => (string) $r->alasan,
            'kostum'     => (string) $r->ide_kostum,
            'status'     => $r->status,
            'bukti'      => $r->bukti_url,
            'gambar'     => $gambar[$r->danbooru_tag] ?? [],
            'gambarPasangan' => $gambar[$r->pasangan_tag] ?? [],
            'tampil'     => $this->cariJejak($jejak, $r->danbooru_tag, $r->karakter),
            'tampilPasangan' => $r->pasangan !== '' ? $this->cariJejak($jejak, $r->pasangan_tag, $r->pasangan) : [],
            'poll'       => FbxPollGambar::info($r),
        ])->values();

        $riset = $minggu !== 'semua' ? DB::table('fbx_riset')->where('minggu', $minggu)->first() : null;

        return Inertia::render('Fbx/Scout', [
            'minggu'     => $minggu,
            'rentang'    => $minggu !== 'semua' && $minggu !== '' ? AgentFbx::rentangMinggu($minggu) : '',
            'daftarMinggu' => $mingguAda->map(fn ($m) => ['minggu' => $m, 'rentang' => AgentFbx::rentangMinggu($m)])->all(),
            'kandidat'   => $kandidat,
            'ringkasan'  => (string) ($riset->ringkasan ?? ''),
            'laporan'    => (string) ($riset->isi ?? ''),
            'naik'       => $this->trenTeratas(8),
            'bisaSinkron' => AgentFbx::bisaSinkron(),
            'folder'     => AgentFbx::folder(),
            'kategoriRoster' => AgentFbx::KATEGORI_ROSTER,
            'poll'       => FbxPoll::daftar(),
            'bisaPoll'   => FbxPoll::bisa(),
            'bisaNai'    => GambarAi::siapTokoh(),
        ]);
    }

    public function roster(): Response
    {
        // GIRL OF THE MONTH = nama lama Monthly Showdown (2023-05 s.d. 2025-10).
        $urut = ['FEMALE FIGHT', 'CHAMPIONEE', 'HAWT SHOT', 'MIXED FIGHT', 'MONTHLY SHOWDOWN', 'GIRL OF THE MONTH',
            'WAIFU CRUSHED', 'PHOTOBOX', 'REQUESTOR'];
        $baris = DB::table('fbx_roster')->get()
            ->sort(fn ($a, $b) => [$b->bulan, self::urutan($urut, $a->kategori), $a->id]
                <=> [$a->bulan, self::urutan($urut, $b->kategori), $b->id])->values();
        $gambar = FbxGambar::peta($baris->pluck('danbooru_tag')->filter()->all());

        $tampil = $baris->filter(fn ($r) => $r->karakter !== '?');

        return Inertia::render('Fbx/Roster', [
            'baris' => $baris->map(fn ($r) => [
                'id'       => (int) $r->id,
                'bulan'    => $r->bulan,
                'kategori' => $r->kategori,
                'karakter' => $r->karakter,
                'sumber'   => $r->sumber,
                'lawan'    => $r->lawan,
                'babak'    => $r->babak,
                'hasil'    => $r->hasil,
                'yakin'    => $r->yakin,
                'bukti'    => $r->bukti,
                'tag'      => $r->danbooru_tag,
                'foto'     => ($gambar[$r->danbooru_tag] ?? [])[0] ?? null,
            ])->values(),
            'sering' => $tampil->groupBy(fn ($r) => mb_strtolower($r->karakter))
                ->map(fn (Collection $g) => [
                    'karakter' => $g->first()->karakter,
                    'sumber'   => $g->first()->sumber,
                    'jumlah'   => $g->count(),
                    'foto'     => ($gambar[$g->first()->danbooru_tag] ?? [])[0] ?? null,
                ])
                ->sortByDesc('jumlah')->take(10)->values(),
            'judul' => $tampil->filter(fn ($r) => $r->sumber !== '')->groupBy('sumber')
                ->map(fn (Collection $g, $s) => ['sumber' => $s, 'jumlah' => $g->count()])
                ->sortByDesc('jumlah')->take(10)->values(),
            'kosong' => $baris->filter(fn ($r) => $r->karakter === '?')
                ->map(fn ($r) => ['bulan' => $r->bulan, 'kategori' => $r->kategori])->values(),
            'bisaSinkron' => AgentFbx::bisaSinkron(),
        ]);
    }

    public function tren(Request $request): Response
    {
        $mingguAda = DB::table('fbx_tren')->distinct()->orderByDesc('minggu')->pluck('minggu');
        $minggu = (string) $request->query('m', '');
        if (!$mingguAda->contains($minggu)) {
            $minggu = (string) ($mingguAda->first() ?? '');
        }

        $baris = $this->trenPerempuan($minggu, 100);
        $gambar = FbxGambar::peta($baris->pluck('karakter_tag')->all());
        $jejak = $this->jejakRoster();
        $kandidat = DB::table('fbx_kandidat')->pluck('danbooru_tag')->filter()->flip();
        $tingkat = AgentFbx::tingkatTag();

        return Inertia::render('Fbx/Tren', [
            'minggu'  => $minggu,
            'rentang' => $minggu !== '' ? AgentFbx::rentangMinggu($minggu) : '',
            'daftarMinggu' => $mingguAda->map(fn ($m) => ['minggu' => $m, 'rentang' => AgentFbx::rentangMinggu($m)])->all(),
            'baris' => $baris->map(fn ($r) => [
                'peringkat' => (int) $r->peringkat,
                'asli'      => (int) $r->asli,
                'tag'       => $r->karakter_tag,
                'nama'      => self::namaTag($r->karakter_tag),
                'pendek'    => self::namaTag($r->karakter_tag, false),
                'judul'     => self::namaTag($r->judul_tag),
                'post'      => (int) $r->post_minggu,
                'skor'      => (int) $r->skor_minggu,
                'solo'      => (int) $r->solo_cewek,
                'total'     => (int) $r->total_post,
                'rasio'     => (float) $r->rasio_baru,
                'ubah'      => $r->perubahan,
                'gambar'    => $gambar[$r->karakter_tag] ?? [],
                'tampil'    => $this->cariJejak($jejak, $r->karakter_tag, self::namaTag($r->karakter_tag, false)),
                'kandidat'  => isset($kandidat[$r->karakter_tag]),
                // null = Scout belum pernah mengecek umurnya; dialog Roster memintanya.
                'tingkat'   => $tingkat[$r->karakter_tag] ?? null,
            ])->values(),
            'kategoriRoster' => AgentFbx::KATEGORI_ROSTER,
        ]);
    }

    /** Gambar galeri di atas rating general: disimpan di luar public/, hanya keluar lewat sini. */
    public function gambar(string $nama): BinaryFileResponse
    {
        $file = storage_path('app/fbx/' . $nama);
        abort_unless(is_file($file), 404);

        return response()->file($file, ['Cache-Control' => 'private, max-age=604800']);
    }

    /** Masukkan ke Roster dari kartu Scout atau Tren. Aturan umurnya di AgentFbx::tambahRoster(). */
    public function tambahRoster(Request $request): JsonResponse
    {
        try {
            $baris = AgentFbx::tambahRoster($request->only(['bulan', 'kategori', 'karakter', 'sumber', 'lawan', 'danbooru_tag', 'kandidat_id']),
                $request->input('tingkat'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 423);
        }

        return response()->json(['ok' => true, 'baris' => $baris, 'keCsv' => AgentFbx::bisaSinkron()]);
    }

    /** Buat StrawPoll dari kandidat yang dicentang. Aturannya di FbxPoll::buat(). */
    public function pollBuat(Request $request): JsonResponse
    {
        @set_time_limit(180);
        try {
            $poll = FbxPoll::buat((string) $request->input('kategori', ''), (array) $request->input('kandidat', []),
                (string) $request->input('judul', ''), (int) $request->input('tutup', 0), (string) $request->input('hasil', ''),
                $request->boolean('gambar'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }

        return response()->json(['ok' => true, 'poll' => $poll]);
    }

    /** Potret NovelAI untuk satu pilihan poll (Mixed Fight: dua potret digabung). */
    public function pollGambarNai(Request $request): JsonResponse
    {
        @set_time_limit(180);

        return $this->jawabGambar(fn () => FbxPollGambar::nai((int) $request->input('kandidat_id'),
            (string) $request->input('warna', ''), (string) $request->input('warna_pasangan', '')));
    }

    /** Gambar milik sendiri untuk satu pilihan poll. */
    public function pollGambarUnggah(Request $request): JsonResponse
    {
        $berkas = $request->file('berkas');
        if ($berkas === null || !$berkas->isValid()) {
            return response()->json(['ok' => false, 'error' => 'Berkas gambar tidak terkirim (mungkin melebihi batas unggah server).'], 422);
        }

        return $this->jawabGambar(fn () => FbxPollGambar::unggah((int) $request->input('kandidat_id'), (string) $berkas->get()));
    }

    private function jawabGambar(callable $kerja): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $kerja());
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    public function pollGambar(string $nama): BinaryFileResponse
    {
        $f = FbxPollGambar::berkas(substr($nama, 0, -4));
        abort_if($f === null, 404);

        return response()->file($f, ['Cache-Control' => 'private, max-age=604800']);
    }

    public function pollHasil(string $id): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + FbxPoll::hasil($id));
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $status = (string) $request->input('status', '');
        if (!in_array($status, AgentFbx::STATUS, true)) {
            return response()->json(['ok' => false, 'error' => 'Status tidak dikenal.'], 422);
        }

        try {
            $ok = AgentFbx::ubahStatus($id, $status);
        } catch (Throwable $e) {
            // Status sudah tersimpan di database; yang gagal cuma salinan CSV
            // (misalnya kandidat.csv sedang dibuka Excel).
            return response()->json(['ok' => true, 'peringatan' => 'Tersimpan, tapi kandidat.csv belum ikut diperbarui: ' . $e->getMessage()]);
        }

        return response()->json(['ok' => $ok, 'error' => $ok ? null : 'Kandidat itu tidak ada.'], $ok ? 200 : 404);
    }

    /**
     * Tombol Sinkron di halaman: data seluruhnya, galeri kandidat sebagian.
     * Galeri lengkap (tren dan roster) urusan `php artisan fbx:sinkron`
     * yang dijalankan Scout — permintaan web tidak boleh menunggu menit.
     */
    public function sinkron(): JsonResponse
    {
        if (!AgentFbx::bisaSinkron()) {
            return response()->json(['ok' => false, 'error' => 'Folder data agent tidak ditemukan di server ini: ' . AgentFbx::folder()], 422);
        }

        @set_time_limit(180);
        try {
            $h = AgentFbx::sinkron();
            $tags = DB::table('fbx_kandidat')->pluck('danbooru_tag')
                ->merge(DB::table('fbx_kandidat')->pluck('pasangan_tag'))->filter()->unique()->values()->all();
            $g = FbxGambar::lengkapi($tags, (int) config('fbx.gambar_per_karakter', 6), false, 8);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'hasil' => $h, 'gambar' => $g]);
    }

    /** "phoenix_reffaella_(daydream_complex)" → "Phoenix Reffaella (Daydream Complex)". */
    public static function namaTag(string $tag, bool $denganKurung = true): string
    {
        $tag = $denganKurung ? $tag : (string) preg_replace('/_\([^)]*\)$/', '', $tag);

        return ucwords(str_replace('_', ' ', $tag), " (\t-");
    }

    /** @return array<string, list<array{bulan:string, kategori:string, babak:string, hasil:string}>> */
    private function jejakRoster(): array
    {
        $peta = [];
        foreach (DB::table('fbx_roster')->where('karakter', '!=', '?')->orderByDesc('bulan')->get() as $r) {
            $isi = ['bulan' => $r->bulan, 'kategori' => $r->kategori, 'babak' => $r->babak, 'hasil' => $r->hasil];
            if ($r->danbooru_tag !== '') {
                $peta['t:' . $r->danbooru_tag][] = $isi;
            }
            $peta['n:' . self::kunciNama($r->karakter)][] = $isi;
        }

        return $peta;
    }

    /** Riwayat tampil satu karakter: dicocokkan lewat tag, lalu lewat nama. */
    private function cariJejak(array $peta, string $tag, string $nama): array
    {
        $hasil = $peta['t:' . $tag] ?? $peta['n:' . self::kunciNama($nama)] ?? [];

        return array_slice($hasil, 0, 5);
    }

    /** Posisi nilai di daftar urutan; yang tidak dikenal paling belakang. */
    private static function urutan(array $daftar, string $nilai): int
    {
        $i = array_search($nilai, $daftar, true);

        return $i === false ? count($daftar) : $i;
    }

    /** "Tsuki Uzaki" dan "Uzaki Tsuki" jadi kunci yang sama. */
    private static function kunciNama(string $nama): string
    {
        $kata = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($nama), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($kata);

        return implode(' ', $kata);
    }

    /**
     * Potret tren satu minggu, karakter perempuan saja, diberi peringkat ulang
     * di antara mereka. `asli` = peringkat di potret lengkap; `perubahan`
     * dihitung ulang terhadap daftar perempuan minggu sebelumnya.
     */
    private function trenPerempuan(string $minggu, int $n): Collection
    {
        if ($minggu === '') {
            return collect();
        }
        $jenis = DB::table('fbx_tag_cek')->where('jenis', '!=', '')->pluck('jenis', 'danbooru_tag');
        $lalu = DB::table('fbx_tren')->where('minggu', '<', $minggu)->max('minggu');
        $peringkatLalu = $lalu !== null ? $this->perempuan($lalu, $jenis)->pluck('peringkat', 'karakter_tag') : null;

        return $this->perempuan($minggu, $jenis)->take($n)->each(function ($r) use ($peringkatLalu) {
            $r->perubahan = $peringkatLalu === null ? ''
                : (isset($peringkatLalu[$r->karakter_tag]) ? sprintf('%+d', $peringkatLalu[$r->karakter_tag] - $r->peringkat) : 'BARU');
        })->values();
    }

    /**
     * Perempuan = tebakan galeri (fbx_tag_cek.jenis, dari posting solo
     * karakter itu) kalau sudah ada; kalau belum, ada posting 1girl + solo
     * minggu itu.
     */
    private function perempuan(string $minggu, Collection $jenis): Collection
    {
        return DB::table('fbx_tren')->where('minggu', $minggu)->orderBy('peringkat')->get()
            ->filter(fn ($r) => ($jenis[$r->karakter_tag] ?? ($r->solo_cewek > 0 ? 'P' : '')) === 'P')
            ->values()
            ->each(function ($r, $i) {
                $r->asli = (int) $r->peringkat;
                $r->peringkat = $i + 1;
            });
    }

    /** @return list<array<string,mixed>> */
    private function trenTeratas(int $n): array
    {
        $minggu = DB::table('fbx_tren')->max('minggu');
        if ($minggu === null) {
            return [];
        }
        $baris = $this->trenPerempuan($minggu, $n);
        $gambar = FbxGambar::peta($baris->pluck('karakter_tag')->all());

        return $baris->map(fn ($r) => [
            'peringkat' => (int) $r->peringkat,
            'nama'   => self::namaTag($r->karakter_tag, false),
            'judul'  => self::namaTag($r->judul_tag),
            'skor'   => (int) $r->skor_minggu,
            'ubah'   => $r->perubahan,
            'avatar' => ($gambar[$r->karakter_tag] ?? [])[0]['kecil'] ?? null,
        ])->all();
    }
}
