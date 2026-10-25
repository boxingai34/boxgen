<?php

namespace App\Http\Controllers;

use App\Services\Produksi\Ekspor;
use App\Services\Produksi\Gudang;
use App\Services\Produksi\Higgsfield;
use App\Services\Produksi\HiggsfieldGalat;
use App\Services\Produksi\Peluncur;
use App\Services\Produksi\Pemantau;
use App\Services\Produksi\ProduksiGalat;
use App\Services\Produksi\Subtitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * PRODUKSI — dari prompt pack jadi sampai take terpilih (RENCANA-PRODUKSI.md §7–§8).
 *
 * Tujuh halaman Inertia (Papan, Pack, Kirim, Antrian, Review, Hasil,
 * Pengaturan) dan endpoint JSON-nya. Kerja sebenarnya ada di
 * App\Services\Produksi: Gudang (pack dan item di database), Pemantau
 * (siklus take, gerbang kirim, review, tahap pack), Ekspor (folder FINAL),
 * Higgsfield (kunci API). Controller ini hanya menyusun data halaman,
 * memeriksa masukan, dan melayani berkas.
 *
 * Pagar yang dijaga di sini:
 *   - Dari browser hanya datang id angka atau nama berkas tanpa path. Setiap
 *     berkas yang dilayani di-realpath() dan wajib berada di dalam folder
 *     pack (gambar acuan, video take) atau folder storage produksi (bingkai,
 *     gambar kecil). Selain itu 404.
 *   - Uang hanya keluar lewat POST kirim dengan `konfirmasi: true`
 *     sungguhan; perkiraan tidak pernah menghubungi jaringan.
 *   - Galat yang pesannya untuk pengguna (ProduksiGalat, HiggsfieldGalat,
 *     validasi, RuntimeException polos dari Gudang) dijawab 422 {ok, pesan};
 *     galat lain tetap 500 supaya tidak tertelan diam-diam. Kunci API tidak
 *     pernah ikut: Higgsfield sudah menyensor pesannya sendiri.
 *
 * Semua rute di belakang middleware `admin` + `produksi.lokal` (404 kalau
 * folder "1 - ANTRIAN" tidak ada, mis. di hosting).
 */
class ProduksiController extends Controller
{
    /** Lebar maksimum gambar kecil acuan, piksel. */
    private const LEBAR_KECIL = 360;

    /** Gambar lebih besar dari ini (piksel) tidak dikecilkan GD, dikirim apa adanya — supaya memori PHP tidak habis. */
    private const PIKSEL_MAKS = 25_000_000;

    /** Take terakhir di halaman Antrian (yang aktif selalu ikut). */
    private const BATAS_ANTRIAN = 200;

    /** Take terakhir di jawaban status (yang aktif selalu ikut). */
    private const BATAS_STATUS = 20;

    private const BATAS_AKTIVITAS = 8;

    private const EKSTENSI_GAMBAR = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    private const JENIS_VIDEO = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'];

    /** Kalimat status take — sama dengan lib/produksi.ts, supaya chip dan teks di semua layar sejalan. */
    private const LABEL_TAKE = [
        'siap'        => 'Menunggu dikirim',
        'unggah'      => 'Mengunggah acuan',
        'queued'      => 'Antre di Higgsfield',
        'in_progress' => 'Sedang dibuat',
        'completed'   => 'Jadi, sedang diambil',
        'unduh'       => 'Mengunduh',
        'selesai'     => 'Selesai',
        'failed'      => 'Gagal di Higgsfield',
        'nsfw'        => 'Diblokir filter',
        'canceled'    => 'Dibatalkan',
        'galat'       => 'Galat',
    ];

    /** Aturan pilihan kirim/perkiraan: 1–3 take per item. */
    private const ATURAN_PILIHAN = [
        'pilihan'           => 'required|array|min:1|max:100',
        'pilihan.*'         => 'array',
        'pilihan.*.item_id' => 'required|integer|min:1',
        'pilihan.*.takes'   => 'required|integer|min:1|max:' . Pemantau::MAKS_TAKE,
    ];

    private const PESAN_PILIHAN = [
        'pilihan.required'    => 'Belum ada tester atau klip yang dipilih.',
        'pilihan.min'         => 'Belum ada tester atau klip yang dipilih.',
        'pilihan.max'         => 'Terlalu banyak item sekaligus. Kirim sebagian dulu.',
        'pilihan.array'       => 'Pilihan tidak sah. Muat ulang halaman lalu pilih lagi.',
        'pilihan.*.takes.*'   => 'Jumlah take per item harus 1 sampai ' . Pemantau::MAKS_TAKE . '.',
        'pilihan.*.item_id.*' => 'Pilihan item tidak sah. Muat ulang halaman lalu pilih lagi.',
        'pilihan.*.array'     => 'Pilihan tidak sah. Muat ulang halaman lalu pilih lagi.',
    ];

    /** Peta nama berkas di akar folder pack (huruf kecil → nama di disk), dibaca sekali per permintaan. */
    private array $petaBerkas = [];

    public function __construct(private Gudang $gudang, private Pemantau $pemantau, private Higgsfield $hf)
    {
    }

    // ------------------------------------------------------------------
    // Halaman
    // ------------------------------------------------------------------

    /** Papan Produksi: pack yang sedang dikerjakan, folder baru, koneksi, biaya, aktivitas. */
    public function papan(): Response
    {
        $packs = $this->gudang->semuaPack();
        $ids = $packs->pluck('id')->map(fn ($x) => (int) $x)->all();
        $item = $this->itemSemua($ids)->groupBy('pack_id');
        $take = $this->takeSemua($ids)->groupBy('pack_id');
        $m = $this->modelBawaan();

        return Inertia::render('Produksi/Papan', [
            'packs'         => $packs->map(fn ($p) => $this->ringkasPack($p, $item->get($p->id, collect()), $take->get($p->id, collect())))
                ->values()->all(),
            'folderBaru'    => $this->gudang->folderBaru(),
            'koneksi'       => [
                'siap'          => $this->hf->siap(),
                'awalan'        => $this->hf->awalanKunci(),
                'model'         => $m['model'],
                'nama_model'    => $m['nama'],
                'resolusi'      => $m['resolusi'],
                'rasio'         => $m['rasio'],
                'slot_terpakai' => $this->slotTerpakai(),
            ],
            'biayaBulanIni' => $this->biayaBulanIni(),
            'aktivitas'     => $this->aktivitas(),
        ]);
    }

    /** Detail pack: gerbang umur, cek otomatis, tester, klip penuh, biaya. */
    public function pack(int $id): Response
    {
        $p = $this->packAtau404($id);
        $hasil = Gudang::urai($p->cek);
        $data = Gudang::urai($p->data);
        $cek = self::daftarCek($hasil);
        $m = $this->modelPack($p);
        $item = $this->gudang->itemsPack($id);
        $take = $this->takeSemua([$id]);
        $klip = $item->where('jenis', 'klip');
        $kurang = $this->pemantau->testerKurang($id);
        $halaman = fn (object $i) => $this->itemHalaman($p, $i, $take, $cek);

        return Inertia::render('Produksi/Pack', [
            'pack'   => [
                'id'                => $id,
                'folder'            => (string) $p->folder,
                'judul'             => self::judul($p),
                'revisi'            => (string) $p->revisi,
                'model'             => $m['model'],
                'nama_model'        => $m['nama'],
                'resolusi'          => $m['resolusi'],
                'rasio'             => $m['rasio'],
                'jumlah_klip'       => $klip->count(),
                'durasi_total'      => (int) $klip->sum('durasi'),
                'tahap'             => $this->pemantau->tahap($id),
                'umur_status'       => self::umurStatus($p),
                'umur'              => self::daftarUmur($hasil),
                'umur_dikonfirmasi' => (bool) $p->umur_dikonfirmasi,
                'klip_dibuka'       => (bool) $p->klip_dibuka,
                'klip_terkunci'     => $kurang !== [] && !(int) $p->klip_dibuka,
                'kurang'            => $kurang,
                'cek'               => $cek,
                'kontinuitas'       => self::daftarTeks($data['kontinuitas'] ?? []),
                'dokumen'           => self::daftarTeks($data['dokumen'] ?? []),
                'biaya_terpakai'    => self::jumlahBiaya($take),
                'perkiraan_sisa'    => self::perkiraanSisa($item, $m),
                'peringatan'        => self::daftarTeks($data['peringatan'] ?? []),
                // Polling halaman Pack membandingkan sidik ini dengan jawaban produksi.status?pack_id=.
                'sidik_take'        => self::sidikTake($take),
            ],
            'tester' => $item->where('jenis', 'tester')->map($halaman)->values()->all(),
            'klip'   => $klip->map($halaman)->values()->all(),
        ]);
    }

