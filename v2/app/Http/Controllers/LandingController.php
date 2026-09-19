<?php

namespace App\Http\Controllers;

use App\Services\AngkaHidup;
use Illuminate\Support\Facades\Cookie;
use App\Services\BahasaLanding;
use App\Services\DeviantartTerbaru;
use App\Services\LandingContent;
use App\Services\PatreonTerbaru;
use App\Services\YoutubeTerbaru;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman depan publik — etalase BoxinGenerated, bukan pintu generator.
 *
 * Teksnya dari CMS (LandingContent); yang berubah sendiri diambil dari
 * sumber resmi masing-masing dan disimpan di cache, jadi halaman ini tidak
 * pernah menunggu YouTube, Patreon, atau DeviantArt lebih dari yang perlu:
 *
 *   - daftar video   : umpan Atom kanal YouTube (1 jam)
 *   - subscriber/tayangan, patron/pos : halaman kanal + API kampanye (1-6 jam)
 *   - pos Patreon terbaru : API pos publik (1 jam)
 *   - galeri         : umpan RSS galeri DeviantArt (1 jam), kalau dinyalakan
 *
 * Semua punya salinan terakhir yang berhasil, jadi sumber yang sedang mati
 * tidak pernah membuat bagian halaman jadi kosong.
 */
class LandingController extends Controller
{
    /** Batas gambar di kartu geser sampul. */
    private const HERO_MAKS = 10;

    /**
     * Batas gambar di lapisan grid satu layar.
     *
     * Lima kolom dikali lima baris sudah lebih tinggi dari layar mana pun,
     * jadi selebihnya cuma menambah berkas yang diunduh tanpa pernah dilihat
     * — dan semua gambarnya lazy, jadi angka ini batas atas, bukan beban.
     */
    private const GRID_MAKS = 24;

    public function show(Request $request): Response
    {
        $isi = LandingContent::ambil();

        // Bahasa dipilih orangnya, tidak ditebak dari perambannya: halaman
        // ini ditulis bahasa Inggris, dan pembaca yang perambannya berbahasa
        // lain belum tentu ingin membacanya dalam bahasa itu. Pilihannya
        // diingat lewat kuki supaya kunjungan berikutnya tidak perlu
        // memilih lagi.
        $bahasa = BahasaLanding::sah($request->query('lang') ?? $request->cookie('lang'));

        // Diingat setahun, dan TIDAK dienkripsi: isinya dua huruf yang sudah
        // terlihat di alamatnya sendiri, dan kuki terenkripsi tidak bisa
        // dibaca cache di depan aplikasi.
        if ($request->query('lang') !== null) {
            Cookie::queue(cookie('lang', $bahasa, 60 * 24 * 365, null, null, null, false));
        }

        // Angka hidup (subscriber, tayangan, patron) dipasang lebih dulu:
        // penanda {subs} dan kawan-kawannya ada di banyak kalimat.
        $isi = AngkaHidup::terapkan($isi, AngkaHidup::kumpulkan($isi));

        $isi['hero']['images'] = array_slice($isi['hero']['images'], 0, self::HERO_MAKS);
        $isi['patreon']['recent'] = $this->posPatreon($isi);

        // Dua daftar dari satu panggilan: yang pendek untuk seksi galeri,
        // yang panjang untuk lapisan grid satu layar.
        ['tampil' => $isi['gallery'], 'semua' => $isi['gallery_semua']] = $this->galeri($isi);

        $video = $this->video($isi);

        // Tombol kedua di sampul boleh menunjuk video paling baru.
        if (! empty($isi['hero']['secondary']['auto_latest']) && isset($video[0]['url'])) {
            $isi['hero']['secondary']['url'] = $video[0]['url'];
        }

        // Diterjemahkan di ujung, sesudah angka hidup dan umpan terpasang:
        // yang diterjemahkan kalimat jadinya, bukan cetakannya. Kalimat
        // "{subs} subscribers" yang belum terisi angkanya tidak akan pernah
        // cocok dengan peta mana pun.
        $isi = BahasaLanding::terapkan($isi, $bahasa);

        $seo = $this->seo($isi);

        return Inertia::render('Landing', [
            'isi'    => self::untukPublik($isi),
            'seo'    => $seo,
            'video'  => $video,
            'masuk'  => $request->user() !== null,
            'bahasa' => [
                'kini'   => $bahasa,
                'daftar' => BahasaLanding::daftar($bahasa),
            ],
        ])->withViewData([
            'lang'   => BahasaLanding::html($bahasa),
            'seo'    => $seo,
            'hero'   => $isi['hero']['images'][0]['src'] ?? '',
            'publik' => [
                'nama'    => $isi['brand']['name'],
                'kalimat' => $isi['hero']['subtitle'],
                'tautan'  => array_map(fn ($s) => ['label' => $s['label'], 'url' => $s['url']], $isi['socials']),
            ],
        ]);
    }

    /** Video pilihan (dari CMS) didahulukan, lalu yang terbaru dari umpan. */
    private function video(array $isi): array
    {
        if (empty($isi['sections']['youtube'])) {
            return [];
        }

        $video = [];
        foreach ($isi['youtube']['featured'] as $id) {
            if (preg_match('/^[\w-]{11}$/', $id)) {
                $video[$id] = [
                    'id'      => $id,
                    'judul'   => '',
                    'url'     => 'https://www.youtube.com/watch?v=' . $id,
                    'tanggal' => '',
                    'thumb'   => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
                ];
            }
        }

        if ($isi['youtube']['auto_latest'] && $isi['youtube']['channel_id'] !== '') {
            foreach (YoutubeTerbaru::ambil($isi['youtube']['channel_id'], 12) as $v) {
                $video[$v['id']] = $v;
            }
        }

        return array_slice(array_values($video), 0, max(1, (int) $isi['youtube']['max']));
    }

