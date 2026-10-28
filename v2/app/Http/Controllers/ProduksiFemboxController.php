<?php

namespace App\Http\Controllers;

use AiClient;
use App\Services\FbxGambar;
use App\Services\Penulis\Venice;
use App\Services\ProduksiFembox\Acuan;
use App\Services\ProduksiFembox\Bulan;
use App\Services\ProduksiFembox\CekUmur;
use App\Services\ProduksiFembox\Galat;
use App\Services\ProduksiFembox\Kostum;
use App\Services\ProduksiFembox\Peserta;
use FemboxReferensi;
use GambarAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as TampilanInertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * PRODUKSI FEMBOX — papan bulan: karakter → kostum (tahap 1), lalu pose
 * dan serah ke artist (tahap berikutnya).
 *
 * Seluruh aturan (umur, kategori, NSFW) ada di App\Services\ProduksiFembox;
 * di sini hanya menjaga pintu dan menyusun data halaman. Tombol di halaman
 * memanggil rute POST yang menjawab JSON, lalu halaman memuat ulang datanya.
 */
class ProduksiFemboxController extends Controller
{
    /** Alamat ramah → nama kategori di papan dan di Drive. */
    public const SLUG = [
        'female-fight'     => 'FEMALE FIGHT',
        'mixed-fight'      => 'MIXED FIGHT',
        'waifu-crushed'    => 'WAIFU CRUSHED',
        'monthly-showdown' => 'MONTHLY SHOWDOWN',
    ];

    public function papan(Request $request): TampilanInertia
    {
        $daftar = Bulan::daftar();
        $pilih = (string) $request->query('b', '');
        if (! in_array($pilih, $daftar, true)) {
            $pilih = $daftar[0] ?? '';
        }
        $s = $pilih !== '' ? Bulan::baca($pilih) : null;

        return Inertia::render('ProduksiFembox/Papan', [
            'daftar'     => array_map(fn ($b) => ['bulan' => $b, 'label' => Bulan::label($b)], $daftar),
            'berikutnya' => Bulan::berikutnya(),
            'papan'      => $s !== null ? $this->ringkasPapan($s) : null,
            'siap'       => $this->siap(),
        ]);
    }

    public function buatBulan(Request $request): JsonResponse
    {
        $request->validate(['bulan' => ['required', 'string', 'regex:/^20\d{2}-(0[1-9]|1[0-2])$/']]);

        return $this->jalankan(function () use ($request) {
            $s = Bulan::buat((string) $request->input('bulan'));

            return ['bulan' => $s['bulan'], 'folder' => $s['folder']];
        });
    }

    public function kategori(string $bulan, string $slug): TampilanInertia
    {
        $kategori = self::SLUG[$slug] ?? abort(404);
        $s = $this->papanAtau404($bulan);
        $peserta = $s['kategori'][$kategori]['peserta'];
        $foto = FbxGambar::peta(array_column($peserta, 'tag'));

        return Inertia::render('ProduksiFembox/Kategori', [
            'bulan'    => $bulan,
            'label'    => Bulan::label($bulan),
            'folder'   => $s['folder'],
            'kategori' => $kategori,
            'slug'     => $slug,
            'maks'     => (int) config("produksi_fembox.maks.$kategori"),
            'peserta'  => array_map(fn ($p) => $this->ringkasPeserta($p, $foto), $peserta),
            'ff'       => $kategori === 'FEMALE FIGHT' ? [
                'laga'     => Bulan::lagaFf($s),
                'pemenang' => $s['kategori']['FEMALE FIGHT']['pemenang'],
            ] : null,
            'scout'    => $this->daftarScout(),
            'roster'   => $this->daftarRoster(),
            'siap'     => $this->siap(),
            'drive'    => Bulan::folderReferensi($s, $kategori),
        ]);
    }

