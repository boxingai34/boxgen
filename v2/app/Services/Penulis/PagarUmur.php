<?php

namespace App\Services\Penulis;

/**
 * Pagar umur PENULIS — diputuskan di kode, bukan di prompt.
 *
 * Modelnya tanpa sensor dan tidak pernah menolak, jadi kode inilah satu-
 * satunya pagar. Umur canon dicari model di langkah riset (panggilan yang
 * memang sudah ada), lalu dibaca di sini:
 *
 *   dewasa     ≥18 canon, ada bukti          → semua jenis laga dan isi
 *   remaja     16–17 canon                   → laga pro saja, mode olahraga
 *   tak_jelas  tidak ada bukti dewasa, atau  → sama dengan remaja (Fight
 *              angka dan keterangan bentrok     Bible file 12)
 *   anak       <16 atau penanda anak         → ditolak
 *
 * Laga campuran (wanita vs pria) dengan petinju remaja/tak jelas ditolak,
 * sama dengan MixedFight. Tokoh nyata hanya boleh di laga pro mode olahraga.
 * Tag "adult", "25 years old" dan sejenisnya tidak pernah mengubah apa pun:
 * yang dibaca adalah jawaban riset tentang canon.
 */
final class PagarUmur
{
    public const DEWASA    = 'dewasa';
    public const REMAJA    = 'remaja';
    public const TAK_JELAS = 'tak_jelas';
    public const ANAK      = 'anak';

    /** Penanda anak — kalau muncul di keterangan umur atau rupa: anak. */
    private const POLA_ANAK = '/\b(loli(ta|con)?|shota(con)?|child(ren|like|ish)?|kids?|toddler|infant|elementary|grade[\s-]?school(er)?|primary school|middle[\s-]?school(er)?|junior high|anak[\s-]anak|balita|bocah|murid sd|siswi? sd|siswi? smp)\b|小学|中学|幼女|幼児|児童|ロリ/iu';

    /**
     * Kilas balik masa kecil di bukti umur — "was already a child when she
     * met Gin" (Rangiku, 2026-10-28) — bukan keadaan versi yang dipakai.
     * Sengaja sempit: hanya kalimat lampau yang terikat "when/since/while";
     * "is a child", "appears as a child", angka umur, dan rupa tetap dibaca.
     */
    private const POLA_MASA_KECIL = '/\b(?:when|since|while)\s+(?:she|he|they)\s+(?:was|were)\s+(?:still\s+)?(?:an?\s+)?(?:little\s+|young\s+|small\s+)?(?:child|kid)\b'
        . '|\b(?:was|were)\s+(?:already\s+|still\s+|just\s+|only\s+)?(?:an?\s+)?(?:little\s+|young\s+|small\s+)?(?:child|kid)\s+when\b'
        // Anak MILIK tokoh itu ("his son and children", "married with a child",
        // "mother of two children") — status orang tua, bukan umurnya sendiri.
        . '|\b(?:his|her|their)\s+(?:own\s+)?(?:young\s+)?(?:child|children|kids?)\b'
        . '|\b(?:mother|father|parent)\s+of\s+(?:an?\s+|one\s+|two\s+|three\s+|\d+\s+)?(?:young\s+)?(?:child|children|kids?)\b'
        . '|\bwith\s+(?:an?\s+|one\s+|two\s+|three\s+|\d+\s+)?(?:young\s+)?(?:child|children|kids?)\b'
        // Judul karya yang memuat kata itu (Draco: "Harry Potter and the Cursed
        // Child", 2026-10-28). Daftar tetap, bukan teks bebas dari model.
        . '|\b(?:harry potter and the\s+)?cursed child\b|\bwolf children\b|\bchildren of the whales\b|\bchild of light\b/iu';

    /**
     * Negasi status sekolah — "ia bukan pelajar" (Shego, 2026-10-28), "not a
     * student", "no longer a high school student" — dibuang sebelum pola
     * sekolah dibaca. Angka umur dan bendera dewasa tetap menentukan.
     */
    private const POLA_BUKAN_SEKOLAH = '/\b(?:not|no longer|never|bukan|tidak lagi|bukan lagi)\s+(?:a\s+|an\s+|seorang\s+)?(?:high[\s-]?school\s+)?(?:student|schoolgirl|schoolboy|pelajar|siswi?|murid)\b/iu';

    /** Penanda remaja sekolah — tidak dewasa walau model menulis angka 18+. */
    private const POLA_SEKOLAH = '/\b(high[\s-]?school(er)?|schoolgirl|schoolboy|school uniform student|sma|smu|siswi|pelajar|kōkōsei|koukousei|joshikousei|jk)\b|高校生|女子高生|高校/iu';

