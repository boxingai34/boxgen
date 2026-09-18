<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Isi halaman landing, disimpan sebagai satu berkas JSON.
 *
 * Bukan tabel database dengan sengaja. Databasenya dipakai bersama
 * aplikasi lama dan tidak ada migrasi yang boleh dijalankan ke sana;
 * tabel settings-nya cuma muat 64 KB per baris. Satu berkas di
 * storage/app tidak menyentuh keduanya, mudah dicadangkan, dan tiap kali
 * disimpan versi sebelumnya disalin ke .bak — jadi salah tekan di CMS
 * tidak pernah menghapus apa pun tanpa jejak.
 *
 * Bawaannya (bawaan()) dipakai kalau berkasnya belum ada, dan juga
 * sebagai kerangka: kunci yang hilang dari berkas tersimpan diisi dari
 * bawaan, supaya halaman tidak pernah menemui kunci yang tidak ada.
 */
class LandingContent
{
    public const BERKAS = 'landing.json';

    /** Berapa salinan lama yang disimpan. */
    private const CADANGAN = 5;

    public static function jalur(): string
    {
        return storage_path('app/' . self::BERKAS);
    }

    /** Isi lengkap, dengan kunci yang hilang diisi dari bawaan. */
    public static function ambil(): array
    {
        $bawaan = self::bawaan();

        if (! is_file(self::jalur())) {
            return $bawaan;
        }

        $tersimpan = json_decode((string) file_get_contents(self::jalur()), true);
        if (! is_array($tersimpan)) {
            return $bawaan;
        }

        return self::lengkapi($tersimpan, $bawaan);
    }

    /**
     * Simpan isi yang sudah dirapikan.
     *
     * Yang dirapikan: tipe dan panjang tiap nilai, dan tautan hanya boleh
     * http(s). Ini halaman publik — satu tautan javascript: yang lolos
     * dari CMS berarti skrip asing di halaman depanmu.
     */
    public static function simpan(array $masuk): array
    {
        $bersih = self::rapikan($masuk, self::bawaan());

        $jalur = self::jalur();
        File::ensureDirectoryExists(dirname($jalur));

        if (is_file($jalur)) {
            for ($i = self::CADANGAN - 1; $i >= 1; $i--) {
                if (is_file("$jalur.bak$i")) {
                    rename("$jalur.bak$i", "$jalur.bak" . ($i + 1));
                }
            }
            copy($jalur, "$jalur.bak1");
        }

        // Tulis ke berkas sementara dulu lalu ganti nama: pembaca tidak
        // pernah melihat JSON setengah jadi, dan kegagalan tulis ketahuan.
        $json = json_encode($bersih, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents("$jalur.tmp", $json) === false || ! rename("$jalur.tmp", $jalur)) {
            throw new RuntimeException('Isi halaman depan gagal disimpan ke ' . $jalur);
        }

        return $bersih;
    }

    // ------------------------------------------------------------------

    private static function lengkapi(array $isi, array $bawaan): array
    {
        foreach ($bawaan as $kunci => $nilai) {
            if (! array_key_exists($kunci, $isi)) {
                $isi[$kunci] = $nilai;
                continue;
            }
            // Daftar (gallery, socials, benefits) dipakai apa adanya —
            // isinya milik pengguna, bukan kerangka.
            if (is_array($nilai) && ! array_is_list($nilai) && is_array($isi[$kunci])) {
                $isi[$kunci] = self::lengkapi($isi[$kunci], $nilai);
            }
        }

        return $isi;
    }

