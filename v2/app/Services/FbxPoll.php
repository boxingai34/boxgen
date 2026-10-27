<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Poll StrawPoll dari kandidat Scout — tautannya dibagikan di post Patreon.
 *
 * Isinya HANYA kandidat Scout (umurnya sudah dicek), satu kategori per
 * poll. Aturannya tetap untuk semua poll: satu pilihan, ditutup pada waktu
 * yang ditentukan, satu suara per IP dan pengguna VPN ditolak, tidak
 * tampil di pencarian StrawPoll (tetap bisa dibuka siapa pun yang punya
 * tautannya, tanpa login). Kandidat 16–17 diberi keterangan "SFW only";
 * di Mixed Fight dan Waifu Crushed mereka ditolak sama sekali.
 *
 * Pilihan bisa teks atau bergambar (gambarnya dari FbxPollGambar: NovelAI
 * atau unggahan sendiri). Halaman StrawPoll punya "Image poll", tapi
 * spesifikasi API v3 masih menandai pilihan bergambar "not in use in the
 * live version" — jadi kalau StrawPoll menolaknya, poll dibuat sebagai teks.
 *
 * Catatan poll (id, tautan, pemetaan pilihan → kandidat) disimpan di
 * storage/app/fbx-poll.json, per server — bukan tabel, supaya hosting tidak
 * butuh migrasi. Hasilnya selalu dibaca langsung dari StrawPoll.
 *
 * API: https://strawpoll.com/docs/api/strawpoll-api-v3.html (kunci di
 * STRAWPOLL_API_KEY, dibuat di pengaturan akun StrawPoll).
 */
final class FbxPoll
{
    private const API = 'https://api.strawpoll.com/v3';

    private const BATAS_CATATAN = 60;

    /** Kapan pemilih boleh melihat hasil. */
    public const HASIL = ['after_vote', 'after_deadline', 'always'];

    public static function bisa(): bool
    {
        return self::kunci() !== '';
    }

    private static function kunci(): string
    {
        return (string) config('fbx.strawpoll_kunci', '');
    }

    private static function berkas(): string
    {
        return storage_path('app/fbx-poll.json');
    }

    /**
     * Poll yang pernah dibuat dari server ini, terbaru dulu.
     *
     * @return list<array<string,mixed>>
     */
    public static function daftar(): array
    {
        $isi = is_file(self::berkas()) ? json_decode((string) file_get_contents(self::berkas()), true) : [];

        return array_values(array_filter(is_array($isi) ? $isi : [], 'is_array'));
    }

