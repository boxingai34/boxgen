<?php

namespace App\Services;

use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * AGENT FBX — jembatan antara folder Drive agent FEMBOX dan halaman admin.
 *
 * Agent Scout menulis ke D:\My Drive\FEMBOX\_DATA (CSV + markdown) supaya
 * hasilnya bisa dibuka dari mana saja, juga tanpa website. Halaman admin
 * membaca salinannya di tabel fbx_*, yang diisi sinkron().
 *
 * PEMILIK TIAP DATA
 *
 *   roster.csv     disunting tangan di Excel, jadi berkasnya yang menang:
 *                  tabelnya diganti seluruhnya tiap sinkron.
 *   kandidat.csv   isinya dari Scout, tapi STATUS-nya milik halaman admin
 *                  (Masuk poll / Terpilih / Tolak). Sinkron memasukkan baris
 *                  baru, memperbarui isi baris lama tanpa menyentuh status
 *                  yang sudah diubah di halaman, lalu menulis seluruh tabel
 *                  balik ke CSV — supaya Scout minggu depan tahu siapa yang
 *                  sudah dipoll atau ditolak.
 *   danbooru\*.csv dan riset\*.md cuma dibaca.
 *
 * ATURAN UMUR DITEGAKKAN DI SINI JUGA, bukan cuma di instruksi Scout:
 * baris yang tingkat umurnya bukan DEWASA atau 16-17 tidak masuk, dan
 * 16-17 hanya boleh di Female Fight. Agent yang lupa satu aturan tidak
 * boleh membuat satu kartu pun tampil menyalahinya.
 */
final class AgentFbx
{
    public const KATEGORI = ['FEMALE FIGHT', 'MIXED FIGHT', 'WAIFU CRUSHED'];

    public const STATUS = ['baru', 'dipoll', 'terpilih', 'ditolak'];

    public const TINGKAT = ['DEWASA', '16-17'];

    /** Urutan kolom kandidat.csv — sama dengan instruksi skill scout-karakter. */
    public const KOLOM_KANDIDAT = [
        'minggu', 'kategori', 'karakter', 'sumber', 'pasangan', 'danbooru_tag', 'pasangan_tag',
        'tingkat_umur', 'umur_canon', 'tinggi_cm', 'alasan', 'ide_kostum', 'status', 'bukti_url',
    ];

    public const KOLOM_ROSTER = [
        'bulan', 'kategori', 'karakter', 'sumber', 'lawan', 'babak', 'hasil', 'yakin', 'bukti', 'danbooru_tag',
    ];

    /**
     * Kategori yang bisa diisi dari halaman (Masukkan ke Roster). GIRL OF THE
     * MONTH dan PHOTOBOX nama lama — hanya ada di riwayat, tidak ditawarkan.
     */
    public const KATEGORI_ROSTER = [
        'FEMALE FIGHT', 'MIXED FIGHT', 'MONTHLY SHOWDOWN', 'WAIFU CRUSHED', 'HAWT SHOT', 'CHAMPIONEE', 'REQUESTOR',
    ];

    public static function folder(): string
    {
        return rtrim((string) config('fbx.data'), '\\/');
    }

    public static function bisaSinkron(): bool
    {
        return is_dir(self::folder());
    }

