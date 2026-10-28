<?php

namespace App\Http\Controllers;

use App\Services\RequestPatreon\Gudang;
use App\Services\RequestPatreon\Kartu;
use App\Services\RequestPatreon\Notion;
use App\Services\RequestPatreon\RequestGalat;
use App\Services\RequestPatreon\Sinkron;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRODUKSI › Request Patreon — kartu request member dari Notion dan
 * sampai mana prosesnya (Prompt → Generate → Patreon & DA → Pixiv & Buffer
 * → Uploaded). Prompt-nya ditulis lewat skill request-patreon
 * (`artisan request:simpan`); halaman ini membaca, menyalin, dan
 * memajukan status.
 */
class RequestPatreonController extends Controller
{
    /** Jumlah kartu uploaded yang ditampilkan (terbaru dulu). */
    private const BATAS_SELESAI = 15;

    public function __construct(private Gudang $gudang, private Notion $notion)
    {
    }

    public function papan(): Response
    {
        $daftar = $this->gudang->daftar();
        $prompt = $this->gudang->semuaPrompt();
        $kartu = [];
        foreach ($daftar['kartu'] as $id => $k) {
            $kartu[] = self::ringkas($k, $prompt[$id] ?? null);
        }
        // Belum pernah sinkron dari Notion: tampilkan kartu yang promptnya sudah tersimpan.
        if ($daftar['sumber'] !== 'notion') {
            foreach ($prompt as $id => $p) {
                if (! isset($daftar['kartu'][$id])) {
                    $kartu[] = self::ringkas(['id' => $id, 'judul' => $p['judul'] ?? '', 'patron' => $p['patron'] ?? '', 'url' => $p['url'] ?? '',
                        'status' => $p['status_notion'] ?? Kartu::ANTRI, 'dibuat' => $p['disimpan'] ?? ''], $p);
                }
            }
        }

        $selesai = array_values(array_filter($kartu, static fn ($k) => $k['kelompok'] === 'selesai'));
        usort($selesai, static fn ($a, $b) => strcmp((string) $b['diubah'], (string) $a['diubah']));
        $lain = array_values(array_filter($kartu, static fn ($k) => $k['kelompok'] !== 'selesai'));

        return Inertia::render('Produksi/Request', [
            'kartu'   => array_merge($lain, array_slice($selesai, 0, self::BATAS_SELESAI)),
            'jumlahSelesai' => count($selesai),
            'notion'  => ['siap' => $this->notion->siap(), 'diperbarui' => $daftar['diperbarui'], 'sumber' => $daftar['sumber']],
            'langkah' => Kartu::LANGKAH,
            'alur'    => Kartu::ALUR,
        ]);
    }

    public function kartu(string $id, Sinkron $sinkron): Response
    {
        $k = $this->gudang->daftar()['kartu'][$id] ?? null;
        $p = $this->gudang->prompt($id);
        abort_if($k === null && $p === null, 404);

        $galat = null;
        if ($k !== null && ! array_key_exists('isi', $k) && $this->notion->siap()) {
            try {
                $k = $sinkron->denganIsi($k);
                $this->gudang->ubahKartu($id, array_intersect_key($k, array_flip(['isi', 'gambar', 'tautan', 'isi_diubah'])));
            } catch (RequestGalat $e) {
                $galat = $e->getMessage();
            }
        }
        $k ??= ['id' => $id, 'judul' => $p['judul'] ?? '', 'patron' => $p['patron'] ?? '', 'url' => $p['url'] ?? '',
            'status' => $p['status_notion'] ?? Kartu::ANTRI];

        return Inertia::render('Produksi/RequestKartu', [
            'kartu'   => self::ringkas($k, $p) + [
                'isi'    => $k['isi'] ?? null,
                'lampiran' => (int) ($k['gambar'] ?? 0),
                'tautan' => array_values((array) ($k['tautan'] ?? [])),
            ],
            'prompt'  => $p === null ? null : [
                'keputusan' => $p['keputusan'] ?? 'buat',
                'putusan'   => $p['putusan'] ?? null,
                'alasan'    => $p['alasan'] ?? '',
                'balasan'   => $p['balasan'] ?? '',
                'gaya'      => $p['gaya'] ?? '',
                'catatan'   => $p['catatan'] ?? '',
                'umur'      => array_values((array) ($p['umur'] ?? [])),
                'gambar'    => array_values((array) ($p['gambar'] ?? [])),
                'disimpan'  => $p['disimpan'] ?? '',
                'notion'    => $p['notion'] ?? 'belum',
                'biaya_usd' => (float) ($p['biaya_usd'] ?? 0),
            ],
            'notion'  => ['siap' => $this->notion->siap(), 'galat' => $galat],
            'langkah' => Kartu::LANGKAH,
            'alur'    => Kartu::ALUR,
        ]);
    }