    /**
     * Kirim ke Higgsfield: daftar item dengan harga per take. `?item=ID`
     * membuat item itu saja tercentang awal (tombol "Ulangi" di Review);
     * tanpa itu yang tercentang adalah langkah berikutnya — tester yang
     * belum lolos, atau kalau tester sudah beres, klip yang belum punya take.
     *
     * Item yang take terakhirnya mungkin sudah dibuat dan ditagih
     * (Pemantau::bolehUlang) tidak pernah tercentang awal: peringatannya
     * menggantikan `ket`, dan halaman Kirim meminta centang tambahan
     * sebelum item itu bisa ikut dikirim.
     */
    public function halamanKirim(Request $request, int $id): Response
    {
        $p = $this->packAtau404($id);
        $m = $this->modelPack($p);
        $item = $this->gudang->itemsPack($id);
        $take = $this->takeSemua([$id]);

        $baris = $item->map(function (object $i) use ($p, $take) {
            $milik = $take->where('item_id', (int) $i->id)->sortBy('nomor')->values();
            $alasan = $this->pemantau->alasanTidakKirim((int) $i->id);
            $peringatan = $milik->isNotEmpty() ? $this->peringatanUlang((int) $i->id) : null;

            return [
                'id'             => (int) $i->id,
                'jenis'          => (string) $i->jenis,
                'kode'           => (string) $i->kode,
                'judul'          => (string) $i->judul,
                'durasi'         => (int) $i->durasi,
                'gambar'         => $this->gambarItem($p, $i),
                'ket'            => $peringatan ?? self::ketKirim($i, $milik),
                'peringatan'     => $peringatan,
                'pilih_awal'     => false,
                'take_berikut'   => (int) $milik->max('nomor') + 1,
                'tarif_per_take' => $this->tarifPerTake((int) $i->id),
                'bisa_kirim'     => $alasan === null,
                'alasan_tidak'   => $alasan,
                'status'         => (string) $i->status, // untuk pilihan awal di bawah, dibuang sebelum dikirim
            ];
        })->values()->all();

        // Pilihan awal: satu tahap saja, supaya harga yang tampil pertama kali tidak mengejutkan.
        $minta = filter_var($request->query('item'), FILTER_VALIDATE_INT);
        $diminta = $minta !== false && in_array($minta, array_column($baris, 'id'), true);
        $ulang = fn (array $b) => $b['bisa_kirim'] && in_array($b['status'], ['belum', 'gagal', 'diblokir'], true);
        $adaTester = (bool) array_filter($baris, fn ($b) => $b['jenis'] === 'tester' && $ulang($b));
        foreach ($baris as &$b) {
            // Yang mungkin sudah ditagih tidak pernah dicentang otomatis, juga lewat ?item=.
            $b['pilih_awal'] = $b['peringatan'] === null && ($diminta
                ? $b['id'] === $minta && $b['bisa_kirim']
                : $ulang($b) && $b['jenis'] === ($adaTester ? 'tester' : 'klip'));
            unset($b['status']);
        }
        unset($b);

        return Inertia::render('Produksi/Kirim', [
            'pack' => [
                'id'         => $id,
                'judul'      => self::judul($p),
                'model'      => $m['model'],
                'nama_model' => $m['nama'],
                'resolusi'   => $m['resolusi'],
                'rasio'      => $m['rasio'],
            ],
            'item' => $baris,
        ]);
    }

    /** Antrian: semua take, terbaru di atas (yang aktif selalu ikut). */
    public function antrian(): Response
    {
        return Inertia::render('Produksi/Antrian', ['takes' => $this->barisTake(self::BATAS_ANTRIAN)]);
    }

    /** Review satu take: video, bingkai, analisis, checklist kriteria, catatan, keputusan. */
    public function review(int $id): Response
    {
        $t = $this->takeAtau404($id);
        $i = $this->gudang->item((int) $t->item_id);
        abort_if($i === null, 404);
        $p = $this->packAtau404((int) $i->pack_id);
        $a = Gudang::urai($t->analisis);
        $data = Gudang::urai($p->data);

        return Inertia::render('Produksi/Review', [
            'take'  => [
                'id'         => (int) $t->id,
                'nomor'      => (int) $t->nomor,
                'status'     => (string) $t->status,
                'sumber'     => (string) $t->sumber,
                'seed'       => $t->seed !== null ? (int) $t->seed : null,
                'model'      => (string) $t->model,
                'resolusi'   => (string) $t->resolusi,
                'durasi'     => (int) $t->durasi,
                'video_url'  => $this->berkasTake($t) !== null ? route('produksi.take.video', (int) $t->id) : null,
                'poster_url' => self::berkasBingkai((int) $t->id, 0) !== null ? route('produksi.take.bingkai', ['id' => (int) $t->id, 'n' => 0]) : null,
                'bingkai'    => self::daftarBingkai((int) $t->id, $a),
                'analisis'   => self::ringkasAnalisis($a),
                'cek'        => array_values(array_map(fn ($c) => [
                    'tingkat' => (string) ($c['tingkat'] ?? 'saran'),
                    'teks'    => (string) ($c['teks'] ?? ''),
                    'detail'  => isset($c['detail']) && $c['detail'] !== '' ? (string) $c['detail'] : null,
                ], array_filter((array) ($a['cek'] ?? []), 'is_array'))),
                // Objek berkunci indeks kriteria ({"0":"lolos"}), juga waktu kosong ({}).
                'nilai'      => (object) Gudang::urai($t->nilai),
                'catatan'    => array_values(array_filter(Gudang::urai($t->catatan), 'is_array')),
                'keputusan'  => $t->keputusan !== null && $t->keputusan !== '' ? (string) $t->keputusan : null,
                'galat'      => $t->galat !== null && $t->galat !== '' ? (string) $t->galat : null,
                // Pemulihan take galat: request_id boleh tampil (untuk dicari di dashboard
                // Higgsfield); alamat video dan body tidak pernah dikirim ke browser.
                'request_id'   => trim((string) $t->request_id) !== '' ? (string) $t->request_id : null,
                'biaya'        => $t->biaya_usd !== null ? round((float) $t->biaya_usd, 2) : null,
                'bisa_periksa' => self::bisaPeriksa($t),
            ],
            'item'  => [
                'id'       => (int) $i->id,
                'jenis'    => (string) $i->jenis,
                'kode'     => (string) $i->kode,
                'judul'    => (string) $i->judul,
                'durasi'   => (int) $i->durasi,
                'kriteria' => self::daftarTeks(Gudang::urai($i->kriteria)),
                'gambar'   => $this->gambarItem($p, $i),
            ],
            'takes' => DB::table('produksi_take')->where('item_id', $i->id)->orderBy('nomor')->get(['id', 'nomor', 'status', 'keputusan'])
                ->map(fn ($x) => [
                    'id'        => (int) $x->id,
                    'nomor'     => (int) $x->nomor,
                    'status'    => (string) $x->status,
                    'keputusan' => $x->keputusan !== null && $x->keputusan !== '' ? (string) $x->keputusan : null,
                ])->values()->all(),
            'pack'  => [
                'id'          => (int) $p->id,
                'judul'       => self::judul($p),
                'revisi'      => (string) $p->revisi,
                'kontinuitas' => self::daftarTeks($data['kontinuitas'] ?? []),
            ],
        ]);
    }

    /** Siap edit: urutan take terpilih, subtitle, biaya, ekspor, serahkan. */
    public function hasil(int $id): Response
    {
        $p = $this->packAtau404($id);
        $data = Gudang::urai($p->data);
        $folder = $this->folderPack($p);
        $klip = $this->gudang->itemsPack($id, 'klip');
        $pilihan = DB::table('produksi_take')->whereIn('id', $klip->pluck('take_pilihan')->filter()->all())->get()->keyBy('id');

        $urutan = [];
        $rencanaMs = [];
        $aktualMs = [];
        $totalRencana = 0;
        $totalAktual = 0.0;
        $siap = $klip->isNotEmpty();
        foreach ($klip as $k) {
            $t = $k->take_pilihan !== null ? $pilihan->get($k->take_pilihan) : null;
            $sah = $t !== null && $t->status === 'selesai' && (int) $t->item_id === (int) $k->id;
            $aktual = $sah ? self::durasiAktual($t) : null;
            $siap = $siap && $sah;

            $urutan[] = [
                'kode'           => (string) $k->kode,
                'judul'          => (string) $k->judul,
                'take_id'        => $sah ? (int) $t->id : null,
                'take_nomor'     => $sah ? (int) $t->nomor : null,
                'durasi_rencana' => (int) $k->durasi,
                'durasi_aktual'  => $aktual,
            ];
            $rencanaMs[] = (int) $k->durasi * 1000;
            $aktualMs[] = $aktual !== null ? (int) round($aktual * 1000) : null;
            $totalRencana += (int) $k->durasi;
            $totalAktual += $aktual ?? (float) $k->durasi;
        }

        $take = $this->takeSemua([$id]);
        $tester = $take->where('jenis', 'tester');
        $klipTake = $take->where('jenis', 'klip');
        $final = $folder !== null ? $folder . DIRECTORY_SEPARATOR . self::namaFinal() : '';

        return Inertia::render('Produksi/Hasil', [
            'pack'          => ['id' => $id, 'judul' => self::judul($p), 'folder' => (string) $p->folder],
            'urutan'        => $urutan,
            'total_rencana' => $totalRencana,
            'total_aktual'  => round($totalAktual, 3),
            'subtitle'      => $this->ringkasSubtitle($folder, $data, $rencanaMs, $aktualMs, $siap),
            'catatan_edit'  => self::daftarTeks($data['catatan_edit'] ?? []),
            'biaya'         => [
                'tester'       => self::jumlahBiaya($tester),
                'tester_take'  => $tester->count(),
                'klip'         => self::jumlahBiaya($klipTake),
                'klip_take'    => $klipTake->count(),
                'total'        => self::jumlahBiaya($take),
                'detik_dibuat' => (int) round($take->where('status', 'selesai')->sum(fn ($t) => (float) $t->durasi)),
                'detik_film'   => (int) round($totalAktual),
            ],
            'siap'          => $siap,
            'sudah_ekspor'  => $final !== '' && is_file($final . DIRECTORY_SEPARATOR . Ekspor::NAMA_CATATAN),
            'folder_final'  => $final,
            'diserahkan'    => $p->diserahkan_at !== null,
        ]);
    }