    /**
     * Tarik seluruh isi folder _DATA ke database.
     *
     * @return array{roster:int, kandidat:int, kandidat_baru:int, ditolak_umur:int, tren:int, riset:int}
     */
    public static function sinkron(): array
    {
        $dir = self::folder();
        if (!is_dir($dir)) {
            throw new RuntimeException("Folder data agent tidak ditemukan: $dir");
        }

        $hasil = ['roster' => 0, 'kandidat' => 0, 'kandidat_baru' => 0, 'ditolak_umur' => 0, 'tren' => 0, 'riset' => 0];

        // ---- Roster: berkasnya yang menang ----
        $roster = "$dir/roster.csv";
        if (is_file($roster)) {
            $baris = [];
            foreach (self::bacaCsv($roster) as $r) {
                if (($r['karakter'] ?? '') === '' || !preg_match('/^\d{4}-\d{2}$/', $r['bulan'] ?? '')) {
                    continue;
                }
                $baris[] = array_combine(self::KOLOM_ROSTER, array_map(
                    fn (string $k) => mb_substr(trim((string) ($r[$k] ?? '')), 0, $k === 'bukti' ? 500 : 190),
                    self::KOLOM_ROSTER,
                ));
            }
            DB::transaction(function () use ($baris) {
                DB::table('fbx_roster')->delete();
                foreach (array_chunk($baris, 200) as $potong) {
                    DB::table('fbx_roster')->insert($potong);
                }
            });
            $hasil['roster'] = count($baris);
        }

        // ---- Kandidat: isi dari berkas, status dari halaman ----
        $kandidat = "$dir/kandidat.csv";
        if (is_file($kandidat)) {
            foreach (self::bacaCsv($kandidat) as $r) {
                $k = self::rapikanKandidat($r);
                if ($k === null) {
                    $hasil['ditolak_umur']++;
                    continue;
                }

                $ada = DB::table('fbx_kandidat')->where([
                    'minggu' => $k['minggu'], 'kategori' => $k['kategori'],
                    'karakter' => $k['karakter'], 'pasangan' => $k['pasangan'],
                ])->first(['id', 'status']);

                if ($ada === null) {
                    DB::table('fbx_kandidat')->insert($k);
                    $hasil['kandidat_baru']++;
                } else {
                    // Status yang sudah diubah di halaman tidak ditimpa berkas.
                    $isi = $k;
                    if ($ada->status !== 'baru') {
                        unset($isi['status']);
                    }
                    DB::table('fbx_kandidat')->where('id', $ada->id)->update($isi);
                }
                $hasil['kandidat']++;
            }
            self::tulisKandidat();
        }

        // ---- Tren Danbooru: satu berkas per minggu ----
        foreach (glob("$dir/danbooru/*.csv") ?: [] as $f) {
            $minggu = basename($f, '.csv');
            if (!preg_match('/^\d{4}-W\d{2}$/', $minggu)) {
                continue;
            }
            $baris = [];
            foreach (self::bacaCsv($f) as $r) {
                if (($r['karakter_tag'] ?? '') === '') {
                    continue;
                }
                $baris[] = [
                    'minggu'       => $minggu,
                    'karakter_tag' => mb_substr($r['karakter_tag'], 0, 190),
                    'peringkat'    => (int) ($r['peringkat'] ?? 0),
                    'judul_tag'    => mb_substr((string) ($r['judul_tag'] ?? ''), 0, 190),
                    'post_minggu'  => (int) ($r['post_minggu'] ?? 0),
                    'skor_minggu'  => max(0, (int) ($r['skor_minggu'] ?? 0)),
                    'solo_cewek'   => (int) ($r['solo_cewek'] ?? 0),
                    'total_post'   => (int) ($r['total_post'] ?? 0),
                    'rasio_baru'   => (float) ($r['rasio_baru'] ?? 0),
                    'perubahan'    => mb_substr((string) ($r['perubahan'] ?? ''), 0, 8),
                ];
            }
            DB::transaction(function () use ($minggu, $baris) {
                DB::table('fbx_tren')->where('minggu', $minggu)->delete();
                foreach (array_chunk($baris, 200) as $potong) {
                    DB::table('fbx_tren')->insert($potong);
                }
            });
            $hasil['tren'] += count($baris);
        }

        // ---- Laporan Scout ----
        foreach (glob("$dir/riset/*.md") ?: [] as $f) {
            $minggu = basename($f, '.md');
            if (!preg_match('/^\d{4}-W\d{2}$/', $minggu)) {
                continue;
            }
            $isi = (string) file_get_contents($f);
            DB::table('fbx_riset')->updateOrInsert(
                ['minggu' => $minggu],
                ['ringkasan' => self::ringkasan($isi), 'isi' => $isi],
            );
            $hasil['riset']++;
        }

        return $hasil;
    }

    /**
     * Satu baris kandidat.csv jadi baris tabel — atau null kalau ditolak aturan umur.
     *
     * @param array<string,string> $r
     * @return array<string,string>|null
     */
    public static function rapikanKandidat(array $r): ?array
    {
        $teks = fn (string $k, int $maks = 190) => mb_substr(trim((string) ($r[$k] ?? '')), 0, $maks);

        $minggu = strtoupper($teks('minggu', 8));
        $karakter = $teks('karakter', 120);
        $kategori = self::kategori($teks('kategori'));
        if (!preg_match('/^\d{4}-W\d{2}$/', $minggu) || $karakter === '' || $kategori === null) {
            return null;
        }

        $tingkat = self::tingkat($teks('tingkat_umur'));
        if ($tingkat === null || ($tingkat === '16-17' && $kategori !== 'FEMALE FIGHT')) {
            return null;
        }

        $status = strtolower($teks('status', 12));

        return [
            'minggu'       => $minggu,
            'kategori'     => $kategori,
            'karakter'     => $karakter,
            'sumber'       => $teks('sumber', 120),
            'pasangan'     => $teks('pasangan', 120),
            'danbooru_tag' => self::tag($teks('danbooru_tag')),
            'pasangan_tag' => self::tag($teks('pasangan_tag')),
            'tingkat_umur' => $tingkat,
            'umur_canon'   => $teks('umur_canon', 80),
            'tinggi_cm'    => $teks('tinggi_cm', 30),
            'alasan'       => $teks('alasan', 2000),
            'ide_kostum'   => $teks('ide_kostum', 2000),
            'status'       => in_array($status, self::STATUS, true) ? $status : 'baru',
            'bukti_url'    => $teks('bukti_url', 500),
        ];
    }

