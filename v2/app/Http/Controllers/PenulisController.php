<?php

namespace App\Http\Controllers;

use App\Services\Penulis\JenisLaga;
use App\Services\Penulis\Penulis;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Tugas;
use App\Services\Penulis\Venice;
use App\Services\Produksi\Peluncur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu PENULIS — cerita tinju + pack video dari dua nama petinju.
 *
 * Halaman hanya membuat tugas dan membacanya; pekerjaannya (10–20 menit,
 * panggilan Venice) berjalan di `artisan penulis:jalan <id>` yang
 * dinyalakan lepas lewat Peluncur, sama seperti pemantau PRODUKSI. Satu
 * tugas sekaligus: dua tugas bersamaan bisa menulis folder pack yang sama.
 *
 * Rute di bawah middleware admin + produksi.lokal: hanya di PC produksi.
 */
class PenulisController extends Controller
{
    public const BAHASA_CERITA = ['ja', 'ko', 'zh', 'en', 'id'];

    public function index(): Response
    {
        return Inertia::render('Penulis/Index', [
            'tugas'   => Tugas::daftar(15),
            'hasil'   => self::daftarHasil(),
            'siap'    => Venice::adaKunci(),
            'model'   => (string) config('penulis.model'),
            'antrian' => (string) config('produksi.antrian'),
            'pilihan' => [
                'jenis'   => array_map(fn ($k, $v) => ['nilai' => $k, 'label' => $v['label'], 'bawaan' => JenisLaga::ISI_BAWAAN[$k]], array_keys(JenisLaga::JENIS), JenisLaga::JENIS),
                'isi'     => array_map(fn ($k, $v) => ['nilai' => $k, 'label' => $v['label']], array_keys(JenisLaga::ISI), JenisLaga::ISI),
                'bahasa'  => array_merge([['nilai' => 'auto', 'label' => 'Ikut sumber karakter (bawaan Jepang)']],
                    array_map(fn ($k, $v) => ['nilai' => $k, 'label' => $v], array_keys(Penulis::BAHASA), Penulis::BAHASA)),
                'panjang' => array_map(fn ($k, $v) => ['nilai' => $k, 'label' => ucfirst($k) . " (±{$v} kata/bab)"], array_keys(Penulis::PANJANG), Penulis::PANJANG),
            ],
        ]);
    }

    /** Dipanggil halaman tiap beberapa detik selama ada tugas berjalan. */
    public function status(): JsonResponse
    {
        return response()->json(['tugas' => Tugas::daftar(15), 'hasil' => self::daftarHasil()]);
    }

    public function mulai(Request $request): JsonResponse
    {
        return $this->coba(function () use ($request) {
            $d = $request->validate([
                'a.nama' => 'required|string|max:' . Penulis::MAKS_TEKS, 'a.dari' => 'nullable|string|max:' . Penulis::MAKS_TEKS,
                'a.catatan' => 'nullable|string|max:' . Penulis::MAKS_TEKS,
                'b.nama' => 'required|string|max:' . Penulis::MAKS_TEKS, 'b.dari' => 'nullable|string|max:' . Penulis::MAKS_TEKS,
                'b.catatan' => 'nullable|string|max:' . Penulis::MAKS_TEKS,
                'jenis' => 'required|string', 'isi' => 'nullable|string', 'bab' => 'nullable', 'bahasa' => 'nullable|string',
                'panjang' => 'nullable|string', 'venue' => 'nullable|string|max:' . Penulis::MAKS_TEKS,
                'ide' => 'nullable|string|max:' . Penulis::MAKS_IDE, 'folder' => 'nullable|string|max:120', 'sampai' => 'nullable|string',
            ], [
                'a.nama.required' => 'Nama petinju A wajib diisi.', 'b.nama.required' => 'Nama petinju B wajib diisi.',
                'ide.max' => 'Ide cerita paling panjang ' . Penulis::MAKS_IDE . ' karakter.',
            ]);
            // Normalisasi + validasi aturan yang sama dengan baris perintah,
            // supaya kesalahan isian ketahuan SEBELUM proses latar dinyalakan.
            $isi = Penulis::rapikan($d);

            return $this->luncurkan('tulis', $isi);
        });
    }

    /** Lanjutkan/buat pack (pack) atau rakit ulang (rakit) untuk satu folder hasil PENULIS. */
    public function aksi(Request $request): JsonResponse
    {
        return $this->coba(function () use ($request) {
            $d = $request->validate(['jenis' => 'required|in:pack,rakit', 'folder' => 'required|string|max:200']);
            if (! in_array($d['folder'], array_column(self::daftarHasil(), 'folder'), true)) {
                throw PenulisGalat::ditolak('Folder itu bukan hasil PENULIS di antrian.');
            }

            return $this->luncurkan($d['jenis'], ['folder' => $d['folder']]);
        });
    }

