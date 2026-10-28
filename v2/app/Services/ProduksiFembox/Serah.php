<?php

namespace App\Services\ProduksiFembox;

use App\Services\Penulis\PagarUmur;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;
use App\Services\PustakaPose;

/**
 * Langkah terakhir: brief untuk artist dan serah terima.
 *
 * Satu SATUAN serah = satu folder laga di Drive, sama dengan susunan yang
 * dipakai selama ini: Semi Final 1, Semi Final 2, Final (3 halaman), Mixed
 * Fight (2 halaman), Waifu Crushed (4 halaman), dan tiap match Showdown.
 *
 *   BRIEF.md       disusun kode dari papan + paket pose, bahasa Indonesia
 *                  santai (pemilik studio 2026-10-28: "jangan terlalu baku").
 *   BRIEF NSFW.md  ditulis DeepSeek dengan BRIEF.md sebagai acuan — hanya
 *                  kalau SEMUA karakter di satuan itu dewasa. Kode memeriksa
 *                  hasilnya sebelum disimpan.
 *
 * Tandai diserahkan hanya mencatat (artist, tanggal); kirim link-nya tetap
 * dilakukan pemilik studio sendiri.
 */
final class Serah
{
    /**
     * Satu kalimat per pose. {A} = yang memukul, {B} = yang kena — atau
     * satu-satunya petinju di pose sendirian.
     */
    private const KALIMAT = [
        'jab-buka'            => '{A} buka dengan jab cepat, kepala {B} sampai tersentak ke belakang.',
        'pipi-terhantam'      => 'Close-up: sarung {A} nancep di pipi {B}, mukanya sampai penyok kena pukulan.',
        'body-blow'           => '{A} masukin hook ke ulu hati {B}, sarungnya tenggelam di perut sampai {B} ngebungkuk.',
        'uppercut'            => 'Uppercut {A} kena telak di dagu, kepala {B} terlempar ke atas.',
        'ko-terkapar'         => '{B} KO terkapar di kanvas, {A} berdiri di dekatnya sambil ngos-ngosan.',
        'cross-counter'       => 'Cross counter: {A} dan {B} saling kena pukul di muka di saat yang sama.',
        'terpojok-tali'       => '{B} terpojok di tali ring, {A} ngehujanin pukulan beruntun.',
        'ko-sudut'            => '{B} KO terduduk lemas di sudut ring, {A} berdiri di depannya.',
        'tumbang'             => '{B} tumbang ke belakang habis kena pukulan {A}.',
        'adu-dahi'            => 'Adu dahi sebelum bel: {A} dan {B} saling tatap, sarung sudah siap.',
        'clinch'              => 'Clinch kecapekan: dua-duanya ngebungkuk saling bertumpu, kepala di bahu lawan (bentuk huruf A, bukan pelukan).',
        'menang'              => '{A} angkat tangan sebagai pemenang, {B} kalah di belakangnya.',
        'pov-body-blow'       => 'Dari mata {A}: sarungnya masuk ke perut {B}.',
        'ko-lengan-atas'      => '{B} KO telentang, lengan terlempar ke atas, wasit menghitung.',
        'tumbang-merangkak'   => 'Knockdown: {B} merangkak gemetar di kanvas.',
        'headlock-perut'      => '{A} ngunci kepala {B} sambil mukulin perutnya.',
        'siap-sudut'          => '{B} siap tanding di sudut ring, sendirian.',
        'babak-belur-berdiri' => '{B} babak belur tapi masih berdiri.',
        'hook-penentu'        => 'Pukulan penentu: hook keras {A} ke wajah {B}.',
        'sd-uppercut-tali'    => 'Pukulan penentu: uppercut {A} di tali ring, badan {B} sampai terangkat.',
        'sd-liver'            => 'Pukulan penentu: hook {A} ke hati {B} (liver shot).',
        'sd-overhand'         => 'Pukulan penentu: overhand {A} mendarat di pipi {B}.',
        'sd-straight'         => 'Pukulan penentu: straight bersih {A} ke wajah {B}.',
        'sd-angkat-perut'     => 'Pukulan penentu: body blow {A} sampai badan {B} terangkat.',
        'sd-hook-sudut'       => 'Pukulan penentu: hook {A} ke {B} di sudut ring.',
        'nyaris-tumbang'      => '{B} kecapekan, nyaris tumbang.',
        'perut-penentu'       => 'Pukulan penentu ke perut {B}.',
        'ko-perut'            => '{B} KO gara-gara pukulan perut, sendirian di kanvas.',
        'ko-dekat'            => '{B} KO terkapar, dilihat dekat dari atas.',
        'body-rusuk'          => '{A} mukul rusuk {B} waktu {B} lagi nutup kepala.',
        'hook-pipi'           => 'Hook {A} mendarat di pipi {B}, guard-nya telat.',
        'body-bahu'           => 'Body blow {A} ke {B}, dilihat dari balik bahu {A}.',
        'uppercut-dekat'      => 'Uppercut jarak dekat dari {A} ke dagu {B}.',
        'ko-lebar'            => 'KO: {A} berdiri di atas {B} yang terkapar (shot lebar).',
        'cu-perut'            => 'Close-up perut {B} pas kena sarung {A}.',
        'cu-wajah'            => 'Close-up wajah {B} pas kena pukul.',
        'cu-mata'             => 'Close-up mata {B}, tatapannya mulai goyah.',
        'reaksi-perut'        => 'Reaksi {B}: megangin perut, lutut goyah.',
        'reaksi-wajah'        => 'Reaksi {B}: kepala terlempar ke samping.',
        'tatap-tajam'         => 'Close-up tatapan tajam {B}.',
        'istirahat-sudut'     => '{B} istirahat di sudut antarronde.',
        'tali-terlempar'      => '{B} terpental ke tali ring kena pukulan {A}.',
    ];