    /** "Female Fight", "female_fight", "FF" → FEMALE FIGHT. */
    public static function kategori(string $s): ?string
    {
        $s = strtoupper(trim(str_replace(['_', '-'], ' ', $s)));
        $s = (string) preg_replace('/\s+/', ' ', $s);

        return match ($s) {
            'FEMALE FIGHT', 'FF' => 'FEMALE FIGHT',
            'MIXED FIGHT', 'MF' => 'MIXED FIGHT',
            'WAIFU CRUSHED', 'WC' => 'WAIFU CRUSHED',
            default => null,
        };
    }

    /** "DEWASA (24)", "dewasa", "16-17 (17)", "16–17" → DEWASA / 16-17; lainnya null. */
    public static function tingkat(string $s): ?string
    {
        $s = strtoupper(str_replace(['–', '—'], '-', $s));
        if (str_starts_with($s, 'DEWASA') || str_starts_with($s, 'ADULT')) {
            return 'DEWASA';
        }
        if (preg_match('/^16\s*-\s*17\b/', $s)) {
            return '16-17';
        }

        return null;
    }

    /** Tag Danbooru: huruf kecil, spasi jadi garis bawah. */
    public static function tag(string $s): string
    {
        return mb_substr(str_replace(' ', '_', mb_strtolower(trim($s))), 0, 190);
    }

    /** Bagian "## Ringkasan ..." dari laporan Scout, sampai judul berikutnya. */
    public static function ringkasan(string $md): string
    {
        if (preg_match('/^##\s*Ringkasan[^\n]*\n(.*?)(?=^##\s|\z)/msu', $md, $m) === 1) {
            return trim($m[1]);
        }

        return '';
    }