    /** Penanda "remaja" yang lemah — dipakai hanya kalau angka umur tidak ada. */
    private const POLA_REMAJA = '/\b(teen(age|ager)?s?|adolescent|remaja|minor|underage|di bawah umur|belum dewasa)\b|未成年/iu';

    /** Frasa dewasa yang menyebut sekolah — dibuang sebelum pola sekolah dibaca. */
    private const POLA_SEKOLAH_DEWASA = '/\b(former|ex-|graduated?|alumn(a|i|us)|mantan|lulusan|teacher|guru|sensei|nurse|coach|pelatih|principal|kepala sekolah|staff)\s+(of\s+|di\s+|dari\s+)?(a\s+|the\s+)?(high[\s-]?school|sma|smu)\b/iu';

    /**
     * Umur angka di bawah 18 dalam teks bebas: "16-year-old", "umur 15",
     * "15歳". "3 tahun" sendirian sengaja tidak ikut — itu lama waktu.
     */
    private const POLA_ANGKA_MUDA = '/\b(?:[1-9]|1[0-7])[\s-]*(?:years?[\s-]*old|yo\b|y\/o)|\b(?:aged?|umur|usia|berumur|berusia)\s*(?:[1-9]|1[0-7])\b|(?<![0-9０-９])(?:[1-9]|1[0-7])\s*歳/iu';

    /** Penanda kuat di isian pengguna: selalu menolak. */
    private const POLA_ISIAN = '/\b(loli(ta|con)?|shota(con)?|underage|di bawah umur|belum (cukup )?umur|belum dewasa|minors?|child(ren)?|kids?|anak[\s-]anak|bocah|elementary|middle[\s-]?school|junior high|high[\s-]?school(er)?|schoolgirl|schoolboy|sisw[ai]|pelajar|sma|smu|smp|sd)\b|小学|中学|高校生|幼女|ロリ|女子高生/iu';

    /**
     * Penanda di PROSA cerita. Lebih sempit dari POLA_ANAK: "Come on, kid!"
     * dari pelatih atau "like a child" sebagai kiasan itu biasa di cerita
     * tinju, jadi yang dicari hanya penanda tubuh/umur anak yang jelas.
     */
    private const POLA_PROSA = '/\b(loli(ta|con)?|shota(con)?|underage|child[\s-]?like|childish body|child\'s body|schoolgirl|schoolboy|elementary school(er)?|middle school(er)?|junior high)\b|小学生|中学生|女子高生|幼女|ロリ|未成年/iu';

    /**
     * Tingkat umur satu petinju dari jawaban riset.
     *
     * @param array<string,mixed> $r baris riset: dewasa, umur_angka, umur_canon, bukti_umur, rupa
     */
    public static function tingkat(array $r): string
    {
        $angka = isset($r['umur_angka']) && is_numeric($r['umur_angka']) ? (int) $r['umur_angka'] : null;
        $dewasa = ($r['dewasa'] ?? false) === true;
        $ket = trim(((string) ($r['umur_canon'] ?? '')) . ' ' . ((string) ($r['bukti_umur'] ?? '')));
        $rupa = (string) ($r['rupa'] ?? '');

        $ketAnak = preg_replace(self::POLA_MASA_KECIL, ' ', $ket) ?? $ket;
        if (preg_match(self::POLA_ANAK, $ketAnak . ' ' . $rupa) === 1) {
            return self::ANAK;
        }
        if ($angka !== null && $angka > 0 && $angka < 16) {
            return self::ANAK;
        }
        if ($angka !== null && $angka >= 16 && $angka <= 17) {
            return self::REMAJA;
        }

        $ketSekolah = preg_replace([self::POLA_SEKOLAH_DEWASA, self::POLA_BUKAN_SEKOLAH], ' ', $ket) ?? $ket;
        $sekolah = preg_match(self::POLA_SEKOLAH, $ketSekolah) === 1;
        $angkaMuda = preg_match(self::POLA_ANGKA_MUDA, $ketSekolah) === 1;

        if ($angka !== null && $angka >= 18) {
            // Angka dewasa tapi keterangannya murid sekolah atau umur muda:
            // bentrok, jangan percaya angkanya.
            return ($dewasa && ! $sekolah && ! $angkaMuda) ? self::DEWASA : self::TAK_JELAS;
        }

        // Tanpa angka: hanya dewasa kalau model yakin dan tidak ada penanda.
        if ($dewasa && ! $sekolah && ! $angkaMuda && preg_match(self::POLA_REMAJA, $ketSekolah) !== 1) {
            return self::DEWASA;
        }

        return self::TAK_JELAS;
    }

