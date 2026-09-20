<?php

namespace App\Http\Controllers;

use App\Services\GambarModul;
use Database;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use TagResolver;

/**
 * Master katalog — menyunting modul tanpa menyentuh berkas data.
 *
 * KENAPA ADA KUNCINYA
 * Modul dibangun dari database/data/*.php dan seeder MENIMPA semuanya tiap
 * kali jalan — nama, keterangan, dan seluruh daftar tagnya. Selama berkas
 * itu satu-satunya sumber, menimpa memang yang diinginkan.
 *
 * Halaman ini membuat sumbernya jadi dua, dan yang kalah selalu yang
 * disunting tangan: deploy berikutnya menghapusnya tanpa memberi tahu siapa
 * pun. Jadi modul yang pernah disimpan di sini DIKUNCI, dan seeder
 * melewatinya utuh. Membuka kuncinya mengembalikannya ke berkas data pada
 * seed berikutnya — dan itu keputusan yang harus diambil sadar, bukan
 * kejutan.
 *
 * GAMBARNYA SAMA CERITANYA
 * Yang digambar `php artisan modul:contoh` ikut ke git, jadi `git pull`
 * menimpanya. Unggahan disimpan di public/uploads yang justru ada di
 * .gitignore supaya selamat, dan GambarModul memenangkannya.
 */
class KatalogController extends Controller
{
    /** Ukuran unggahan paling besar. */
    private const MAKS_KB = 8192;

    /** Sisi terpanjang gambar yang disimpan. */
    private const SISI_MAKS = 640;

    /**
     * Kolom yang boleh disunting dari sini.
     *
     * sentence, action_tag, direction_* dan sub_groups SENGAJA tidak ada:
     * keempatnya mengubah cara mesin menyusun kalimat dan menentukan arah
     * pukulan, dan salah isi di sana rusaknya tidak kelihatan sampai
     * promptnya dipakai. Itu urusan berkas data, tempat perubahannya
     * tercatat di git.
     */
    private const KOLOM = ['name', 'name_id', 'category', 'description', 'is_nsfw', 'is_active'];

    public function halaman(): Response
    {
        return Inertia::render('Cms/Katalog', [
            'tipe' => $this->daftarTipe(),
        ]);
    }

    /** Tipe modul beserta jumlahnya, untuk menu di kiri. */
    private function daftarTipe(): array
    {
        $rows = Database::all(
            'SELECT type, COUNT(*) AS jumlah, SUM(dikunci_at IS NOT NULL) AS dikunci
               FROM modules GROUP BY type ORDER BY type'
        );

        return array_map(static fn (array $r): array => [
            'tipe'    => (string) $r['type'],
            'jumlah'  => (int) $r['jumlah'],
            'dikunci' => (int) $r['dikunci'],
        ], $rows);
    }

    /** Seluruh modul satu tipe, lengkap dengan tag dan gambarnya. */
    public function daftar(Request $request): JsonResponse
    {
        $tipe = $this->tipeSah($request->query('tipe'));
        $gambar = GambarModul::peta($tipe);

        $modul = Database::all(
            'SELECT id, slug, name, name_id, category, description, is_nsfw, is_active,
                    dikunci_at, sort_order
               FROM modules WHERE type = ? ORDER BY sort_order, name',
            [$tipe]
        );

        // Satu query untuk SELURUH tag tipe ini, bukan satu per modul.
        // Tiga ratus modul berarti tiga ratus perjalanan ke database untuk
        // pekerjaan yang muat dalam satu.
        $tag = [];
        $rows = Database::all(
            'SELECT mt.module_id, t.name, mt.weight, mt.role
               FROM module_tags mt
               JOIN tags t ON t.id = mt.tag_id
               JOIN modules m ON m.id = mt.module_id
              WHERE m.type = ?
              ORDER BY mt.sort_order, t.name',
            [$tipe]
        );
        foreach ($rows as $r) {
            $tag[(int) $r['module_id']][] = [
                'nama'  => (string) $r['name'],
                'bobot' => (float) $r['weight'],
                'peran' => $r['role'] ?: null,
            ];
        }

        return response()->json([
            'ok'    => true,
            'tipe'  => $tipe,
            'hasil' => array_map(static function (array $m) use ($tag, $gambar, $tipe): array {
                $slug = (string) $m['slug'];

                return [
                    'id'         => (int) $m['id'],
                    'slug'       => $slug,
                    'nama'       => (string) $m['name'],
                    'nama_id'    => (string) ($m['name_id'] ?? ''),
                    'kategori'   => (string) ($m['category'] ?? ''),
                    'keterangan' => (string) ($m['description'] ?? ''),
                    'nsfw'       => (int) $m['is_nsfw'] === 1,
                    'aktif'      => (int) $m['is_active'] === 1,
                    'dikunci'    => $m['dikunci_at'] !== null,
                    'gambar'     => $gambar[$slug] ?? null,
                    'diunggah'   => GambarModul::diunggah($tipe, $slug),
                    'tag'        => $tag[(int) $m['id']] ?? [],
                ];
            }, $modul),
        ]);
    }