    /**
     * Buat poll di StrawPoll dari kandidat terpilih.
     *
     * @param list<int> $ids kandidat, urutannya jadi urutan pilihan
     * @return array<string,mixed> catatan poll (id, url, judul, ...)
     *
     * @throws InvalidArgumentException isian tidak sah atau melanggar aturan umur
     * @throws RuntimeException         StrawPoll menolak atau tidak terjangkau
     */
    public static function buat(string $kategori, array $ids, string $judul, int $tutup, string $hasilTerlihat, bool $gambar = false, ?int $kini = null): array
    {
        $kini ??= time();
        if (!self::bisa()) {
            throw new RuntimeException('STRAWPOLL_API_KEY belum diisi di .env server ini.');
        }
        $judul = self::cekSetelan($judul, $tutup, $hasilTerlihat, $kini);
        $pilihan = self::susun($kategori, $ids, $gambar);

        $isi = fn (bool $bergambar, array $media = []) => [
            'title'        => $judul,
            'type'         => $bergambar ? 'image_poll' : 'multiple_choice',
            'poll_options' => array_map(fn ($p, $i) => array_filter([
                'type' => $bergambar ? 'image' : 'text', 'position' => $i, 'value' => $p['label'], 'description' => $p['keterangan'],
                'media' => $bergambar ? ['id' => $media[$i]] : '',
            ], fn ($v) => $v !== ''), $pilihan, array_keys($pilihan)),
            'poll_config'  => self::SETELAN + ['deadline_at' => $tutup, 'results_visibility' => $hasilTerlihat],
        ];

        // Pilihan bergambar ada di halaman StrawPoll, tapi spesifikasi API-nya masih menyebutnya
        // "not in use in the live version". Kalau StrawPoll menolak bentuknya (4xx selain kunci),
        // poll tetap dibuat sebagai teks dan halaman diberi tahu. (Per 2026-10-27 /upload malah
        // 504 untuk semua berkas — halaman memakai jalur manual: paket() lalu tautkan().)
        $peringatan = '';
        $bergambar = false;
        if ($gambar) {
            try {
                $media = array_map(fn ($p) => self::unggahMedia((string) FbxPollGambar::berkas($p['gambar'])), $pilihan);
                $poll = self::minta('post', '/polls', $isi(true, $media));
                $bergambar = true;
            } catch (RuntimeException $e) {
                if ($e->getCode() < 400 || $e->getCode() >= 500 || in_array($e->getCode(), [401, 403], true)) {
                    throw $e;
                }
                $peringatan = 'StrawPoll menolak pilihan bergambar (' . $e->getMessage() . '), jadi poll dibuat sebagai teks.';
            }
        }
        $poll ??= self::minta('post', '/polls', $isi(false));

        $id = (string) ($poll['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]+$/', $id)) {
            throw new RuntimeException('StrawPoll tidak mengembalikan id poll.');
        }

        // Id pilihan dari StrawPoll, dicocokkan lewat posisinya — hasil nanti dibaca lewat id ini.
        foreach ((array) ($poll['poll_options'] ?? []) as $i => $o) {
            $pos = (int) ($o['position'] ?? $i);
            if (isset($pilihan[$pos])) {
                $pilihan[$pos]['opsi'] = (string) ($o['id'] ?? '');
            }
        }

        $catatan = [
            'id'        => $id,
            'url'       => 'https://strawpoll.com/' . $id,
            'judul'     => $judul,
            'kategori'  => $kategori,
            'tutup'     => $tutup,
            'hasil'     => $hasilTerlihat,
            'bergambar' => $bergambar,
            'dibuat'    => date('c', $kini),
            'pilihan'   => $pilihan,
        ];
        self::simpan($catatan);

        return $catatan + ['peringatan' => $peringatan];
    }

    /** Setelan tetap semua poll FEMBOX — dipakai buat(), dan ditulis di paket untuk poll manual. */
    private const SETELAN = [
        'is_private'           => true,
        'is_multiple_choice'   => false,
        'duplication_checking' => 'ip',
        'allow_vpn_users'      => false,
        'allow_comments'       => false,
        'allow_other_option'   => false,
        'require_voter_names'  => false,
    ];

    /** Periksa judul, waktu tutup, dan "hasil terlihat"; pulang judul yang sudah dirapikan. */
    private static function cekSetelan(string $judul, int $tutup, string $hasilTerlihat, int $kini): string
    {
        $judul = mb_substr(trim($judul), 0, 200);
        if ($judul === '') {
            throw new InvalidArgumentException('Judul poll kosong.');
        }
        if ($tutup < $kini + 600 || $tutup > $kini + 90 * 86400) {
            throw new InvalidArgumentException('Waktu tutup harus antara 10 menit dan 90 hari dari sekarang.');
        }
        if (!in_array($hasilTerlihat, self::HASIL, true)) {
            throw new InvalidArgumentException('Pilihan "hasil terlihat" tidak dikenal.');
        }

        return $judul;
    }