    public function tambah(Request $request, string $bulan): JsonResponse
    {
        $request->validate([
            'kategori' => ['required', 'string'],
            'dari'     => ['required', 'in:scout,roster,manual'],
            'id'       => ['nullable', 'integer', 'min:1'],
            'jk'       => ['nullable', 'in:wanita,pria'],
            'nama'     => ['nullable', 'string', 'max:120'],
            'sumber'   => ['nullable', 'string', 'max:120'],
            'tag'      => ['nullable', 'string', 'max:190'],
            'tinggi'   => ['nullable', 'integer', 'min:0', 'max:300'],
        ]);
        $kategori = (string) $request->input('kategori');
        $jk = (string) $request->input('jk', 'wanita');

        return $this->jalankan(function () use ($request, $bulan, $kategori, $jk) {
            @set_time_limit(0);
            $cek = Venice::adaKunci() ? new CekUmur(new Venice()) : null;
            match ((string) $request->input('dari')) {
                'scout'  => Peserta::dariScout($bulan, $kategori, (int) $request->input('id')),
                'roster' => Peserta::dariRoster($bulan, $kategori, (int) $request->input('id'), $jk, $cek),
                default  => Peserta::manual($bulan, $kategori, $request->only(['nama', 'sumber', 'tag', 'tinggi']) + ['jk' => $jk], $cek),
            };

            return [];
        });
    }

    public function hapus(string $bulan, string $id): JsonResponse
    {
        return $this->jalankan(function () use ($bulan, $id) {
            Peserta::hapus($bulan, $id);

            return [];
        });
    }

    public function geser(Request $request, string $bulan, string $id): JsonResponse
    {
        $request->validate(['arah' => ['required', 'in:-1,1']]);

        return $this->jalankan(function () use ($request, $bulan, $id) {
            Peserta::geser($bulan, $id, (int) $request->input('arah'));

            return [];
        });
    }

    public function pemenang(Request $request, string $bulan): JsonResponse
    {
        $request->validate(['laga' => ['required', 'in:sf1,sf2,final'], 'id' => ['nullable', 'string', 'max:60']]);
        $id = (string) $request->input('id', '');

        return $this->jalankan(function () use ($request, $bulan, $id) {
            Peserta::pemenang($bulan, (string) $request->input('laga'), $id !== '' ? $id : null);

            return [];
        });
    }

    public function peserta(string $bulan, string $id): TampilanInertia
    {
        $s = $this->papanAtau404($bulan);
        $c = Bulan::cari($s, $id) ?? abort(404);
        $p = $c['peserta'];
        $galeri = Acuan::galeri($p);
        $berkas = fn (string $nama) => route('produksi-fembox.berkas', ['bulan' => $bulan, 'id' => $id, 'nama' => $nama], false);
        $versi = function (string $v) use ($p, $berkas) {
            $x = $p['kostum'][$v];

            return [
                'prompt'  => $x['prompt'],
                'ringkas' => $x['ringkas'],
                'catatan' => $x['catatan'],
                'ide'     => $x['ide'] ?? '',
                'usd'     => $x['usd'] ?? 0,
                'dipilih' => $x['dipilih'],
                'drive'   => $x['drive'],
                'hasil'   => array_map(fn ($h) => $h + ['url' => $berkas($h['berkas'])], array_reverse($x['hasil'])),
            ];
        };

        return Inertia::render('ProduksiFembox/Peserta', [
            'bulan'    => $bulan,
            'label'    => Bulan::label($bulan),
            'kategori' => $c['kategori'],
            'slug'     => array_search($c['kategori'], self::SLUG, true),
            'peserta'  => array_intersect_key($p, array_flip([
                'id', 'nama', 'sumber', 'tag', 'jk', 'tingkat', 'umur', 'bukti', 'asal_umur', 'tinggi', 'asal',
            ])),
            'langkah'  => Peserta::langkah($p),
            'alasan'   => ['nsfw' => Kostum::alasanTanpaNsfw($p), 'mesin' => Kostum::alasanTanpaMesin($p)],
            'galeri'   => $galeri,
            'acuan'    => array_map(fn ($a) => $a + [
                'url' => $a['asal'] === 'unggah' ? $berkas($a['berkas'])
                    : (collect($galeri)->firstWhere('post', $a['post'])['kecil'] ?? null),
            ], $p['acuan']),
            'opsi'     => $p['kostum']['opsi'],
            'sfw'      => $versi('sfw'),
            'nsfw'     => $versi('nsfw'),
            'katalog'  => [
                'pakaian' => FemboxController::modul('outfit'),
                'sarung'  => FemboxController::sarung(),
                'rambut'  => FemboxController::modul('hair_style'),
                'gaya'    => FemboxController::modul('style', 'gaya'),
            ],
            'pilihan'  => FemboxReferensi::opsiHalaman(),
            'drive'    => [
                'kostum' => Bulan::folderReferensi($s, $c['kategori']) . DIRECTORY_SEPARATOR . 'KOSTUM',
                'acuan'  => Acuan::folderDrive($s, $c['kategori'], $p['nama']),
            ],
            'siap'     => $this->siap(),
        ]);
    }

