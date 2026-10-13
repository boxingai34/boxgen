<?php

namespace Tests\Unit;

use Database;
use Exporter;
use PromptBuilder;
use Tests\TestCase;

/**
 * Keluaran ChatGPT di Prompt Generator (Exporter -> ChatGptPrompt).
 *
 * ChatGPT membaca prosa: tidak boleh ada bobot NovelAI, garis bawah tag,
 * atau tag jumlah orang; larangan ditulis di baris "Avoid".
 *
 * Butuh MySQL menyala — tag dan modulnya dibaca dari database.
 */
class ChatGptPromptTest extends TestCase
{
    public function test_ketiga_mode_jadi_prosa(): void
    {
        $kasus = [
            'single' => ['mode' => 'single', 'outfit_id' => $this->modul('outfit'), 'pose_id' => $this->modul('pose')],
            'duo'    => [
                'mode' => 'duo', 'interaction_id' => $this->modul('interaction'), 'attacker' => 'a',
                'a' => ['outfit_id' => $this->modul('outfit')], 'b' => ['gender' => 'female'],
            ],
            'sheet'  => ['mode' => 'sheet', 'outfit_id' => $this->modul('outfit'), 'sheet_layout' => 'lengkap'],
        ];

        foreach ($kasus as $mode => $sel) {
            $sel += ['quality_id' => $this->modul('quality'), 'extra_tags' => [], 'trim_implied' => true, 'allow_nsfw' => ALLOW_NSFW];
            $out = Exporter::formatAll(PromptBuilder::build($sel), $sel)['chatgpt'];
            $p = $out['prompt'];

            $this->assertSame('', $out['negative'], "[{$mode}] ChatGPT tidak punya kolom negative.");
            $this->assertStringStartsWith('Create a ', $p);
            $this->assertStringContainsString("\n\nAvoid: ", $p);
            $this->assertStringNotContainsString('::', $p, "[{$mode}] bobot NovelAI bocor.");
            $this->assertStringNotContainsString('_', $p, "[{$mode}] garis bawah tag bocor.");
            $this->assertDoesNotMatchRegularExpression('/\b(1girl|2girls|multiple girls|multiple views|solo|masterpiece)\b/', $p, "[{$mode}] tag jumlah/kualitas bocor.");
        }
    }

    public function test_bentuk_kanvas_dan_sebutan_orang(): void
    {
        $sheet = ['mode' => 'sheet', 'sheet_layout' => 'turnaround', 'extra_tags' => [], 'allow_nsfw' => ALLOW_NSFW];
        $p = Exporter::formatAll(PromptBuilder::build($sheet), $sheet)['chatgpt']['prompt'];
        $this->assertStringContainsString('landscape-orientation', $p);
        $this->assertStringContainsString('turnaround sheet', $p);
        $this->assertStringContainsString('hand wraps', $p, 'Aturan lembar acuan ikut ke baris Avoid.');

        $duo = ['mode' => 'duo', 'a' => [], 'b' => [], 'extra_tags' => [], 'allow_nsfw' => ALLOW_NSFW];
        $p = Exporter::formatAll(PromptBuilder::build($duo), $duo)['chatgpt']['prompt'];
        $this->assertStringContainsString('exactly two people', $p);
        $this->assertStringContainsString('The boxer on the left:', $p);
        $this->assertStringContainsString('The boxer on the right:', $p);
    }

    private function modul(string $tipe): int
    {
        return (int) Database::value(
            'SELECT id FROM modules WHERE type = ? AND is_active = 1 AND is_nsfw = 0 ORDER BY sort_order LIMIT 1',
            [$tipe]
        );
    }
}
