<?php
declare(strict_types=1);

/**
 * Riwayat prompt.
 *
 * Tabel `generations` sejak awal mencatat setiap prompt yang jadi, tapi
 * dulu isinya cuma bisa dilihat lewat database. Sekarang jadi halaman
 * sendiri: bisa dibuka lagi, dipakai ulang, dan diberi gambar hasilnya.
 *
 * GAMBAR HASILNYA DIISI TANGAN
 * Website ini membuat prompt, bukan gambar. Jadi tidak ada cara otomatis
 * mengetahui hasil akhirnya seperti apa — kamu yang menempelkan alamat
 * gambarnya sendiri setelah membuatnya di Stable Diffusion atau NovelAI.
 *
 * MEMAKAI ULANG SUSUNAN LAMA
 * Yang dipulihkan bukan teks promptnya, melainkan pilihannya — persis
 * seperti preset. Jadi prompt dibangun ulang dengan kamus tag terbaru,
 * bukan diulang mentah-mentah dari teks lama.
 */
final class Riwayat
{
    private const PER_HALAMAN = 20;

    /** Panjang maksimal alamat gambar, mengikuti lebar kolomnya. */
    private const MAKS_URL   = 500;
    private const MAKS_JUDUL = 150;
    private const MAKS_NOTE  = 500;

    /**
     * Daftar riwayat milik satu orang.
     *
     * @return array{items: array, total: int, halaman: int, jumlahHalaman: int}
     */
    public static function daftar(int $userId, int $halaman = 1, string $cari = ''): array
    {
        $halaman = max(1, $halaman);
        $cari    = trim($cari);

        $where  = ['user_id = ?'];
        $params = [$userId];

        if ($cari !== '') {
            $where[]  = '(title LIKE ? OR output LIKE ? OR note LIKE ?)';
            $kata     = '%' . $cari . '%';
            $params[] = $kata;
            $params[] = $kata;
            $params[] = $kata;
        }

        $sql = implode(' AND ', $where);

        $total = (int)Database::value(
            'SELECT COUNT(*) FROM generations WHERE ' . $sql, $params
        );

        $mulai = ($halaman - 1) * self::PER_HALAMAN;

        // LIMIT/OFFSET disisipkan sebagai angka hasil (int) — bukan dari
        // input mentah — karena MySQL tidak menerimanya sebagai parameter
        // di semua versi.
        $items = Database::all(
            'SELECT id, mode, target, title, output, negative, preview_url, note,
                    token_estimate, used_ai, created_at
             FROM generations
             WHERE ' . $sql . '
             ORDER BY id DESC
             LIMIT ' . self::PER_HALAMAN . ' OFFSET ' . $mulai,
            $params
        );

        return [
            'items'         => $items,
            'total'         => $total,
            'halaman'       => $halaman,
            'jumlahHalaman' => max(1, (int)ceil($total / self::PER_HALAMAN)),
        ];
    }

    /** Satu baris riwayat, tapi hanya kalau memang miliknya. */
    public static function ambil(int $id, int $userId): ?array
    {
        return Database::one(
            'SELECT * FROM generations WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    /**
     * Pilihan yang dipakai waktu prompt itu dibuat, siap dipasang kembali
     * ke formulir.
     */
    public static function untukDipakaiLagi(int $id, int $userId): ?array
    {
        $baris = self::ambil($id, $userId);
        if ($baris === null) {
            return null;
        }

        $sel = json_decode((string)$baris['selection'], true);
        if (!is_array($sel)) {
            return null;
        }

        return ['riwayat' => self::ringkas($baris)] + Preset::hidupkan($sel);
    }

    /**
     * Simpan judul, alamat gambar hasil, dan catatan.
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function simpanCatatan(int $id, int $userId, array $in): array
    {
        if (self::ambil($id, $userId) === null) {
            return ['ok' => false, 'error' => 'Riwayat tidak ditemukan.'];
        }

        $judul = mb_substr(trim((string)($in['title'] ?? '')), 0, self::MAKS_JUDUL);
        $note  = mb_substr(trim((string)($in['note'] ?? '')), 0, self::MAKS_NOTE);
        $url   = trim((string)($in['preview_url'] ?? ''));

        if ($url !== '') {
            // Alamat ini nanti dipasang sebagai <img src>. Kalau skema apa
            // pun diterima, "javascript:" bisa ikut masuk dan berjalan di
            // browser orang lain. Jadi hanya http dan https yang boleh.
            $urai = parse_url($url);
            $skema = strtolower((string)($urai['scheme'] ?? ''));

            if (!in_array($skema, ['http', 'https'], true) || empty($urai['host'])) {
                return ['ok' => false, 'error' => 'Alamat gambar harus diawali http:// atau https://'];
            }

            if (mb_strlen($url) > self::MAKS_URL) {
                return ['ok' => false, 'error' => 'Alamat gambarnya terlalu panjang.'];
            }
        }

        Database::run(
            'UPDATE generations SET title = ?, preview_url = ?, note = ? WHERE id = ? AND user_id = ?',
            [
                $judul !== '' ? $judul : null,
                $url   !== '' ? $url   : null,
                $note  !== '' ? $note  : null,
                $id,
                $userId,
            ]
        );

        return ['ok' => true, 'error' => null];
    }

    public static function hapus(int $id, int $userId): bool
    {
        return Database::run(
            'DELETE FROM generations WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }

    public static function jumlah(int $userId): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM generations WHERE user_id = ?', [$userId]
        );
    }

    /**
     * Judul otomatis dari isi promptnya, dipakai kalau kamu belum memberi
     * judul sendiri. Diambil dari nama karakternya — itu yang paling
     * membantu waktu menyisir daftar panjang.
     */
    public static function judulOtomatis(array $sel, string $mode): string
    {
        $orang = [];

        foreach (['a', 'b'] as $sisi) {
            $tag = $sel[$sisi]['character'] ?? null;
            if (is_string($tag) && $tag !== '') {
                $orang[] = CharacterResolver::namaCantik($tag);
            }
        }

        // mode 1 petinju menaruh karakternya di tingkat teratas
        if ($orang === [] && !empty($sel['character']) && is_string($sel['character'])) {
            $orang[] = CharacterResolver::namaCantik($sel['character']);
        }

        $label = match ($mode) {
            'duo'        => '2 Petinju',
            'seedance'   => 'Video',
            'storyboard' => 'Storyboard',
            default      => '1 Petinju',
        };

        return $orang === []
            ? $label
            : mb_substr(implode(' vs ', $orang) . ' — ' . $label, 0, self::MAKS_JUDUL);
    }

    private static function ringkas(array $r): array
    {
        return [
            'id'         => (int)$r['id'],
            'mode'       => $r['mode'],
            'target'     => $r['target'],
            'title'      => $r['title'],
            'created_at' => $r['created_at'],
        ];
    }
}
