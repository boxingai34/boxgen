<?php

namespace App\Http\Controllers;

use App\Services\LandingContent;
use App\Services\YoutubeTerbaru;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman depan publik — etalase BoxinGenerated, bukan pintu generator.
 *
 * Isinya dari CMS (LandingContent). Video YouTube terbaru diambil dari
 * umpan Atom kanalnya dan di-cache satu jam, jadi halaman ini tidak
 * pernah menunggu YouTube lebih dari yang perlu.
 */
class LandingController extends Controller
{
    public function show(Request $request): Response
    {
        $isi = LandingContent::ambil();

        $video = [];
        if ($isi['sections']['youtube']) {
            // Video pilihan (dari CMS) didahulukan, lalu yang terbaru dari umpan.
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
            $video = array_slice(array_values($video), 0, max(1, (int) $isi['youtube']['max']));
        }

        // Gambar bagikan harus alamat mutlak (aturan Open Graph); alamat
        // halaman diambil dari permintaan, jadi ikut domain apa pun.
        $seo = $isi['seo'];
        if ($seo['og_image'] === '') {
            $seo['og_image'] = LandingContent::bawaan()['seo']['og_image'];
        }
        $seo['og_image'] = str_starts_with($seo['og_image'], 'http') ? $seo['og_image'] : url($seo['og_image']);
        $seo['url'] = url('/');

        return Inertia::render('Landing', [
            'isi'   => self::untukPublik($isi),
            'seo'   => $seo,
            'video' => $video,
            'masuk' => $request->user() !== null,
        ])->withViewData([
            'seo'    => $seo,
            'hero'   => $isi['hero']['image'],
            'publik' => [
                'nama'    => $isi['brand']['name'],
                'kalimat' => $isi['hero']['subtitle'],
                'tautan'  => array_map(fn ($s) => ['label' => $s['label'], 'url' => $s['url']], $isi['socials']),
            ],
        ]);
    }

    /**
     * Yang dikirim ke halaman publik hanya yang memang ditampilkan.
     *
     * Tier yang disembunyikan, id kanal, daftar video pilihan, dan isi
     * seksi yang dimatikan tidak perlu ikut ke HTML — orang yang membuka
     * "view source" cukup melihat apa yang ada di layar.
     */
    private static function untukPublik(array $isi): array
    {
        $isi['patreon']['tiers'] = array_values(array_filter($isi['patreon']['tiers'], fn ($t) => ! empty($t['show'])));
        unset($isi['youtube']['channel_id'], $isi['youtube']['featured'], $isi['youtube']['auto_latest']);

        foreach (['about', 'stats', 'rounds', 'patreon', 'x'] as $kunci) {
            if (empty($isi['sections'][$kunci])) {
                $isi[$kunci] = [];
            }
        }
        if (empty($isi['sections']['gallery'])) {
            $isi['gallery'] = [];
        }
        if (empty($isi['sections']['instagram'])) {
            $isi['instagram']['embeds'] = [];
        }

        return $isi;
    }
}