    public function sinkron(Sinkron $sinkron): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $sinkron->jalan());
        } catch (RequestGalat $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $status = Kartu::statusBaku((string) $request->input('status'));
        if ($status === null) {
            return response()->json(['ok' => false, 'message' => 'Status tidak dikenal.'], 422);
        }
        try {
            $this->notion->ubahStatus($id, $status);
        } catch (RequestGalat $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        $this->gudang->ubahKartu($id, ['status' => $status, 'diubah' => now()->toIso8601String()]);

        return response()->json(['ok' => true, 'status' => $status]);
    }

    /**
     * @param array<string,mixed> $k kartu dari kartu.json
     * @param array<string,mixed>|null $p prompt/<id>.json
     * @return array<string,mixed>
     */
    public static function ringkas(array $k, ?array $p): array
    {
        $status = (string) ($k['status'] ?? '');
        $posisi = Kartu::posisi($status);
        $video = Kartu::video((string) ($k['judul_asli'] ?? $k['judul'] ?? ''));
        $keputusan = $p['keputusan'] ?? null;

        $kelompok = match (true) {
            $posisi === 5                                                      => 'selesai',
            $posisi === null                                                   => 'lain',
            $posisi === 0 && $video                                            => 'video',
            $posisi === 0 && in_array($keputusan, ['ditolak', 'perlu_info'], true) => 'tolak',
            $posisi === 0 && $p === null                                       => 'perlu',
            default                                                            => 'jalan',
        };

        $berikutnya = match (true) {
            $kelompok === 'perlu' => 'Menunggu prompt — bilang “proses request” ke Claude.',
            $kelompok === 'video' => 'Kartu video — dilewati menu ini.',
            $kelompok === 'tolak' => (string) ($p['alasan'] ?? ''),
            $posisi === 0         => 'Prompt tersimpan, tapi status Notion belum dipindah ke Prompt Siap.',
            $posisi === 1         => 'Berikutnya: generate di NovelAI, lalu tandai Tergenerate.',
            $posisi === 2         => 'Berikutnya: upload ke Patreon & DeviantArt.',
            $posisi === 3         => 'Berikutnya: Pixiv & Buffer.',
            $posisi === 4         => 'Berikutnya: tandai uploaded.',
            default               => '',
        };

        $gambar = (array) ($p['gambar'] ?? []);

        return [
            'id'         => (string) ($k['id'] ?? ''),
            'url'        => (string) ($k['url'] ?? ''),
            'judul'      => (string) (($k['judul'] ?? '') ?: ($k['judul_asli'] ?? '')),
            'patron'     => (string) ($k['patron'] ?? ''),
            'status'     => $status,
            'posisi'     => $posisi,
            'kelompok'   => $kelompok,
            'berikutnya' => $berikutnya,
            'dibuat'     => (string) ($k['dibuat'] ?? ''),
            'diubah'     => (string) ($k['diubah'] ?? ''),
            'prompt'     => $p === null ? null : [
                'keputusan' => (string) $keputusan,
                'putusan'   => $p['putusan'] ?? null,
                'jumlah'    => count($gambar),
                'nsfw'      => count(array_filter($gambar, static fn ($g) => is_array($g['nsfw'] ?? null))),
                'disimpan'  => (string) ($p['disimpan'] ?? ''),
            ],
        ];
    }
}