    /** Pengaturan: status kunci (tanpa kuncinya), folder, model dan tarif. */
    public function pengaturan(): Response
    {
        $m = $this->modelBawaan();

        return Inertia::render('Produksi/Pengaturan', [
            'koneksi'        => ['siap' => $this->hf->siap(), 'awalan' => $this->hf->awalanKunci()],
            'folder'         => (string) config('produksi.folder', ''),
            'antrian_ada'    => Gudang::antrian() !== null,
            'model'          => $m['model'],
            'model_tersedia' => collect((array) config('produksi.model_tersedia', []))
                ->filter(fn ($x) => is_array($x))
                ->map(fn (array $x, $kode) => [
                    'kode'  => (string) $kode,
                    'nama'  => (string) ($x['nama'] ?? $kode),
                    'tarif' => array_map('floatval', array_filter((array) ($x['tarif'] ?? []), 'is_numeric')),
                ])->values()->all(),
            'resolusi'       => $m['resolusi'],
            'rasio'          => $m['rasio'],
        ]);
    }

    // ------------------------------------------------------------------
    // JSON
    // ------------------------------------------------------------------

    /** Uji kunci Higgsfield dengan panggilan yang tidak memakai saldo. */
    public function ujiKunci(): JsonResponse
    {
        return $this->coba(fn () => $this->hf->uji());
    }

    /** Impor satu folder dari "1 - ANTRIAN" (hanya nama folder, bukan path). */
    public function impor(Request $request): JsonResponse
    {
        return $this->coba(function () use ($request) {
            $data = $request->validate(['folder' => 'required|string|max:255'], [
                'folder.required' => 'Pilih folder pack yang mau diimpor.',
                'folder.*'        => 'Nama folder pack tidak sah.',
            ]);
            $folder = trim($data['folder']);
            // Dari browser hanya nama folder. Gudang memeriksanya lagi dengan
            // realpath(): wajib anak langsung "1 - ANTRIAN".
            if ($folder === '' || $folder === '.' || $folder === '..' || preg_match('#[\\\\/:\x00]#', $folder)) {
                throw new ProduksiGalat('Nama folder pack tidak sah. Pilih salah satu folder di 1 - ANTRIAN.');
            }
            $id = $this->gudang->impor($folder);

            return ['ok' => true, 'id' => $id, 'url' => route('produksi.pack', $id)];
        });
    }

