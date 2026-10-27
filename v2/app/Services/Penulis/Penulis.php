<?php

namespace App\Services\Penulis;

use Illuminate\Support\Facades\File;

/**
 * PENULIS — dari dua nama petinju jadi cerita tinju per bab dalam bahasa
 * asal karakter, plus terjemahan Inggris dan Indonesia.
 *
 * Langkahnya (masing-masing satu atau beberapa panggilan Venice):
 *   1. riset     siapa mereka, umur canon, rupa, cara bicara, bahasa asal
 *   2. putusan   PagarUmur — di kode, sebelum apa pun ditulis
 *   3. alur      rencana laga JSON: format, kostum, sudut, bab, ending
 *   4. cerita    satu panggilan per bab, bahasa asal (bawaan Jepang)
 *   5. terjemah  tiap bab ke en dan id (dilewati kalau bahasa asalnya sama)
 *
 * Semua hasil masuk ke "<antrian>\<A> vs <B>\CERITA\" dan ditulis begitu
 * langkahnya selesai, jadi kalau langkah berikutnya gagal, yang sudah dibayar
 * tetap tersimpan. Isi CERITA yang lama dipindah ke CERITA\_lama\<waktu>\.
 *
 * Pack video (tester, klip, md UNCENSORED) dibuat langkah terpisah dari
 * alur.json yang sama.
 */
final class Penulis
{
    public const BAHASA = ['ja' => 'Japanese', 'ko' => 'Korean', 'zh' => 'Simplified Chinese', 'en' => 'English', 'id' => 'Indonesian'];

    /** Target panjang per bab, dalam kata bahasa Inggris. */
    public const PANJANG = ['pendek' => 500, 'sedang' => 1000, 'panjang' => 1800];

    public const LANGKAH = ['riset', 'alur', 'cerita', 'terjemah', 'pack'];

    public const MAKS_TEKS = 300;

    public const MAKS_IDE = 1200;

    /** @var callable(string):void|null */
    private $lapor = null;

    /** @var list<string> hal yang tidak menghentikan kerja tapi perlu diketahui pengguna */
    private array $peringatan = [];

    public function __construct(private Venice $venice)
    {
    }

    /**
     * @param array{a:array{nama:string,dari?:string,catatan?:string}, b:array{nama:string,dari?:string,catatan?:string},
     *              jenis?:string, isi?:string|null, bab?:string|int, bahasa?:string, venue?:string, ide?:string,
     *              panjang?:string, sampai?:string} $p
     * @param callable(string):void|null $lapor dipanggil tiap langkah mulai (untuk perintah/halaman)
     * @return array<string,mixed>
     */
    public function tulis(array $p, ?callable $lapor = null): array
    {
        $this->lapor = $lapor;
        $p = self::rapikan($p);

        PagarUmur::periksaIsian(
            $p['a']['nama'], $p['a']['dari'], $p['a']['catatan'],
            $p['b']['nama'], $p['b']['dari'], $p['b']['catatan'],
            $p['venue'], $p['ide'],
        );

        $this->kabar('Riset karakter dan umur canon');
        $riset = $this->riset($p);
        $putusan = PagarUmur::putuskan($riset['a'], $riset['b'], $p['jenis'], $p['isi']);
        $p['isi'] = $putusan['isi'];
        $bahasa = $this->bahasa($p['bahasa'], $riset);

        $folder = $this->siapkanFolder($p['a']['nama'], $p['b']['nama'], $p['folder']);
        $berkas = [];
        $berkas[] = $this->simpanJson($folder, 'riset.json', ['permintaan' => $p, 'putusan' => $putusan, 'bahasa' => $bahasa] + $riset);

        $hasil = fn (array $berkas) => [
            'folder'  => dirname($folder),
            'cerita'  => $folder,
            'berkas'  => $berkas,
            'putusan' => $putusan,
            'bahasa'  => $bahasa,
            'peringatan' => $this->peringatan,
            'usd'     => round($this->venice->totalUsd(), 4),
            'panggil' => $this->venice->catatan(),
        ];

        if ($p['sampai'] === 'riset') {
            $berkas[] = $this->simpanLog($folder, $p, $putusan, $bahasa);

            return $hasil($berkas);
        }

        $this->kabar('Menyusun alur laga');
        $alur = $this->alur($p, $riset, $bahasa);
        $berkas[] = $this->simpanJson($folder, 'alur.json', $alur);

        if ($p['sampai'] === 'alur') {
            $berkas[] = $this->simpanLog($folder, $p, $putusan, $bahasa);

            return $hasil($berkas);
        }

        $bagian = self::bagian($alur);
        $kepala = self::kepala($alur, $p, $riset);
        $bab = [];
        foreach ($bagian as $i => $b) {
            $this->kabar(sprintf('Menulis bab %d/%d (%s, %s)', $i + 1, count($bagian), $b['label'], $bahasa));
            $bab[] = $this->bab($p, $riset, $alur, $bahasa, $bagian, $i, $bab === [] ? '' : end($bab));
            $berkas['asli'] = $this->simpanMd($folder, "cerita-{$bahasa}.md", $kepala, $bab);
        }
        $berkas = array_values($berkas);

        if ($p['sampai'] === 'cerita') {
            $berkas[] = $this->simpanLog($folder, $p, $putusan, $bahasa);

            return $hasil($berkas);
        }

        foreach (array_diff(['en', 'id'], [$bahasa]) as $ke) {
            $terjemahan = [];
            foreach ($bab as $i => $teks) {
                $this->kabar(sprintf('Menerjemahkan bab %d/%d ke %s', $i + 1, count($bab), $ke));
                $sumber = $i === 0 ? $kepala . "\n\n" . $teks : $teks;
                $terjemahan[] = $this->terjemah($sumber, $bahasa, $ke);
            }
            $berkas[] = $this->simpanMd($folder, "cerita-{$ke}.md", '', $terjemahan);
        }

        // Log ditulis sebelum pack: kalau pack gagal, cerita yang sudah
        // dibayar tetap tercatat lengkap.
        $berkas[] = $this->simpanLog($folder, $p, $putusan, $bahasa);
        if ($p['sampai'] !== 'pack') {
            return $hasil($berkas);
        }

        $pack = (new PackVideo($this->venice))->buat(dirname($folder), $this->lapor);
        $this->simpanLog($folder, $p, $putusan, $bahasa);

        return $hasil($berkas) + ['pack' => $pack];
    }