    private const WARNA_GENERIK = ['A' => 'merah', 'B' => 'biru', 'P' => 'hijau'];

    // ------------------------------------------------------------------
    // Satuan serah

    public static function kunciUnit(string $kunciHalaman): string
    {
        return match (true) {
            str_starts_with($kunciHalaman, 'final')                      => 'final',
            in_array($kunciHalaman, ['pria-kalah', 'wanita-kalah'], true) => 'mf',
            str_starts_with($kunciHalaman, 'wc')                         => 'wc',
            default                                                      => $kunciHalaman,
        };
    }

    /**
     * @return list<array{kunci:string, judul:string, folder:string, halaman:list<array>, peserta:list<array>, remaja:bool}>
     */
    public static function unit(array $s, string $kategori): array
    {
        $hasil = [];
        foreach (Pose::rencana($s, $kategori) as $r) {
            $k = self::kunciUnit($r['kunci']);
            $hasil[$k] ??= ['kunci' => $k, 'folder' => dirname($r['keluar']), 'halaman' => [], 'peserta' => []];
            $hasil[$k]['halaman'][] = $r;
            foreach ($r['petinju'] as $huruf => $p) {
                if ($p !== null) {
                    $hasil[$k]['peserta'][$p['id']] ??= $p + ['huruf' => $huruf];
                }
            }
        }

        return array_values(array_map(function (array $u) {
            $u['peserta'] = array_values($u['peserta']);
            $u['judul'] = self::judulUnit($u);
            $u['remaja'] = in_array('16-17', array_column($u['peserta'], 'tingkat'), true);

            return $u;
        }, $hasil));
    }

    public static function cariUnit(array $s, string $kategori, string $kunci): array
    {
        foreach (self::unit($s, $kategori) as $u) {
            if ($u['kunci'] === $kunci) {
                return $u;
            }
        }
        throw new Galat('Satuan serah itu tidak ada (peserta atau pemenang berubah?).');
    }