    public function susunSfw(Request $request, string $bulan, string $id): JsonResponse
    {
        return $this->jalankan(function () use ($request, $bulan, $id) {
            @set_time_limit(0);
            ignore_user_abort(true);
            $this->kostum()->susunSfw($bulan, $id, (array) $request->input('opsi', []));

            return [];
        });
    }

    public function tulisNsfw(Request $request, string $bulan, string $id): JsonResponse
    {
        $request->validate(['ide' => ['nullable', 'string', 'max:600']]);

        return $this->jalankan(function () use ($request, $bulan, $id) {
            @set_time_limit(0);
            ignore_user_abort(true);
            $this->kostum()->tulisNsfw($bulan, $id, (string) $request->input('ide', ''));

            return [];
        });
    }

    public function gambar(string $bulan, string $id, string $versi): JsonResponse
    {
        return $this->jalankan(function () use ($bulan, $id, $versi) {
            @set_time_limit(0);
            ignore_user_abort(true);
            $this->kostum()->gambar($bulan, $id, $versi);

            return [];
        });
    }

    public function unggahKostum(Request $request, string $bulan, string $id, string $versi): JsonResponse
    {
        $request->validate(['berkas' => ['required', 'file', 'max:15360']]);

        return $this->jalankan(function () use ($request, $bulan, $id, $versi) {
            $this->kostum()->unggah($bulan, $id, $versi, (string) file_get_contents($request->file('berkas')->getRealPath()));

            return [];
        });
    }

    public function pakai(Request $request, string $bulan, string $id, string $versi): JsonResponse
    {
        $request->validate(['hasil' => ['required', 'string', 'regex:/^(sfw|nsfw)-\d+$/']]);

        return $this->jalankan(function () use ($request, $bulan, $id, $versi) {
            $this->kostum()->pakai($bulan, $id, $versi, (string) $request->input('hasil'));

            return [];
        });
    }

    public function acuanDanbooru(Request $request, string $bulan, string $id): JsonResponse
    {
        $request->validate(['post' => ['required', 'integer', 'min:1']]);

        return $this->jalankan(function () use ($request, $bulan, $id) {
            Acuan::pilihDanbooru($bulan, $id, (int) $request->input('post'));

            return [];
        });
    }

    public function acuanUnggah(Request $request, string $bulan, string $id): JsonResponse
    {
        $request->validate(['berkas' => ['required', 'file', 'max:15360']]);

        return $this->jalankan(function () use ($request, $bulan, $id) {
            Acuan::unggah($bulan, $id, (string) file_get_contents($request->file('berkas')->getRealPath()));

            return [];
        });
    }

    public function acuanLepas(Request $request, string $bulan, string $id): JsonResponse
    {
        $request->validate(['berkas' => ['required', 'string', 'max:80']]);

        return $this->jalankan(function () use ($request, $bulan, $id) {
            Acuan::lepas($bulan, $id, (string) $request->input('berkas'));

            return [];
        });
    }

    public function galeri(string $bulan, string $id): JsonResponse
    {
        return $this->jalankan(function () use ($bulan, $id) {
            @set_time_limit(0);

            return ['gambar' => Acuan::ambilGaleri($bulan, $id)];
        });
    }

    /** Kandidat kostum dan acuan unggahan dari folder kerja lokal. */
    public function berkas(string $bulan, string $id, string $nama): BinaryFileResponse
    {
        try {
            $f = Kostum::folderKerja($bulan, $id) . DIRECTORY_SEPARATOR . basename($nama);
        } catch (Galat) {
            abort(404);
        }
        abort_unless(is_file($f), 404);

        return response()->file($f, ['Cache-Control' => 'private, max-age=3600']);
    }

    // ------------------------------------------------------------------

    private function kostum(): Kostum
    {
        return new Kostum(Venice::adaKunci() ? new Venice() : null);
    }

    private function papanAtau404(string $bulan): array
    {
        abort_unless(Bulan::sah($bulan), 404);

        return Bulan::baca($bulan) ?? abort(404);
    }