    /**
     * Kandidat → pilihan poll, dengan aturan yang sama untuk semua jalur:
     * satu kategori, tidak ditolak, 16–17 hanya Female Fight (diberi
     * "SFW only"), 2–30 pilihan. $gambar = tiap pilihan wajib sudah punya gambar.
     *
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private static function susun(string $kategori, array $ids, bool $gambar): array
    {
        if (!in_array($kategori, AgentFbx::KATEGORI, true)) {
            throw new InvalidArgumentException('Kategori poll tidak dikenal.');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) < 2 || count($ids) > 30) {
            throw new InvalidArgumentException('Poll butuh 2 sampai 30 kandidat.');
        }
        $baris = DB::table('fbx_kandidat')->whereIn('id', $ids)->get()->keyBy('id');

        $pilihan = [];
        foreach ($ids as $id) {
            $k = $baris[$id] ?? null;
            if ($k === null || $k->kategori !== $kategori || $k->status === 'ditolak') {
                throw new InvalidArgumentException('Ada kandidat yang tidak ada, beda kategori, atau sudah ditolak.');
            }
            $remaja = $k->tingkat_umur === '16-17';
            if ($remaja && $kategori !== 'FEMALE FIGHT') {
                throw new InvalidArgumentException("{$k->karakter} berumur canon 16–17 — hanya boleh di Female Fight.");
            }
            $pilihan[] = [
                'kandidat_id' => $id,
                // Id kandidat di hosting berganti tiap fbx:kirim; hasil() mencarinya lagi lewat kunci ini.
                'kunci'       => ['minggu' => $k->minggu, 'kategori' => $k->kategori, 'karakter' => $k->karakter, 'pasangan' => $k->pasangan],
                'gambar'      => FbxPollGambar::kunci($k),
                'label'       => mb_substr($k->karakter . ($kategori === 'MIXED FIGHT' && $k->pasangan !== '' ? ' vs ' . $k->pasangan : ''), 0, 200),
                'keterangan'  => mb_substr(implode(' · ', array_filter([$k->sumber, $remaja ? 'SFW only' : ''])), 0, 200),
            ];
        }
        if ($gambar) {
            foreach ($pilihan as $p) {
                if (FbxPollGambar::berkas($p['gambar']) === null) {
                    throw new InvalidArgumentException("Belum ada gambar untuk {$p['label']} — gambar dengan NovelAI atau unggah dulu.");
                }
            }
        }

        return $pilihan;
    }

    /**
     * Paket untuk poll bergambar yang dibuat tangan di strawpoll.com/create —
     * API StrawPoll belum bisa menerima gambar (lihat buat()). Isinya gambar
     * bernomor sesuai urutan pilihan dan pilihan.txt: label, keterangan, dan
     * setelan yang harus dipasang supaya sama dengan poll buatan buat().
     *
     * @param string $tutupTeks waktu tutup dalam jam setempat pemakai (server memakai UTC)
     * @return array{nama:string, isi:string}
     */
    public static function paket(string $kategori, array $ids, string $judul, int $tutup, string $hasilTerlihat, string $tutupTeks = '', ?int $kini = null): array
    {
        $judul = self::cekSetelan($judul, $tutup, $hasilTerlihat, $kini ?? time());
        $pilihan = self::susun($kategori, $ids, true);
        $tutupTeks = trim((string) preg_replace('/[^\p{L}\p{N} :.,\/-]+/u', '', $tutupTeks)) ?: gmdate('j M Y H:i', $tutup) . ' UTC';
        $hasil = ['after_vote' => 'setelah memilih', 'after_deadline' => 'setelah poll ditutup', 'always' => 'selalu'][$hasilTerlihat];

        $berkas = [];
        $teks = ["Judul: $judul", 'Jenis poll: Image poll', '', 'Pilihan — unggah gambar sesuai nomornya, label diisi persis:'];
        foreach ($pilihan as $i => $p) {
            $no = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $nama = trim((string) preg_replace('/[\\\\\/:*?"<>|]+/', '-', $p['label']));
            $berkas["$no $nama.jpg"] = (string) file_get_contents((string) FbxPollGambar::berkas($p['gambar']));
            $teks[] = "$no. {$p['label']}" . ($p['keterangan'] !== '' ? "  ({$p['keterangan']})" : '');
        }
        array_push($teks, '', 'Setelan poll (Settings):',
            '- Satu pilihan saja: multiple choice MATI',
            "- Batas waktu (deadline): $tutupTeks",
            '- Cek suara ganda: per alamat IP',
            '- Pengguna VPN: tidak boleh memilih',
            '- Sembunyikan dari pencarian / private: NYALA (tetap bisa dibuka lewat tautan, tanpa login)',
            "- Hasil terlihat: $hasil",
            '', 'Setelah poll jadi, salin tautannya ke AGENT FBX → Buat StrawPoll → "Tautkan poll", supaya hasil dan tombol Pilih jalan.');
        $berkas['pilihan.txt'] = "\xEF\xBB\xBF" . implode("\r\n", $teks) . "\r\n";

        return ['nama' => 'strawpoll-' . FbxPollGambar::siput($kategori) . '.zip', 'isi' => FbxPollGambar::zip($berkas)];
    }

