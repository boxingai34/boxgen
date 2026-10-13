<?php

namespace Tests\Unit;

use App\Models\User;
use Database;
use Exporter;
use LembarAcuan;
use PromptBuilder;
use Tests\TestCase;
use Throwable;

/**
 * Mode Reference Sheet di Prompt Generator (PromptBuilder 'sheet' + LembarAcuan).
 *
 * Yang dijaga terutama aturan studio untuk lembar acuan: murni desain
 * (kondisi tidak terbawa), tanpa hand wraps/mouthguard/jaket, dan
 * multiple_views tidak boleh ikut dilarang seperti di mode 1 Petinju.
 *
 * Butuh MySQL menyala — tag dan modulnya dibaca dari database.
 */
class LembarAcuanTest extends TestCase
{
    /** Tag yang pemilik studio larang muncul di UC mana pun. */
    private const DILARANG_DI_UC = [
        'nsfw', 'nudity', 'bottomless', 'wardrobe_malfunction', 'ahegao', 'tongue_out', 'panties',
        'pantyshot', 'upskirt', 'breast_focus', 'chest_focus', 'underboob', 'cleavage',
    ];

    public function test_kondisi_dibuang_dan_tata_letak_masuk(): void
    {
        $sel = $this->pilihan(['condition_id' => $this->modul('condition')]);
        $built = PromptBuilder::build($sel);

        $this->assertArrayNotHasKey('condition', $built['blocks'], 'Kondisi tidak boleh ikut ke lembar acuan.');
        $this->assertArrayHasKey('sheet', $built['blocks']);

        $nama = array_column($built['items'], 'name');
        $this->assertContains('reference_sheet', $nama);
        $this->assertContains('color_guide', $nama);
        $this->assertContains('grey_background', $nama);
        $this->assertContains('solo', $nama);

        $this->assertStringContainsString('Kondisi yang dipilih tidak dipakai', implode(' ', $built['catatan']));
    }

    public function test_undesired_content_lembar_acuan(): void
    {
        // Tanpa tema: tema pertama di katalog ("underground") memang
        // membawa bandages, dan yang diminta tidak dilarang.
        $neg = array_column(PromptBuilder::build($this->pilihan(['outfit_id' => null]))['negative_items'], 'name');

        foreach (['injury', 'sweat', 'bandages', 'hand_wraps', 'mouthguard', 'jacket', 'layered_clothing', 'monochrome', '2girls'] as $t) {
            $this->assertContains($t, $neg, "UC lembar acuan harus memuat {$t}.");
        }

        $this->assertNotContains('multiple_views', $neg, 'Lembar acuan justru butuh multiple views.');

        foreach (self::DILARANG_DI_UC as $t) {
            $this->assertNotContains($t, $neg, "{$t} tidak boleh ada di UC.");
        }
    }

    public function test_uc_tidak_melarang_yang_diminta(): void
    {
        $built = PromptBuilder::build($this->pilihan(['extra_tags' => ['scar']]));

        $this->assertContains('scar', array_column($built['items'], 'name'));
        $this->assertNotContains('scar', array_column($built['negative_items'], 'name'));
        $this->assertStringContainsString('justru diminta pilihanmu: scar', implode(' ', $built['catatan']));
    }

    public function test_kalimat_v5_tiap_tata_letak(): void
    {
        $harap = [
            'lengkap'    => ['character reference sheet', 'a back view', 'four head close-ups', 'color palette swatches'],
            'turnaround' => ['turnaround sheet', 'three-quarter view'],
            'ekspresi'   => ['expression sheet', 'six head close-ups'],
            'aksi'       => ['action sheet', 'throwing an uppercut', 'color palette swatches'],
        ];

        foreach ($harap as $letak => $potongan) {
            $sel = $this->pilihan(['sheet_layout' => $letak, 'sheet_background' => 'putih']);
            $out = Exporter::formatAll(PromptBuilder::build($sel), $sel);

            $this->assertSame([], $out['nai5']['structured']['characters'], 'Satu orang: tanpa kotak karakter.');
            $this->assertStringStartsWith('A professional character', $out['nai5']['prompt']);
            $this->assertStringContainsString('plain white background', $out['nai5']['prompt']);
            $this->assertStringContainsString('reference sheet', $out['nai5']['prompt'], 'Tag tata letak ikut di ekor V5.');
            foreach ($potongan as $p) {
                $this->assertStringContainsString($p, $out['nai5']['prompt'], "[{$letak}] kurang: {$p}");
            }
        }
    }

    public function test_warna_slot_jadi_palet(): void
    {
        $sel = $this->pilihan(['outfit_top_color' => 'red', 'outfit_hand_color' => 'blue']);

        $this->assertStringContainsString('color palette swatches: red and blue', LembarAcuan::kalimat($sel));
    }

    public function test_pilihan_tak_dikenal_jatuh_ke_bawaan(): void
    {
        $this->assertSame('lengkap', LembarAcuan::letak(['sheet_layout' => 'ngawur']));
        $this->assertSame('abu', LembarAcuan::latar(['sheet_background' => '<script>']));
    }

    public function test_halaman_dan_pintu_susun(): void
    {
        $this->actingAs($this->pemakai())
            ->get(route('prompt'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Prompt')->has('lembar.tataLetak.lengkap')->has('lembar.latar.abu'));

        $jawab = $this->actingAs($this->pemakai())->postJson(route('prompt.susun'), [
            'mode'         => 'sheet',
            'a'            => ['outfit_id' => $this->modul('outfit'), 'condition_id' => $this->modul('condition')],
            'sheet_layout' => 'turnaround',
            'quality_id'   => $this->modul('quality'),
        ]);

        try {
            $jawab->assertOk()->assertJsonPath('mode', 'sheet');
            $this->assertStringContainsString('turnaround sheet', $jawab->json('keluaran.nai5.prompt'));
        } finally {
            $this->hapusRiwayat($jawab->json('generation_id'));
        }
    }

    // ------------------------------------------------------------------

    private function pilihan(array $tambah = []): array
    {
        return $tambah + [
            'mode'            => 'sheet',
            'outfit_id'       => $this->modul('outfit'),
            'quality_id'      => $this->modul('quality'),
            'sheet_layout'    => 'lengkap',
            'sheet_background' => 'abu',
            'extra_tags'      => [],
            'trim_implied'    => true,
            'allow_nsfw'      => ALLOW_NSFW,
        ];
    }

    private function modul(string $tipe): int
    {
        return (int) Database::value(
            'SELECT id FROM modules WHERE type = ? AND is_active = 1 AND is_nsfw = 0 ORDER BY sort_order LIMIT 1',
            [$tipe]
        );
    }

    /** Tabel users lama tidak punya factory; pemakai cukup dibuat di memori. */
    private function pemakai(): User
    {
        $u = new User(['username' => 'penguji', 'full_name' => 'Penguji', 'email' => 'penguji@example.test', 'role' => 'user', 'status' => 'active']);
        $u->id = 1;

        return $u;
    }

    /** Baris riwayat buatan tes dihapus lagi. */
    private function hapusRiwayat(mixed $id): void
    {
        if (!is_int($id) || $id <= 0) {
            return;
        }
        try {
            Database::run("DELETE FROM generations WHERE id = ? AND mode = 'sheet'", [$id]);
        } catch (Throwable) {
            // Tidak bisa dihapus bukan alasan tesnya gagal.
        }
    }
}