    private static function judulUnit(array $u): string
    {
        $n = fn (string $h) => current(array_filter($u['peserta'], fn ($p) => $p['huruf'] === $h))['nama'] ?? '?';

        return match (true) {
            preg_match('/^sf(\d)$/', $u['kunci'], $m) === 1    => "Semi Final {$m[1]} · " . $n('A') . ' vs ' . $n('B'),
            $u['kunci'] === 'final'                            => 'Final · ' . $n('A') . ' vs ' . $n('B'),
            $u['kunci'] === 'mf'                               => 'Mixed Fight · ' . $n('A') . ' vs ' . $n('P'),
            $u['kunci'] === 'wc'                               => 'Waifu Crushed · ' . $n('B'),
            preg_match('/^match(\d+)$/', $u['kunci'], $m) === 1 => "Match {$m[1]} · " . $n('A') . ' vs ' . $n('B'),
            default                                            => $u['kunci'],
        };
    }

    /** Folder kostum/acuan dilihat dari folder satuan: salinan di 01/02, kecuali satuan = folder kategori itu sendiri. */
    private static function subfolder(array $s, string $kategori, array $u): array
    {
        return rtrim($u['folder'], '\\/') === rtrim(Bulan::folderReferensi($s, $kategori), '\\/')
            ? ['KOSTUM', 'ACUAN', false]
            : ['01 KOSTUM', '02 ACUAN', true];
    }

    // ------------------------------------------------------------------
    // BRIEF.md