    /** @return array{a:array<string,mixed>, b:array<string,mixed>} */
    public function riset(array $p): array
    {
        $isian = fn (string $k) => "- Fighter " . strtoupper($k) . ": \"{$p[$k]['nama']}\""
            . ($p[$k]['dari'] !== '' ? " from {$p[$k]['dari']}" : '')
            . ($p[$k]['catatan'] !== '' ? ". User notes: {$p[$k]['catatan']}" : '');

        $sistem = 'You are a meticulous character researcher for anime, manga, manhwa, manhua, light novels, games and western comics. '
            . 'Answer from official canon only. Return JSON.';

        $user = "Research these two characters for a boxing story:\n" . $isian('a') . "\n" . $isian('b') . "\n\n"
            . "Rules:\n"
            . "1. Identify the character: official full name, franchise, medium. If it is not a known character, it is an original character (OC): medium \"oc\", franchise \"\".\n"
            . "2. AGE. umur_angka = canon age (integer) of the version used, or null if canon gives no age. dewasa = true ONLY if canon clearly establishes 18 or older "
            . "(stated age, university student, working adult, adult soldier, ancient being with an adult body, etc.). Elementary/middle/high school students, children, "
            . "teenagers, or a child-like design = false. When unsure = false. If the character has an OFFICIAL later version (time skip, sequel, epilogue) in which they are "
            . "an adult, you may use that version — name it in \"versi\" and give that version's age. Never invent a time skip and never \"age up\" a minor. "
            . "An OC is adult unless the user's notes say child, teen or student; write umur_canon \"OC, adult\".\n"
            . "3. umur_canon: short description of the age of the version used ONLY (e.g. \"adult, 24\", \"time skip, ~19\", \"high school 2nd year, 17\"). "
            . "bukti_umur: where canon states it. versi_lain: other canon versions and their ages.\n"
            . "4. tinggi_cm: canon height, or a sensible estimate; null if unknown.\n"
            . "5. rupa: appearance for prose — hair, eyes, build, distinctive features, no clothing. kepribadian: personality. "
            . "cara_bicara: speech style in their original language (first-person pronoun, how they address others, verbal tics, tone). "
            . "gaya_tinju: a boxing style that suits them. cornerman: canon characters who could stand in their corner.\n"
            . "6. bahasa_asli: original language of the source — ja (anime, manga, light novel, Japanese games), ko (manhwa), zh (manhua), en (western); ja for an OC.\n"
            . "7. jenis_kelamin: \"wanita\" or \"pria\". tokoh_nyata: true only if this is a real person.";

        $petinju = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'nama_resmi' => ['type' => 'string'], 'franchise' => ['type' => 'string'],
                'medium' => ['type' => 'string', 'enum' => ['anime', 'manga', 'manhwa', 'manhua', 'light_novel', 'game', 'western', 'oc', 'real_person', 'other']],
                'jenis_kelamin' => ['type' => 'string', 'enum' => ['wanita', 'pria']],
                'versi' => ['type' => 'string'], 'umur_canon' => ['type' => 'string'], 'umur_angka' => ['type' => ['integer', 'null']],
                'dewasa' => ['type' => 'boolean'], 'bukti_umur' => ['type' => 'string'], 'versi_lain' => ['type' => 'string'],
                'tinggi_cm' => ['type' => ['integer', 'null']], 'rupa' => ['type' => 'string'], 'kepribadian' => ['type' => 'string'],
                'cara_bicara' => ['type' => 'string'], 'gaya_tinju' => ['type' => 'string'], 'cornerman' => ['type' => 'string'],
                'bahasa_asli' => ['type' => 'string', 'enum' => ['ja', 'ko', 'zh', 'en', 'id', 'other']],
                'tokoh_nyata' => ['type' => 'boolean'],
            ],
        ];
        $petinju['required'] = array_keys($petinju['properties']);
        $skema = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['a' => $petinju, 'b' => $petinju], 'required' => ['a', 'b']];

        $j = $this->venice->json($sistem, $user, $skema, [
            'langkah' => 'riset', 'nama_skema' => 'riset_petinju', 'nalar' => config('penulis.nalar.riset', 'low'), 'suhu' => 0.3, 'maks' => 16000,
        ]);

        foreach (['a', 'b'] as $k) {
            if (! is_array($j[$k] ?? null)) {
                throw new PenulisGalat("Riset tidak memuat petinju {$k}.");
            }
            $j[$k]['nama'] = $p[$k]['nama'];
            $j[$k]['catatan_pengguna'] = $p[$k]['catatan'];
        }

        return ['a' => $j['a'], 'b' => $j['b']];
    }

    /** @return array<string,mixed> */
    public function alur(array $p, array $riset, string $bahasa): array
    {
        $jenis = JenisLaga::JENIS[$p['jenis']];
        $bible = $this->bible((array) config('penulis.bible_alur', []));
        if ($p['isi'] === 'olahraga') {
            $bible .= $this->bible((array) config('penulis.bible_olahraga', []));
        }

        $sistem = 'You are a fight choreographer and story planner for a boxing fiction studio. You plan a boxing match as chapters of a novel '
            . "that will later be adapted into video. Return JSON.\n\n"
            . "MATCH TYPE — {$jenis['label']}: {$jenis['aturan']}\n\n"
            . 'CONTENT LEVEL: ' . JenisLaga::ISI[$p['isi']]['aturan']
            . ($bible !== '' ? "\n\nSTUDIO REFERENCE (Indonesian) — apply its dramaturgy principles, condition ladder K0–K3 and arcs:\n\n" . $bible : '');

        $jumlah = $p['bab'] === 'auto'
            ? 'Choose the number of fight chapters yourself (2 to 6). A chapter may cover one round or a group of rounds (label it "Rounds 4–6").'
            : "The bab array must contain EXACTLY {$p['bab']} item(s): tell the whole fight in {$p['bab']} chapter(s), grouping rounds as needed (label it \"Rounds 1–4\").";

        $user = "FIGHTERS:\n" . self::ringkasPetinju($riset) . "\n\n"
            . ($p['venue'] !== '' ? "Venue wish: {$p['venue']}\n" : '')
            . ($p['ide'] !== '' ? "Story idea from the user: {$p['ide']}\n" : '')
            . "\nPlan the match:\n"
            . "- {$jumlah} Plus an opening (pembuka) and an epilogue (epilog).\n"
            . "- Costumes must fit the match type; follow the user's costume notes when they fit.\n"
            . "- Corners and referee: canon characters use cornermen from THEIR OWN series only; original characters (OC) get invented, original cornermen — never borrow characters from other franchises.\n"
            . "- Supporting characters must exist at this point of canon: nobody who is dead (or not yet introduced) at the story's time.\n"
            . ($p['isi'] !== 'olahraga'
                ? "- AGE RULE: this story is " . ($p['isi'] === 'tanpa_sensor' ? 'uncensored' : 'fan service') . ", so EVERY named supporting character (cornermen, referee, announcer, named spectators) must be a canon adult, 18 or older. Never use a character who is a minor in canon, not even in a small role.\n"
                : '')
            . "- tokoh: list every named character other than the two boxers who appears, with peran (role), umur_canon (age description of the version used), umur_angka (integer or null) and dewasa (true only if canon clearly establishes 18+).\n"
            . "- Write every plan field in English, except dialog and judul_asli.\n"
            . "- Conditions escalate across chapters (bruises, swelling, fatigue, sweat) following the K0–K3 ladder.\n"
            . "- dialog: 2 to 4 key lines per chapter in " . self::BAHASA[$bahasa] . ", in each character's canon speech style, as \"Name: line\".\n"
            . "- judul: catchy English title; judul_asli: the same title in " . self::BAHASA[$bahasa] . '.';

        $adegan = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => ['ringkasan' => ['type' => 'string'], 'momen' => ['type' => 'array', 'items' => ['type' => 'string']]],
            'required' => ['ringkasan', 'momen'],
        ];
        $bab = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'label' => ['type' => 'string'], 'ringkasan' => ['type' => 'string'], 'momen' => ['type' => 'array', 'items' => ['type' => 'string']],
                'kondisi_a' => ['type' => 'string'], 'kondisi_b' => ['type' => 'string'], 'dialog' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['label', 'ringkasan', 'momen', 'kondisi_a', 'kondisi_b', 'dialog'],
        ];
        $skema = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'judul' => ['type' => 'string'], 'judul_asli' => ['type' => 'string'], 'venue' => ['type' => 'string'], 'latar' => ['type' => 'string'],
                'format' => ['type' => 'string'], 'aturan_laga' => ['type' => 'string'], 'kostum_a' => ['type' => 'string'], 'kostum_b' => ['type' => 'string'],
                'sudut_a' => ['type' => 'string'], 'sudut_b' => ['type' => 'string'], 'wasit' => ['type' => 'string'], 'arc' => ['type' => 'string'],
                'pembuka' => $adegan, 'bab' => ['type' => 'array', 'items' => $bab], 'hasil' => ['type' => 'string'], 'epilog' => $adegan,
                'tokoh' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'nama' => ['type' => 'string'], 'peran' => ['type' => 'string'], 'umur_canon' => ['type' => 'string'],
                        'umur_angka' => ['type' => ['integer', 'null']], 'dewasa' => ['type' => 'boolean'],
                    ],
                    'required' => ['nama', 'peran', 'umur_canon', 'umur_angka', 'dewasa'],
                ]],
            ],
        ];
        $skema['required'] = array_keys($skema['properties']);

        $opsi = ['langkah' => 'alur', 'nama_skema' => 'alur_laga', 'nalar' => config('penulis.nalar.alur', 'low'), 'suhu' => 0.9, 'maks' => 24000];
        $j = $this->venice->json($sistem, $user, $skema, $opsi);

        // Jumlah bab yang meleset: minta sekali lagi, lalu terima dengan
        // peringatan — alur yang sudah dibayar lebih berharga daripada angka.
        if (is_int($p['bab']) && is_array($j['bab'] ?? null) && count($j['bab']) !== $p['bab']) {
            $ulang = $this->venice->json($sistem, $user . sprintf(
                "\n\nIMPORTANT: your previous plan had %d chapters in bab. The bab array MUST have exactly %d item(s).", count($j['bab']), $p['bab'],
            ), $skema, $opsi);
            if (is_array($ulang['bab'] ?? null) && $ulang['bab'] !== []) {
                $j = $ulang;
            }
            if (count($j['bab']) !== $p['bab']) {
                $this->peringatan[] = sprintf('Alur memuat %d bab laga, diminta %d; dipakai apa adanya.', count($j['bab']), $p['bab']);
            }
        }

        // Tokoh pendukung di cerita fan service / tanpa sensor wajib dewasa
        // canon — diperiksa di kode dengan pagar yang sama dengan petinju.
        // Yang tidak lolos: alur diminta ulang sekali dengan nama-nama itu
        // diganti; kalau masih ada, gagal tanpa menulis cerita.
        if ($p['isi'] !== 'olahraga') {
            $muda = self::tokohMuda($j);
            if ($muda !== []) {
                $this->kabar('Mengganti tokoh pendukung yang belum dewasa: ' . implode(', ', $muda));
                $ulang = $this->venice->json($sistem, $user . "\n\nIMPORTANT: replace these supporting characters, they are not canon adults: "
                    . implode('; ', $muda) . '. Use canon adults (18+) from the same series instead, and list them in tokoh.', $skema, $opsi);
                if (is_array($ulang['bab'] ?? null) && $ulang['bab'] !== []) {
                    $j = $ulang;
                }
                $muda = self::tokohMuda($j);
                if ($muda !== []) {
                    throw new PenulisGalat('Alur masih memakai tokoh pendukung yang belum dewasa (' . implode('; ', $muda) . '); cerita tidak ditulis. Ulangi atau sebut cornerman dewasa di ide.');
                }
            }
        }

        if (! is_array($j['bab'] ?? null) || $j['bab'] === []) {
            throw new PenulisGalat('Alur tidak memuat satu bab pun.');
        }

        return $j;
    }

    /**
     * Tokoh pendukung yang tidak lolos pagar dewasa, sebagai "Nama (umur)".
     * Tanpa daftar tokoh sama sekali dianggap aman: cerita tanpa tokoh
     * pendukung bernama memang ada (laga privat berdua).
     *
     * @return list<string>
     */
    public static function tokohMuda(array $alur): array
    {
        $muda = [];
        foreach ((array) ($alur['tokoh'] ?? []) as $t) {
            if (! is_array($t)) {
                continue;
            }
            if (PagarUmur::tingkat($t + ['rupa' => '']) !== PagarUmur::DEWASA) {
                $muda[] = sprintf('%s (%s)', (string) ($t['nama'] ?? '?'), (string) ($t['umur_canon'] ?? '?'));
            }
        }

        return $muda;
    }

    /**
     * Satu bab cerita. Untuk isi non-olahraga, prosa diperiksa PagarUmur;
     * kalau ketemu penanda, bab ditulis ulang sekali dengan peringatan, dan
     * kalau masih ketemu, gagal tanpa menyimpan bab itu.
     *
     * @param list<array{label:string, ringkasan:string, momen:list<string>, kondisi_a?:string, kondisi_b?:string, dialog?:list<string>}> $bagian
     */
    public function bab(array $p, array $riset, array $alur, string $bahasa, array $bagian, int $i, string $sebelum): string
    {
        $nama = self::BAHASA[$bahasa];
        $kata = self::PANJANG[$p['panjang']];
        $panjang = in_array($bahasa, ['ja', 'zh'], true) ? "about " . ($kata * 2) . " {$nama} characters" : "about {$kata} words";
        $b = $bagian[$i];

        $sistem = "You are a novelist writing a boxing story in {$nama}, in the style of a published {$nama} light novel or web novel.\n\n"
            . 'MATCH TYPE — ' . JenisLaga::JENIS[$p['jenis']]['label'] . ': ' . JenisLaga::JENIS[$p['jenis']]['aturan'] . "\n\n"
            . 'CONTENT LEVEL: ' . JenisLaga::ISI[$p['isi']]['aturan'] . "\n\n"
            . "Writing rules:\n"
            . "- Third person, past tense, vivid and physical: footwork, specific punches and where they land, impact, breathing, sweat, the crowd, the corners.\n"
            . "- Each character speaks in their canon speech style." . ($bahasa === 'ja' ? ' Dialogue in 「」 brackets.' : '') . "\n"
            . "- Stay consistent with the plan: costumes, venue, conditions and injuries that accumulate.\n"
            . "- Output: first line is \"## \" plus the chapter title in {$nama}, then the prose. No notes, no summaries, no English unless the story language is English.";

        $user = "CHARACTERS:\n" . self::ringkasPetinju($riset) . "\n\n"
            . 'STORY PLAN (JSON):' . "\n" . json_encode($alur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
            . sprintf("Write chapter %d of %d: %s.\n", $i + 1, count($bagian), $b['label'])
            . 'Summary: ' . $b['ringkasan'] . "\n"
            . 'Beats: ' . implode(' | ', $b['momen'] ?? []) . "\n"
            . (! empty($b['kondisi_a']) ? "End condition A: {$b['kondisi_a']}\nEnd condition B: " . ($b['kondisi_b'] ?? '') . "\n" : '')
            . (! empty($b['dialog']) ? 'Key lines: ' . implode(' | ', $b['dialog']) . "\n" : '')
            . "Length: {$panjang}.\n"
            . ($sebelum !== '' ? "\nThe previous chapter ended with:\n«" . mb_substr($sebelum, -700) . "»\n" : '');

        $opsi = ['langkah' => 'cerita', 'nalar' => config('penulis.nalar.cerita', 'none'), 'suhu' => 0.85, 'maks' => $kata * 5 + 1500];
        $teks = $this->venice->chat($sistem, $user, $opsi);

        if ($p['isi'] !== 'olahraga' && ($temu = PagarUmur::periksaProsa($teks)) !== null) {
            $teks = $this->venice->chat($sistem, $user . "\nIMPORTANT: every character is an adult; do not mention ages under 18, school, or childhood.", $opsi);
            if (($temu = PagarUmur::periksaProsa($teks)) !== null) {
                throw new PenulisGalat("Bab {$b['label']} menyebut \"{$temu}\" dua kali — bab tidak disimpan. Ulangi atau ubah idenya.");
            }
        }

        return self::rapiBab($teks, $b['label']);
    }

    public function terjemah(string $teks, string $dari, string $ke): string
    {
        $kutip = $ke === 'ja' ? '「」' : '“ ”';
        $sistem = 'You are a literary translator. Translate the text from ' . self::BAHASA[$dari] . ' into ' . self::BAHASA[$ke] . ".\n"
            . "Keep everything: content, intensity, tone, explicitness, paragraphing, and every Markdown heading line (#, ##) and italic line, translated.\n"
            . "Dialogue in {$kutip} quotes. Keep character names in their usual romanization. Output only the translation.";

        $kata = max(300, (int) (mb_strlen($teks) / ($dari === 'ja' || $dari === 'zh' ? 1.5 : 4)));
        $hasil = $this->venice->chat($sistem, $teks, [
            'langkah' => "terjemah-{$ke}", 'nalar' => config('penulis.nalar.terjemah', 'none'), 'suhu' => 0.3, 'maks' => $kata * 3 + 1500,
        ]);

        return trim($hasil);
    }

    /**
     * Pembuka, bab laga, epilog — urutan bab cerita.
     *
     * @return list<array{label:string, ringkasan:string, momen:list<string>, kondisi_a?:string, kondisi_b?:string, dialog?:list<string>}>
     */
    public static function bagian(array $alur): array
    {
        $daftar = [['label' => 'Opening'] + (array) ($alur['pembuka'] ?? [])];
        foreach ((array) ($alur['bab'] ?? []) as $b) {
            $daftar[] = (array) $b;
        }
        $daftar[] = ['label' => 'Epilogue', 'ringkasan' => trim(($alur['hasil'] ?? '') . ' ' . ($alur['epilog']['ringkasan'] ?? '')), 'momen' => (array) ($alur['epilog']['momen'] ?? [])];

        return array_map(fn ($b) => $b + ['label' => '', 'ringkasan' => '', 'momen' => []], $daftar);
    }

    /** Normalisasi dan validasi permintaan. */
    public static function rapikan(array $p): array
    {
        $petinju = function ($x, string $k) {
            $x = is_array($x) ? $x : [];
            $nama = self::potong((string) ($x['nama'] ?? ''), self::MAKS_TEKS);
            if ($nama === '') {
                throw PenulisGalat::ditolak("Nama petinju {$k} kosong.");
            }

            return ['nama' => $nama, 'dari' => self::potong((string) ($x['dari'] ?? ''), self::MAKS_TEKS), 'catatan' => self::potong((string) ($x['catatan'] ?? ''), self::MAKS_TEKS)];
        };

        // Isian kosong dari formulir = nilai bawaan.
        $jenis = (string) (($p['jenis'] ?? '') ?: 'pro');
        if (! JenisLaga::ada($jenis)) {
            throw PenulisGalat::ditolak("Jenis laga \"{$jenis}\" tidak dikenal. Pilihan: " . implode(', ', array_keys(JenisLaga::JENIS)) . '.');
        }
        $isi = (string) ($p['isi'] ?? '');
        $isi = $isi === '' ? JenisLaga::ISI_BAWAAN[$jenis] : $isi;
        if (! JenisLaga::adaIsi($isi)) {
            throw PenulisGalat::ditolak("Isi \"{$isi}\" tidak dikenal. Pilihan: " . implode(', ', array_keys(JenisLaga::ISI)) . '.');
        }
        if ($jenis === 'topless' && $isi === 'olahraga') {
            $isi = 'fanservice';
        }

        $bab = ($p['bab'] ?? '') ?: 'auto';
        if ($bab !== 'auto') {
            if (! is_numeric($bab) || (int) $bab < 1 || (int) $bab > 8) {
                throw PenulisGalat::ditolak('Jumlah bab laga harus "auto" atau 1–8.');
            }
            $bab = (int) $bab;
        }

        $bahasa = (string) (($p['bahasa'] ?? '') ?: 'auto');
        if ($bahasa !== 'auto' && ! isset(self::BAHASA[$bahasa])) {
            throw PenulisGalat::ditolak("Bahasa \"{$bahasa}\" tidak dikenal. Pilihan: auto, " . implode(', ', array_keys(self::BAHASA)) . '.');
        }

        $panjang = (string) (($p['panjang'] ?? '') ?: 'sedang');
        if (! isset(self::PANJANG[$panjang])) {
            throw PenulisGalat::ditolak('Panjang harus pendek, sedang, atau panjang.');
        }

        $sampai = (string) (($p['sampai'] ?? '') ?: 'pack');
        if (! in_array($sampai, self::LANGKAH, true)) {
            throw PenulisGalat::ditolak('Langkah terakhir harus salah satu dari: ' . implode(', ', self::LANGKAH) . '.');
        }

        return [
            'a' => $petinju($p['a'] ?? null, 'A'), 'b' => $petinju($p['b'] ?? null, 'B'),
            'jenis' => $jenis, 'isi' => $isi, 'bab' => $bab, 'bahasa' => $bahasa, 'panjang' => $panjang, 'sampai' => $sampai,
            'venue' => self::potong((string) ($p['venue'] ?? ''), self::MAKS_TEKS),
            'ide' => self::potong((string) ($p['ide'] ?? ''), self::MAKS_IDE),
            // Nama folder sendiri, mis. versi SFW dan NSFW dari pasangan
            // yang sama supaya tidak saling timpa. Kosong = "<A> vs <B>".
            'folder' => self::bersihNama((string) ($p['folder'] ?? '')),
        ];
    }

    /** Nama folder pack: "<A> vs <B>", tanpa karakter yang dilarang Windows. */
    public static function namaFolder(string $a, string $b): string
    {
        return mb_substr(self::bersihNama($a) . ' vs ' . self::bersihNama($b), 0, 120);
    }

    private static function bersihNama(string $s): string
    {
        $s = trim((string) preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', ' ', $s), " .\t");

        return mb_substr((string) preg_replace('/\s+/u', ' ', $s), 0, 120);
    }

    private function bahasa(string $pilihan, array $riset): string
    {
        if ($pilihan !== 'auto') {
            return $pilihan;
        }
        $a = (string) ($riset['a']['bahasa_asli'] ?? '');
        $b = (string) ($riset['b']['bahasa_asli'] ?? '');

        return ($a === $b && isset(self::BAHASA[$a])) ? $a : 'ja';
    }

    private function siapkanFolder(string $a, string $b, string $nama = ''): string
    {
        // Folder yang sudah berisi pack buatan tangan/sesi lain tidak
        // disentuh: cerita dan pack baru masuk "<A> vs <B> (2)", "(3)", …
        $dasar = rtrim((string) config('produksi.antrian'), '/\\') . DIRECTORY_SEPARATOR . ($nama !== '' ? $nama : self::namaFolder($a, $b));
        $pack = $dasar;
        for ($n = 2; PackVideo::adaPackAsing($pack); $n++) {
            $pack = "{$dasar} ({$n})";
        }
        $cerita = $pack . DIRECTORY_SEPARATOR . (string) config('penulis.folder_cerita', 'CERITA');
        File::ensureDirectoryExists($cerita);

        $lama = File::files($cerita);
        if ($lama !== []) {
            $arsip = $cerita . DIRECTORY_SEPARATOR . '_lama' . DIRECTORY_SEPARATOR . date('Ymd-His');
            File::ensureDirectoryExists($arsip);
            foreach ($lama as $f) {
                File::move($f->getPathname(), $arsip . DIRECTORY_SEPARATOR . $f->getFilename());
            }
        }

        return $cerita;
    }

    private function bible(array $berkas): string
    {
        $akar = rtrim((string) config('penulis.fight_bible'), '/\\');
        $isi = '';
        foreach ($berkas as $nama) {
            $jalur = $akar . DIRECTORY_SEPARATOR . $nama;
            if (is_file($jalur)) {
                $isi .= "=== {$nama} ===\n" . trim((string) file_get_contents($jalur)) . "\n\n";
            }
        }

        return trim($isi);
    }

    private static function kepala(array $alur, array $p, array $riset): string
    {
        $judul = trim((string) ($alur['judul_asli'] ?? '')) ?: trim((string) ($alur['judul'] ?? '')) ?: self::namaFolder($p['a']['nama'], $p['b']['nama']);
        $baris = sprintf('_%s vs %s · %s · %s_',
            $riset['a']['nama_resmi'] ?? $p['a']['nama'], $riset['b']['nama_resmi'] ?? $p['b']['nama'],
            JenisLaga::JENIS[$p['jenis']]['label'], trim((string) ($alur['format'] ?? '')));

        return "# {$judul}\n\n{$baris}";
    }

    private static function ringkasPetinju(array $riset): string
    {
        $baris = [];
        foreach (['a' => 'A', 'b' => 'B'] as $k => $label) {
            $r = $riset[$k];
            $baris[] = sprintf(
                "%s — %s%s (%s; %s; %s). Looks: %s. Personality: %s. Speech: %s. Boxing style: %s. Possible corner: %s.%s",
                $label, $r['nama_resmi'] ?? $r['nama'], ! empty($r['franchise']) ? " from {$r['franchise']}" : '',
                $r['jenis_kelamin'] ?? '?', $r['umur_canon'] ?? '?', ! empty($r['tinggi_cm']) ? "{$r['tinggi_cm']} cm" : 'height unknown',
                $r['rupa'] ?? '', $r['kepribadian'] ?? '', $r['cara_bicara'] ?? '', $r['gaya_tinju'] ?? '', $r['cornerman'] ?? '',
                ! empty($r['catatan_pengguna']) ? " User notes: {$r['catatan_pengguna']}." : '',
            );
        }

        return implode("\n", $baris);
    }

    private static function rapiBab(string $teks, string $label): string
    {
        $teks = trim((string) preg_replace('/^```\w*\s*|\s*```$/', '', $teks));
        if (! str_starts_with($teks, '#')) {
            $teks = "## {$label}\n\n{$teks}";
        }

        return (string) preg_replace('/^#\s+/', '## ', $teks);
    }

    private function simpanJson(string $folder, string $nama, array $data): string
    {
        $jalur = $folder . DIRECTORY_SEPARATOR . $nama;
        File::put($jalur, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

        return $jalur;
    }

    /** @param list<string> $bab */
    private function simpanMd(string $folder, string $nama, string $kepala, array $bab): string
    {
        $jalur = $folder . DIRECTORY_SEPARATOR . $nama;
        File::put($jalur, trim(($kepala !== '' ? $kepala . "\n\n" : '') . implode("\n\n", $bab)) . "\n");

        return $jalur;
    }

    private function simpanLog(string $folder, array $p, array $putusan, string $bahasa): string
    {
        return $this->simpanJson($folder, 'log.json', [
            'waktu'   => date('c'),
            'model'   => (string) config('penulis.model'),
            'jenis'   => $p['jenis'],
            'isi'     => $p['isi'],
            'bahasa'  => $bahasa,
            'putusan' => $putusan,
            'peringatan' => $this->peringatan,
            'usd'     => round($this->venice->totalUsd(), 4),
            'panggil' => $this->venice->catatan(),
        ]);
    }

    private function kabar(string $pesan): void
    {
        if ($this->lapor !== null) {
            ($this->lapor)($pesan);
        }
    }

    private static function potong(string $s, int $maks): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $s)), 0, $maks);
    }
}