    /**
     * Pos Patreon terbaru.
     *
     * Kalau otomatisnya menyala dan Patreon menjawab, judul dari sanalah
     * yang dipakai; kalau tidak, daftar ketikan di CMS yang tampil.
     */
    private function posPatreon(array $isi): array
    {
        if (empty($isi['patreon']['recent_auto']) || empty($isi['patreon']['campaign_id'])) {
            return $isi['patreon']['recent'];
        }

        $pos = PatreonTerbaru::pos(
            (string) $isi['patreon']['campaign_id'],
            (int) ($isi['patreon']['recent_max'] ?: 4),
            $isi['patreon']['recent_filter'],
        );

        return $pos === [] ? $isi['patreon']['recent'] : array_column($pos, 'judul');
    }

    /**
     * Galeri.
     *
     * Bawaannya daftar gambar sendiri dari CMS. Kalau umpan DeviantArt
     * dinyalakan dan menjawab, karya terbarunya yang tampil — yang ditandai
     * "adult" oleh DeviantArt tetap dilewati kecuali memang diminta ikut.
     *
     * Dikembalikan dua daftar. "tampil" sepanjang yang diminta CMS, dipakai
     * seksi galeri; "semua" lebih panjang, dipakai lapisan grid satu layar
     * yang memang butuh dinding gambar. Keduanya lahir dari satu panggilan
     * jaringan — grid yang mengambil sendiri berarti umpan yang sama ditarik
     * dua kali hanya karena potongannya beda panjang.
     */
    private function galeri(array $isi): array
    {
        $umpan = $isi['gallery_feed'];
        if (empty($umpan['on']) || $umpan['username'] === '') {
            return ['tampil' => $isi['gallery'], 'semua' => $isi['gallery']];
        }

        $pendek = max(1, (int) ($umpan['max'] ?: 6));
        $panjang = max($pendek, self::GRID_MAKS);

        $karya = DeviantartTerbaru::ambil(
            (string) $umpan['username'],
            $panjang + count($umpan['skip']),
            (bool) $umpan['ikut_dewasa'],
        );

        // Penjaga kedua. Layanannya sudah menyaring, tapi ini satu-satunya
        // tempat di mana kesalahan berarti karya bertanda dewasa terpampang
        // di halaman umum — jadi disaring sekali lagi di sini.
        if (empty($umpan['ikut_dewasa'])) {
            $karya = array_filter($karya, fn ($k) => empty($k['dewasa']));
        }

        $lewati = array_flip($umpan['skip']);
        $keluar = [];
        foreach ($karya as $k) {
            if (isset($lewati[$k['url']])) {
                continue;
            }
            $keluar[] = [
                'src'     => $k['thumb'],
                'alt'     => $k['judul'],
                'caption' => $k['judul'],
                'link'    => $k['url'],
                'kind'    => 'fighter',
            ];
        }

        if ($keluar === []) {
            return ['tampil' => $isi['gallery'], 'semua' => $isi['gallery']];
        }

        return [
            'tampil' => array_slice($keluar, 0, $pendek),
            'semua'  => array_slice($keluar, 0, $panjang),
        ];
    }

    /** Gambar bagikan harus alamat mutlak (aturan Open Graph). */
    private function seo(array $isi): array
    {
        $seo = $isi['seo'];
        if ($seo['og_image'] === '') {
            $seo['og_image'] = LandingContent::bawaan()['seo']['og_image'];
        }
        $seo['og_image'] = str_starts_with($seo['og_image'], 'http') ? $seo['og_image'] : url($seo['og_image']);
        $seo['url'] = url('/');

        return $seo;
    }

    /**
     * Yang dikirim ke halaman publik hanya yang memang ditampilkan.
     *
     * Tier yang disembunyikan, id kanal, kata saring, dan isi seksi yang
     * dimatikan tidak perlu ikut ke HTML — orang yang membuka "view source"
     * cukup melihat apa yang ada di layar.
     */
    private static function untukPublik(array $isi): array
    {
        $isi['patreon']['tiers'] = array_values(array_filter($isi['patreon']['tiers'], fn ($t) => ! empty($t['show'])));

        unset(
            $isi['youtube']['channel_id'],
            $isi['youtube']['featured'],
            $isi['youtube']['auto_latest'],
            $isi['youtube']['auto_stats'],
            $isi['patreon']['campaign_id'],
            $isi['patreon']['recent_auto'],
            $isi['patreon']['recent_max'],
            $isi['patreon']['recent_filter'],
            $isi['patreon']['auto_stats'],
            $isi['gallery_feed'],
            $isi['angka_cadangan'],
        );

        foreach (['about', 'stats', 'rounds', 'patreon', 'x'] as $kunci) {
            if (empty($isi['sections'][$kunci])) {
                $isi[$kunci] = [];
            }
        }
        if (empty($isi['sections']['gallery'])) {
            $isi['gallery'] = [];
            $isi['gallery_semua'] = [];
        }
        if (empty($isi['sections']['instagram'])) {
            $isi['instagram']['embeds'] = [];
        }

        return $isi;
    }
}