    public static function brief(array $s, string $kategori, array $u): string
    {
        [$dirKostum, $dirAcuan] = self::subfolder($s, $kategori, $u);
        $paket = Pose::paket($s['bulan'], $kategori);
        $halamanPaket = [];
        foreach ($paket['halaman'] ?? [] as $h) {
            $halamanPaket[$h['pfx']['kunci'] ?? ''] = $h;
        }
        $nama = fn (string $h) => mb_strtoupper(current(array_filter($u['peserta'], fn ($p) => $p['huruf'] === $h))['nama'] ?? 'lawan');
        $A = $nama('A');
        $lawan = $nama($u['kunci'] === 'mf' ? 'P' : 'B');
        $ms = $kategori === 'MONTHLY SHOWDOWN';
        $sumber = fn (array $p) => $p['sumber'] !== '' ? " ({$p['sumber']})" : '';

        $baris = ['FEMBOX COLLECTION — ' . $kategori . (in_array($u['kunci'], ['mf', 'wc'], true) ? '' : ' · ' . mb_strtoupper(explode(' · ', $u['judul'])[0]))];
        $baris[] = implode(' vs ', array_map(fn ($p) => mb_strtoupper($p['nama']) . $sumber($p), $u['peserta']));

        $ff = $s['kategori']['FEMALE FIGHT']['pemenang'] ?? [];
        $baris[] = match (true) {
            str_starts_with($u['kunci'], 'sf') => ($ff[$u['kunci']] ?? null) !== null ? "Yang menang: $A" : "Pemenang belum ditentukan (sementara $A).",
            $u['kunci'] === 'final'            => ($ff['final'] ?? null) !== null ? "Juaranya: $A" : "Juara belum ditentukan (sementara $A).",
            $u['kunci'] === 'mf'               => "Dua versi: $lawan kalah, lalu $A kalah.",
            $u['kunci'] === 'wc'               => "$lawan yang dihajar sampai KO.",
            default                            => "$A yang mendaratkan pukulan penentu.",
        };

        // ---- Yang dikerjakan
        $baris[] = '';
        $baris[] = 'YANG DIKERJAKAN';
        $jumlah = count($u['halaman']);
        $baris[] = '- ' . $jumlah . ' ' . ($jumlah > 1 ? 'halaman/art' : 'halaman') . ', susunannya ikutin halaman pose di folder ini ya:';
        foreach ($u['halaman'] as $r) {
            $panel = count($halamanPaket[$r['kunci']]['panel'] ?? []);
            $baris[] = '  · ' . basename($r['keluar']) . ' (' . ($r['bentuk'] === 'portrait' || $r['bentuk'] === 'tegak' ? 'tegak' : 'landscape 16:9')
                . ($panel > 0 ? ", $panel panel" : '') . ')';
        }
        $baris[] = '  Pose utuh tiap panel ada di folder "… - POSE UTUH" di sebelahnya.';
        $baris[] = $u['remaja']
            ? '- Versi SFW saja — ada petinju umur 16–17, jadi nggak ada versi NSFW.'
            : '- Versi SFW (brief ini) + versi NSFW (lihat BRIEF NSFW.md).';

        // ---- Siapa jadi siapa
        $baris[] = '';
        $baris[] = 'SIAPA JADI SIAPA DI HALAMAN POSE';
        foreach (['A', 'B', 'P'] as $h) {
            $p = current(array_filter($u['peserta'], fn ($x) => $x['huruf'] === $h)) ?: null;
            $adaHuruf = array_filter($u['halaman'], fn ($r) => array_key_exists($h, $r['petinju']));
            if ($adaHuruf === []) {
                continue;
            }
            if ($p === null) {
                $baris[] = "- Petinju $h (" . self::WARNA_GENERIK[$h] . ') = lawan bebas, nggak perlu karakter tertentu.';
                continue;
            }
            $kostum = $p['kostum']['sfw']['drive'] ?? null;
            $label = Pose::label($p);
            $baris[] = "- Petinju $h (" . self::WARNA_GENERIK[$h] . ') = ' . mb_strtoupper($p['nama'])
                . ($label !== mb_strtoupper($p['nama']) ? " (label di halaman pose: $label)" : '')
                . (! $ms && $p['tinggi'] ? " · {$p['tinggi']} cm" : '')
                . ($p['tingkat'] === '16-17' ? ' · umur 16–17' : '');
            $baris[] = '  kostum: ' . ($kostum ? "$dirKostum/" . basename((string) $kostum) : '(kostum belum dipilih)');
        }
        $baris[] = '  Petinju di halaman pose itu cuma model generik, jadi tinggal digambar jadi karakter aslinya pakai kostum di atas.';
        if (array_filter($u['peserta'], fn ($p) => ($p['acuan'] ?? []) !== [])) {
            $baris[] = "  Wajah & rambut lihat folder $dirAcuan.";
        }

        // ---- Panel per halaman
        foreach ($u['halaman'] as $r) {
            $baris[] = '';
            $baris[] = 'PANEL' . ($jumlah > 1 ? ' — ' . mb_strtoupper($r['judul']) : '');
            $panel = $halamanPaket[$r['kunci']]['panel'] ?? null;
            if ($panel === null) {
                $baris[] = '(halaman pose belum disusun)';
                continue;
            }
            foreach (array_values($panel) as $i => $id) {
                $baris[] = ($i + 1) . '. ' . self::kalimatPanel((string) $id, $r['nama'], $u['kunci'] === 'wc');
            }
        }

        // ---- Aturan
        $baris[] = '';
        $baris[] = 'ATURAN RUMAH';
        $baris[] = '- Pakai sarung tinju, tanpa hand wraps, tanpa perhiasan/aksesori.';
        $baris[] = '- Tiap pukulan yang kena kasih speed line / motion blur.';
        $baris[] = '- Bagian badan yang kena pukul kasih garis getar.';
        $baris[] = '- Tanpa mouthguard.';
        if ($kategori === 'WAIFU CRUSHED') {
            $baris[] = '- Tanpa tag nama di gambar.';
        }
        if ($u['remaja']) {
            $baris[] = '- Gaya olahraga bersih: tanpa SFX hati, tanpa baju basah tembus pandang, kamera nggak fokus ke badan, wajah KO nggak sugestif.';
        }

        $baris[] = '';
        $tenggat = (string) ($s['tenggat_artist'] ?? '');
        $baris[] = 'DEADLINE: ' . ($tenggat !== '' ? self::tanggal($tenggat) : 'belum ditentukan');

        return implode("\n", $baris) . "\n";
    }

