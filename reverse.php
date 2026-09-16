<?php
declare(strict_types=1);

require_once __DIR__ . '/_page.php';   // sekaligus penjaga login

/**
 * Reverse prompt: unggah gambar atau video, biar dibaca dulu, lalu
 * disusun jadi prompt NovelAI V5 / Wan 3.0 / Seedance 2.5.
 *
 * Halaman ini sengaja tidak memuat app.js — semua logikanya ada di
 * assets/js/reverse.js yang berdiri sendiri. Tidak ada satu pun profil AI
 * yang dicek di sisi PHP; itu urusan api/reverse.php?action=status, supaya
 * halaman tetap terbuka walau mesinnya belum siap.
 */

$maksFrame = defined('REVERSE_MAX_FRAMES') ? max(4, (int)REVERSE_MAX_FRAMES) : 12;
$bawaanFrame = min(8, $maksFrame);

// Daftar gaya diambil dari modul yang sama dengan Prompt Generator, jadi
// apa pun yang kamu tambahkan lewat Admin langsung muncul di sini juga.
try {
    $gayaGambar = PromptBuilder::listModules('style', ALLOW_NSFW);
    $gayaVideo  = PromptBuilder::listModules('video_style', ALLOW_NSFW);
} catch (Throwable $e) {
    $gayaGambar = $gayaVideo = [];
}

/** <option> untuk daftar modul, dikelompokkan per kategori. */
$opsiGaya = static function (array $modul): string {
    $html = '';
    $kategoriTerakhir = null;

    foreach ($modul as $m) {
        $kategori = (string)($m['category'] ?? '');
        if ($kategori !== $kategoriTerakhir) {
            if ($kategoriTerakhir !== null) {
                $html .= '</optgroup>';
            }
            $html .= '<optgroup label="' . e(ucwords(str_replace(['-', '_'], ' ', $kategori ?: 'lainnya'))) . '">';
            $kategoriTerakhir = $kategori;
        }
        $label = (string)($m['name_id'] ?: $m['name']);
        $html .= '<option value="' . (int)$m['id'] . '"'
               . ' title="' . e((string)($m['description'] ?? '')) . '">'
               . e($label) . ((int)($m['is_nsfw'] ?? 0) === 1 ? ' &bull;' : '') . '</option>';
    }

    return $html . ($kategoriTerakhir !== null ? '</optgroup>' : '');
};

halamanHeader('Dari Gambar/Video', 'reverse.php');
?>

<p class="sub">
    Kebalikan dari Prompt Generator: kamu beri gambar atau video, halaman ini
    yang menebak isinya, lalu menyusunnya jadi prompt yang setia pada referensinya.
</p>