    /**
     * Putuskan jenis dan isi yang boleh untuk pasangan ini.
     *
     * @param array<string,mixed> $a riset petinju A (+ 'nama')
     * @param array<string,mixed> $b riset petinju B (+ 'nama')
     * @return array{jenis:string, isi:string, tingkat:array{a:string,b:string}, catatan:list<string>}
     * @throws PenulisGalat ditolak
     */
    public static function putuskan(array $a, array $b, string $jenis, string $isi): array
    {
        $tA = self::tingkat($a);
        $tB = self::tingkat($b);
        $catatan = [];
        $label = fn (array $r, string $t) => sprintf('%s (%s: %s)', (string) ($r['nama_resmi'] ?? $r['nama'] ?? '?'), $t, (string) ($r['umur_canon'] ?? '?'));

        foreach ([[$a, $tA], [$b, $tB]] as [$r, $t]) {
            if ($t === self::ANAK) {
                throw PenulisGalat::ditolak('Ditolak: ' . $label($r, $t) . ' — umur canon di bawah 16 atau tampak anak. Karakter ini tidak dibuat sama sekali.');
            }
        }

        $muda = array_filter([[$a, $tA], [$b, $tB]], fn ($x) => $x[1] !== self::DEWASA);
        if ($muda !== []) {
            $siapa = implode(', ', array_map(fn ($x) => $label($x[0], $x[1]), $muda));
            if (in_array($jenis, JenisLaga::KHUSUS_DEWASA, true)) {
                throw PenulisGalat::ditolak("Ditolak: laga {$jenis} hanya untuk petinju dewasa canon. {$siapa} — yang bisa dibuat hanya laga pro mode olahraga nonseksual.");
            }
            $gA = (string) ($a['jenis_kelamin'] ?? '');
            $gB = (string) ($b['jenis_kelamin'] ?? '');
            if ($gA !== '' && $gB !== '' && $gA !== $gB) {
                throw PenulisGalat::ditolak("Ditolak: laga campuran wanita vs pria hanya untuk petinju dewasa canon. {$siapa}.");
            }
            if ($isi !== 'olahraga') {
                $catatan[] = "Isi diganti ke mode olahraga nonseksual karena {$siapa}.";
            }
            $isi = 'olahraga';
        }

        foreach ([$a, $b] as $r) {
            if (($r['tokoh_nyata'] ?? false) === true) {
                if ($jenis !== 'pro') {
                    throw PenulisGalat::ditolak('Ditolak: ' . (string) ($r['nama_resmi'] ?? $r['nama'] ?? '?') . " orang sungguhan — hanya laga pro mode olahraga.");
                }
                if ($isi !== 'olahraga') {
                    $catatan[] = 'Isi diganti ke mode olahraga karena ' . (string) ($r['nama_resmi'] ?? $r['nama'] ?? '?') . ' orang sungguhan.';
                }
                $isi = 'olahraga';
            }
        }

        return ['jenis' => $jenis, 'isi' => $isi, 'tingkat' => ['a' => $tA, 'b' => $tB], 'catatan' => $catatan];
    }

    /**
     * Isian pengguna (nama, asal, catatan, ide) dibaca sendiri, tidak
     * bergantung pada jawaban model.
     *
     * @throws PenulisGalat ditolak
     */
    public static function periksaIsian(string ...$teks): void
    {
        foreach ($teks as $t) {
            $t = preg_replace(self::POLA_SEKOLAH_DEWASA, ' ', $t) ?? $t;
            if (preg_match(self::POLA_ISIAN, $t, $m) === 1 || preg_match(self::POLA_ANGKA_MUDA, $t, $m) === 1) {
                throw PenulisGalat::ditolak("Ditolak: isian menyebut \"{$m[0]}\" — penanda di bawah umur.");
            }
        }
    }

    /**
     * Prosa hasil model untuk laga non-olahraga: penanda anak atau umur di
     * bawah 18 → potongan yang ketemu, atau null kalau bersih.
     */
    public static function periksaProsa(string $teks): ?string
    {
        foreach ([self::POLA_PROSA, self::POLA_ANGKA_MUDA] as $pola) {
            if (preg_match($pola, $teks, $m) === 1) {
                return $m[0];
            }
        }

        return null;
    }
}