    private static function kalimatPanel(string $id, array $nama, bool $tanpaNama): string
    {
        $u = PustakaPose::urai($id) ?? (preg_match('/^([a-z0-9-]+)__([ABP])([ABP])$/', $id, $m) === 1 ? [$m[1], $m[2], $m[3], 0] : null);
        if ($u === null) {
            return 'Pukulan penentu (posenya dipilih waktu halaman dibuat).';
        }
        [$pose, $a, $b] = $u;
        $siapa = fn (string $h) => $tanpaNama && $h === 'A' ? 'lawan' : mb_strtoupper((string) ($nama[$h] ?? 'lawan'));
        $kalimat = self::KALIMAT[$pose] ?? ((\PoseFbx::POSE[$pose]['judul'] ?? $pose) . '.');

        return ucfirst(strtr($kalimat, ['{A}' => $siapa($a), '{B}' => $siapa($b)]));
    }

    private static function tanggal(string $tgl): string
    {
        $t = \DateTimeImmutable::createFromFormat('Y-m-d', $tgl);
        $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return $t ? $t->format('j') . ' ' . $bulan[(int) $t->format('n')] . ' ' . $t->format('Y') : $tgl;
    }

    // ------------------------------------------------------------------
    // Siapkan folder

    /**
     * Isi folder satuan: salinan kostum + acuan (kalau satuannya punya
     * subfolder sendiri) dan BRIEF.md. Brief yang disunting tangan tidak
     * ditimpa kecuali $briefUlang.
     *
     * @return array{folder:string, catatan:list<string>}
     */
    public static function siapkan(string $bulan, string $kategori, string $kunci, bool $briefUlang = false): array
    {
        $s = Bulan::bacaWajib($bulan);
        $u = self::cariUnit($s, $kategori, $kunci);
        [$dirKostum, $dirAcuan, $salin] = self::subfolder($s, $kategori, $u);
        Bulan::pastikanDir($u['folder']);
        $catatan = [];

        foreach ($u['peserta'] as $p) {
            foreach (['sfw', 'nsfw'] as $v) {
                $asal = (string) ($p['kostum'][$v]['drive'] ?? '');
                if ($v === 'nsfw' && $u['remaja']) {
                    continue;
                }
                if ($asal === '' || ! is_file($asal)) {
                    if ($v === 'sfw' || Kostum::alasanTanpaNsfw($p) === null) {
                        $catatan[] = 'Kostum ' . strtoupper($v) . " {$p['nama']} belum dipilih.";
                    }
                } elseif ($salin) {
                    Bulan::pastikanDir($u['folder'] . DIRECTORY_SEPARATOR . $dirKostum);
                    copy($asal, $u['folder'] . DIRECTORY_SEPARATOR . $dirKostum . DIRECTORY_SEPARATOR . basename($asal));
                }
            }
            // Satuan di folder kategori sendiri (Mixed Fight, Waifu Crushed) sudah berisi ACUAN\.
            if ($salin) {
                foreach (glob(Acuan::folderDrive($s, $kategori, $p['nama']) . DIRECTORY_SEPARATOR . '*.*') ?: [] as $f) {
                    $ke = $u['folder'] . DIRECTORY_SEPARATOR . $dirAcuan . DIRECTORY_SEPARATOR . Kostum::namaBerkas($p['nama']);
                    Bulan::pastikanDir($ke);
                    copy($f, $ke . DIRECTORY_SEPARATOR . basename($f));
                }
            }
        }

        $berkas = $u['folder'] . DIRECTORY_SEPARATOR . 'BRIEF.md';
        $disunting = (bool) ($s['serah'][$kategori][$kunci]['brief_disunting'] ?? false);
        if (! $disunting || $briefUlang || ! is_file($berkas)) {
            file_put_contents($berkas, self::brief($s, $kategori, $u));
            $disunting = false;
        } else {
            $catatan[] = 'BRIEF.md disunting tangan, jadi tidak ditimpa. Pakai "Susun ulang brief" kalau mau versi baru.';
        }

        Bulan::ubah($bulan, function (array $st) use ($kategori, $kunci, $disunting) {
            $st['serah'][$kategori][$kunci] = array_merge($st['serah'][$kategori][$kunci] ?? [], [
                'disiapkan' => now()->toIso8601String(), 'brief_disunting' => $disunting,
            ]);
            Bulan::catat($st, "Folder serah $kategori $kunci disiapkan");

            return $st;
        });

        return ['folder' => $u['folder'], 'catatan' => $catatan];
    }

