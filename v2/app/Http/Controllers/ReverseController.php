<?php

namespace App\Http\Controllers;

use AiClient;
use Database;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PromptBuilder;
use RateLimiter as KuotaHarian;
use Referensi;
use ReversePrompt;
use RuntimeException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Dari Gambar/Video — kebalikan Prompt Generator.
 *
 * Sama seperti CeritaController, yang berpikir tetap engine/ReversePrompt.php:
 * membaca gambarnya dengan model vision, memvalidasi tag hasil bacaan ke
 * kamus Danbooru, lalu menyusunnya jadi prompt NovelAI. Di sini cuma jalur
 * masuk, jatah harian, dan bentuk jawabannya.
 *
 * Gambarnya dikecilkan di browser sebelum dikirim, jadi yang sampai ke sini
 * sudah berupa base64 seukuran layar — bukan berkas aslinya.
 */
class ReverseController extends Controller
{
    /** Format gambar yang diterima model vision. */
    private const MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function halaman(): Response
    {
        return Inertia::render('Reverse', [
            'status' => $this->status(),
            // Daftar gaya diambil dari modul yang sama dengan Prompt Generator,
            // jadi apa pun yang ditambahkan lewat Admin langsung muncul di sini.
            'gaya'   => $this->modulGaya('style'),
            'maks'   => [
                'frame' => (int) REVERSE_MAX_FRAMES,
                'byte'  => (int) REVERSE_MAX_IMAGE_BYTES,
                'hint'  => ReversePrompt::MAKS_HINT,
                'artis' => ReversePrompt::MAKS_ARTIS,
            ],
            'kuat'   => array_map(static fn (array $k): string => $k['label'], ReversePrompt::KUAT),
            'gambar' => GambarController::status(),
        ]);
    }

    /** Model apa yang siap, berapa contoh emasnya, sisa jatah hari ini. */
    public function status(): array
    {
        try {
            return ReversePrompt::status() + ['quota' => $this->kuota()];
        } catch (Throwable $e) {
            return ['profil' => [], 'golden' => [], 'quota' => $this->kuota(), 'galat' => $e->getMessage()];
        }
    }

