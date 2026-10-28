<?php

namespace App\Services\Penulis;

/**
 * Jenis laga dan tingkat isi cerita.
 *
 * Teks 'aturan' masuk ke prompt alur dan cerita apa adanya (bahasa Inggris,
 * karena modelnya paling patuh di sana). 'video' = jenis yang dipakai pack
 * video: topless dilarang Higgsfield, jadi pack-nya memakai versi bikini
 * dengan alur yang sama.
 */
final class JenisLaga
{
    public const JENIS = [
        'pro' => [
            'label'  => 'Pro fight',
            'video'  => 'pro',
            'aturan' => 'Sanctioned professional bout in a proper boxing ring with ropes and corner posts. A referee is in the ring, '
                . 'three judges at ringside, a crowd, standard boxing gloves and athletic boxing attire (sports top and trunks). '
                . 'The fight opens with a walkout in robes and the referee\'s instructions; each corner has a canon cornerman/coach. '
                . 'YOU choose the round length (2 or 3 minutes) and vary the ending from fight to fight — knockout, referee stoppage, '
                . 'the corner throwing in the towel, unanimous or split decision, a draw, a late comeback, a knockdown that changes everything. '
                . 'Do not default to a knockout. '
                . 'Tone: exciting, sexy and sensual, but always sporty — real boxing first.',
        ],
        'underground' => [
            'label'  => 'Underground match',
            'video'  => 'underground',
            'aturan' => 'Unsanctioned underground fight: a basement, warehouse, or cage lit by harsh hanging lights, a rowdy betting crowd pressed against the ropes or fence. '
                . 'Gloves on, but the rules are loose and enforced by a sketchy organizer instead of a real referee; rounds may run long or be called by the house. '
                . 'Gritty, dangerous mood. Ends by knockout, surrender, or the house stopping it.',
        ],
        'bikini' => [
            'label'  => 'Bikini match',
            'video'  => 'bikini',
            'aturan' => 'Boxing match where both fighters wear bikinis with boxing gloves. Boxing rules apply (punches above the belt, rounds, a referee or host). '
                . 'Venue can be a beach ring, a poolside ring, or an arena event with a crowd. Ends by knockout or decision.',
        ],
        'topless' => [
            'label'  => 'Topless match',
            'video'  => 'bikini',
            'aturan' => 'Topless boxing match between consenting adult fighters: both fight bare-chested in trunks and boxing gloves. Boxing rules apply '
                . '(punches above the belt, rounds, a referee or host). Venue can be a private club, an underground ring, or an adult-only event. Ends by knockout or decision.',
        ],
        'private' => [
            'label'  => 'Private match',
            'video'  => 'private',
            'aturan' => 'Private one-on-one match with no referee and no audience: a closed gym, a rooftop, a mansion hall, or a quiet makeshift ring. '
                . 'Instead of a walkout, the story opens with both fighters preparing (gloving up, warming up, a tense exchange of words). '
                . 'Cornermen, if any, are canon characters from their own series. They agree the rules between themselves. Ends when one cannot continue or gives up.',
        ],
    ];

    /**
     * Format semua laga (pengguna 2026-10-27: "cukup 3 ronde, tapi 1 ronde
     * terdiri dari 2–3 klip; interval jangan terlalu banyak" → 2 jeda).
     * Menggantikan permintaan awal "variasikan jumlah ronde".
     */
    public const FORMAT = 'FORMAT (studio rule, every match type): the fight lasts exactly 3 rounds — it goes the distance or ends inside round 3, '
        . 'never earlier and never later. Each round is told in depth (2–3 video clips of action per round), with one rest between rounds 1–2 and one between rounds 2–3.';

    public const ISI = [
        'olahraga' => [
            'label'  => 'Olahraga (nonseksual)',
            'aturan' => 'NON-SEXUAL SPORTS MODE: describe technique, footwork, guard, faces, effort, and sportsmanship. Normal athletic clothing. '
                . 'No sexualized framing or description of bodies, no erotic language, no nudity, no fetish focus on pain or defeat. Injuries are non-graphic.',
        ],
        'fanservice' => [
            'label'  => 'Fan service sporty',
            'aturan' => 'SPORTY FAN-SERVICE MODE (adults only): confident, attractive adult athletes; sweat, body physics, tight athletic wear and lingering looks are allowed, '
                . 'in the tone of an ecchi sports anime. No explicit sexual acts.',
        ],
        'tanpa_sensor' => [
            'label'  => 'Tanpa sensor',
            'aturan' => 'UNCENSORED MODE (adults only): nudity, explicit sexual content between the adult fighters, and graphic violence are allowed when the story calls for it. '
                . 'Everyone involved is an adult.',
        ],
    ];

    /** Jenis yang hanya boleh untuk petinju dewasa. */
    public const KHUSUS_DEWASA = ['underground', 'bikini', 'topless', 'private'];

    /** Isi bawaan per jenis kalau pengguna tidak memilih (petinju dewasa). */
    public const ISI_BAWAAN = [
        'pro'         => 'fanservice',
        'underground' => 'fanservice',
        'bikini'      => 'fanservice',
        'topless'     => 'tanpa_sensor',
        'private'     => 'fanservice',
    ];

    /**
     * Perlu md video UNCENSORED terpisah (untuk Luno/Venice, bukan Higgsfield)?
     * Ya untuk topless dan untuk semua laga bertingkat tanpa sensor.
     */
    public static function perluUncensored(string $jenis, string $isi): bool
    {
        return $jenis === 'topless' || $isi === 'tanpa_sensor';
    }

    public static function ada(string $jenis): bool
    {
        return isset(self::JENIS[$jenis]);
    }

    public static function adaIsi(string $isi): bool
    {
        return isset(self::ISI[$isi]);
    }
}