    /** Simpan brief yang disunting di halaman. */
    public static function simpanBrief(string $bulan, string $kategori, string $kunci, string $isi): void
    {
        $u = self::cariUnit(Bulan::bacaWajib($bulan), $kategori, $kunci);
        Bulan::pastikanDir($u['folder']);
        file_put_contents($u['folder'] . DIRECTORY_SEPARATOR . 'BRIEF.md', rtrim(str_replace("\r\n", "\n", $isi)) . "\n");
        Bulan::ubah($bulan, function (array $st) use ($kategori, $kunci) {
            $st['serah'][$kategori][$kunci]['brief_disunting'] = true;

            return $st;
        });
    }

    // ------------------------------------------------------------------
    // BRIEF NSFW.md (DeepSeek)

    /** Kenapa satuan ini tidak punya brief NSFW (null = boleh). */
    public static function alasanTanpaNsfw(array $u): ?string
    {
        foreach ($u['peserta'] as $p) {
            if ($p['tingkat'] !== 'DEWASA') {
                return "{$p['nama']} umur canon 16–17 — satuan ini SFW saja.";
            }
            if ($p['jk'] === 'wanita' && ($a = Kostum::alasanTanpaNsfw($p)) !== null) {
                return "{$p['nama']}: $a";
            }
        }

        return null;
    }