<div class="grid" data-maks-frame="<?= $maksFrame ?>">

    <!-- ============ KIRI ============ -->
    <section class="panel">
        <h2>1. Unggah referensi</h2>

        <!-- Diisi JS dari action=status. Sebelum jawabannya datang, tombol
             utama dimatikan — lebih baik menunggu sedetik daripada mengirim
             gambar ke profil yang belum punya kunci. -->
        <div class="ai-box" id="status-box">
            <p class="hint" id="status-note">Memeriksa profil AI…</p>
        </div>

        <div class="field">
            <div class="zona-unggah" id="zona-unggah">
                <p class="zona-teks">
                    <strong>Seret gambar atau video ke sini</strong>, atau pilih berkasnya.
                </p>
                <p class="hint">JPG, PNG, WebP &middot; MP4, WebM, MOV. Diproses di browser, yang terkirim cuma versi kecilnya.</p>
                <input type="file" id="berkas"
                       accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime,.jpg,.jpeg,.png,.webp,.mp4,.webm,.mov">
            </div>
            <p class="hint" id="berkas-info"></p>
        </div>

        <div class="field">
            <label for="url-ref">
                Atau tempel alamatnya
                <span class="tiny-note">gambar atau video</span>
            </label>
            <div class="preset-row">
                <input type="text" id="url-ref" autocomplete="off" maxlength="2000"
                       placeholder="https://... alamat gambar atau berkas video">
                <button type="button" id="btn-url" class="btn">Ambil</button>
            </div>
            <p class="hint">
                Untuk gambar dari internet, cara tercepat justru <strong>Ctrl+V</strong>: salin
                gambarnya di browser lalu tempel di halaman ini, tidak perlu disimpan dulu.
                Kalau yang tersalin cuma alamatnya, tempel di kotak ini.
                Untuk video, isi alamat berkasnya langsung (yang berakhiran
                <code>.mp4</code> atau <code>.webm</code>).
            </p>
        </div>

        <!-- khusus video: berapa frame yang diambil -->
        <div class="field only-ref-video" id="opsi-video" hidden>
            <label for="jumlah-frame">
                Frame yang diambil
                <span class="tiny-note"><span id="jumlah-frame-nilai"><?= $bawaanFrame ?></span> frame</span>
            </label>
            <input type="range" id="jumlah-frame" min="4" max="<?= $maksFrame ?>" step="1" value="<?= $bawaanFrame ?>">
            <p class="hint">
                Diambil merata sepanjang durasi, plus satu lembar kontak 4&times;2 supaya
                pembacanya melihat urutannya sekaligus. Makin banyak frame, makin
                teliti — dan makin lama dibaca.
            </p>
        </div>

        <div class="strip-frame" id="strip-frame"></div>

        <div class="field" style="margin-top:14px">
            <label>Target prompt</label>
            <div class="modebar modebar-target" id="target-bar">
                <button type="button" class="modebtn active" data-target="nai5">NovelAI V5</button>
                <button type="button" class="modebtn" data-target="wan">Video Wan 3.0</button>
                <button type="button" class="modebtn" data-target="seedance25">Video Seedance 2.5</button>
            </div>
            <p class="hint">
                Gambar boleh ke target mana pun. Video juga — kalau targetnya NovelAI,
                yang dipakai frame pertamanya.
            </p>
        </div>

        <!-- pengaturan kecil yang cuma berlaku untuk target video -->
        <div class="field-row only-target-video" hidden>
            <div class="field">
                <label for="video-rasio">Rasio</label>
                <select id="video-rasio">
                    <?php foreach (['16:9', '9:16', '4:3', '1:1', 'adaptive'] as $r): ?>
                        <option value="<?= e($r) ?>"><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="video-detik">Durasi</label>
                <select id="video-detik">
                    <?php foreach ([5, 6, 8, 10, 12, 15, 20, 25, 30] as $n): ?>
                        <option value="<?= $n ?>" <?= $n === 10 ? 'selected' : '' ?>><?= $n ?> detik</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field only-target-seed" hidden>
                <label for="video-resolusi">Resolusi</label>
                <select id="video-resolusi">
                    <?php foreach (Seedance25Builder::RESOLUSI as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $k === '720p' ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <hr class="sep">

        <!-- ============ GAYA VISUAL ============ -->
        <div class="field">
            <label for="gaya-gambar">Gaya visual <span class="tiny-note">opsional</span></label>

            <select id="gaya-gambar" class="only-target-gambar">
                <option value="">— ikut referensi —</option>
                <?= $opsiGaya($gayaGambar) ?>
            </select>

            <select id="gaya-video" class="only-target-video" hidden>
                <option value="">— ikut referensi —</option>
                <?= $opsiGaya($gayaVideo) ?>
            </select>

            <p class="hint">
                Biarkan kosong kalau mau meniru gaya referensinya. Pilih salah satu
                kalau kamu ingin wujudnya beda: gaya pilihanmu menggantikan gaya
                bacaan, tidak dicampur, supaya keduanya tidak saling berkelahi.
            </p>
        </div>

        <div class="field">
            <label for="artis">
                Tag artis
                <span class="tiny-note">opsional, pisahkan dengan koma</span>
            </label>
            <div class="tag-input-wrap">
                <input type="text" id="artis" autocomplete="off" maxlength="500"
                       placeholder="ketik nama artis, misal: dairi">
                <div class="suggest" id="artis-suggest"></div>
            </div>
            <p class="hint">
                Tuas paling ampuh untuk memberi watak: satu nama artis mengubah garis,
                warna, dan proporsi sekaligus. Hanya nama yang ada di kamus Danbooru yang
                dipakai — sisanya dibuang dan dilaporkan. Untuk video, tag ini masuk ke
                prompt lembar acuannya.
            </p>
        </div>

        <div class="field">
            <label for="gaya-kuat">Kekuatan gaya</label>
            <select id="gaya-kuat">
                <option value="ikut">Ikut apa adanya</option>
                <option value="sedang" selected>Sedang</option>
                <option value="kuat">Kuat</option>
            </select>
            <p class="hint">
                Seberapa keras gaya dan artis ditekankan dibanding tag isi. "Sedang"
                menulis <code>1.15::gaya::</code>, "Kuat" menulis <code>1.30::gaya::</code>.
            </p>
        </div>

        <hr class="sep">

        <div class="field">
            <label for="hint">Catatan untuk pembaca <span class="tiny-note">opsional, maks 400 huruf</span></label>
            <textarea id="hint" rows="2" maxlength="400"
                      placeholder="misal: yang kiri itu Usagi, yang kanan Rei; ini ronde terakhir"></textarea>
            <p class="hint">
                Dibaca oleh model vision bersama gambarnya. Berguna untuk menyebut nama
                karakter yang tidak dikenalinya sendiri.
            </p>
        </div>

        <label class="check">
            <input type="checkbox" id="opsi-nsfw" checked>
            Versi setia (NSFW)
        </label>
        <label class="check">
            <input type="checkbox" id="opsi-haluskan">
            Haluskan kata untuk penyaring
        </label>
        <label class="check">
            <input type="checkbox" id="opsi-polish" checked>
            Poles dengan model kuat
        </label>
        <label class="check">
            <input type="checkbox" id="opsi-fewshot" checked>
            Sertakan contoh emas
        </label>
        <label class="check">
            <input type="checkbox" id="opsi-dewasa" checked>
            Petinju dewasa (mature female/male)
        </label>
        <label class="check">
            <input type="checkbox" id="opsi-agedup">
            Versi dewasa dari karakter anak (aged up)
        </label>
        <p class="hint">
            <strong>Petinju dewasa</strong> memasang tag <code>mature_female</code> /
            <code>mature_male</code>. NovelAI cenderung menggambar wajah remaja kalau
            tidak diberi tahu, jadi biarkan menyala. Matikan hanya kalau wajahnya jadi
            terlalu tua.
            <br>
            <strong>Versi dewasa</strong> memasang <code>aged_up</code>, khusus untuk
            karakter yang aslinya memang anak-anak. Tanpa itu, tag karakternya sendiri
            akan menarik wujud aslinya kembali. Keduanya hanya berlaku untuk NovelAI —
            Wan dan Seedance tidak mengerti kosakata Danbooru.
        </p>
        <p class="hint" id="opsi-note"></p>

        <div class="actions">
            <button id="btn-baca" class="btn primary" type="button" disabled>Baca Referensi</button>
        </div>
        <p class="hint" id="baca-note"></p>

        <!-- ============ 2. HASIL PEMBACAAN ============ -->
        <div class="only-hasil-baca" id="hasil-baca" hidden>
            <hr class="sep">
            <h2>2. Hasil pembacaan</h2>

            <p class="ringkas" id="ringkas"></p>
            <div id="validasi-note"></div>

            <p class="hint" style="margin:0 0 12px">
                Semua kolom di bawah bisa kamu betulkan sebelum promptnya disusun —
                pembacanya sering salah menebak nama karakter dan siapa yang memukul.
            </p>

            <div class="field">
                <label for="adegan">Jenis adegan</label>
                <select id="adegan">
                    <option value="fight">Bertanding</option>
                    <option value="corner">Istirahat di sudut ring</option>
                    <option value="lineup">Foto bersama / berpose</option>
                    <option value="training">Latihan</option>
                    <option value="aftermath">Sesudah bertanding</option>
                    <option value="other">Lainnya</option>
                </select>
                <p class="hint">
                    Tidak semua gambar tinju itu pertandingan. Kalau adegannya istirahat
                    di sudut atau foto bersama, pilih di sini supaya kuda-kuda dan tag
                    pukulan tidak dipaksakan masuk.
                </p>
            </div>

            <div id="subjek-list"></div>

            <div class="field" id="striker-box">
                <label for="striker">Siapa yang memukul?</label>
                <select id="striker">
                    <option value="a">Boxer A memukul Boxer B</option>
                    <option value="b">Boxer B memukul Boxer A</option>
                    <option value="">Tidak ada yang memukul</option>
                </select>
                <p class="hint" id="interaksi-note"></p>
            </div>

            <div class="field">
                <label>Latar, cahaya, kamera</label>
                <p class="ringkas-subjek" id="adegan-teks"></p>
                <div class="chips" id="chip-adegan"></div>
            </div>

            <details class="advanced" id="raw-box">
                <summary>Advanced — JSON mentah hasil pembacaan</summary>
                <textarea id="raw-json" rows="14" spellcheck="false"></textarea>
                <p class="hint" id="raw-note">
                    Kalau kamu ubah teks di sini, <strong>ini yang dikirim</strong> —
                    kolom di atas diabaikan.
                </p>
            </details>

            <div class="actions">
                <button id="btn-susun" class="btn primary" type="button" disabled>Susun Prompt</button>
                <button id="btn-raw-reset" class="btn ghost" type="button" hidden>Batalkan suntingan JSON</button>
            </div>
            <p class="hint" id="susun-note"></p>
        </div>
    </section>

    <!-- ============ KANAN ============ -->
    <section class="panel">
        <h2>3. Prompt</h2>

        <div id="empty" class="empty">
            Belum ada prompt. Unggah referensi, tekan <strong>Baca Referensi</strong>,
            betulkan hasil bacaannya kalau perlu, lalu tekan <strong>Susun Prompt</strong>.
        </div>

        <div id="result" hidden>
            <div class="out-head" id="result-head">
                <span id="result-ringkasan"></span>
                <button class="btn tiny" id="btn-copy-all" type="button">Salin semua</button>
            </div>

            <div class="tabs" id="tabs">
                <button class="tab active" type="button" data-versi="sfw">Versi aman</button>
                <button class="tab" type="button" data-versi="nsfw" hidden>Versi setia</button>
            </div>

            <!-- diisi JS: kotak-kotak NovelAI, atau satu kotak prompt video -->
            <div id="out-list"></div>

            <!-- Wan / Seedance: gambar acuannya harus dibuat dulu -->
            <div id="acuan-block" hidden>
                <div class="out-head">
                    <span>Gambar acuan — buat dulu di NovelAI</span>
                </div>
                <div id="acuan-list"></div>
                <p class="hint">
                    Pakai set gambar acuan yang sama untuk semua percobaan, dan jangan
                    mengubah blok <code>Image 1 is …</code> walau satu kata.
                </p>
            </div>

            <div class="meta">
                <span id="token-count" class="pill"></span>
                <span id="token-warn" class="warn-text"></span>
            </div>

            <div id="notes"></div>

            <details class="why">
                <summary>Tahap yang dipakai</summary>
                <div id="why-body"></div>
            </details>
        </div>
    </section>

</div>

<script src="assets/js/gambar.js?v=2"></script>
<script src="assets/js/reverse.js?v=16"></script>
<?php halamanFooter(); ?>