    /** Menyimpan satu modul — dan menguncinya dari seeder. */
    public function simpan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'         => ['required', 'integer'],
            'nama'       => ['required', 'string', 'max:120'],
            'nama_id'    => ['nullable', 'string', 'max:120'],
            'kategori'   => ['nullable', 'string', 'max:40'],
            'keterangan' => ['nullable', 'string', 'max:500'],
            'nsfw'       => ['boolean'],
            'aktif'      => ['boolean'],
            'tag'        => ['array', 'max:40'],
            'tag.*.nama' => ['required', 'string', 'max:190'],
            'tag.*.bobot' => ['numeric', 'between:0.1,2'],
        ]);

        $modul = Database::one('SELECT id, type, slug FROM modules WHERE id = ?', [(int) $data['id']]);
        abort_if($modul === null, 404, 'Modulnya tidak ada.');

        Database::run(
            'UPDATE modules SET name=?, name_id=?, category=?, description=?, is_nsfw=?, is_active=?,
                    dikunci_at = NOW() WHERE id = ?',
            [
                trim($data['nama']),
                trim((string) ($data['nama_id'] ?? '')) ?: null,
                trim((string) ($data['kategori'] ?? '')) ?: null,
                trim((string) ($data['keterangan'] ?? '')) ?: null,
                ! empty($data['nsfw']) ? 1 : 0,
                ! empty($data['aktif']) ? 1 : 0,
                (int) $modul['id'],
            ]
        );

        $this->simpanTag((int) $modul['id'], (string) $modul['type'], $data['tag'] ?? []);

        return response()->json(['ok' => true, 'dikunci' => true]);
    }

    /**
     * Daftar tag ditulis ulang seluruhnya, bukan ditambal.
     *
     * Menyunting berarti mengatakan "isinya PERSIS ini". Menambal
     * meninggalkan tag lama yang sudah dihapus orangnya di layar, dan tag
     * yang tidak bisa dibuang adalah tag yang tidak pernah benar-benar
     * disunting.
     */
    private function simpanTag(int $modulId, string $tipe, array $tag): void
    {
        Database::run('DELETE FROM module_tags WHERE module_id = ?', [$modulId]);

        $urut = 0;
        $sudah = [];

        foreach ($tag as $t) {
            $nama = TagResolver::normalize((string) $t['nama']);
            if ($nama === '' || isset($sudah[$nama])) {
                continue;
            }
            $sudah[$nama] = true;

            // Tag yang belum dikenal DIBUATKAN, tidak ditolak.
            //
            // Tanpa ini, kata yang memang bukan tag Danbooru — bentuk sarung
            // tinju, misalnya — tidak akan pernah bisa ditambahkan dari sini,
            // padahal itu justru yang paling sering perlu disetel tangan.
            $tagId = TagResolver::getOrCreate($nama, 0, $tipe);

            Database::run(
                'INSERT INTO module_tags (module_id, tag_id, weight, role, sort_order) VALUES (?,?,?,?,?)',
                [$modulId, $tagId, round((float) ($t['bobot'] ?? 1), 2), null, ++$urut]
            );
        }
    }

    /** Mengembalikan modul ke berkas data pada seed berikutnya. */
    public function bukaKunci(Request $request): JsonResponse
    {
        $id = (int) $request->input('id');
        $modul = Database::one('SELECT id FROM modules WHERE id = ?', [$id]);
        abort_if($modul === null, 404, 'Modulnya tidak ada.');

        Database::run('UPDATE modules SET dikunci_at = NULL WHERE id = ?', [$id]);

        return response()->json(['ok' => true, 'dikunci' => false]);
    }

    /** Mengganti gambar contoh satu modul. */
    public function gambar(Request $request): JsonResponse
    {
        $request->validate([
            'id'     => ['required', 'integer'],
            'gambar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:' . self::MAKS_KB],
        ], [
            'gambar.mimes' => 'Hanya JPG, PNG, atau WebP.',
            'gambar.max'   => 'Ukuran paling besar 8 MB.',
        ]);

        $modul = Database::one('SELECT type, slug FROM modules WHERE id = ?', [(int) $request->input('id')]);
        abort_if($modul === null, 404, 'Modulnya tidak ada.');

        $tipe = (string) $modul['type'];
        $slug = (string) $modul['slug'];
        $tujuan = GambarModul::jalurUnggah($tipe, $slug);
        File::ensureDirectoryExists(dirname($tujuan));

        $berkas = $request->file('gambar');

        // Disimpan sebagai WebP 640 piksel, sama seperti yang digambar
        // otomatis — kalau tidak, satu kartu unggahan bisa berukuran empat
        // megabita di katalog yang berisi ratusan kartu.
        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            return response()->json([
                'ok'    => false,
                'error' => 'PHP di server ini tidak punya GD/WebP, jadi gambarnya tidak bisa dikecilkan.',
            ], 503);
        }

        $sumber = @imagecreatefromstring((string) file_get_contents($berkas->getRealPath()));
        if ($sumber === false) {
            return response()->json(['ok' => false, 'error' => 'Berkasnya tidak bisa dibaca sebagai gambar.'], 422);
        }

        $w = imagesx($sumber);
        $h = imagesy($sumber);
        $skala = min(1, self::SISI_MAKS / max(1, max($w, $h)));
        $lebar = (int) round($w * $skala);
        $tinggi = (int) round($h * $skala);

        $kecil = imagecreatetruecolor($lebar, $tinggi);
        imagealphablending($kecil, false);
        imagesavealpha($kecil, true);
        imagecopyresampled($kecil, $sumber, 0, 0, 0, 0, $lebar, $tinggi, $w, $h);
        $jadi = imagewebp($kecil, $tujuan, 82);
        imagedestroy($kecil);
        imagedestroy($sumber);

        if (! $jadi) {
            return response()->json(['ok' => false, 'error' => 'Gagal menyimpan gambarnya.'], 500);
        }

        return response()->json([
            'ok'     => true,
            'gambar' => '/' . GambarModul::FOLDER_UNGGAH . '/' . $tipe . '/' . $slug . '.webp?v=' . time(),
            'ukuran' => $lebar . '×' . $tinggi,
        ]);
    }

    /** Membuang gambar unggahan; yang digambar otomatis kembali dipakai. */
    public function hapusGambar(Request $request): JsonResponse
    {
        $modul = Database::one('SELECT type, slug FROM modules WHERE id = ?', [(int) $request->input('id')]);
        abort_if($modul === null, 404, 'Modulnya tidak ada.');

        $jalur = GambarModul::jalurUnggah((string) $modul['type'], (string) $modul['slug']);
        if (is_file($jalur)) {
            @unlink($jalur);
        }

        return response()->json(['ok' => true]);
    }

    /** Tipe yang benar-benar ada di database — bukan apa pun yang diketik. */
    private function tipeSah(?string $tipe): string
    {
        $tipe = trim((string) $tipe);
        $ada = Database::value('SELECT 1 FROM modules WHERE type = ? LIMIT 1', [$tipe]);
        abort_if($ada === null, 404, 'Tipe modul tidak dikenal.');

        return $tipe;
    }
}
