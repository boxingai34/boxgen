<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Data pendukung untuk mengisi menu.
 *
 * GET /api/options.php?what=series&universe=game
 *     -> daftar judul dalam satu universe
 *
 * GET /api/options.php?what=outfit_defaults&id=12
 *     -> isi bawaan tiap slot untuk sebuah tema pakaian
 *        (dipakai untuk mengisi otomatis menu Advanced)
 *
 * GET /api/options.php?what=suggested_bg&character=chun-li
 *     -> latar yang disarankan untuk seri karakter itu
 */

$what = (string)($_GET['what'] ?? '');

switch ($what) {

    case 'series':
        $universe = (string)($_GET['universe'] ?? '');
        jsonOk([
            'results' => CharacterResolver::seriesList($universe !== '' ? $universe : null),
        ]);
        // no break — jsonOk berhenti sendiri

    case 'colors':
        // Seluruh peta warna dikirim sekali saja saat halaman dibuka,
        // supaya ganti-ganti slot tidak memanggil server terus-menerus.
        jsonOk([
            'palette' => Palette::COLORS,
            'map'     => Palette::map(),
            'bases'   => Database::all(
                "SELECT id, color_base FROM modules WHERE color_base IS NOT NULL"
            ),
        ]);

    case 'outfit_defaults':
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            jsonFail('Parameter id wajib diisi.');
        }
        jsonOk(['defaults' => PromptBuilder::outfitDefaults($id)]);

    case 'suggested_bg':
        $tag = (string)($_GET['character'] ?? '');
        if ($tag === '') {
            jsonOk(['results' => []]);
        }

        // Jangan panggil API Danbooru cuma untuk mengisi saran latar —
        // ini dipanggil tiap kali user ganti karakter.
        $char = CharacterResolver::ensure($tag, false);
        $ids  = $char !== null
            ? PromptBuilder::suggestedBackgrounds(
                $char['series_id'] !== null ? (int)$char['series_id'] : null
              )
            : [];

        jsonOk(['results' => array_map('intval', $ids)]);

    default:
        jsonFail('Parameter "what" tidak dikenal.');
}
