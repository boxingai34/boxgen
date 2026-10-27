<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
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
 * Pilihan berupa teks: pilihan bergambar ada di spesifikasi API v3 tapi
 * ditandai "not in use in the live version".
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
    public static function buat(string $kategori, array $ids, string $judul, int $tutup, string $hasilTerlihat, ?int $kini = null): array
    {
        $kini ??= time();
        if (!self::bisa()) {
            throw new RuntimeException('STRAWPOLL_API_KEY belum diisi di .env server ini.');
        }
        if (!in_array($kategori, AgentFbx::KATEGORI, true)) {
            throw new InvalidArgumentException('Kategori poll tidak dikenal.');
        }
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
                'label'       => mb_substr($k->karakter . ($kategori === 'MIXED FIGHT' && $k->pasangan !== '' ? ' vs ' . $k->pasangan : ''), 0, 200),
                'keterangan'  => mb_substr(implode(' · ', array_filter([$k->sumber, $remaja ? 'SFW only' : ''])), 0, 200),
            ];
        }

        $poll = self::minta('post', '/polls', [
            'title'        => $judul,
            'type'         => 'multiple_choice',
            'poll_options' => array_map(fn ($p, $i) => array_filter([
                'type' => 'text', 'position' => $i, 'value' => $p['label'], 'description' => $p['keterangan'],
            ], fn ($v) => $v !== ''), $pilihan, array_keys($pilihan)),
            'poll_config'  => [
                'is_private'           => true,
                'is_multiple_choice'   => false,
                'deadline_at'          => $tutup,
                'duplication_checking' => 'ip',
                'allow_vpn_users'      => false,
                'allow_comments'       => false,
                'allow_other_option'   => false,
                'require_voter_names'  => false,
                'results_visibility'   => $hasilTerlihat,
            ],
        ]);
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
            'id'       => $id,
            'url'      => 'https://strawpoll.com/' . $id,
            'judul'    => $judul,
            'kategori' => $kategori,
            'tutup'    => $tutup,
            'hasil'    => $hasilTerlihat,
            'dibuat'   => date('c', $kini),
            'pilihan'  => $pilihan,
        ];
        self::simpan($catatan);

        return $catatan;
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
            $pilihan[] = $p;
        }
        usort($pilihan, fn ($a, $b) => $b['suara'] <=> $a['suara']);

        return [
            'id'      => $id,
            'suara'   => (int) ($h['voteCount'] ?? array_sum(array_column($pilihan, 'suara'))),
            'peserta' => (int) ($h['participantCount'] ?? 0),
            'ditutup' => ($kini ?? time()) >= (int) $catatan['tutup'],
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
            $semua = array_slice([$catatan, ...array_filter(is_array($lama) ? $lama : [], 'is_array')], 0, self::BATAS_CATATAN);
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
        $klien = Http::withHeaders(['X-API-Key' => self::kunci(), 'Accept' => 'application/json'])->timeout(30)->connectTimeout(15);
        if (defined('CA_BUNDLE') && CA_BUNDLE !== '') {
            $klien = $klien->withOptions(['verify' => CA_BUNDLE]);
        }

        try {
            $res = $metode === 'post' ? $klien->post(self::API . $jalur, $isi ?? []) : $klien->get(self::API . $jalur);
        } catch (ConnectionException $e) {
            throw new RuntimeException('StrawPoll tidak terjangkau: ' . $e->getMessage());
        }
        $j = $res->json();
        if (!$res->successful() || !is_array($j)) {
            $pesan = is_array($j) ? ($j['error']['message'] ?? $j['message'] ?? json_encode($j)) : mb_substr(trim(strip_tags($res->body())), 0, 200);
            $pesan = rtrim((string) $pesan, '. ');
            $saran = in_array($res->status(), [401, 403], true) ? ' Periksa STRAWPOLL_API_KEY.' : '';
            throw new RuntimeException("StrawPoll menolak ({$res->status()}): $pesan.$saran");
        }

        return $j;
    }
}