    /**
     * Ambil gambar atau video dari sebuah alamat.
     *
     * Memakai jaringan dan ffmpeg, jadi ikut dibatasi jatah harian yang sama
     * dengan pembacaan — kalau tidak, satu tombol bisa dipakai menghujani
     * server dengan unduhan.
     */
    public function ambilUrl(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url'    => ['required', 'string', 'max:2000'],
            'frames' => ['nullable', 'integer', 'min:1', 'max:' . (int) REVERSE_MAX_FRAMES],
        ], [
            'url.required' => 'Tempel dulu alamat gambar atau videonya.',
        ]);

        if (($tolak = $this->jagaKuota()) !== null) {
            return $tolak;
        }

        try {
            $ref = Referensi::dariUrl(trim($data['url']), (int) ($data['frames'] ?? 8));
        } catch (RuntimeException $e) {
            return $this->gagal($e->getMessage(), 422);
        }

        KuotaHarian::hit('reverse');

        return response()->json(['ok' => true] + $ref + ['quota' => $this->kuota()]);
    }

    /** Baca referensinya jadi ekstrak yang sudah divalidasi ke kamus tag. */
    public function baca(Request $request): JsonResponse
    {
        if (! AiClient::siapProfil('vision')) {
            return $this->gagal('Profil AI vision belum diisi. Isi VENICE_API_KEY (atau AI_VISION_API_KEY) di config.local.php.', 503);
        }

        $kind = $request->input('kind') === 'video' ? 'video' : 'image';

        $mentah = $request->input('images');
        $mentah = is_array($mentah) ? array_values($mentah) : [];
        if ($mentah === []) {
            return $this->gagal('Belum ada gambar yang diunggah.');
        }
        if (count($mentah) > (int) REVERSE_MAX_FRAMES) {
            return $this->gagal('Terlalu banyak frame (maks ' . (int) REVERSE_MAX_FRAMES . ').', 422);
        }

        $images = [];
        foreach ($mentah as $i => $g) {
            try {
                $img = $this->bacaGambar($g, 'gambar ke-' . ($i + 1));
            } catch (InvalidArgumentException $e) {
                return $this->gagal($e->getMessage(), 422);
            }
            if ($img !== null) {
                $images[] = $img;
            }
        }
        if ($images === []) {
            return $this->gagal('Tidak ada gambar yang sah.');
        }

        $sheet = null;
        if ($kind === 'video' && is_array($request->input('sheet'))) {
            try {
                $sheet = $this->bacaGambar($request->input('sheet'), 'contact sheet');
            } catch (InvalidArgumentException $e) {
                return $this->gagal($e->getMessage(), 422);
            }
        }

        if (($tolak = $this->jagaKuota()) !== null) {
            return $tolak;
        }

        $durasi = $request->input('duration');
        $hint = mb_substr(trim((string) $request->input('hint', '')), 0, ReversePrompt::MAKS_HINT);

        // Membaca satu gambar dengan model vision sering lebih lama dari
        // batas waktu bawaan PHP; halaman yang menunggunya sudah menampilkan
        // perkiraan waktu, bukan diam.
        @set_time_limit(0);

        try {
            $hasil = ReversePrompt::baca($images, $sheet, $kind, is_numeric($durasi) ? max(0.0, (float) $durasi) : null, $hint);
        } catch (RuntimeException $e) {
            return $this->gagal('AI vision gagal dipanggil: ' . $e->getMessage(), 502);
        }

        KuotaHarian::hit('reverse');

        // Nama model pembaca dititipkan di dalam ekstrak: baca dan susun itu
        // dua permintaan terpisah, jadi tanpa ini panel "tahap yang dipakai"
        // tidak pernah tahu siapa yang membaca.
        $hasil['ekstrak']['pembaca'] = $hasil['model'];

        $val = ReversePrompt::validasi($hasil['ekstrak']);

        return response()->json([
            'ok'       => true,
            'ekstrak'  => $val['ekstrak'],
            'ringkas'  => $hasil['ringkas'],
            'validasi' => [
                'karakter'    => $val['karakter'],
                'tag_dikenal' => $val['tag_dikenal'],
                'tag_ditolak' => $val['tag_ditolak'],
                'catatan'     => array_merge($hasil['catatan'] ?? [], $val['catatan']),
            ],
            'model' => $hasil['model'],
            'quota' => $this->kuota(),
            'token' => ['rincian' => AiClient::pemakaian(), 'jumlah' => AiClient::totalToken()],
        ]);
    }

    /** Susun ekstraknya jadi prompt, lalu simpan ke riwayat. */
    public function susun(Request $request): JsonResponse
    {
        $ekstrak = $request->input('ekstrak');
        if (is_string($ekstrak)) {
            $ekstrak = json_decode($ekstrak, true);
        }
        if (! is_array($ekstrak) || ! is_array($ekstrak['subjects'] ?? null)) {
            return $this->gagal('Hasil pembacaan (ekstrak) belum ada atau rusak. Baca referensinya dulu.');
        }

        $target = (string) $request->input('target', 'nai5');
        if (! isset(ReversePrompt::TARGET[$target])) {
            return $this->gagal('Target tidak dikenal. Pakai: ' . implode(', ', array_keys(ReversePrompt::TARGET)));
        }

        $opsi = $this->opsi(is_array($request->input('opsi')) ? $request->input('opsi') : []);

        // Tahap polish memakai AI hanya kalau profilnya siap; kalau tidak,
        // hasilnya tetap keluar dari aturan kode. Jatah dipotong hanya kalau
        // memang ada AI yang dipanggil.
        $pakaiAi = $opsi['polish'] && AiClient::siapProfil('polish');
        if ($pakaiAi && ($tolak = $this->jagaKuota()) !== null) {
            return $tolak;
        }

        @set_time_limit(0);

        try {
            $hasil = ReversePrompt::susun($ekstrak, $target, $opsi);
        } catch (InvalidArgumentException $e) {
            return $this->gagal($e->getMessage());
        } catch (RuntimeException $e) {
            return $this->gagal('Gagal menyusun: ' . $e->getMessage(), 502);
        }

        if ($pakaiAi && ($hasil['tahap']['polish']['model'] ?? null) !== null) {
            KuotaHarian::hit('reverse');
        }

        $genId = $this->simpan($request, $hasil, $ekstrak, $target, $opsi);

        unset($hasil['rencana']);

        return response()->json(['ok' => true] + $hasil + [
            'quota'         => $this->kuota(),
            'generation_id' => $genId,
            'token'         => ['rincian' => AiClient::pemakaian(), 'jumlah' => AiClient::totalToken()],
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * Pilihan penyusunan.
     *
     * Empat saklar pertama praktis selalu menyala dan tidak lagi ditanyakan
     * di halaman; yang tersisa untuk diatur cuma umur dan gaya.
     */
    private function opsi(array $o): array
    {
        $g = is_array($o['gaya'] ?? null) ? $o['gaya'] : [];

        return [
            'nsfw'     => ! array_key_exists('nsfw', $o) || ! empty($o['nsfw']),
            'haluskan' => ! array_key_exists('haluskan', $o) || ! empty($o['haluskan']),
            'polish'   => ! array_key_exists('polish', $o) || ! empty($o['polish']),
            'fewshot'  => ! array_key_exists('fewshot', $o) || ! empty($o['fewshot']),
            // Dewasa menyala bawaan (NovelAI condong ke wajah remaja kalau
            // dibiarkan); aged_up mati bawaan karena cuma perlu untuk
            // karakter yang aslinya anak-anak.
            'dewasa'   => ! array_key_exists('dewasa', $o) || ! empty($o['dewasa']),
            'aged_up'  => ! empty($o['aged_up']),
            'gaya'     => [
                'style_id' => (int) ($g['style_id'] ?? 0) > 0 ? (int) $g['style_id'] : null,
                'artis'    => mb_substr(trim((string) ($g['artis'] ?? '')), 0, ReversePrompt::MAKS_ARTIS),
                'kuat'     => isset(ReversePrompt::KUAT[(string) ($g['kuat'] ?? '')]) ? (string) $g['kuat'] : 'sedang',
            ],
            'wan'      => ['rasio' => '16:9', 'detik' => 0],
        ];
    }

    /** Simpan hasilnya ke riwayat: pembacaannya, bukan cuma teksnya. */
    private function simpan(Request $request, array $hasil, array $ekstrak, string $target, array $opsi): int
    {
        $judul = [];
        foreach ($hasil['karakter'] as $k) {
            if ($k !== null) {
                $judul[] = $k['name'];
            }
        }

        $label = $target === 'nai5' ? 'Dari Gambar' : ('Dari Video · ' . ReversePrompt::TARGET[$target]);
        $title = mb_substr(($judul === [] ? '' : implode(' vs ', $judul) . ' — ') . $label, 0, 150);

        $teksAman = $target === 'nai5'
            ? (string) $hasil['outputs']['sfw']['flat']
            : (string) $hasil['outputs']['sfw']['prompt'];

        Database::run(
            'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                (int) $request->user()->id,
                $hasil['mode'],
                $target,
                $title,
                json_encode([
                    'mode'    => 'reverse',
                    'target'  => $target,
                    'ekstrak' => ReversePrompt::normalisasi($ekstrak),
                    'opsi'    => $opsi,
                ], JSON_UNESCAPED_UNICODE),
                $teksAman,
                $target === 'nai5' ? (string) $hasil['outputs']['sfw']['undesired'] : '',
                min(65535, (int) $hasil['token_estimate']),
                1,
                KuotaHarian::ipHash(),
            ]
        );

        // lastId() harus dibaca SEBELUM query lain (mysqlnd memulangkan 0
        // kalau pernyataan terakhir bukan INSERT).
        return (int) Database::lastId();
    }

    /**
     * Periksa satu gambar base64: mime, ukuran, dan bahwa isinya memang
     * gambar — bukan sekadar mengaku lewat mime-nya.
     */
    private function bacaGambar($g, string $untuk): ?array
    {
        if (! is_array($g)) {
            return null;
        }

        $mime = strtolower(trim((string) ($g['mime'] ?? '')));
        if (! in_array($mime, self::MIME, true)) {
            throw new InvalidArgumentException("Format {$untuk} harus JPEG, PNG, atau WebP.");
        }

        $data = (string) ($g['data'] ?? '');
        if (str_starts_with($data, 'data:')) {
            $data = (string) preg_replace('/^data:[^,]*,/', '', $data);
        }
        $data = preg_replace('/\s+/', '', $data) ?? '';
        $bin = base64_decode($data, true);

        if ($bin === false || strlen($bin) < 64) {
            throw new InvalidArgumentException("Data {$untuk} tidak bisa dibaca (bukan base64 yang sah).");
        }
        if (strlen($bin) > (int) REVERSE_MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException(
                "Ukuran {$untuk} terlalu besar (maks " . round(REVERSE_MAX_IMAGE_BYTES / 1048576, 1) . ' MB). Kecilkan dulu.'
            );
        }

        // Sidik jari format, supaya berkas lain tidak lolos cuma karena
        // mime-nya diaku.
        $magic = substr($bin, 0, 12);
        $sah = ($mime === 'image/jpeg' && str_starts_with($magic, "\xFF\xD8\xFF"))
            || ($mime === 'image/png' && str_starts_with($magic, "\x89PNG\r\n\x1a\n"))
            || ($mime === 'image/webp' && substr($magic, 0, 4) === 'RIFF' && substr($magic, 8, 4) === 'WEBP');

        if (! $sah) {
            throw new InvalidArgumentException("Isi {$untuk} tidak cocok dengan formatnya.");
        }

        return [
            'data' => base64_encode($bin),
            'mime' => $mime,
            't'    => isset($g['t']) && is_numeric($g['t']) ? (float) $g['t'] : null,
            'w'    => (int) ($g['w'] ?? 0),
            'h'    => (int) ($g['h'] ?? 0),
        ];
    }

    /** @return list<array{id:int,nama:string,kategori:string,nsfw:bool,ket:string}> */
    private function modulGaya(string $tipe): array
    {
        try {
            $modul = PromptBuilder::listModules($tipe, ALLOW_NSFW);
        } catch (Throwable) {
            return [];
        }

        // Gambar contoh tiap gaya (dibuat `php artisan gaya:contoh`) dibaca
        // sekali sebagai daftar, bukan diperiksa satu per satu: lima puluh
        // is_file() di tiap kunjungan halaman itu lima puluh kali menyentuh
        // disk untuk pertanyaan yang jawabannya sama sepanjang hari.
        // Yang bergerak didahulukan: contoh tiga frame (gaya:gerak) jauh
        // lebih memberi tahu daripada gambar diam, dan yang belum punya
        // tetap memakai gambar diamnya.
        $contoh = [];
        foreach (glob(public_path('img/gaya/*.webp')) ?: [] as $berkas) {
            $contoh[pathinfo($berkas, PATHINFO_FILENAME)] = '/img/gaya/'.basename($berkas);
        }
        foreach (glob(public_path('img/gaya-gerak/*.webp')) ?: [] as $berkas) {
            $contoh[pathinfo($berkas, PATHINFO_FILENAME)] = '/img/gaya-gerak/'.basename($berkas);
        }

        return array_map(static fn (array $m): array => [
            'id'       => (int) $m['id'],
            'nama'     => (string) ($m['name_id'] ?: $m['name']),
            'kategori' => (string) ($m['category'] ?? ''),
            'nsfw'     => (int) ($m['is_nsfw'] ?? 0) === 1,
            'ket'      => (string) ($m['description'] ?? ''),
            'contoh'   => $contoh[(string) $m['slug']] ?? null,
        ], $modul);
    }

    private function kuota(): array
    {
        return KuotaHarian::check('reverse', (int) REVERSE_DAILY_LIMIT_PER_IP);
    }

    private function jagaKuota(): ?JsonResponse
    {
        $q = $this->kuota();
        if ($q['ok']) {
            return null;
        }

        return response()->json([
            'ok'    => false,
            'error' => "Jatah hari ini sudah habis ({$q['limit']}x). Coba lagi besok, atau naikkan REVERSE_DAILY_LIMIT_PER_IP.",
            'quota' => $q,
        ], 429);
    }

    private function gagal(string $pesan, int $kode = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $pesan], $kode);
    }
}