    /** Isi cerita satu bahasa, untuk dibaca di halaman. */
    public function cerita(Request $request): JsonResponse
    {
        return $this->coba(function () use ($request) {
            $d = $request->validate(['folder' => 'required|string|max:200', 'bahasa' => 'required|in:' . implode(',', self::BAHASA_CERITA)]);
            if (! in_array($d['folder'], array_column(self::daftarHasil(), 'folder'), true)) {
                throw PenulisGalat::ditolak('Folder itu bukan hasil PENULIS di antrian.');
            }
            $jalur = Tugas::folderPack($d['folder']) . DIRECTORY_SEPARATOR . config('penulis.folder_cerita', 'CERITA') . DIRECTORY_SEPARATOR . "cerita-{$d['bahasa']}.md";
            if (! is_file($jalur)) {
                throw PenulisGalat::ditolak("Cerita bahasa {$d['bahasa']} belum ada di folder ini.");
            }

            return ['ok' => true, 'teks' => (string) file_get_contents($jalur)];
        });
    }

    /**
     * Folder hasil PENULIS di antrian (yang punya CERITA\riset.json),
     * terbaru dulu, dengan ringkasan keadaannya.
     *
     * @return list<array<string,mixed>>
     */
    public static function daftarHasil(): array
    {
        $antrian = rtrim((string) config('produksi.antrian'), '/\\');
        $cerita = (string) config('penulis.folder_cerita', 'CERITA');
        $hasil = [];
        foreach (glob($antrian . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $d) {
            $c = $d . DIRECTORY_SEPARATOR . $cerita;
            $riset = is_file("{$c}/riset.json") ? json_decode((string) file_get_contents("{$c}/riset.json"), true) : null;
            if (! is_array($riset)) {
                continue;
            }
            $alur = is_file("{$c}/alur.json") ? (json_decode((string) file_get_contents("{$c}/alur.json"), true) ?: []) : [];
            $usd = 0.0;
            foreach (['log.json', 'log-pack.json'] as $log) {
                $l = is_file("{$c}/{$log}") ? json_decode((string) file_get_contents("{$c}/{$log}"), true) : null;
                $usd += is_array($l) ? (float) ($l['usd'] ?? 0) : 0;
            }
            $jenis = (string) ($riset['putusan']['jenis'] ?? '');
            $isi = (string) ($riset['putusan']['isi'] ?? '');
            $hasil[] = [
                'folder'     => basename($d),
                'jalur'      => $d,
                'judul'      => (string) (($alur['judul_asli'] ?? '') ?: ($alur['judul'] ?? '')),
                'judul_en'   => (string) ($alur['judul'] ?? ''),
                'jenis'      => JenisLaga::JENIS[$jenis]['label'] ?? $jenis,
                'isi'        => JenisLaga::ISI[$isi]['label'] ?? $isi,
                'hasil_laga' => (string) ($alur['hasil'] ?? ''),
                'bahasa'     => array_values(array_filter(self::BAHASA_CERITA, fn ($b) => is_file("{$c}/cerita-{$b}.md"))),
                'pack'       => is_file("{$d}/pack.json"),
                'uncensored' => is_dir("{$d}/UNCENSORED"),
                'terputus'   => is_file("{$c}/pack-kerja.json"),
                'bisa_rakit' => is_file("{$c}/pack-bagian.json"),
                'klip'       => is_file("{$d}/pack.json") ? count((array) (json_decode((string) file_get_contents("{$d}/pack.json"), true)['klip'] ?? [])) : 0,
                'usd'        => round($usd, 4),
                'waktu'      => date('c', (int) filemtime("{$c}/riset.json")),
            ];
        }
        usort($hasil, fn ($x, $y) => strcmp($y['waktu'], $x['waktu']));

        return $hasil;
    }

    /** Buat tugas lalu nyalakan prosesnya. Satu tugas sekaligus. */
    private function luncurkan(string $jenis, array $isi): array
    {
        if (! Venice::adaKunci()) {
            throw PenulisGalat::ditolak('Kunci Venice belum diisi (VENICE_API_KEY di config.local.php atau PENULIS_KEY di v2/.env).');
        }
        $jalan = Tugas::adaYangJalan();
        if ($jalan !== null) {
            throw PenulisGalat::ditolak('Masih ada tugas berjalan (' . $jalan['id'] . '). Tunggu sampai selesai.');
        }

        $t = Tugas::buat($jenis, $isi);
        if (! Peluncur::lepas('penulis:jalan ' . $t['id'], $sebab)) {
            Tugas::ubah($t['id'], ['status' => 'gagal', 'selesai' => date('c'), 'galat' => 'Proses latar tidak bisa dinyalakan: ' . $sebab]);
            throw PenulisGalat::ditolak('Proses latar tidak bisa dinyalakan: ' . $sebab . ' Jalankan manual: php v2\artisan penulis:jalan ' . $t['id']);
        }

        return ['ok' => true, 'id' => $t['id']];
    }

    private function coba(callable $kerja): JsonResponse
    {
        try {
            return response()->json($kerja());
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'pesan' => (string) collect($e->errors())->flatten()->first()], 422);
        } catch (PenulisGalat $e) {
            return response()->json(['ok' => false, 'pesan' => $e->getMessage()], 422);
        }
    }
}