    /**
     * Tautkan poll yang dibuat tangan di StrawPoll ke kandidatnya, supaya
     * hasil() dan tombol Pilih jalan seperti poll buatan buat(). Pilihan
     * dicocokkan lewat URUTAN (seperti nomor di paket); setelan yang
     * menyimpang dari aturan poll FEMBOX dilaporkan, bukan ditolak.
     *
     * @return array<string,mixed> catatan poll + 'peringatan'
     */
    public static function tautkan(string $tautan, string $kategori, array $ids, ?int $kini = null): array
    {
        if (!self::bisa()) {
            throw new RuntimeException('STRAWPOLL_API_KEY belum diisi di .env server ini.');
        }
        $tautan = trim($tautan);
        if (preg_match('~^(?:https?://)?(?:www\.)?strawpoll\.com/(?:polls/|embed/)?([A-Za-z0-9]{5,20})(?:[/?#].*)?$~i', $tautan, $m)) {
            $id = $m[1];
        } elseif (preg_match('/^[A-Za-z0-9]{5,20}$/', $tautan)) {
            $id = $tautan;
        } else {
            throw new InvalidArgumentException('Itu bukan tautan poll StrawPoll (contoh: https://strawpoll.com/AbC123xyz).');
        }
        $pilihan = self::susun($kategori, $ids, false);

        try {
            $poll = self::minta('get', '/polls/' . $id);
        } catch (RuntimeException $e) {
            throw $e->getCode() >= 400 && $e->getCode() < 500 && !in_array($e->getCode(), [401, 403], true)
                ? new InvalidArgumentException("Poll $id tidak ditemukan di StrawPoll.") : $e;
        }
        $opsi = array_values(array_filter((array) ($poll['poll_options'] ?? []), 'is_array'));
        usort($opsi, fn ($a, $b) => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));
        if (count($opsi) !== count($pilihan)) {
            throw new InvalidArgumentException('Poll itu punya ' . count($opsi) . ' pilihan, sedangkan yang dicentang ' . count($pilihan) . '.');
        }

        $peringatan = [];
        foreach ($opsi as $i => $o) {
            $pilihan[$i]['opsi'] = (string) ($o['id'] ?? '');
            $kata = explode(' ', $pilihan[$i]['label'])[0];
            if (mb_stripos((string) ($o['value'] ?? ''), $kata) === false) {
                $peringatan[] = 'Pilihan #' . ($i + 1) . ' di StrawPoll "' . ($o['value'] ?? '') . "\" tidak menyebut \"$kata\" — periksa urutannya.";
            }
        }
        $c = (array) ($poll['poll_config'] ?? []);
        if (!empty($c['is_multiple_choice'])) {
            $peringatan[] = 'Poll ini membolehkan lebih dari satu pilihan.';
        }
        if (empty($c['deadline_at'])) {
            $peringatan[] = 'Poll ini belum punya batas waktu.';
        }
        if (($c['duplication_checking'] ?? 'ip') !== 'ip') {
            $peringatan[] = 'Cek suara ganda poll ini bukan per IP.';
        }
        if (!empty($c['allow_vpn_users'])) {
            $peringatan[] = 'Pengguna VPN masih boleh memilih.';
        }

        $catatan = [
            'id'        => $id,
            'url'       => 'https://strawpoll.com/' . $id,
            'judul'     => mb_substr((string) ($poll['title'] ?? $id), 0, 200),
            'kategori'  => $kategori,
            'tutup'     => (int) ($c['deadline_at'] ?? 0),
            'hasil'     => (string) ($c['results_visibility'] ?? ''),
            'bergambar' => ($poll['type'] ?? '') === 'image_poll',
            'manual'    => true,
            'dibuat'    => date('c', $kini ?? time()),
            'pilihan'   => $pilihan,
        ];
        self::simpan($catatan);