    public static function tulisNsfw(string $bulan, string $kategori, string $kunci, string $ide, ?Venice $venice): array
    {
        $s = Bulan::bacaWajib($bulan);
        $u = self::cariUnit($s, $kategori, $kunci);
        if (($a = self::alasanTanpaNsfw($u)) !== null) {
            throw Galat::ditolak($a);
        }
        $berkas = $u['folder'] . DIRECTORY_SEPARATOR . 'BRIEF.md';
        if (! is_file($berkas)) {
            throw new Galat('Siapkan folder dulu — BRIEF NSFW ditulis dari BRIEF.md.');
        }
        if ($venice === null) {
            throw new Galat('Kunci Venice (DeepSeek) belum ada.');
        }
        $ide = mb_substr(trim($ide), 0, 600);
        try {
            PagarUmur::periksaIsian($ide);
        } catch (PenulisGalat $e) {
            throw Galat::ditolak($e->getMessage());
        }

        [$dirKostum] = self::subfolder($s, $kategori, $u);
        $kostum = [];
        foreach ($u['peserta'] as $p) {
            $nsfw = $p['kostum']['nsfw'] ?? [];
            $kostum[] = '- ' . mb_strtoupper($p['nama']) . ' (' . ($p['jk'] === 'pria' ? 'pria' : 'wanita') . ', dewasa canon'
                . ($p['umur'] !== '' ? ": {$p['umur']}" : '') . '): '
                . (($nsfw['drive'] ?? null) ? "kostum NSFW $dirKostum/" . basename((string) $nsfw['drive']) . '. ' . ($nsfw['ringkas'] ?? '') : 'tanpa lembar kostum NSFW terpisah.');
        }

        $sistem = 'Kamu menulis brief untuk artist ilustrasi tinju di studio khusus dewasa. Bahasa Indonesia santai, nggak baku, seperti brief contoh. Balas JSON saja.';
        $user = "Ini BRIEF.md (versi SFW) yang sudah disetujui pemilik studio:\n\n-----\n" . (string) file_get_contents($berkas) . "-----\n\n"
            . "Karakter dan kostum NSFW-nya:\n" . implode("\n", $kostum) . "\n\n"
            . "Tulis BRIEF NSFW.md untuk art yang SAMA:\n"
            . "- Jelaskan apa saja yang beda di versi NSFW, per halaman dan per panel, dengan nomor panel yang sama. Kostumnya ikut kostum NSFW di atas.\n"
            . "- Jangan ubah jumlah halaman, urutan panel, pose, siapa yang menang, atau aturan rumah.\n"
            . ($ide !== '' ? "- Ide pemilik studio untuk versi ini (ikuti selama masih dalam aturan): $ide\n" : '')
            . "- Semua karakter dewasa. Jangan pernah menulis apa pun yang menyiratkan anak, remaja, sekolah, atau badan kekanakan.\n"
            . "- Gaya bahasa sama dengan BRIEF.md: santai, singkat, langsung ke poin.\n"
            . "\"isi\" = isi BRIEF NSFW.md lengkap (teks biasa, judul baris pertama seperti BRIEF.md tapi ditambah \"· NSFW\").";
        $skema = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['isi' => ['type' => 'string']], 'required' => ['isi']];

        try {
            $r = $venice->json($sistem, $user, $skema, ['langkah' => 'brief-nsfw', 'nama_skema' => 'brief_nsfw', 'nalar' => 'none', 'suhu' => 0.6, 'maks' => 6000]);
        } catch (PenulisGalat $e) {
            throw new Galat('DeepSeek gagal menulis brief NSFW: ' . $e->getMessage());
        }
        $isi = trim((string) ($r['isi'] ?? ''));
        if ($isi === '') {
            throw new Galat('DeepSeek memulangkan brief kosong.');
        }
        if (Kostum::adaPenandaMuda($isi)) {
            throw Galat::ditolak('DeepSeek menulis penanda umur muda di brief NSFW — tidak disimpan. Tulis ulang, atau periksa idenya.');
        }

        file_put_contents($u['folder'] . DIRECTORY_SEPARATOR . 'BRIEF NSFW.md', $isi . "\n");
        $usd = $venice->totalUsd();
        Bulan::ubah($bulan, function (array $st) use ($kategori, $kunci, $ide, $usd) {
            $lama = $st['serah'][$kategori][$kunci]['nsfw'] ?? [];
            $st['serah'][$kategori][$kunci]['nsfw'] = [
                'ditulis' => now()->toIso8601String(), 'ide' => $ide, 'usd' => round((float) ($lama['usd'] ?? 0) + $usd, 4),
            ];
            Bulan::catat($st, "BRIEF NSFW $kategori $kunci ditulis DeepSeek");

            return $st;
        });