    /** @param callable(): array $kerja */
    private function jalankan(callable $kerja): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $kerja());
        } catch (Galat $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'ditolak' => $e->ditolak], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => 'Gagal: ' . $e->getMessage(), 'ditolak' => false], 500);
        }
    }

    private function siap(): array
    {
        return [
            'venice'  => Venice::adaKunci(),
            'novelai' => GambarAi::siapTokoh(),
            'mesin'   => AiClient::siapProfil('ubah') || AiClient::siapProfil('nsfw') || AiClient::siapProfil('polish')
                || AiClient::profilDiatur('fembox'),
        ];
    }

    private function ringkasPapan(array $s): array
    {
        $tags = [];
        foreach (Bulan::KATEGORI as $k) {
            $tags = array_merge($tags, array_column($s['kategori'][$k]['peserta'], 'tag'));
        }
        $foto = FbxGambar::peta($tags);

        $kategori = [];
        foreach (Bulan::KATEGORI as $k) {
            $ps = array_map(fn ($p) => $this->ringkasPeserta($p, $foto), $s['kategori'][$k]['peserta']);
            $kategori[] = [
                'nama'    => $k,
                'slug'    => array_search($k, self::SLUG, true),
                'maks'    => (int) config("produksi_fembox.maks.$k"),
                'peserta' => $ps,
                'kostum'  => count(array_filter($ps, fn ($p) => $p['langkah']['kostum_selesai'])),
            ];
        }
        $t = Bulan::turunan($s);

        return [
            'bulan'    => $s['bulan'],
            'label'    => Bulan::label($s['bulan']),
            'folder'   => $s['folder'],
            'jalur'    => Bulan::drive() . DIRECTORY_SEPARATOR . $s['folder'],
            'kategori' => $kategori,
            'turunan'  => [
                'finalis' => array_map(fn ($p) => $this->ringkasPeserta($p, $foto) + ['terkunci' => $p['tingkat'] !== 'DEWASA'], $t['finalis']),
                'juara'   => $t['juara'] !== null ? $this->ringkasPeserta($t['juara'], $foto) : null,
            ],
            'log'      => array_reverse(array_slice($s['log'], -8)),
        ];
    }

    private function ringkasPeserta(array $p, array $foto): array
    {
        return [
            'id'      => $p['id'],
            'nama'    => $p['nama'],
            'sumber'  => $p['sumber'],
            'tag'     => $p['tag'],
            'jk'      => $p['jk'],
            'tingkat' => $p['tingkat'],
            'umur'    => $p['umur'],
            'tinggi'  => $p['tinggi'],
            'asal'    => $p['asal'],
            'foto'    => ($foto[$p['tag']] ?? [])[0]['kecil'] ?? null,
            'langkah' => Peserta::langkah($p),
        ];
    }

    /** Kandidat Scout untuk formulir tambah: yang Terpilih dulu, lalu terbaru; tanpa yang ditolak. */
    private function daftarScout(): array
    {
        $baris = DB::table('fbx_kandidat')->where('status', '!=', 'ditolak')->get()
            ->sort(fn ($a, $b) => [$a->status === 'terpilih' ? 0 : 1, $b->minggu, $b->id] <=> [$b->status === 'terpilih' ? 0 : 1, $a->minggu, $a->id])
            ->take(120)->values();
        $foto = FbxGambar::peta($baris->pluck('danbooru_tag')->filter()->all());

        return $baris->map(fn ($r) => [
            'id'       => (int) $r->id,
            'minggu'   => $r->minggu,
            'kategori' => $r->kategori,
            'karakter' => $r->karakter,
            'pasangan' => $r->pasangan,
            'sumber'   => $r->sumber,
            'tingkat'  => $r->tingkat_umur,
            'status'   => $r->status,
            'foto'     => ($foto[$r->danbooru_tag] ?? [])[0]['kecil'] ?? null,
        ])->all();
    }

    /** Roster untuk formulir tambah: satu baris per karakter (penampilan terakhir). */
    private function daftarRoster(): array
    {
        return DB::table('fbx_roster')->where('karakter', '!=', '?')->get()
            ->sortByDesc(fn ($r) => [$r->bulan, $r->id])
            ->unique(fn ($r) => mb_strtolower($r->karakter))
            ->take(400)->values()
            ->map(fn ($r) => [
                'id'       => (int) $r->id,
                'karakter' => $r->karakter,
                'sumber'   => $r->sumber,
                'bulan'    => $r->bulan,
                'kategori' => $r->kategori,
                'tag'      => $r->danbooru_tag,
            ])->all();
    }
}
