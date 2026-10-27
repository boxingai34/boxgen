<?php

namespace Tests\Unit;

use AksesoriPetinju;
use Database;
use PromptBuilder;
use Tests\TestCase;

/**
 * Standar studio: petinju tanpa aksesori (perhiasan, kacamata, selempang,
 * hiasan rambut, topi, high heels) — AksesoriPetinju + jalur Prompt
 * Generator (PromptBuilder).
 *
 * Tes pengenal polanya murni; tes PromptBuilder butuh MySQL menyala.
 */
class AksesoriPetinjuTest extends TestCase
{
    public function test_pengenal_aksesori(): void
    {
        $buang = [
            'hoop_earrings', 'gold hoop earrings', 'pink sunglasses on head', 'eyewear_on_head', 'tinted_eyewear',
            'jade pendant choker', 'red sash tied at waist', 'red ribbon on upper arm', 'hair_ribbon',
            'x_hair_ornament', 'hairclip', 'black frilled headband', 'maid_headdress', 'black bowtie collar',
            'baseball_cap', 'witch_hat', 'cape', 'high_heels', 'long_fingernails', 'glasses',
        ];
        $tetap = [
            // perlengkapan dan pakaian yang memang dipakai
            'boxing_gloves', 'sports_bra', 'high-top boxing shoes', 'headband', 'hachimaki', 'hijab', 'headscarf',
            'elbow_pads', 'hair_tie', 'scrunchie',
            // ciri tubuh
            'facial_mark', 'mole_under_eye',
            // jebakan kata: ring tinju, gaya rambut, kata yang memuat potongan nama barang
            'boxing_ring', 'ring', 'crown_braid', 'kneecap', 'rainbow',
            // kalimat aksi yang memuat kata barang
            'a piercing glare', 'she bows her head', 'takes a bow', 'a ribbon of blood', 'stole a glance',
            'cap sleeves', 'a head scarf',
        ];

        foreach ($buang as $t) {
            $this->assertTrue(AksesoriPetinju::adalah($t), "{$t} harus dikenali sebagai aksesori.");
        }
        foreach ($tetap as $t) {
            $this->assertFalse(AksesoriPetinju::adalah($t), "{$t} bukan aksesori.");
        }
    }

    public function test_uc_tidak_melarang_yang_diminta_walau_berwarna(): void
    {
        $uc = AksesoriPetinju::uc(['red_high_heels', 'witch_hat']);

        $this->assertNotContains('high_heels', $uc);
        $this->assertNotContains('hat', $uc);
        $this->assertContains('earrings', $uc);
        $this->assertNotContains('ring', AksesoriPetinju::UC, 'Kata "ring" dibaca sebagai ring tinju.');
    }

    public function test_tag_karakter_aksesori_dibuang(): void
    {
        // Maki Zenin membawa "glasses" di tag penampilannya. Pilihan karakter
        // dikirim sebagai tag booru, bukan nama tampilan.
        $tag = (string) Database::value("SELECT booru_tag FROM characters WHERE name = 'Maki Zenin' LIMIT 1");
        $this->assertNotSame('', $tag);
        $built = PromptBuilder::build($this->pilihan(['character' => $tag]));
        $nama = array_column($built['items'], 'name');

        $this->assertContains('green_hair', $nama, 'Ciri fisik lain tetap ikut.');
        $this->assertNotContains('glasses', $nama);
        $this->assertContains('glasses', array_column($built['negative_items'], 'name'));
    }

    public function test_slot_aksesori_dibuang_dengan_catatan(): void
    {
        $kacamata = (int) Database::value("SELECT id FROM modules WHERE type = 'outfit_head' AND slug = 'sunglasses'");
        $built = PromptBuilder::build($this->pilihan(['outfit_head_id' => $kacamata]));

        $this->assertNotContains('sunglasses', array_column($built['items'], 'name'));
        $this->assertStringContainsString('aksesori tidak dipakai petinju', implode(' ', $built['catatan']));
    }

    public function test_menu_tidak_menawarkan_aksesori(): void
    {
        foreach (['outfit_hand', 'outfit_foot', 'outfit_head'] as $tipe) {
            $kategori = array_column(PromptBuilder::listModules($tipe, true), 'category');

            foreach (AksesoriPetinju::KATEGORI as $k) {
                $this->assertNotContains($k, $kategori, "Menu {$tipe} tidak boleh menawarkan {$k}.");
            }
        }

        $this->assertNotEmpty(PromptBuilder::listModules('outfit_head', true), 'Slot kepala tetap punya pilihan lain.');
    }

    public function test_uc_aksesori_di_ketiga_mode(): void
    {
        foreach (['single', 'duo', 'sheet'] as $mode) {
            $neg = array_column(PromptBuilder::build($this->pilihan(['mode' => $mode]))['negative_items'], 'name');

            foreach (['earrings', 'sunglasses', 'sash', 'hair_ribbon'] as $t) {
                $this->assertContains($t, $neg, "UC mode {$mode} harus memuat {$t}.");
            }
        }
    }

    private function pilihan(array $tambah = []): array
    {
        return $tambah + [
            'mode'         => 'single',
            'quality_id'   => (int) Database::value(
                "SELECT id FROM modules WHERE type = 'quality' AND is_active = 1 AND is_nsfw = 0 ORDER BY sort_order LIMIT 1"
            ),
            'extra_tags'   => [],
            'trim_implied' => true,
            'allow_nsfw'   => ALLOW_NSFW,
        ];
    }
}