    /** "2026-W43" → "20–26 Okt 2026". */
    public static function rentangMinggu(string $minggu): string
    {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $minggu, $m)) {
            return $minggu;
        }
        $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $awal = (new DateTimeImmutable())->setISODate((int) $m[1], (int) $m[2], 1);
        $akhir = $awal->modify('+6 days');

        return $awal->format('n') === $akhir->format('n')
            ? $awal->format('j') . '–' . $akhir->format('j') . ' ' . $bulan[(int) $akhir->format('n')] . ' ' . $akhir->format('Y')
            : $awal->format('j') . ' ' . $bulan[(int) $awal->format('n')] . ' – ' . $akhir->format('j') . ' ' . $bulan[(int) $akhir->format('n')] . ' ' . $akhir->format('Y');
    }

    /**
     * Ubah status satu kandidat dari halaman admin, lalu tulis balik ke CSV.
     * Di server tanpa folder Drive (hosting) perubahannya masuk antre, dan
     * fbx:kirim berikutnya membawanya pulang ke CSV — lihat FbxKirim.
     */
    public static function ubahStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUS, true)) {
            return false;
        }
        $n = DB::table('fbx_kandidat')->where('id', $id)->update(['status' => $status]);
        if ($n > 0 && self::bisaSinkron()) {
            self::tulisKandidat();
        } elseif ($n > 0) {
            FbxKirim::catatStatus($id, $status);
        }

        return $n > 0 || DB::table('fbx_kandidat')->where('id', $id)->exists();
    }

    /**
     * Tingkat umur tiap tag menurut kandidat Scout. Kalau satu barisnya saja
     * 16-17, tagnya 16-17 — yang paling ketat yang dipakai. Tag yang tidak
     * pernah dicek Scout tidak ada di sini.
     *
     * @return array<string, string>
     */
    public static function tingkatTag(): array
    {
        $hasil = [];
        foreach (DB::table('fbx_kandidat')->where('danbooru_tag', '!=', '')->get(['danbooru_tag', 'tingkat_umur']) as $r) {
            $t = self::tingkat((string) $r->tingkat_umur);
            if ($t !== null && ($hasil[$r->danbooru_tag] ?? null) !== '16-17') {
                $hasil[$r->danbooru_tag] = $t;
            }
        }

        return $hasil;
    }

    /**
     * Masukkan satu karakter ke roster dari halaman Scout atau Tren.
     *
     * ATURAN UMUR: tingkat dari Scout (baris kandidatnya dan semua baris
     * bertag sama) mengalahkan pilihan di halaman; pilihan halaman hanya
     * dipakai untuk karakter yang belum pernah dicek Scout. 16-17 hanya
     * Female Fight; tanpa tingkat sama sekali ditolak.
     *
     * roster.csv yang menang di sinkron, jadi di komputer studio barisnya
     * ditulis ke berkas itu DULU — kalau berkasnya terkunci Excel, tabelnya
     * juga tidak disentuh. Di hosting (tanpa folder Drive) barisnya masuk
     * tabel dan antre, lalu fbx:kirim berikutnya membawanya pulang ke CSV.
     *
     * @param array<string,mixed> $isi bulan, kategori, karakter, sumber, lawan, danbooru_tag, kandidat_id
     * @return array<string,string> baris yang tersimpan
     *
     * @throws InvalidArgumentException isian tidak sah atau melanggar aturan umur
     * @throws DomainException          sudah ada di roster
     * @throws RuntimeException         roster.csv tidak bisa ditulis
     */
    public static function tambahRoster(array $isi, ?string $tingkatHalaman, bool $catatAntre = true): array
    {
        $teks = fn (string $k, int $maks = 120) => mb_substr(trim((string) ($isi[$k] ?? '')), 0, $maks);

        $bulan = $teks('bulan', 7);
        $kategori = strtoupper($teks('kategori', 30));
        $karakter = $teks('karakter');
        $tag = self::tag($teks('danbooru_tag', 190));
        if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $bulan)) {
            throw new InvalidArgumentException('Bulan harus berbentuk TTTT-BB.');
        }
        if (!in_array($kategori, self::KATEGORI_ROSTER, true)) {
            throw new InvalidArgumentException('Kategori roster tidak dikenal.');
        }
        if ($karakter === '') {
            throw new InvalidArgumentException('Nama karakter kosong.');
        }

        // Yang paling ketat menang: baris kandidat, semua baris bertag sama, baru pilihan halaman.
        $dariScout = [];
        if (!empty($isi['kandidat_id'])) {
            $dariScout[] = self::tingkat((string) DB::table('fbx_kandidat')->where('id', (int) $isi['kandidat_id'])->value('tingkat_umur'));
        }
        if ($tag !== '') {
            $dariScout[] = self::tingkatTag()[$tag] ?? null;
        }
        $dariScout = array_filter($dariScout);
        $tingkat = $dariScout !== [] ? (in_array('16-17', $dariScout, true) ? '16-17' : 'DEWASA') : self::tingkat((string) $tingkatHalaman);
        if ($tingkat === null) {
            throw new InvalidArgumentException('Umur canon karakter ini belum dicek Scout — pilih tingkat umurnya dulu.');
        }
        if ($tingkat === '16-17' && $kategori !== 'FEMALE FIGHT') {
            throw new InvalidArgumentException('Umur canon 16–17 hanya boleh masuk Female Fight.');
        }

        $sudah = DB::table('fbx_roster')->where('bulan', $bulan)->where('kategori', $kategori)->get(['karakter', 'danbooru_tag'])
            ->contains(fn ($r) => ($tag !== '' && $r->danbooru_tag === $tag) || mb_strtolower($r->karakter) === mb_strtolower($karakter));
        if ($sudah) {
            throw new DomainException("$karakter sudah ada di roster $kategori $bulan.");
        }

        $baris = array_combine(self::KOLOM_ROSTER, array_map(fn (string $k) => match ($k) {
            'bulan' => $bulan, 'kategori' => $kategori, 'karakter' => $karakter, 'sumber' => $teks('sumber'),
            'lawan' => $teks('lawan'), 'yakin' => 'tinggi', 'danbooru_tag' => $tag, default => '',
        }, self::KOLOM_ROSTER));

        if (self::bisaSinkron()) {
            self::tambahBarisCsv(self::folder() . '/roster.csv', $baris);
        }
        DB::table('fbx_roster')->insert($baris);
        if (!self::bisaSinkron() && $catatAntre) {
            FbxKirim::catatRoster($baris, $tingkat);
        }

        return $baris;
    }

    /**
     * Tambah satu baris di ujung CSV tanpa menulis ulang isinya — roster.csv
     * disunting tangan di Excel. Urutan kolom mengikuti kepala berkasnya;
     * gaya mengikuti baris pertama (semua dikutip, CRLF kalau berkasnya CRLF).
     *
     * @param array<string,string> $baris
     */
    public static function tambahBarisCsv(string $file, array $baris): void
    {
        $isi = is_file($file) ? (string) @file_get_contents($file, false, null, 0, 4096) : '';
        if (trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $isi)) === '') {
            self::tulisCsv($file, array_keys($baris), [array_values($baris)]);

            return;
        }

        $kepala = str_getcsv((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) strtok($isi, "\r\n")), ',', '"', '');
        $kepala = array_map(fn ($k) => strtolower(trim((string) $k)), $kepala);
        if (!in_array('karakter', $kepala, true)) {
            throw new RuntimeException('Kepala kolom ' . basename($file) . ' tidak dikenal — baris pertamanya harus nama kolom.');
        }
        $eol = str_contains($isi, "\r\n") ? "\r\n" : "\n";
        $sel = array_map(fn ($k) => '"' . str_replace('"', '""', (string) ($baris[$k] ?? '')) . '"', $kepala);

        $fh = @fopen($file, 'a+');
        if ($fh === false) {
            throw new RuntimeException('roster.csv tidak bisa ditulis — kalau sedang dibuka di Excel, tutup dulu lalu coba lagi.');
        }
        fseek($fh, -1, SEEK_END);
        $akhir = filesize($file) > 0 ? fread($fh, 1) : "\n";
        fwrite($fh, ($akhir === "\n" ? '' : $eol) . implode(',', $sel) . $eol);
        fclose($fh);
    }

    /** Seluruh tabel kandidat ditulis ke kandidat.csv — status hasil halaman ikut. */
    public static function tulisKandidat(): void
    {
        $baris = DB::table('fbx_kandidat')->orderBy('minggu')->orderBy('kategori')->orderBy('id')
            ->get(self::KOLOM_KANDIDAT)
            ->map(fn ($r) => array_map(fn (string $k) => (string) $r->{$k}, self::KOLOM_KANDIDAT));

        self::tulisCsv(self::folder() . '/kandidat.csv', self::KOLOM_KANDIDAT, $baris->all());
    }

    /**
     * CSV jadi daftar baris berkunci nama kolom. BOM dibuang, nama kolom
     * dikecilkan, baris yang lebih pendek dari kepalanya diisi kosong.
     *
     * @return list<array<string,string>>
     */
    public static function bacaCsv(string $file): array
    {
        $fh = @fopen($file, 'r');
        if ($fh === false) {
            return [];
        }
        $kepala = fgetcsv($fh, null, ',', '"', '');
        if ($kepala === false || $kepala === [null]) {
            fclose($fh);

            return [];
        }
        $kepala = array_map(fn ($k) => strtolower(trim((string) $k)), $kepala);
        $kepala[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $kepala[0]);

        $hasil = [];
        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $row = array_pad(array_slice($row, 0, count($kepala)), count($kepala), '');
            $hasil[] = array_combine($kepala, array_map(fn ($v) => (string) $v, $row));
        }
        fclose($fh);

        return $hasil;
    }

    /**
     * Tulis CSV ber-BOM (supaya Excel membaca huruf Jepang dengan benar),
     * lewat berkas sementara lalu diganti — Drive tidak pernah menyinkronkan
     * berkas yang setengah jadi.
     *
     * @param list<string> $kolom
     * @param list<list<string>> $baris
     */
    public static function tulisCsv(string $file, array $kolom, array $baris): void
    {
        $tmp = $file . '.tmp';
        $fh = fopen($tmp, 'w');
        if ($fh === false) {
            throw new RuntimeException("Tidak bisa menulis $tmp");
        }
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $kolom, ',', '"', '');
        foreach ($baris as $b) {
            fputcsv($fh, $b, ',', '"', '');
        }
        fclose($fh);

        if (!@rename($tmp, $file)) {
            // Windows menolak rename ke berkas yang sedang dibuka Excel/Drive.
            copy($tmp, $file);
            @unlink($tmp);
        }
    }
}