        return ['usd' => $usd];
    }

    // ------------------------------------------------------------------
    // Serah terima

    public static function serahkan(string $bulan, string $kategori, string $kunci, string $artist, string $tanggal, string $catatan): void
    {
        $artist = mb_substr(trim($artist), 0, 80);
        if ($artist === '') {
            throw new Galat('Isi nama artist-nya dulu.');
        }
        if (\DateTimeImmutable::createFromFormat('Y-m-d', $tanggal) === false) {
            throw new Galat('Tanggal serah tidak sah.');
        }
        self::cariUnit(Bulan::bacaWajib($bulan), $kategori, $kunci);
        Bulan::ubah($bulan, function (array $st) use ($kategori, $kunci, $artist, $tanggal, $catatan) {
            $st['serah'][$kategori][$kunci]['diserahkan'] = [
                'artist' => $artist, 'tanggal' => $tanggal, 'catatan' => mb_substr(trim($catatan), 0, 500), 'waktu' => now()->toIso8601String(),
            ];
            Bulan::catat($st, "$kategori $kunci diserahkan ke $artist");

            return $st;
        });
    }

    public static function batal(string $bulan, string $kategori, string $kunci): void
    {
        Bulan::ubah($bulan, function (array $st) use ($kategori, $kunci) {
            unset($st['serah'][$kategori][$kunci]['diserahkan']);
            Bulan::catat($st, "Serah $kategori $kunci dibatalkan");

            return $st;
        });
    }

    public static function tenggat(string $bulan, ?string $tanggal): void
    {
        if ($tanggal !== null && \DateTimeImmutable::createFromFormat('Y-m-d', $tanggal) === false) {
            throw new Galat('Tanggal tenggat tidak sah.');
        }
        Bulan::ubah($bulan, function (array $st) use ($tanggal) {
            $st['tenggat_artist'] = $tanggal;

            return $st;
        });
    }

    /**
     * Daftar cek sebelum serah. Nada: lolos / awas / tidak (tidak berlaku).
     * Tombol serah tetap bisa dipakai walau ada yang awas — pemilik studio
     * yang memutuskan.
     *
     * @return list<array{teks:string, nada:string}>
     */
    public static function cek(array $s, string $kategori, array $u): array
    {
        $hasil = [];
        $sfw = array_filter($u['peserta'], fn ($p) => ($p['kostum']['sfw']['dipilih'] ?? null) !== null);
        $hasil[] = ['teks' => 'Kostum SFW ' . count($sfw) . '/' . count($u['peserta']), 'nada' => count($sfw) === count($u['peserta']) ? 'lolos' : 'awas'];

        $butuh = array_filter($u['peserta'], fn ($p) => Kostum::alasanTanpaNsfw($p) === null);
        if ($u['remaja'] || $butuh === []) {
            $hasil[] = ['teks' => 'Kostum NSFW · tidak berlaku', 'nada' => 'tidak'];
        } else {
            $nsfw = array_filter($butuh, fn ($p) => ($p['kostum']['nsfw']['dipilih'] ?? null) !== null);
            $hasil[] = ['teks' => 'Kostum NSFW ' . count($nsfw) . '/' . count($butuh), 'nada' => count($nsfw) === count($butuh) ? 'lolos' : 'awas'];
        }

        $st = $s['pose'][$kategori]['halaman'] ?? [];
        $setuju = array_filter($u['halaman'], fn ($r) => ($st[$r['kunci']]['disetujui'] ?? false) === true);
        $hasil[] = ['teks' => 'Halaman pose disetujui ' . count($setuju) . '/' . count($u['halaman']), 'nada' => count($setuju) === count($u['halaman']) ? 'lolos' : 'awas'];

        $brief = is_file($u['folder'] . DIRECTORY_SEPARATOR . 'BRIEF.md');
        $hasil[] = ['teks' => $brief ? 'BRIEF.md' : 'BRIEF.md belum dibuat (Siapkan folder)', 'nada' => $brief ? 'lolos' : 'awas'];

        if (self::alasanTanpaNsfw($u) !== null) {
            $hasil[] = ['teks' => 'BRIEF NSFW · tidak berlaku', 'nada' => 'tidak'];
        } else {
            $ada = is_file($u['folder'] . DIRECTORY_SEPARATOR . 'BRIEF NSFW.md');
            $hasil[] = ['teks' => $ada ? 'BRIEF NSFW.md' : 'BRIEF NSFW.md belum ditulis', 'nada' => $ada ? 'lolos' : 'awas'];
        }

        return $hasil;
    }
}
