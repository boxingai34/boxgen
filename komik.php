<?php
declare(strict_types=1);

require_once __DIR__ . '/_page.php';   // sekaligus penjaga login

/**
 * Reverse prompt untuk HALAMAN KOMIK.
 *
 * Sengaja halaman sendiri, bukan target keempat di reverse.php. Yang dibaca
 * beda bentuknya sejak awal: panel, dialog, tata letak — bukan satu
 * komposisi dengan satu sudut kamera. Menumpangkannya di sana akan
 * memaksa mengubah kolom-kolom yang sudah ada, padahal itu yang paling
 * sering dipakai.
 *
 * Logikanya berdiri sendiri di assets/js/komik.js; halaman ini tidak
 * memuat app.js maupun reverse.js.
 */

$gayaGambar = PromptBuilder::listModules('style', ALLOW_NSFW);

halamanHeader('Dari Komik');
?>

<div class="grid-2">

    <section class="panel">
        <h2>Halaman komik</h2>
        <p class="hint">
            Unggah atau tempel satu halaman komik. Tiap panel dibaca sendiri —
            isinya, siapa di dalamnya, dan tulisannya — lalu keluar prompt untuk
            satu halaman utuh <em>dan</em> prompt terpisah tiap panel.
        </p>

        <div id="zona" class="dropzone" tabindex="0">
            <p><strong>Taruh gambar di sini</strong>, klik untuk memilih,
               atau tekan Ctrl+V untuk menempel.</p>
            <input type="file" id="berkas" accept="image/*" hidden>
        </div>
        <p class="hint" id="berkas-info"></p>

        <div id="pratinjau" class="strip" hidden></div>

        <div class="field">
            <label for="hint">Keterangan tambahan <span class="tiny-note">opsional</span></label>
            <textarea id="hint" rows="2" maxlength="400"
                      placeholder="misal: yang rambut merah namanya Kaede; dibaca kanan ke kiri"></textarea>
            <p class="hint">
                Dibaca bersama gambarnya. Berguna untuk menyebut nama karakter yang
                tidak dikenali sendiri oleh pembacanya.
            </p>
        </div>

        <div class="actions">
            <button id="btn-baca" class="btn primary" type="button" disabled>Baca Halaman</button>
        </div>
        <p class="hint" id="baca-note"></p>

        <div id="hasil-baca" hidden>
            <h3>Hasil pembacaan</h3>
            <p class="hint" id="ringkas"></p>

            <div class="field-row">
                <div class="field">
                    <label for="urutan">Urutan baca</label>
                    <select id="urutan">
                        <option value="left-to-right">Kiri ke kanan (gaya barat)</option>
                        <option value="right-to-left">Kanan ke kiri (gaya Jepang)</option>
                    </select>
                </div>
                <div class="field">
                    <label for="warna">Warna</label>
                    <select id="warna">
                        <option value="full_color">Berwarna penuh</option>
                        <option value="greyscale">Hitam putih</option>
                        <option value="monochrome">Monokrom</option>
                        <option value="partially_colored">Sebagian berwarna</option>
                    </select>
                </div>
            </div>

            <h3>Pemain</h3>
            <p class="hint">
                Orang yang muncul di lebih dari satu panel. Menjaga wujudnya tetap
                sama di semua panel — itu bagian yang paling sering meleset kalau
                tiap panel digambar sendiri-sendiri.
            </p>
            <div id="cast-list"></div>

            <h3>Panel</h3>
            <div id="panel-list"></div>
        </div>
    </section>

    <section class="panel">
        <h2>Prompt</h2>

        <div class="field">
            <label for="gaya">Gaya visual <span class="tiny-note">opsional</span></label>
            <select id="gaya">
                <option value="">— ikuti gaya referensinya —</option>
                <?php foreach ($gayaGambar as $g): ?>
                    <option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Dipakai sama rata di semua panel, supaya halamannya terasa digambar satu orang.</p>
        </div>

        <div class="field">
            <label for="artis">Tag artis <span class="tiny-note">opsional</span></label>
            <input type="text" id="artis" autocomplete="off" maxlength="500"
                   placeholder="ketik nama artis Danbooru">
        </div>

        <label class="check">
            <input type="checkbox" id="opsi-teks" checked>
            Salin tulisan ke prompt
        </label>
        <label class="check">
            <input type="checkbox" id="opsi-dewasa" checked>
            Semua tokoh dewasa (mature female/male)
        </label>
        <p class="hint">
            <strong>Salin tulisan</strong> memasukkan dialog dan teks efek ke dalam
            prompt supaya NovelAI mencoba menggambarnya. V5 lumayan bisa menulis
            teks Latin pendek, walau tidak selalu rapi. Matikan kalau kamu mau
            menulis teksnya sendiri di editor gambar — balonnya tetap tergambar,
            cuma kosong.
        </p>

        <div class="actions">
            <button id="btn-susun" class="btn primary" type="button" disabled>Susun Prompt</button>
        </div>
        <p class="hint" id="susun-note"></p>

        <div id="keluaran" hidden>
            <div class="tabs" id="tabs">
                <button class="tab aktif" data-tab="halaman" type="button">Satu halaman</button>
                <button class="tab" data-tab="panel" type="button">Per panel</button>
            </div>
            <div id="isi-halaman"></div>
            <div id="isi-panel" hidden></div>
        </div>
    </section>

</div>

<script src="assets/js/gambar.js?v=1"></script>
<script src="assets/js/komik.js?v=3"></script>
<?php halamanFooter(); ?>