    /**
     * Rapikan isi dari CMS mengikuti bentuk bawaannya.
     *
     * Aturannya sederhana: string dipotong, boolean dipaksa boolean, angka
     * dipaksa angka, daftar dibersihkan per anggota, dan kunci yang tidak
     * ada di bawaan dibuang. Nilai yang namanya mengandung "url", "link",
     * atau "src" harus tautan http(s) atau jalur lokal.
     */
    private static function rapikan(array $masuk, array $bawaan): array
    {
        $keluar = [];

        foreach ($bawaan as $kunci => $contoh) {
            $nilai = $masuk[$kunci] ?? null;

            if (is_array($contoh) && array_is_list($contoh)) {
                $keluar[$kunci] = self::rapikanDaftar(is_array($nilai) ? $nilai : [], $contoh[0] ?? '');
            } elseif (is_array($contoh)) {
                $keluar[$kunci] = self::rapikan(is_array($nilai) ? $nilai : [], $contoh);
            } elseif (is_bool($contoh)) {
                $keluar[$kunci] = (bool) $nilai;
            } elseif (is_int($contoh)) {
                $keluar[$kunci] = is_numeric($nilai) ? (int) $nilai : $contoh;
            } else {
                $keluar[$kunci] = self::rapikanTeks($kunci, $nilai, $contoh);
            }
        }

        return $keluar;
    }

    private static function rapikanDaftar(array $daftar, $contoh): array
    {
        $keluar = [];
        foreach (array_slice(array_values($daftar), 0, 60) as $anggota) {
            if (is_array($contoh)) {
                if (is_array($anggota)) {
                    $keluar[] = self::rapikan($anggota, $contoh);
                }
            } elseif (is_scalar($anggota)) {
                $t = self::rapikanTeks('item', $anggota, '');
                if ($t !== '') {
                    $keluar[] = $t;
                }
            }
        }

        return $keluar;
    }

    private static function rapikanTeks(string $kunci, $nilai, string $contoh): string
    {
        if (! is_scalar($nilai)) {
            return $contoh;
        }

        $t = trim((string) $nilai);
        $t = mb_substr($t, 0, str_contains($kunci, 'body') ? 4000 : 500);

        if (preg_match('/url|link|src|image$/i', $kunci) && $t !== '') {
            // Tautan luar harus http(s); tautan dalam harus mulai dari /.
            if (! preg_match('~^(https?://|/(?!/)|#)~i', $t)) {
                return '';
            }
        }

        return $t;
    }

    // ------------------------------------------------------------------