        return $catatan + ['peringatan' => implode(' ', $peringatan)];
    }

    /** Unggah satu gambar pilihan ke StrawPoll; pulang id media-nya. */
    private static function unggahMedia(string $file): string
    {
        $j = self::jawab(fn (PendingRequest $k) => $k->attach('file', (string) file_get_contents($file), basename($file), ['Content-Type' => 'image/jpeg'])
            ->post(self::API . '/upload', ['type' => 'poll_option']));
        $id = (string) ($j['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('StrawPoll tidak mengembalikan id gambar.', 422);
        }

        return $id;
    }

    /** Id kandidat SEKARANG untuk satu pilihan — di hosting id berganti tiap fbx:kirim. */
    private static function idKini(array $p): ?int
    {
        if (!isset($p['kunci']) || !is_array($p['kunci'])) {
            return isset($p['kandidat_id']) ? (int) $p['kandidat_id'] : null;
        }
        $id = DB::table('fbx_kandidat')->where(array_map('strval', $p['kunci']))->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Hasil terbaru dari StrawPoll, dipetakan balik ke kandidat, terbanyak dulu.
     *
     * @return array{id:string, suara:int, peserta:int, ditutup:bool, pilihan: list<array<string,mixed>>}
     */
    public static function hasil(string $id, ?int $kini = null): array
    {
        $catatan = collect(self::daftar())->firstWhere('id', $id);
        if ($catatan === null) {
            throw new InvalidArgumentException('Poll itu tidak dibuat dari server ini.');
        }
        $h = self::minta('get', '/polls/' . rawurlencode($id) . '/results');

        $suara = [];
        foreach ((array) ($h['poll_options'] ?? []) as $i => $o) {
            $suara['id:' . ($o['id'] ?? '')] = (int) ($o['vote_count'] ?? 0);
            $suara['pos:' . (int) ($o['position'] ?? $i)] = (int) ($o['vote_count'] ?? 0);
        }
        $pilihan = [];
        foreach ($catatan['pilihan'] as $i => $p) {
            $p['suara'] = $suara['id:' . ($p['opsi'] ?? '')] ?? $suara['pos:' . $i] ?? 0;
            $p['kandidat_id'] = self::idKini($p);
            $pilihan[] = $p;
        }
        usort($pilihan, fn ($a, $b) => $b['suara'] <=> $a['suara']);

        return [
            'id'      => $id,
            'suara'   => (int) ($h['voteCount'] ?? array_sum(array_column($pilihan, 'suara'))),
            'peserta' => (int) ($h['participantCount'] ?? 0),
            'ditutup' => (int) $catatan['tutup'] > 0 && ($kini ?? time()) >= (int) $catatan['tutup'],
            'pilihan' => $pilihan,
        ];
    }

    /** @param array<string,mixed> $catatan */
    private static function simpan(array $catatan): void
    {
        $file = self::berkas();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        $fh = fopen($file, 'c+');
        if ($fh === false) {
            throw new RuntimeException("Tidak bisa menulis $file");
        }
        try {
            flock($fh, LOCK_EX);
            $lama = json_decode((string) stream_get_contents($fh), true);
            // Poll yang ditautkan ulang menggantikan catatan lamanya, bukan menggandakan.
            $lama = array_filter(is_array($lama) ? $lama : [], fn ($p) => is_array($p) && ($p['id'] ?? '') !== $catatan['id']);
            $semua = array_slice([$catatan, ...$lama], 0, self::BATAS_CATATAN);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($semua, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** @return array<string,mixed> */
    private static function minta(string $metode, string $jalur, ?array $isi = null): array
    {
        return self::jawab(fn (PendingRequest $k) => $metode === 'post' ? $k->post(self::API . $jalur, $isi ?? []) : $k->get(self::API . $jalur));
    }

    private static function klien(): PendingRequest
    {
        $klien = Http::withHeaders(['X-API-Key' => self::kunci(), 'Accept' => 'application/json'])->timeout(60)->connectTimeout(15);
        if (defined('CA_BUNDLE') && CA_BUNDLE !== '') {
            $klien = $klien->withOptions(['verify' => CA_BUNDLE]);
        }

        return $klien;
    }

    /**
     * Kirim lalu baca jawabannya. Galat StrawPoll membawa kode HTTP-nya di
     * getCode(), supaya buat() bisa membedakan "bentuknya ditolak" (4xx)
     * dari "kuncinya salah" atau "tidak terjangkau" (0).
     *
     * @param callable(PendingRequest): Response $kirim
     * @return array<string,mixed>
     */
    private static function jawab(callable $kirim): array
    {
        try {
            $res = $kirim(self::klien());
        } catch (ConnectionException $e) {
            throw new RuntimeException('StrawPoll tidak terjangkau: ' . $e->getMessage());
        }
        $j = $res->json();
        if (!$res->successful() || !is_array($j)) {
            $pesan = is_array($j) ? ($j['error']['message'] ?? $j['message'] ?? json_encode($j)) : mb_substr(trim(strip_tags($res->body())), 0, 200);
            $pesan = rtrim((string) $pesan, '. ');
            $saran = in_array($res->status(), [401, 403], true) ? ' Periksa STRAWPOLL_API_KEY.' : '';
            throw new RuntimeException("StrawPoll menolak ({$res->status()}): $pesan.$saran", $res->status());
        }

        return $j;
    }
}
