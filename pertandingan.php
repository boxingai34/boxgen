<?php
declare(strict_types=1);

require_once __DIR__ . '/_page.php';   // sekaligus penjaga login

/**
 * Rancang Pertandingan: dari tiga gambar acuan jadi daftar prompt klip.
 *
 * Halaman sendiri karena arahnya berlawanan dengan "Dari Gambar/Video".
 * Di sana kamu punya videonya lalu mencari promptnya; di sini belum ada
 * videonya sama sekali — yang ada cuma wujud dua petinju dan arenanya.
 *
 * Logikanya berdiri sendiri di assets/js/pertandingan.js.
 */

$gayaVideo = PromptBuilder::listModules('video_style', ALLOW_NSFW);

halamanHeader('Rancang Pertandingan');
?>

<div class="grid-2">

    <section class="panel">
        <div class="tabs" id="mode-tabs">
            <button class="tab aktif" data-mode="gambar" type="button">Dari gambar acuan</button>
            <button class="tab" data-mode="cerita" type="button">Dari cerita</button>
        </div>

        <div id="mode-cerita" hidden>
            <h2>Jalan cerita</h2>
            <p class="hint">
                Tidak perlu gambar apa pun. Tulis saja jalan ceritanya — siapa tokohnya, di mana,
                jam berapa, apa yang terjadi, siapa menang, dan berapa panjang videonya. Mesin yang
                menentukan siapa perlu gambar acuan apa dan di adegan mana.
            </p>
            <div class="field">
                <label for="cerita">Ceritanya</label>
                <textarea id="cerita" rows="14" maxlength="6000"
                    placeholder="Char: Loid vs Yor&#10;&#10;Pada malam itu Yor menunggu kepulangan Loid di ruang tamu. Jam menunjukkan pukul 1 malam...&#10;&#10;Yor menang, buat video berdurasi 3 menit 30 detik."></textarea>
                <p class="hint">
                    Sebutkan panjang videonya di dalam cerita ("buat video 3 menit 30 detik") dan jamnya
                    ("pukul 1 malam") — keduanya dipakai otomatis. Kalau tokohmu berganti pakaian di
                    tengah cerita, sebutkan; tiap wujud jadi satu gambar acuan sendiri.
                </p>
            </div>
            <div class="actions">
                <button id="btn-baca-cerita" class="btn primary" type="button">Baca Cerita</button>
            </div>
            <p class="hint" id="cerita-note"></p>
            <div id="hasil-cerita" hidden>
                <h3>Terbaca</h3>
                <p class="hint" id="cerita-ringkas"></p>
                <div id="adegan-list"></div>
            </div>
        </div>

        <div id="mode-gambar">
        <h2>Gambar acuan</h2>
        <p class="hint">
            Tiga gambar: dua petinjunya, dan arenanya. Wujud dasar saja —
            belum berkeringat, belum memar. Kerusakannya diatur belakangan.
        </p>

        <div class="field-row">
            <div class="field">
                <label>Petinju A</label>
                <div class="dropzone kecil" id="zona-a" tabindex="0">
                    <p>Taruh, klik, atau Ctrl+V</p>
                    <input type="file" class="berkas" data-slot="a" accept="image/*" hidden>
                </div>
                <div class="strip" id="pra-a" hidden></div>
            </div>
            <div class="field">
                <label>Petinju B</label>
                <div class="dropzone kecil" id="zona-b" tabindex="0">
                    <p>Taruh, klik, atau Ctrl+V</p>
                    <input type="file" class="berkas" data-slot="b" accept="image/*" hidden>
                </div>
                <div class="strip" id="pra-b" hidden></div>
            </div>
            <div class="field">
                <label>Arena <span class="tiny-note">opsional</span></label>
                <div class="dropzone kecil" id="zona-arena" tabindex="0">
                    <p>Taruh, klik, atau Ctrl+V</p>
                    <input type="file" class="berkas" data-slot="arena" accept="image/*" hidden>
                </div>
                <div class="strip" id="pra-arena" hidden></div>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label>Wasit <span class="tiny-note">opsional</span></label>
                <div class="dropzone kecil" id="zona-wasit" tabindex="0">
                    <p>Taruh, klik, atau Ctrl+V</p>
                    <input type="file" class="berkas" data-slot="wasit" accept="image/*" hidden>
                </div>
                <div class="strip" id="pra-wasit" hidden></div>
            </div>
            <div class="field">
                <label>Cornerman <span class="tiny-note">opsional</span></label>
                <div class="dropzone kecil" id="zona-cornerman" tabindex="0">
                    <p>Taruh, klik, atau Ctrl+V</p>
                    <input type="file" class="berkas" data-slot="cornerman" accept="image/*" hidden>
                </div>
                <div class="strip" id="pra-cornerman" hidden></div>
            </div>
        </div>

        <p class="hint">
            <strong>Urutan kotak ini = urutan Image di prompt.</strong> Lampirkan gambarnya ke
            Wan atau Seedance dengan urutan yang sama: Petinju A jadi Image 1, Petinju B jadi
            Image 2, arena jadi Image 3, lalu wasit dan cornerman. Kalau urutannya tertukar,
            model mengunci wujud orang ke foto yang salah.
        </p>
        <p class="hint" id="berkas-info">
            Ctrl+V menempel ke kotak yang terakhir kamu klik.
        </p>

        <div class="field">
            <label for="hint">Keterangan tambahan <span class="tiny-note">opsional</span></label>
            <textarea id="hint" rows="2" maxlength="400"
                      placeholder="misal: yang A namanya Reina, sarungnya merah; arena bawah tanah"></textarea>
        </div>

        <div class="actions">
            <button id="btn-baca" class="btn primary" type="button" disabled>Baca Gambar Acuan</button>
        </div>
        <p class="hint" id="baca-note"></p>

        <div id="hasil-baca" hidden>
            <h3>Terbaca</h3>
            <p class="hint" id="ringkas"></p>
            <div id="petinju-list"></div>
        </div>
        </div>
    </section>

    <section class="panel">
        <h2>Jalannya pertandingan</h2>

        <div id="blok-durasi">
        <div class="field-row">
            <div class="field">
                <label for="durasi">Panjang video</label>
                <select id="durasi">
                    <option value="15">15 detik</option>
                    <option value="30">30 detik</option>
                    <option value="60" selected>1 menit</option>
                    <option value="90">1,5 menit</option>
                    <option value="120">2 menit</option>
                    <option value="180">3 menit</option>
                    <option value="240">4 menit</option>
                </select>
            </div>
            <div class="field">
                <label for="perklip">Panjang tiap klip</label>
                <select id="perklip">
                    <?php for ($d = 1; $d <= Pertandingan::MAKS_DETIK_KLIP; $d++): ?>
                        <option value="<?= $d ?>"<?= $d === 10 ? ' selected' : '' ?>><?= $d ?> detik</option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        <p class="hint">
            Wan dan Seedance menghasilkan klip pendek, bukan satu film. Panjang video
            dibagi jadi beberapa prompt berurutan yang kamu hasilkan satu per satu lalu
            sambung sendiri di editor. Jumlahnya dihitung di bawah tombol Rancang.
            Makin pendek tiap klip, makin banyak potongan kamera per detiknya.
        </p>
        </div>

        <p class="hint" id="catatan-cerita" hidden>
            Panjang video diambil dari ceritamu, dan panjang tiap klip ditentukan mesin
            per adegan — pertukaran pukulan dipotong lebih pendek supaya kameranya sering
            berganti, adegan biasa dibiarkan panjang supaya bisa bernapas.
        </p>

        <div id="blok-hasil">
        <div class="field-row">
            <div class="field">
                <label for="pemenang">Siapa yang menang</label>
                <select id="pemenang">
                    <option value="a">Petinju A</option>
                    <option value="b">Petinju B</option>
                </select>
            </div>
            <div class="field">
                <label for="cara">Cara selesainya</label>
                <select id="cara">
                    <?php foreach (Pertandingan::CARA as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        </div>

        <div class="field-row">
            <div class="field">
                <label for="target">Target video</label>
                <select id="target">
                    <option value="wan">Wan 3.0</option>
                    <option value="seedance25">Seedance 2.5</option>
                </select>
            </div>
            <div class="field">
                <label for="rasio">Rasio</label>
                <select id="rasio">
                    <option value="16:9">16:9</option>
                    <option value="9:16">9:16</option>
                    <option value="1:1">1:1</option>
                    <option value="4:3">4:3</option>
                </select>
            </div>
            <div class="field">
                <label for="resolusi">Resolusi</label>
                <select id="resolusi">
                    <option value="480p">480p</option>
                    <option value="720p" selected>720p</option>
                    <option value="1080p">1080p</option>
                </select>
            </div>
        </div>

        <div id="blok-latar">
        <div class="field-row">
            <div class="field">
                <label for="latar">Latar</label>
                <select id="latar">
                    <?php foreach (Pertandingan::LATAR as $k => $v): ?>
                        <option value="<?= e($k) ?>"><?= e($v['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">Dipakai kalau kamu tidak memberi gambar arena.</p>
            </div>
            <div class="field">
                <label for="penonton">Penonton</label>
                <select id="penonton">
                    <?php foreach (Pertandingan::PENONTON as $k => $v): ?>
                        <option value="<?= e($k) ?>"><?= e($v['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <label class="check">
            <input type="checkbox" id="opsi-wasit" checked>
            Ada wasit di ring
        </label>
        <label class="check">
            <input type="checkbox" id="opsi-cornerman">
            Ada cornerman di sudut
        </label>
        <p class="hint">
            Dicentang tanpa gambar acuan berarti dipakai wujud bawaan yang tetap sama di
            semua klip. Kalau tidak dicentang, promptnya menyebut tegas bahwa mereka
            <strong>tidak ada</strong> — tanpa itu model video sering menambahkan wasit sendiri.
        </p>
        </div>

        <div class="field">
            <label for="gaya">Gaya visual <span class="tiny-note">opsional</span></label>
            <select id="gaya">
                <option value="">— ikuti gaya gambar acuannya —</option>
                <?php foreach ($gayaVideo as $g): ?>
                    <option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="blok-centang">
        <label class="check">
            <input type="checkbox" id="opsi-nsfw" checked>
            Versi setia (NSFW)
        </label>
        <label class="check">
            <input type="checkbox" id="opsi-dewasa" checked>
            Semua petinju dewasa
        </label>
        </div>

        <div class="actions">
            <button id="btn-rancang" class="btn primary" type="button" disabled>Rancang Pertandingan</button>
        </div>
        <p class="hint" id="rancang-note"></p>

        <div id="keluaran" hidden>
            <div class="tabs" id="tabs">
                <button class="tab aktif" data-tab="klip" type="button">Prompt klip</button>
                <button class="tab" data-tab="kartu" type="button">Kartu kondisi</button>
                <button class="tab" data-tab="latar" type="button">Latar</button>
                <button class="btn kecil alat-kanan" id="btn-salin-semua" type="button">Salin semua</button>
            </div>
            <div id="isi-klip"></div>
            <div id="isi-kartu" hidden></div>
            <div id="isi-latar" hidden></div>

            <!-- Hanya mode cerita: mode gambar berangkat dari foto yang tidak
                 bisa ikut disimpan, jadi rancangannya tidak bisa dibuka utuh. -->
            <div id="blok-simpan" hidden>
                <button class="btn" id="btn-simpan" type="button">Simpan rancangan</button>
                <p class="hint" id="simpan-note">
                    Yang disimpan ceritamu dan hasil pembacaannya, bukan promptnya —
                    membuka lagi berarti menyusun ulang, jadi rancangan lama ikut
                    membaik sendiri waktu mesinnya diperbaiki.
                </p>
            </div>
        </div>
    </section>

</div>

<script src="assets/js/gambar.js?v=2"></script>
<script src="assets/js/pertandingan.js?v=17"></script>
<?php halamanFooter(); ?>