    /**
     * Polling 5 detik dari Antrian, Pack, Kirim, dan Review. Sekalian
     * membangunkan pemantau latar kalau ada take aktif tapi detaknya mati.
     *
     * `?pack_id=` (Pack, Kirim, Review) membuat SEMUA take pack itu ikut,
     * bukan hanya yang aktif + 20 terakhir — take yang baru selesai tetap
     * terlihat walau pack lain sudah mengirim banyak take sesudahnya.
     * `pemantau` jujur: hidup hanya kalau pemantau latar sendiri yang
     * menulis detaknya; kalau tidak, `pesan` menjelaskan kenapa dan
     * `perintah_pemantau` adalah perintah untuk menyalakannya sendiri.
     */
    public function status(Request $request): JsonResponse
    {
        $this->pemantau->pastikanJalan();
        $info = (array) $this->pemantau->infoPemantau();
        $packId = filter_var($request->query('pack_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pemantau = [
            'hidup' => (bool) ($info['hidup'] ?? false),
            'pesan' => is_string($info['pesan'] ?? null) && trim($info['pesan']) !== '' ? $info['pesan'] : null,
            'detak' => is_string($info['detak'] ?? null) && $info['detak'] !== '' ? $info['detak'] : null,
        ];

        return response()->json([
            'takes'             => $this->barisTake(self::BATAS_STATUS, $packId === false ? null : $packId),
            'slot_terpakai'     => $this->slotTerpakai(),
            'pemantau'          => $pemantau,
            'pemantau_hidup'    => $pemantau['hidup'], // nama lama, sama dengan pemantau.hidup
            'perintah_pemantau' => self::perintahPemantau(),
        ]);
    }

    /** Perkiraan harga dari tarif config. Tidak menghubungi Higgsfield. */
    public function perkiraan(Request $request): JsonResponse
    {
        return $this->coba(function () use ($request) {
            $data = $request->validate(self::ATURAN_PILIHAN, self::PESAN_PILIHAN);

            return ['ok' => true] + $this->pemantau->perkiraan($data['pilihan']);
        });
    }

    /**
     * Satu-satunya pintu ke uang: membuat take `siap` yang lalu dikirim
     * pemantau latar. Wajib `konfirmasi: true` (boolean JSON, bukan "1"
     * atau "on"); semua gerbang lain diperiksa Pemantau::kirim().
     */
    public function kirim(Request $request): JsonResponse
    {
        return $this->coba(function () use ($request) {
            if ($request->input('konfirmasi') !== true) {
                throw new ProduksiGalat('Kiriman belum dikonfirmasi. Periksa harganya, lalu setujui kirim sekali lagi.');
            }
            $data = $request->validate(['pack_id' => 'required|integer|min:1'] + self::ATURAN_PILIHAN,
                ['pack_id.*' => 'Pack tidak dikenal. Muat ulang halaman.'] + self::PESAN_PILIHAN);
            $hasil = $this->pemantau->kirim((int) $data['pack_id'], $data['pilihan']);

            return ['ok' => true, 'take_ids' => $hasil['take_ids'], 'total' => $hasil['total']];
        });
    }

    /** Baca ulang pack dari foldernya sesudah berkas pack diubah. */
    public function segarkan(int $id): JsonResponse
    {
        $this->packAtau404($id);

        return $this->coba(function () use ($id) {
            $this->gudang->segarkan($id);

            return ['ok' => true, 'tahap' => $this->pemantau->tahap($id)];
        });
    }

    /** Pengguna menyatakan karakter yang tidak tercatat sudah 18+. Pack "tolak" tidak bisa dibuka lewat sini. */
    public function konfirmasiUmur(Request $request, int $id): JsonResponse
    {
        $p = $this->packAtau404($id);

        return $this->coba(function () use ($request, $p) {
            if (self::umurStatus($p) === 'tolak') {
                throw new ProduksiGalat('Pack ini ditolak aturan umur. Konfirmasi tidak bisa membukanya.');
            }
            if ($request->input('konfirmasi') !== true) {
                throw new ProduksiGalat('Centang dulu pernyataan bahwa semua karakter pack ini 18 tahun ke atas.');
            }
            DB::table('produksi_pack')->where('id', $p->id)->update(['umur_dikonfirmasi' => 1]);

            return ['ok' => true];
        });
    }

    /** Buka paksa klip penuh walau tester belum semua lolos. */
    public function bukaKlip(int $id): JsonResponse
    {
        $p = $this->packAtau404($id);

        return $this->coba(function () use ($p) {
            if (self::umurStatus($p) === 'tolak') {
                throw new ProduksiGalat('Pack ini ditolak aturan umur, jadi klip tidak bisa dibuka.');
            }
            DB::table('produksi_pack')->where('id', $p->id)->update(['klip_dibuka' => 1]);

            return ['ok' => true];
        });
    }

    /** Siap edit: salin take terpilih + subtitle tergeser + catatan ke FINAL\. */
    public function ekspor(int $id): JsonResponse
    {
        $this->packAtau404($id);

        return $this->coba(fn () => ['ok' => true] + app(Ekspor::class)->siapEdit($id));
    }

    /** Tandai pack sudah diserahkan ke CapCut (tahap "selesai"). */
    public function serahkan(int $id): JsonResponse
    {
        $p = $this->packAtau404($id);
        if ($p->diserahkan_at === null) {
            DB::table('produksi_pack')->where('id', $id)->update(['diserahkan_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Buka folder pack di Explorer (Windows saja). `sub` = "FINAL" atau
     * "TAKES" membuka subfolder itu kalau sudah ada; kalau belum, folder
     * pack-nya yang dibuka.
     */
    public function bukaFolder(Request $request, int $id): JsonResponse
    {
        $p = $this->packAtau404($id);

        return $this->coba(function () use ($request, $p) {
            if (PHP_OS_FAMILY !== 'Windows') {
                throw new ProduksiGalat('Membuka folder hanya bisa di PC Windows tempat aplikasi ini berjalan.');
            }
            $folder = $this->folderPackWajib($p);

            $tujuan = $folder;
            $sub = strtoupper(trim((string) $request->input('sub', '')));
            if ($sub !== '') {
                $nama = match ($sub) {
                    'FINAL' => self::namaFinal(),
                    'TAKES' => self::namaTakes(),
                    default => throw new ProduksiGalat('Subfolder tidak dikenal.'),
                };
                $calon = realpath($folder . DIRECTORY_SEPARATOR . $nama);
                if ($calon !== false && is_dir($calon) && self::samaPath(dirname($calon), $folder)) {
                    $tujuan = $calon;
                }
            }
            $this->bukaExplorer($tujuan);

            return ['ok' => true, 'folder' => $tujuan];
        });
    }

    /** Tambah take dari luar (Luno, web Higgsfield): unggahan `berkas` disalin ke TAKES\. */
    public function tambahManual(Request $request, int $id): JsonResponse
    {
        abort_if($this->gudang->item($id) === null, 404);

        return $this->coba(function () use ($request, $id) {
            $request->validate(['berkas' => 'required|file'], [
                'berkas.required' => 'Pilih berkas video yang mau ditambahkan.',
                'berkas.*'        => 'Unggahan video gagal. Coba lagi; kalau berkasnya besar, periksa upload_max_filesize di php.ini.',
            ]);
            $berkas = $request->file('berkas');
            $takeId = $this->pemantau->tambahManual($id, (string) $berkas->getRealPath(), $berkas->getClientOriginalName());

            return ['ok' => true, 'take_id' => $takeId, 'url' => route('produksi.take', $takeId)];
        });
    }

    /**
     * "Periksa lagi ke Higgsfield" untuk take galat yang mungkin sudah
     * dibayar: Pemantau mengembalikannya ke antrean pantau (punya
     * request_id) atau ke kiriman ulang dengan body + Idempotency-Key yang
     * sama (body tersimpan, request_id belum ada). Tidak pernah membuat
     * request baru. Body yang pasti ditolak Higgsfield (biaya 0, tanpa
     * request_id) tidak dikirim ulang dari sini: itu kiriman baru yang
     * harus lewat halaman Kirim dengan harga dan konfirmasi.
     */
    public function periksaLagi(int $id): JsonResponse
    {
        $t = $this->takeAtau404($id);

        return $this->coba(function () use ($t) {
            if ($t->status === 'galat' && trim((string) $t->request_id) === '' && trim((string) ($t->body ?? '')) !== '' && $t->biaya_usd !== null) {
                throw new ProduksiGalat('Higgsfield sudah menolak take ini (tidak ditagih), jadi tidak ada yang bisa diperiksa. Kirim take baru dari halaman Kirim.');
            }
            $this->pemantau->periksaLagi((int) $t->id);

            return ['ok' => true];
        });
    }

    /** Simpan checklist kriteria + catatan per waktu (disimpan otomatis dari Review). */
    public function nilai(Request $request, int $id): JsonResponse
    {
        $this->takeAtau404($id);

        return $this->coba(function () use ($request, $id) {
            $request->validate([
                'nilai'   => 'nullable|array',
                'catatan' => 'nullable|array|max:' . Pemantau::MAKS_CATATAN,
            ], [
                'catatan.max' => 'Paling banyak ' . Pemantau::MAKS_CATATAN . ' catatan per take.',
                '*'           => 'Penilaian tidak sah. Muat ulang halaman.',
            ]);
            $this->pemantau->simpanNilai($id, (array) $request->input('nilai', []), array_values((array) $request->input('catatan', [])));

            return ['ok' => true];
        });
    }

    /** Keputusan review: lolos (tester), pakai (klip), atau gagal. */
    public function putuskan(Request $request, int $id): JsonResponse
    {
        $this->takeAtau404($id);

        return $this->coba(function () use ($request, $id) {
            $data = $request->validate(['keputusan' => 'required|string|in:' . implode(',', Pemantau::KEPUTUSAN)], [
                'keputusan.*' => 'Pilih keputusan: lolos, pakai, atau gagal.',
            ]);
            $this->pemantau->putuskan($id, $data['keputusan']);
            $itemId = DB::table('produksi_take')->where('id', $id)->value('item_id');

            return ['ok' => true, 'item_status' => (string) DB::table('produksi_item')->where('id', $itemId)->value('status')];
        });
    }

    // ------------------------------------------------------------------
    // Berkas
    // ------------------------------------------------------------------

    /**
     * Gambar kecil acuan (JPEG, lebar ≤ 360 px, disimpan di
     * storage/app/produksi/gambar/<pack>/). Hanya nama yang memang tercatat
     * sebagai gambar acuan item pack ini, dan berkasnya wajib berada tepat di
     * akar folder pack.
     */
    public function gambar(int $id, string $nama): BinaryFileResponse
    {
        $p = $this->packAtau404($id);
        // Tanpa trim(): trim membuang \0 di ujung, jadi "A-00.png%00" akan lolos sebagai nama lain.
        abort_if($nama === '' || $nama === '.' || $nama === '..' || preg_match('/[\x00-\x1F\x7F]/', $nama)
            || $nama !== basename(str_replace('\\', '/', $nama)), 404);
        abort_unless(in_array(strtolower(pathinfo($nama, PATHINFO_EXTENSION)), self::EKSTENSI_GAMBAR, true), 404);

        $kecil = mb_strtolower($nama);
        $dikenal = DB::table('produksi_item')->where('pack_id', $id)->pluck('gambar')
            ->contains(fn ($json) => collect(Gudang::urai($json))
                ->contains(fn ($g) => is_string($g) && mb_strtolower(basename(str_replace('\\', '/', trim($g)))) === $kecil));
        abort_unless($dikenal, 404);

        $folder = $this->folderPack($p);
        $asli = $folder !== null ? $this->namaDiDisk($folder, $nama) : null;
        $sumber = $asli !== null ? realpath($folder . DIRECTORY_SEPARATOR . $asli) : false;
        abort_unless($sumber !== false && is_file($sumber) && self::samaPath(dirname($sumber), $folder), 404);

        $jpeg = $this->gambarKecil($id, $sumber);

        // Umur cache pendek: pengguna bisa mengganti gambar acuan dengan nama yang sama.
        return $jpeg !== null
            ? response()->file($jpeg, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=600'])
            : response()->file($sumber, ['Cache-Control' => 'private, max-age=600']);
    }

    /** Video take untuk pemutar Review. BinaryFileResponse melayani Range (lompat waktu). */
    public function video(int $id): BinaryFileResponse
    {
        $path = $this->berkasTake($this->takeAtau404($id));
        abort_if($path === null, 404);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return response()->file($path, array_filter([
            'Content-Type'  => self::JENIS_VIDEO[$ext] ?? null,
            'Cache-Control' => 'private, max-age=3600',
        ]));
    }

    /** Bingkai n take (0 = poster) dari folder analisis storage/app/produksi/take-<id>. */
    public function bingkai(int $id, int $n): BinaryFileResponse
    {
        $this->takeAtau404($id);
        $path = self::berkasBingkai($id, $n);
        abort_if($path === null, 404);

        return response()->file($path, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** Explorer dibuka tanpa ditunggu. Tes menggantinya supaya tidak ada jendela yang muncul. */
    protected function bukaExplorer(string $path): void
    {
        // create_new_console: Explorer tetap hidup sesudah permintaan selesai
        // (tanpanya Process mematikan anaknya waktu dibuang).
        $p = new Process(['explorer.exe', $path]);
        $p->setOptions(['create_new_console' => true]);
        $p->start();
    }

    // ------------------------------------------------------------------
    // Penyusun data halaman
    // ------------------------------------------------------------------

    /** Satu kartu pack di Papan. */
    private function ringkasPack(object $p, Collection $item, Collection $take): array
    {
        $hasil = Gudang::urai($p->cek);
        $cek = self::daftarCek($hasil);
        $tahap = $this->pemantau->tahap((int) $p->id);
        $m = $this->modelPack($p);
        $klip = $item->where('jenis', 'klip');
        [$a, $b] = $this->gambarPetinju($p, $item);

        return [
            'id'           => (int) $p->id,
            'folder'       => (string) $p->folder,
            'judul'        => self::judul($p),
            'revisi'       => (string) $p->revisi,
            'model'        => $m['model'],
            'resolusi'     => $m['resolusi'],
            'rasio'        => $m['rasio'],
            'jumlah_klip'  => $klip->count(),
            'durasi_total' => (int) $klip->sum('durasi'),
            'tahap'        => $tahap,
            'ringkas'      => self::kalimatRingkas($p, $tahap, $item, $cek),
            'berikutnya'   => self::kalimatBerikutnya($p, $tahap, $item, $take, $cek, $hasil),
            'umur_status'  => self::umurStatus($p),
            'umur_alasan'  => self::alasanUmur($p, $hasil),
            'gambar_a'     => $a,
            'gambar_b'     => $b,
            'biaya'        => self::jumlahBiaya($take),
            // Semua yang perlu dilihat sebelum generate, termasuk yang menahan.
            'saran'        => count(array_filter($cek, fn ($c) => $c['tingkat'] !== 'aman')),
        ];
    }

    /**
     * ITEM_HALAMAN (§8): satu tester atau klip di halaman pack.
     * `take_review` = take selesai pertama yang belum diputuskan (tujuan
     * tombol Review), `take_pilihan` = take yang lolos/dipakai (tujuan
     * Lihat); keduanya bisa berbeda dari take_terakhir (nomor terbesar).
     */
    private function itemHalaman(object $p, object $i, Collection $take, array $cek): array
    {
        $milik = $take->where('item_id', (int) $i->id)->sortBy('nomor')->values();
        $akhir = $milik->last();
        $review = $milik->first(fn ($t) => $t->status === 'selesai' && (string) $t->keputusan === '');
        $pilihan = $i->take_pilihan !== null
            ? $milik->first(fn ($t) => (int) $t->id === (int) $i->take_pilihan && $t->status === 'selesai')
            : null;
        $alasan = $this->pemantau->alasanTidakKirim((int) $i->id);
        $singkat = fn (?object $t) => $t ? ['id' => (int) $t->id, 'nomor' => (int) $t->nomor] : null;

        return [
            'id'            => (int) $i->id,
            'kode'          => (string) $i->kode,
            'judul'         => (string) $i->judul,
            'durasi'        => (int) $i->durasi,
            'gambar'        => $this->gambarItem($p, $i),
            'status'        => (string) $i->status,
            'take_terakhir' => $akhir ? ['id' => (int) $akhir->id, 'nomor' => (int) $akhir->nomor, 'status' => (string) $akhir->status] : null,
            'take_review'   => $singkat($review),
            'take_pilihan'  => $singkat($pilihan),
            'jumlah_take'   => $milik->count(),
            'biaya'         => self::jumlahBiaya($milik),
            'cek'           => array_values(array_filter($cek, fn ($c) => $c['item'] !== null && $c['item'] === (string) $i->kode)),
            'bisa_kirim'    => $alasan === null,
            'alasan_tidak'  => $alasan,
        ];
    }

    /** Slot gambar @image1…N item: nama seperti di pack, url gambar kecilnya. */
    private function gambarItem(object $p, object $i): array
    {
        $folder = $this->folderPack($p);
        $hasil = [];
        foreach (Gudang::urai($i->gambar) as $g) {
            if (!is_string($g) || trim($g) === '') {
                continue;
            }
            $nama = basename(str_replace('\\', '/', trim($g)));
            $asli = $folder !== null ? $this->namaDiDisk($folder, $nama) : null;
            $hasil[] = ['nama' => $nama, 'url' => $this->urlGambar((int) $p->id, $asli ?? $nama)];
        }

        return $hasil;
    }

    /** Wajah petinju di kartu Papan: gambar A-… dan B-… pertama yang ada di disk. */
    private function gambarPetinju(object $p, Collection $item): array
    {
        $folder = $this->folderPack($p);
        $hasil = ['A' => null, 'B' => null];
        if ($folder === null) {
            return [null, null];
        }
        foreach ($item as $i) {
            foreach (Gudang::urai($i->gambar) as $g) {
                foreach (['A', 'B'] as $huruf) {
                    if ($hasil[$huruf] === null && is_string($g) && preg_match('/^' . $huruf . '[-_ ]/i', basename(str_replace('\\', '/', trim($g))))
                        && ($asli = $this->namaDiDisk($folder, $g)) !== null) {
                        $hasil[$huruf] = $this->urlGambar((int) $p->id, $asli);
                    }
                }
            }
        }

        return [$hasil['A'], $hasil['B']];
    }

    /**
     * TAKE_BARIS (§8) untuk Antrian dan polling status: semua take aktif +
     * $batas take terakhir, terbaru di atas; dengan $packId juga semua take
     * pack itu.
     */
    private function barisTake(int $batas, ?int $packId = null): array
    {
        $dasar = fn () => DB::table('produksi_take as t')
            ->join('produksi_item as i', 'i.id', '=', 't.item_id')
            ->join('produksi_pack as p', 'p.id', '=', 'i.pack_id')
            ->select('t.id', 't.item_id', 't.nomor', 't.status', 't.sumber', 't.durasi', 't.estimasi_usd', 't.biaya_usd', 't.keputusan',
                't.galat', 't.analisis', 't.dibuat_at', 't.dikirim_at', 't.selesai_at',
                'i.kode as item_kode', 'i.judul as item_judul', 'i.jenis as item_jenis', 'i.pack_id', 'p.judul as pack_judul', 'p.folder as pack_folder');

        $baris = $dasar()->whereIn('t.status', Pemantau::AKTIF)->get()
            ->merge($dasar()->orderByDesc('t.id')->limit($batas)->get());
        if ($packId !== null) {
            $baris = $baris->merge($dasar()->where('i.pack_id', $packId)->get());
        }

        return $baris->unique('id')->sortByDesc('id')->values()
            ->map(fn (object $t) => [
                'id'          => (int) $t->id,
                'pack_id'     => (int) $t->pack_id,
                'pack_judul'  => (string) ($t->pack_judul ?: $t->pack_folder),
                'item_kode'   => (string) $t->item_kode,
                'item_judul'  => (string) $t->item_judul,
                'nomor'       => (int) $t->nomor,
                'status'      => (string) $t->status,
                'label'       => self::labelTake($t),
                'ket'         => self::ketTake($t),
                'biaya'       => $t->biaya_usd !== null ? round((float) $t->biaya_usd, 2) : null,
                'bisa_review' => $t->status === 'selesai',
                'url_review'  => route('produksi.take', (int) $t->id),
                'waktu'       => self::jam($t->selesai_at ?? $t->dikirim_at ?? $t->dibuat_at),
            ])->all();
    }

    /** Aktivitas terbaru di Papan: kejadian take terakhir, satu kalimat masing-masing. */
    private function aktivitas(): array
    {
        return DB::table('produksi_take as t')
            ->join('produksi_item as i', 'i.id', '=', 't.item_id')
            ->join('produksi_pack as p', 'p.id', '=', 'i.pack_id')
            ->select('t.id', 't.nomor', 't.status', 't.sumber', 't.keputusan', 't.dibuat_at', 't.dikirim_at', 't.selesai_at',
                'i.kode', 'i.jenis', 'p.judul', 'p.folder')
            ->orderByRaw('COALESCE(t.selesai_at, t.dikirim_at, t.dibuat_at) DESC')
            ->orderByDesc('t.id')
            ->limit(self::BATAS_AKTIVITAS)
            ->get()
            ->map(function (object $t) {
                $siapa = self::kapital(self::labelKode((string) $t->jenis, (string) $t->kode)) . ' take ' . (int) $t->nomor;
                $apa = match ((string) $t->status) {
                    'selesai'               => $t->sumber === 'manual' ? 'ditambahkan manual' : 'selesai diunduh',
                    'nsfw'                  => 'diblokir filter, tidak ditagih',
                    'failed'                => 'gagal di Higgsfield, tidak ditagih',
                    'canceled'              => 'dibatalkan',
                    'galat'                 => 'berhenti karena galat',
                    'completed', 'unduh'    => 'jadi, sedang diunduh',
                    'queued', 'in_progress' => 'sedang dibuat di Higgsfield',
                    default                 => 'menunggu dikirim',
                };
                $waktu = $t->selesai_at ?? $t->dikirim_at ?? $t->dibuat_at;

                return [
                    // Hari ini cukup jamnya; yang lebih lama ditambah tanggal supaya tidak dikira hari ini.
                    'waktu' => $waktu !== null && !Carbon::parse($waktu)->isToday() ? Carbon::parse($waktu)->format('d/m H.i') : self::jam($waktu),
                    'teks'  => "{$siapa} {$apa} · " . ((string) $t->judul ?: (string) $t->folder),
                ];
            })->values()->all();
    }

    /** Ringkasan subtitle untuk Siap edit — pratinjau, belum menulis apa pun. */
    private function ringkasSubtitle(?string $folder, array $data, array $rencanaMs, array $aktualMs, bool $siap): array
    {
        $bahasa = [];
        $baris = 0;
        $bergeser = 0;
        $hilang = [];
        foreach ((array) ($data['subtitle'] ?? []) as $kode => $nama) {
            $nama = (string) $nama;
            $path = $folder !== null ? self::berkasDalam($folder, $nama) : null;
            $isi = $path !== null ? @file_get_contents($path) : false;
            if ($isi === false) {
                $hilang[] = basename(str_replace('\\', '/', $nama));
                continue;
            }
            $cue = Subtitle::baca($isi);
            $bahasa[] = (string) $kode;
            $baris = max($baris, count($cue));
            if ($siap) {
                $bergeser = max($bergeser, Subtitle::geser($cue, $rencanaMs, $aktualMs)['bergeser']);
            }
        }

        $kalimat = [];
        if (!$bahasa) {
            $kalimat[] = 'Pack ini tidak punya subtitle.';
        } else {
            $kalimat[] = count($bahasa) . ' bahasa, ' . $baris . ' baris.';
            $kalimat[] = !$siap
                ? 'Waktunya disesuaikan dengan durasi take asli saat ekspor, sesudah semua klip punya take terpilih.'
                : ($bergeser > 0
                    ? "{$bergeser} baris bergeser lebih dari 0,5 dtk mengikuti durasi take asli; periksa sekilas di CapCut."
                    : 'Waktunya tetap pas dengan durasi take asli.');
        }
        foreach ($hilang as $h) {
            $kalimat[] = "{$h} tidak ditemukan di folder pack.";
        }

        return ['bahasa' => $bahasa, 'baris' => $baris, 'bergeser' => $bergeser, 'catatan' => implode(' ', $kalimat)];
    }

    // ------------------------------------------------------------------
    // Kalimat
    // ------------------------------------------------------------------

    /** Chip ringkas kartu Papan: "Tester 3 dari 6 lolos". */
    private static function kalimatRingkas(object $p, string $tahap, Collection $item, array $cek): string
    {
        $tester = $item->where('jenis', 'tester');
        $klip = $item->where('jenis', 'klip');
        $berat = count(array_filter($cek, fn ($c) => in_array($c['tingkat'], ['awas', 'blokir'], true)));
        $saran = count(array_filter($cek, fn ($c) => $c['tingkat'] === 'saran'));
        $terpilih = $klip->filter(fn ($i) => $i->status === 'lolos' && $i->take_pilihan !== null)->count();

        return match ($tahap) {
            'diblokir' => 'Diblokir aturan umur',
            'selesai'  => 'Sudah diserahkan',
            'cek'      => match (true) {
                $berat > 0          => "{$berat} cek menahan kiriman",
                self::perluUmur($p) => 'Umur belum dipastikan',
                $saran > 0          => "{$saran} saran sebelum generate",
                default             => $tester->isNotEmpty() ? 'Siap menjalankan tester' : 'Siap menjalankan klip',
            },
            'tester'   => 'Tester ' . $tester->where('status', 'lolos')->count() . ' dari ' . $tester->count() . ' lolos',
            'siap'     => 'Semua ' . $klip->count() . ' klip terpilih',
            default    => $klip->isEmpty() ? 'Belum ada klip' : "Klip {$terpilih} dari {$klip->count()} terpilih",
        };
    }

    /** "Berikutnya: review 2 take baru T04, ulangi T05 yang diblokir filter, jalankan T06." */
    private static function kalimatBerikutnya(object $p, string $tahap, Collection $item, Collection $take, array $cek, array $hasil): string
    {
        $tester = $item->where('jenis', 'tester');
        $klip = $item->where('jenis', 'klip');
        $dengan = fn (string $tingkat) => array_values(array_filter($cek, fn ($c) => $c['tingkat'] === $tingkat));
        $blokir = $dengan('blokir');
        $awas = $dengan('awas');

        $bagian = match ($tahap) {
            'diblokir', 'selesai' => [],
            'cek'    => match (true) {
                $blokir !== []      => ['betulkan dulu “' . rtrim($blokir[0]['judul'], '.') . '”, lalu segarkan pack'],
                self::perluUmur($p) => ['pastikan umur ' . (self::gabung(self::namaUmur($hasil, 'tak_dikenal')) ?: 'karakter') . ' di halaman pack'],
                $awas !== []        => ['betulkan ' . (self::gabung(array_map(
                    fn (string $k) => self::labelKode((string) ($item->first(fn ($i) => (string) $i->kode === $k)?->jenis ?? 'tester'), $k),
                    array_values(array_unique(array_filter(array_column($awas, 'item'))))
                )) ?: 'item yang tertahan') . ' lalu segarkan pack'],
                $dengan('saran') !== [] => ['lihat ' . count($dengan('saran')) . ' saran di halaman pack, lalu ' . ($tester->isNotEmpty() ? 'jalankan tester' : 'kirim klip')],
                default             => self::langkahItem($tester->isNotEmpty() ? $tester : $klip, $take),
            },
            'tester' => self::langkahItem($tester->where('status', '!=', 'lolos'), $take),
            'siap'   => ['ekspor ke folder FINAL di halaman Siap edit, lalu sunting di CapCut'],
            default  => self::langkahItem($klip->where('status', '!=', 'lolos'), $take),
        };

        return $bagian ? 'Berikutnya: ' . implode(', ', $bagian) . '.' : '';
    }

    /** Bagian kalimat berikutnya untuk sekelompok item sejenis, menurut statusnya. */
    private static function langkahItem(Collection $item, Collection $take): array
    {
        $dengan = fn (string $status) => $item->where('status', $status)->values();
        $bagian = [];

        if (($x = $dengan('review'))->isNotEmpty()) {
            $ids = $x->pluck('id')->map(fn ($v) => (int) $v)->all();
            $baru = $take->filter(fn ($t) => in_array((int) $t->item_id, $ids, true) && $t->status === 'selesai' && (string) $t->keputusan === '')->count();
            $bagian[] = 'review ' . max(1, $baru) . ' take baru ' . self::kodeItem($x);
        }
        if (($x = $dengan('diblokir'))->isNotEmpty()) {
            $bagian[] = 'ulangi ' . self::kodeItem($x) . ' yang diblokir filter';
        }
        if (($x = $dengan('gagal'))->isNotEmpty()) {
            $bagian[] = 'ulangi ' . self::kodeItem($x) . ' yang gagal';
        }
        if (($x = $dengan('belum'))->isNotEmpty()) {
            $bagian[] = 'jalankan ' . self::kodeItem($x);
        }
        if (($x = $dengan('berjalan'))->isNotEmpty()) {
            $bagian[] = 'tunggu ' . self::kodeItem($x) . ' yang sedang dibuat';
        }

        return $bagian;
    }

    /** Alasan di balik status umur pack, untuk kartu Papan. */
    private static function alasanUmur(object $p, array $hasil): string
    {
        $catatan = fn (string $s) => array_values(array_filter(array_map(fn ($u) => trim((string) ($u['catatan'] ?? '')),
            array_filter((array) ($hasil['umur'] ?? []), fn ($u) => is_array($u) && ($u['status'] ?? '') === $s))));

        switch (self::umurStatus($p)) {
            case 'tolak':
                $alasan = $catatan('tolak');
                $awal = $alasan ? implode(' ', $alasan) : (self::gabung(self::namaUmur($hasil, 'tolak')) ?: 'Ada karakter') . ' di bawah 18 tahun menurut canon.';

                return rtrim($awal) . ' Video tidak dibuat untuk pack ini.';
            case 'tak_dikenal':
                if ((int) $p->umur_dikonfirmasi) {
                    return '';
                }

                return 'Umur ' . (self::gabung(self::namaUmur($hasil, 'tak_dikenal')) ?: 'karakter pack ini')
                    . ' belum tercatat. Pastikan semuanya 18 tahun ke atas di halaman pack sebelum mengirim.';
            case 'olahraga':
                return implode(' ', $catatan('olahraga'));
            default:
                return '';
        }
    }

    /** Keterangan item di halaman Kirim: riwayat take terakhirnya. */
    private static function ketKirim(object $i, Collection $milik): string
    {
        $akhir = $milik->last();
        if ($akhir === null) {
            return 'Belum pernah dijalankan';
        }
        $n = (int) $akhir->nomor;
        $pilihan = $milik->first(fn ($t) => (int) $t->id === (int) $i->take_pilihan);

        return match ((string) $i->status) {
            'lolos'    => $i->jenis === 'klip'
                ? 'Take ' . ($pilihan ? (int) $pilihan->nomor : $n) . ' sudah dipilih'
                : 'Sudah lolos dengan take ' . ($pilihan ? (int) $pilihan->nomor : $n),
            'diblokir' => "Ulang dengan seed baru — take {$n} diblokir filter",
            'gagal'    => "Ulang dengan seed baru — take {$n} " . ($akhir->status === 'selesai' ? 'ditandai gagal' : 'gagal'),
            'berjalan' => "Take {$n} sedang dibuat",
            'review'   => "Take {$n} menunggu review",
            default    => 'Belum pernah dijalankan',
        };
    }

    /** Label chip take; take selesai menyebut keputusannya. */
    private static function labelTake(object $t): string
    {
        if ($t->status === 'selesai') {
            return match ((string) $t->keputusan) {
                'lolos' => 'Selesai · lolos',
                'pakai' => 'Selesai · dipakai',
                'gagal' => 'Selesai · ditandai gagal',
                default => 'Selesai · menunggu review',
            };
        }

        return self::LABEL_TAKE[$t->status] ?? (string) $t->status;
    }

    /** Keterangan baris take: "Diunduh 07.12 · 10,0 dtk", "Dikirim 07.05 · 10 dtk · perkiraan $0,40", atau pesan galatnya. */
    private static function ketTake(object $t): string
    {
        $durasi = (int) $t->durasi . ' dtk';
        $perkiraan = 'perkiraan ' . self::teksUsd((float) $t->estimasi_usd);
        $galat = self::pendek((string) $t->galat, 160);

        $bagian = match ((string) $t->status) {
            'siap'                  => ['Menunggu pemantau mengirim', $durasi, $perkiraan],
            'unggah'                => ['Mengunggah gambar acuan', $durasi, $galat],
            'queued', 'in_progress' => [trim('Dikirim ' . self::jam($t->dikirim_at)), $durasi, $perkiraan, $galat],
            'completed', 'unduh'    => ['Jadi di Higgsfield, sedang diunduh', $durasi, $galat],
            'selesai'               => [
                ($t->sumber === 'manual' ? 'Ditambahkan manual ' : 'Diunduh ') . self::jam($t->selesai_at),
                self::koma(self::durasiAktual($t) ?? (float) $t->durasi) . ' dtk',
            ],
            default                 => [$galat !== '' ? self::pendek((string) $t->galat, 240) : (self::LABEL_TAKE[$t->status] ?? (string) $t->status)],
        };

        return implode(' · ', array_filter($bagian, fn ($x) => trim((string) $x) !== ''));
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    private function packAtau404(int $id): object
    {
        $p = $this->gudang->pack($id);
        abort_if($p === null, 404);

        return $p;
    }

    private function takeAtau404(int $id): object
    {
        $t = DB::table('produksi_take')->where('id', $id)->first();
        abort_if($t === null, 404);

        return $t;
    }

    /** Item aktif (urutan > 0) beberapa pack: tester dulu, lalu klip, menurut urutan. */
    private function itemSemua(array $packIds): Collection
    {
        return DB::table('produksi_item')->whereIn('pack_id', $packIds ?: [0])->where('urutan', '>', 0)
            ->orderBy('pack_id')
            ->orderByRaw("CASE WHEN jenis = 'tester' THEN 0 ELSE 1 END")
            ->orderBy('urutan')
            ->orderBy('id')
            ->get();
    }

    /** Take beberapa pack (tanpa kolom besar), dengan pack_id dan jenis item-nya. */
    private function takeSemua(array $packIds): Collection
    {
        return DB::table('produksi_take as t')
            ->join('produksi_item as i', 'i.id', '=', 't.item_id')
            ->whereIn('i.pack_id', $packIds ?: [0])
            ->orderBy('t.id')
            ->get(['t.id', 't.item_id', 't.nomor', 't.status', 't.sumber', 't.durasi', 't.keputusan', 't.biaya_usd',
                'i.pack_id', 'i.jenis', 'i.kode']);
    }

    /** Take yang sedang dibuat di Higgsfield (memakai slot antrean akun). */
    private function slotTerpakai(): int
    {
        return DB::table('produksi_take')->whereIn('status', ['queued', 'in_progress'])->count();
    }

    /** Jumlah biaya_usd take yang tercatat bulan ini (zona app). */
    private function biayaBulanIni(): float
    {
        return round((float) DB::table('produksi_take')->whereNotNull('biaya_usd')
            ->whereRaw('COALESCE(selesai_at, dikirim_at, dibuat_at) >= ?', [now()->startOfMonth()->format('Y-m-d H:i:s')])
            ->sum('biaya_usd'), 2);
    }

    private function tarifPerTake(int $itemId): float
    {
        try {
            return $this->pemantau->tarifPerTake($itemId);
        } catch (ProduksiGalat) {
            return 0.0; // tarif belum ada di config; alasannya muncul lagi saat kirim
        }
    }

    /**
     * Peringatan halaman Kirim kalau take terakhir item ini mungkin sudah
     * dibuat dan ditagih (Pemantau::bolehUlang); null kalau aman diulang.
     */
    private function peringatanUlang(int $itemId): ?string
    {
        $hasil = (array) $this->pemantau->bolehUlang($itemId);
        if ($hasil['boleh'] ?? true) {
            return null;
        }
        $teks = trim((string) ($hasil['peringatan'] ?? ''));

        return $teks !== '' ? $teks : 'Take terakhir item ini mungkin sudah dibuat dan ditagih Higgsfield. Cek dashboard-nya dulu sebelum mengirim take baru.';
    }

    /**
     * Take galat yang mungkin sudah dibuat (dan ditagih) di Higgsfield, jadi
     * layak "Periksa lagi": punya request_id, atau body tersimpan yang belum
     * pasti ditolak (biaya NULL). Lihat periksaLagi().
     */
    private static function bisaPeriksa(object $t): bool
    {
        if ($t->status !== 'galat') {
            return false;
        }
        if (trim((string) $t->request_id) !== '') {
            return true;
        }

        return trim((string) ($t->body ?? '')) !== '' && $t->biaya_usd === null;
    }

    /** Sidik semua take pack: "id:status" terurut, digabung koma — sama persis dengan sidikTake() di lib/produksi.ts. */
    private static function sidikTake(Collection $take): string
    {
        $bagian = $take->map(fn ($t) => (int) $t->id . ':' . (string) $t->status)->all();
        sort($bagian, SORT_STRING);

        return implode(',', $bagian);
    }

    /**
     * Perintah Command Prompt untuk menyalakan pemantau sendiri, memakai
     * php.exe yang sama dengan yang dipakai Peluncur (satu instalasi dengan
     * PHP aplikasi ini; di bawah Apache bukan httpd.exe, dan bukan "php" dari
     * PATH yang di PC ini bisa PHP versi lain).
     */
    private static function perintahPemantau(): string
    {
        $php = Peluncur::php() ?? 'php';

        return PHP_OS_FAMILY === 'Windows'
            ? 'cd /d "' . base_path() . '" && "' . $php . '" artisan produksi:pantau'
            : 'cd ' . escapeshellarg(base_path()) . ' && ' . escapeshellarg($php) . ' artisan produksi:pantau';
    }

    /**
     * Model, nama, resolusi, rasio, dan tarif per detik untuk take baru pack
     * ini — aturan yang sama dengan Pemantau: yang tertulis di pack kalau
     * dikenal, selain itu bawaan config.
     *
     * @return array{model:string, nama:string, resolusi:string, rasio:string, tarif:?float}
     */
    private function modelPack(?object $pack): array
    {
        $daftar = (array) config('produksi.model_tersedia', []);
        $model = $pack !== null && is_array($daftar[(string) $pack->model] ?? null) ? (string) $pack->model : (string) config('produksi.model', 'wan-3.0');
        $resolusi = $pack !== null && in_array($pack->resolusi, Higgsfield::RESOLUSI, true) ? (string) $pack->resolusi : (string) config('produksi.resolusi', '720p');
        $rasio = $pack !== null && in_array($pack->rasio, Higgsfield::RASIO, true) ? (string) $pack->rasio : (string) config('produksi.rasio', '16:9');
        $tarif = is_array($daftar[$model]['tarif'] ?? null) ? ($daftar[$model]['tarif'][$resolusi] ?? null) : null;

        return [
            'model'    => $model,
            'nama'     => (string) ($daftar[$model]['nama'] ?? $model),
            'resolusi' => $resolusi,
            'rasio'    => $rasio,
            'tarif'    => is_numeric($tarif) && $tarif >= 0 ? (float) $tarif : null,
        ];
    }

    private function modelBawaan(): array
    {
        return $this->modelPack(null);
    }

    /** Perkiraan biaya menyelesaikan pack: satu take lagi untuk tiap item yang belum lolos dan tidak sedang berjalan/menunggu review. */
    private static function perkiraanSisa(Collection $item, array $m): float
    {
        if ($m['tarif'] === null) {
            return 0.0;
        }

        return round((float) $item->filter(fn ($i) => in_array($i->status, ['belum', 'gagal', 'diblokir'], true))
            ->sum(fn ($i) => round($m['tarif'] * (int) $i->durasi, 2)), 2);
    }

    /** Cek Pemeriksa (§4) dirapikan: kunci lengkap, item null untuk seluruh pack. */
    private static function daftarCek(array $hasil): array
    {
        $cek = [];
        foreach ((array) ($hasil['cek'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $item = $c['item'] ?? null;
            $cek[] = [
                'kode'    => (string) ($c['kode'] ?? ''),
                'tingkat' => in_array($c['tingkat'] ?? '', ['aman', 'saran', 'awas', 'blokir'], true) ? $c['tingkat'] : 'saran',
                'judul'   => (string) ($c['judul'] ?? ''),
                'detail'  => (string) ($c['detail'] ?? ''),
                'item'    => is_scalar($item) && (string) $item !== '' ? (string) $item : null,
            ];
        }

        return $cek;
    }

    /** Daftar umur karakter (§4) dirapikan. */
    private static function daftarUmur(array $hasil): array
    {
        $hasilUmur = [];
        foreach ((array) ($hasil['umur'] ?? []) as $u) {
            if (!is_array($u)) {
                continue;
            }
            $hasilUmur[] = [
                'nama'    => (string) ($u['nama'] ?? ''),
                'status'  => in_array($u['status'] ?? '', ['dewasa', 'olahraga', 'tolak', 'tak_dikenal'], true) ? $u['status'] : 'tak_dikenal',
                'umur'    => is_numeric($u['umur'] ?? null) ? (int) $u['umur'] : null,
                'catatan' => isset($u['catatan']) && $u['catatan'] !== '' ? (string) $u['catatan'] : null,
                'karya'   => isset($u['karya']) && $u['karya'] !== '' ? (string) $u['karya'] : null,
            ];
        }

        return $hasilUmur;
    }

    /** Nama karakter berstatus $status. */
    private static function namaUmur(array $hasil, string $status): array
    {
        return array_values(array_map(fn ($u) => (string) ($u['nama'] ?? ''),
            array_filter((array) ($hasil['umur'] ?? []), fn ($u) => is_array($u) && ($u['status'] ?? '') === $status && (string) ($u['nama'] ?? '') !== '')));
    }

    /** Larik teks dari JSON pack; selain teks dibuang. */
    private static function daftarTeks(mixed $daftar): array
    {
        return array_values(array_map(fn ($x) => (string) $x, array_filter((array) $daftar, fn ($x) => is_scalar($x) && trim((string) $x) !== '')));
    }

    private static function umurStatus(object $p): string
    {
        return in_array($p->umur_status, ['dewasa', 'olahraga', 'tolak', 'tak_dikenal'], true) ? (string) $p->umur_status : 'tak_dikenal';
    }

    private static function perluUmur(object $p): bool
    {
        return self::umurStatus($p) === 'tak_dikenal' && !(int) $p->umur_dikonfirmasi;
    }

    private static function judul(object $p): string
    {
        return trim((string) $p->judul) !== '' ? (string) $p->judul : (string) $p->folder;
    }

    private static function jumlahBiaya(Collection $take): float
    {
        return round((float) $take->sum(fn ($t) => (float) $t->biaya_usd), 2);
    }

    /** Durasi asli take dari analisisnya, detik; null kalau belum dianalisis. */
    private static function durasiAktual(object $t): ?float
    {
        $d = Gudang::urai($t->analisis ?? null)['durasi'] ?? null;

        return is_numeric($d) && (float) $d > 0 ? round((float) $d, 3) : null;
    }

    /** Bagian `analisis` halaman Review; null kalau take belum (atau gagal) dianalisis. */
    private static function ringkasAnalisis(array $a): ?array
    {
        if (!isset($a['durasi'])) {
            return null;
        }
        $angka = fn ($x, bool $bulat = false) => is_numeric($x) ? ($bulat ? (int) $x : (float) $x) : null;

        return [
            'durasi'    => $angka($a['durasi']),
            'lebar'     => $angka($a['lebar'] ?? null, true),
            'tinggi'    => $angka($a['tinggi'] ?? null, true),
            'fps'       => $angka($a['fps'] ?? null),
            'audio'     => isset($a['audio']) ? (bool) $a['audio'] : null,
            'puncak_db' => $angka($a['puncak_db'] ?? null),
            'mirip'     => array_values(array_map(fn ($m) => [
                'take_id' => (int) ($m['take_id'] ?? 0),
                'nomor'   => (int) ($m['nomor'] ?? 0),
                'skor'    => (float) ($m['skor'] ?? 0),
            ], array_filter((array) ($a['mirip'] ?? []), 'is_array'))),
        ];
    }

    /** Bingkai Review yang berkasnya masih ada: {url, t}. */
    private static function daftarBingkai(int $takeId, array $a): array
    {
        $hasil = [];
        foreach (array_values(array_filter((array) ($a['bingkai'] ?? []), 'is_array')) as $i => $b) {
            // Media menamai bingkai f1.jpg…fn.jpg sesuai urutan; nomornya diambil dari nama kalau ada.
            $n = preg_match('/^f(\d{1,2})\.jpg$/i', basename(str_replace('\\', '/', (string) ($b['berkas'] ?? ''))), $m) ? (int) $m[1] : $i + 1;
            if (self::berkasBingkai($takeId, $n) !== null) {
                $hasil[] = ['url' => route('produksi.take.bingkai', ['id' => $takeId, 'n' => $n]), 't' => (float) ($b['t'] ?? 0)];
            }
        }

        return $hasil;
    }

    // ------------------------------------------------------------------
    // Path
    // ------------------------------------------------------------------

    /** Folder pack (realpath) kalau masih ada dan anak langsung "1 - ANTRIAN"; null kalau tidak. */
    private function folderPack(object $pack): ?string
    {
        $antrian = Gudang::antrian();
        if ($antrian === null) {
            return null;
        }
        try {
            $path = realpath($this->gudang->folderPack($pack));
        } catch (Throwable) {
            return null;
        }

        return $path !== false && is_dir($path) && self::samaPath(dirname($path), $antrian) ? $path : null;
    }

    private function folderPackWajib(object $pack): string
    {
        return $this->folderPack($pack)
            ?? throw new ProduksiGalat('Folder pack «' . $pack->folder . '» tidak ada lagi di 1 - ANTRIAN. Kembalikan foldernya dengan nama yang sama.');
    }

    /** Nama berkas di akar folder pack, tanpa membedakan huruf ("bg-01.png" → "BG-01.PNG"); null kalau tidak ada. */
    private function namaDiDisk(string $folder, string $nama): ?string
    {
        $nama = basename(str_replace('\\', '/', trim($nama)));
        if ($nama === '' || $nama === '.' || $nama === '..') {
            return null;
        }
        // Kunci ikut waktu ubah folder: berkas yang ditambah/dihapus/diganti nama membuat peta dibaca ulang.
        clearstatcache(true, $folder);
        $kunci = $folder . '|' . (int) @filemtime($folder);
        if (!isset($this->petaBerkas[$kunci])) {
            $peta = [];
            foreach (@scandir($folder) ?: [] as $f) {
                if ($f !== '.' && $f !== '..' && is_file($folder . DIRECTORY_SEPARATOR . $f)) {
                    $peta[mb_strtolower($f)] ??= $f;
                }
            }
            $this->petaBerkas[$kunci] = $peta;
        }

        return $this->petaBerkas[$kunci][mb_strtolower($nama)] ?? null;
    }

    /** URL gambar kecil. route() membiarkan "#", "?", "%" tidak dikodekan, jadi nama berkas dikodekan sendiri. */
    private function urlGambar(int $packId, string $nama): string
    {
        return str_replace('__NAMA__', rawurlencode($nama), route('produksi.pack.gambar', ['id' => $packId, 'nama' => '__NAMA__']));
    }

    /**
     * Berkas video take: `berkas` relatif ke folder pack ("TAKES/T04-3.mp4"),
     * cadangannya TAKES\<nama berkas>. Wajib berada di dalam folder pack.
     */
    private function berkasTake(object $t): ?string
    {
        $rel = trim((string) ($t->berkas ?? ''));
        $item = $rel !== '' ? $this->gudang->item((int) $t->item_id) : null;
        $pack = $item !== null ? $this->gudang->pack((int) $item->pack_id) : null;
        $folder = $pack !== null ? $this->folderPack($pack) : null;
        if ($folder === null) {
            return null;
        }

        return self::berkasDalam($folder, $rel)
            ?? self::berkasDalam($folder, self::namaTakes() . '/' . basename(str_replace('\\', '/', $rel)));
    }

    /** Berkas relatif di dalam $akar (pemisah '/' atau '\'); null kalau tidak ada atau keluar dari $akar. */
    private static function berkasDalam(string $akar, string $rel): ?string
    {
        $rel = trim($rel);
        if ($rel === '' || str_contains($rel, "\0") || preg_match('#^([A-Za-z]:|[\\\\/])#', $rel)) {
            return null;
        }
        $path = realpath($akar . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));

        return $path !== false && is_file($path) && self::di($path, $akar) ? $path : null;
    }

    /** Bingkai n (0 = poster.jpg, n = fn.jpg) di storage/app/produksi/take-<id>, atau null. */
    private static function berkasBingkai(int $takeId, int $n): ?string
    {
        if ($n < 0 || $n > 12) {
            return null;
        }
        $akar = realpath(storage_path('app' . DIRECTORY_SEPARATOR . 'produksi'));
        $path = realpath(storage_path('app' . DIRECTORY_SEPARATOR . 'produksi' . DIRECTORY_SEPARATOR . 'take-' . $takeId
            . DIRECTORY_SEPARATOR . ($n === 0 ? 'poster.jpg' : "f{$n}.jpg")));

        return $akar !== false && $path !== false && is_file($path) && self::di($path, $akar) ? $path : null;
    }

    /**
     * Gambar kecil JPEG di storage/app/produksi/gambar/<pack>/, dibuat sekali
     * dan dibuat ulang kalau berkas aslinya berubah (kunci = nama + waktu
     * ubah + ukuran). Null kalau GD tidak ada atau gambarnya tidak terbaca —
     * pemanggil lalu mengirim berkas aslinya.
     */
    private function gambarKecil(int $packId, string $sumber): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }
        $dir = storage_path('app' . DIRECTORY_SEPARATOR . 'produksi' . DIRECTORY_SEPARATOR . 'gambar' . DIRECTORY_SEPARATOR . $packId);
        $kunci = sha1(mb_strtolower(basename($sumber)) . '|' . (int) @filemtime($sumber) . '|' . (int) @filesize($sumber) . '|' . self::LEBAR_KECIL);
        $tujuan = $dir . DIRECTORY_SEPARATOR . $kunci . '.jpg';
        if (is_file($tujuan) && filesize($tujuan) > 0) {
            return $tujuan;
        }

        $info = @getimagesize($sumber);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::PIKSEL_MAKS) {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $isi = @file_get_contents($sumber);
        $asli = $isi !== false ? @imagecreatefromstring($isi) : false;
        if ($asli === false) {
            return null;
        }

        $w = imagesx($asli);
        $h = imagesy($asli);
        $lebar = min(self::LEBAR_KECIL, $w);
        $tinggi = max(1, (int) round($h * $lebar / $w));
        $kanvas = imagecreatetruecolor($lebar, $tinggi);
        // Latar putih: bagian transparan PNG tidak jadi hitam di JPEG (SlotGambar juga berlatar putih).
        imagefill($kanvas, 0, 0, imagecolorallocate($kanvas, 255, 255, 255));
        imagecopyresampled($kanvas, $asli, 0, 0, 0, 0, $lebar, $tinggi, $w, $h);

        // Ditulis ke berkas sementara lalu diganti nama, supaya permintaan lain tidak membaca JPEG setengah jadi.
        $sementara = $tujuan . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = @imagejpeg($kanvas, $sementara, 82);
        imagedestroy($kanvas);
        imagedestroy($asli);
        if (!$ok || !@rename($sementara, $tujuan)) {
            @unlink($sementara);

            return is_file($tujuan) ? $tujuan : null;
        }

        return $tujuan;
    }

    private static function namaFinal(): string
    {
        return basename(str_replace('\\', '/', (string) config('produksi.folder_final', 'FINAL'))) ?: 'FINAL';
    }

    private static function namaTakes(): string
    {
        return basename(str_replace('\\', '/', (string) config('produksi.folder_take', 'TAKES'))) ?: 'TAKES';
    }

    private static function samaPath(string $a, string $b): bool
    {
        $baku = fn (string $p) => rtrim(str_replace('\\', '/', PHP_OS_FAMILY === 'Windows' ? strtolower($p) : $p), '/');

        return $baku($a) === $baku($b);
    }

    /** $path berada di dalam $akar? Tanpa membedakan huruf di Windows. */
    private static function di(string $path, string $akar): bool
    {
        $baku = fn (string $p) => str_replace('\\', '/', PHP_OS_FAMILY === 'Windows' ? strtolower($p) : $p);

        return str_starts_with($baku($path), rtrim($baku($akar), '/') . '/');
    }

    // ------------------------------------------------------------------
    // Pembantu kecil
    // ------------------------------------------------------------------

    /** Jalankan kerja JSON; galat yang pesannya untuk pengguna → 422 {ok: false, pesan}. */
    private function coba(callable $kerja): JsonResponse
    {
        try {
            return response()->json($kerja());
        } catch (ValidationException $e) {
            return self::galat((string) (collect($e->errors())->flatten()->first() ?? 'Isian tidak sah. Muat ulang halaman.'));
        } catch (ProduksiGalat|HiggsfieldGalat $e) {
            return self::galat($e->getMessage());
        } catch (RuntimeException $e) {
            // Gudang melempar RuntimeException polos dengan kalimat untuk
            // pengguna. Turunannya (QueryException, HttpException, …) bukan
            // pesan untuk pengguna: tetap dilempar.
            if (get_class($e) !== RuntimeException::class) {
                throw $e;
            }

            return self::galat($e->getMessage());
        }
    }

    private static function galat(string $pesan): JsonResponse
    {
        return response()->json(['ok' => false, 'pesan' => $pesan], 422);
    }

    /** "T04" untuk tester, "klip 05" untuk klip. */
    private static function labelKode(string $jenis, string $kode): string
    {
        return $jenis === 'tester' ? $kode : 'klip ' . $kode;
    }

    /** Kode sekelompok item sejenis: "T04", "T04 dan T05", "klip 01, 02, dan 03", "T01, T02, T03, dan 3 lainnya". */
    private static function kodeItem(Collection $item): string
    {
        $kode = $item->pluck('kode')->map(fn ($k) => (string) $k)->values()->all();
        if (count($kode) > 4) {
            $kode = [...array_slice($kode, 0, 3), (count($kode) - 3) . ' lainnya'];
        }

        return ($item->first()?->jenis === 'klip' ? 'klip ' : '') . self::gabung($kode);
    }

    /** "a", "a dan b", "a, b, dan c". */
    private static function gabung(array $daftar): string
    {
        $daftar = array_values($daftar);

        return match (count($daftar)) {
            0       => '',
            1       => (string) $daftar[0],
            2       => $daftar[0] . ' dan ' . $daftar[1],
            default => implode(', ', array_slice($daftar, 0, -1)) . ', dan ' . end($daftar),
        };
    }

    private static function kapital(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /** Jam "HH.mm" zona app; kosong kalau tidak ada waktunya. */
    private static function jam(mixed $waktu): string
    {
        return $waktu !== null && $waktu !== '' ? Carbon::parse($waktu)->format('H.i') : '';
    }

    /** "$0,40" — gaya pengguna menulis uang. */
    private static function teksUsd(float $x): string
    {
        return '$' . number_format($x, 2, ',', '.');
    }

    /** "10,0" */
    private static function koma(float $x, int $desimal = 1): string
    {
        return number_format($x, $desimal, ',', '.');
    }

    private static function pendek(string $teks, int $batas): string
    {
        $teks = trim((string) preg_replace('/\s+/u', ' ', $teks));

        return mb_strlen($teks) > $batas ? rtrim(mb_substr($teks, 0, $batas - 1)) . '…' : $teks;
    }
}