    /**
     * Isi awal, dalam bahasa Inggris, dari profil BoxinGenerated.
     *
     * Semua angka dan kalimat di sini diambil dari profil yang bisa
     * dibaca publik pada September 2026 — bio YouTube/Patreon/Pixiv, tier
     * Patreon, jumlah pengikut — bukan karangan. Angka pasti basi; karena
     * itu semuanya bisa diganti dari CMS dan halamannya menampilkan
     * "as of" supaya pembaca tahu kapan dihitung.
     */
    public static function bawaan(): array
    {
        $patreon = 'https://www.patreon.com/cw/BoxinGenerated';
        $youtube = 'https://www.youtube.com/@BoxinGenerated';

        return [
            'seo' => [
                'title'       => 'BoxinGenerated — Anime waifus, reimagined as boxers',
                'description' => 'AI-generated anime female boxing: your favorite anime girls step into the ring in original bouts — on YouTube, and 1–2 months early on Patreon.',
                'og_image'    => '/img/duel/bocchi-yui.webp',
            ],

            'brand' => [
                'name'    => 'BoxinGenerated',
                'jp'      => 'ボクシンジェネレイテッド',
                'kicker'  => 'Female Boxing AI Animation',
                'tagline' => 'Turn your waifu to be a boxer!',
            ],

            'hero' => [
                'eyebrow'     => 'Vol. 01 · 第一巻 — Female Boxing AI Animation',
                'title'       => 'Turn your waifu to be a boxer.',
                'subtitle'    => "Ever wondered what your favorite anime waifu would look like if she stepped out of her slice-of-life show and straight into the boxing ring? You're in the right place.",
                'primary'     => ['label' => 'Support on Patreon', 'url' => $patreon],
                'secondary'   => ['label' => 'Watch the latest bout', 'url' => '#bouts'],
                'pill'        => 'New bout every week',
                'image'       => '/img/tokoh/kafka.webp',
                'image_alt'   => 'Kafka, gloves laced, waiting for the bell',
                'caption'     => 'Fig. 01 — Kafka, at the weigh-in.',
                'jp_vertical' => '女子ボクシング',
                'hanko'       => '拳',
                'stats'       => [
                    ['value' => 'Weekly', 'label' => 'new bouts on YouTube'],
                    ['value' => '1–2 mo', 'label' => 'early access on Patreon'],
                    ['value' => '6', 'label' => 'places to follow'],
                ],
            ],

            'marquee' => [
                'Turn your waifu to be a boxer!', '女子ボクシング', 'A new bout every week', 'ボクシング',
                "Let's talk with our fist!", 'ノックアウト', 'Female Boxing AI Animation', '毎週更新',
            ],

            'about' => [
                'eyebrow' => 'The story',
                'heading' => 'Beloved characters. A brand-new discipline.',
                'body'    => "BoxinGenerated is a one-person studio that takes the anime girls you already love and gives them a complete boxing makeover — then puts them in the ring against each other in original, fully animated bouts.\n\nFrom cute and gentle to fierce and powerful, every fight is built with advanced AI generation and released weekly on YouTube. Stills, portraits and fight art land on Instagram and DeviantArt, with a few on Pixiv. Patreon is where the fights arrive first.",
                'quote'   => 'From cute and gentle to fierce and powerful.',
                'facts'   => [
                    ['label' => 'Format',     'value' => 'Original, fully animated bouts'],
                    ['label' => 'Cadence',    'value' => 'One release every week'],
                    ['label' => 'Made with',  'value' => 'Advanced AI generation, by one person'],
                    ['label' => 'First stop', 'value' => 'Patreon, then YouTube'],
                ],
            ],

            'stats' => [
                'eyebrow'   => 'Tale of the tape',
                'heading'   => 'The record so far.',
                'note'      => 'Figures as of September 2026. Counted by hand, updated by hand.',
                'secondary' => '2.07K subscribers · 24 videos on YouTube · 2,471 followers · 390 posts on X',
                'items'     => [
                    ['value' => '466', 'suffix' => 'K', 'label' => 'YouTube views',      'jp' => '再生'],
                    ['value' => '570', 'suffix' => '',  'label' => 'Patrons',            'jp' => '支援者'],
                    ['value' => '471', 'suffix' => '',  'label' => 'Patreon posts',      'jp' => '投稿'],
                    ['value' => '571', 'suffix' => '',  'label' => 'DeviantArt pieces',  'jp' => '作品'],
                ],
            ],

            'rounds' => [
                'eyebrow' => 'How a bout is made',
                'heading' => 'Three rounds, every week.',
                'body'    => 'Every release follows the same three beats.',
                'items'   => [
                    ['tag' => 'R1', 'jp' => '計量', 'title' => 'Weigh-in',      'body' => 'Two characters are matched, gloved up and weighed in.'],
                    ['tag' => 'R2', 'jp' => '試合', 'title' => 'Rounds',        'body' => 'The fight itself is built with advanced AI generation — from the opening bell to the final flurry.'],
                    ['tag' => 'R3', 'jp' => '公開', 'title' => 'Patrons first', 'body' => 'The finished bout goes to patrons 1–2 months before YouTube — and some fights are made for patrons only.'],
                ],
                'kit' => ['gloves', 'wraps', 'mouthguard', 'headguard', 'bra'],
            ],

            'youtube' => [
                'eyebrow'     => "Tonight's card",
                'heading'     => 'Latest bouts.',
                'body'        => 'The most recent fights from the channel. Only thumbnails load until you press play — no YouTube player until then.',
                'cta'         => 'See all bouts on YouTube',
                'meta'        => '2.07K subscribers · 466K views',
                'handle'      => '@BoxinGenerated',
                'url'         => $youtube,
                'channel_id'  => 'UCK0eKIuvxD8UWu0QGaFNxsg',
                'auto_latest' => true,
                'max'         => 6,
                'featured'    => [],
            ],

            'patreon' => [
                'eyebrow'   => 'Support',
                'heading'   => 'Ringside seats.',
                'body'      => 'Patreon is where you support BoxinGenerated — and where every bout lands first. Patrons watch upcoming YouTube fights up to two months early and get exclusive animated videos that are never posted anywhere public.',
                'benefits'  => [
                    ['text' => 'Upcoming YouTube bouts, 1–2 months before they go public.', 'jp' => '先行公開'],
                    ['text' => 'Exclusive animated videos — made for patrons only and never shared publicly.', 'jp' => '限定'],
                    ['text' => 'Request art: 2 full fight scenes every month on the $20 tier.', 'jp' => 'リクエスト'],
                    ['text' => 'A corner of 570 patrons — 247 paid members, 471 posts in the archive.', 'jp' => '応援'],
                ],
                // Tier persis seperti di Patreon (September 2026). Tier request
                // sedang penuh (slot 0) dan tier NSFW tidak cocok untuk halaman
                // depan, jadi disembunyikan — tinggal dicentang di CMS.
                'tiers' => [
                    ['name' => 'Animation only',               'price' => '$8',  'benefit' => '1–2 months early access + exclusive weekly animated videos', 'highlight' => true,  'show' => true],
                    ['name' => 'Animation + request more art', 'price' => '$20', 'benefit' => 'Early access plus 2 full fight-scene requests every month',   'highlight' => false, 'show' => true],
                    ['name' => 'Animation + request art',      'price' => '$13', 'benefit' => 'Early access plus 2 character requests every month',          'highlight' => false, 'show' => false],
                    ['name' => 'Request fight art',            'price' => '$10', 'benefit' => 'Request 2 full fight scenes every month',                   'highlight' => false, 'show' => false],
                    ['name' => 'Request art',                  'price' => '$5',  'benefit' => 'Request 2 characters every month',                          'highlight' => false, 'show' => false],
                    ['name' => 'Animation + NSFW art',         'price' => '$10', 'benefit' => 'Early access plus every NSFW artwork the moment it drops',   'highlight' => false, 'show' => false],
                    ['name' => 'NSFW art',                     'price' => '$3',  'benefit' => 'All NSFW artworks, scenes and special updates',              'highlight' => false, 'show' => false],
                ],
                'recent'        => ['Hinata vs Orihime — Full Fight', 'Aki vs Asuka — Full Fight', 'Yor vs Fiona — Full Fight', 'Faye vs Revy — Full Fight'],
                'trust'         => '570 patrons · 247 paid members · 471 posts',
                'url'           => $patreon,
                'cta'           => 'Become a patron',
                'secondary_cta' => 'See all tiers on Patreon',
                'note'          => 'Prices as listed on Patreon. Cancel anytime.',
            ],

            'gallery_text' => [
                'eyebrow' => 'Gallery',
                'heading' => 'Weigh-in portraits.',
                'body'    => 'Stills straight from the studio — portraits and fight posters. More on Instagram, and the full archive — 571 pieces and counting — on DeviantArt.',
                'cta'     => 'Follow on Instagram',
            ],

            // Empat potret dan dua poster yang layak untuk halaman umum. Poster
            // lain di public/img/duel (kafka-himeko, raiden-yae, yamada-sasaki)
            // terlalu terbuka untuk kartu bagikan/galeri bawaan — bisa
            // ditambahkan sendiri dari CMS kalau memang mau.
            'gallery' => [
                ['src' => '/img/tokoh/kafka.webp',     'alt' => 'Kafka, gloves up, ready for the bell', 'caption' => 'Kafka',         'link' => '', 'kind' => 'fighter'],
                ['src' => '/img/tokoh/bocchi.webp',    'alt' => 'Bocchi in the ring, red gloves on',    'caption' => 'Bocchi',        'link' => '', 'kind' => 'fighter'],
                ['src' => '/img/tokoh/lucy.webp',      'alt' => 'Lucy, gloves on, at the weigh-in',    'caption' => 'Lucy',          'link' => '', 'kind' => 'fighter'],
                ['src' => '/img/tokoh/evelyn.webp',    'alt' => 'Evelyn at the weigh-in',              'caption' => 'Evelyn',        'link' => '', 'kind' => 'fighter'],
                ['src' => '/img/duel/bocchi-yui.webp', 'alt' => 'Bocchi vs Yui fight poster',          'caption' => 'Bocchi vs Yui', 'link' => '', 'kind' => 'bout'],
                ['src' => '/img/duel/erica-iris.webp', 'alt' => 'Erica vs Iris fight poster',          'caption' => 'Erica vs Iris', 'link' => '', 'kind' => 'bout'],
            ],

            'instagram' => [
                'handle' => 'boxingenerated',
                'url'    => 'https://www.instagram.com/boxingenerated/',
                'embeds' => [],
            ],

            'x' => [
                'eyebrow'       => 'From the corner',
                'heading'       => "Let's talk with our fist!",
                'body'          => 'Fight announcements, previews and between-round chatter — 390 posts and counting, with 2,471 people in the corner.',
                'cta'           => 'Follow on X',
                'meta'          => '@boxingenerated · 2,471 followers · 390 posts',
                'handle'        => 'boxingenerated',
                'url'           => 'https://x.com/boxingenerated',
                'show_timeline' => true,
            ],

            'socials_text' => [
                'eyebrow' => 'Where to find us',
                'heading' => 'Follow the fights.',
                'body'    => 'One studio, six corners of the internet. Patreon is the one that keeps the gym open.',
            ],

            'socials' => [
                ['key' => 'patreon',    'label' => 'Patreon',    'handle' => 'BoxinGenerated',  'url' => $patreon,                                        'highlight' => true,  'description' => 'Early access and exclusive bouts', 'meta' => '570 patrons · 471 posts'],
                ['key' => 'youtube',    'label' => 'YouTube',    'handle' => '@BoxinGenerated', 'url' => $youtube,                                        'highlight' => false, 'description' => 'Full fights, every week',          'meta' => '2.07K subscribers · 24 videos'],
                ['key' => 'instagram',  'label' => 'Instagram',  'handle' => '@boxingenerated', 'url' => 'https://www.instagram.com/boxingenerated/',     'highlight' => false, 'description' => 'Portraits, posters and previews',  'meta' => ''],
                ['key' => 'x',          'label' => 'X',          'handle' => '@boxingenerated', 'url' => 'https://x.com/boxingenerated',                  'highlight' => false, 'description' => 'Announcements and clips',          'meta' => '2,471 followers · 390 posts'],
                ['key' => 'pixiv',      'label' => 'Pixiv',      'handle' => 'BoxinGenerated',  'url' => 'https://www.pixiv.net/en/users/111982750',       'highlight' => false, 'description' => 'Illustrations',                    'meta' => ''],
                ['key' => 'deviantart', 'label' => 'DeviantArt', 'handle' => 'boxingenerated',  'url' => 'https://www.deviantart.com/boxingenerated',     'highlight' => false, 'description' => 'The fight-art archive',            'meta' => '571 deviations'],
            ],

            'sections' => [
                'about'   => true,
                'stats'   => true,
                'rounds'  => true,
                'youtube' => true,
                'patreon' => true,
                'gallery' => true,
                // Sematan Instagram mati bawaan: akunnya ditandai restricted,
                // pengunjung yang tidak masuk Instagram cuma melihat kotak kosong.
                'instagram' => false,
                'x'       => true,
                'socials' => true,
            ],

            'footer' => [
                'line'      => 'AI-generated anime female boxing. Original bouts, every week.',
                'note'      => 'Characters belong to their respective creators. Fights are original, fan-made and AI-generated. Figures on this page as of September 2026.',
                'copyright' => '© 2026 BoxinGenerated',
            ],
        ];
    }
}
