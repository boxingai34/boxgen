/**
 * Tema terang/gelap dan bahasa tampilan.
 *
 * Dimuat di <head> SEBELUM halaman digambar, bukan di akhir body. Kalau
 * dimuat belakangan, halaman sempat tampil gelap satu kedipan dulu
 * sebelum berubah terang — dan kedipan itu jauh lebih mengganggu
 * daripada kelihatannya.
 *
 * Pilihannya disimpan di localStorage, jadi bertahan antar halaman dan
 * antar kunjungan tanpa perlu menyimpannya di server.
 */
(function () {
    'use strict';

    var KUNCI_TEMA   = 'boxgen-tema';
    var KUNCI_BAHASA = 'boxgen-bahasa';

    /** Baca pilihan tersimpan; kalau belum ada, ikuti setelan sistem. */
    function temaTersimpan() {
        try {
            var t = localStorage.getItem(KUNCI_TEMA);
            if (t === 'light' || t === 'dark') { return t; }
        } catch (e) { /* mode penyamaran: abaikan */ }

        return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches
            ? 'light' : 'dark';
    }

    function bahasaTersimpan() {
        try {
            var b = localStorage.getItem(KUNCI_BAHASA);
            if (b === 'id' || b === 'en') { return b; }
        } catch (e) { /* abaikan */ }
        return 'id';
    }

    function pasangTema(t) {
        document.documentElement.setAttribute('data-theme', t);
        try { localStorage.setItem(KUNCI_TEMA, t); } catch (e) { /* abaikan */ }
    }

    function pasangBahasa(b) {
        document.documentElement.setAttribute('lang', b);
        document.documentElement.setAttribute('data-bahasa', b);
        try { localStorage.setItem(KUNCI_BAHASA, b); } catch (e) { /* abaikan */ }
        terjemahkan(b);
    }

    /**
     * Ganti teks yang punya terjemahan.
     *
     * Hanya menyentuh elemen yang memang diberi data-en: teks aslinya
     * bahasa Indonesia dan tetap di tempatnya. Cara ini dipilih supaya
     * tidak perlu membongkar seluruh halaman jadi kunci terjemahan —
     * yang belum diberi data-en tetap tampil dalam bahasa Indonesia,
     * bukan hilang atau jadi nama kunci.
     */
    function terjemahkan(b) {
        var n = document.querySelectorAll('[data-en]');
        for (var i = 0; i < n.length; i++) {
            var el = n[i];
            if (!el.hasAttribute('data-id')) {
                el.setAttribute('data-id', el.textContent);
            }
            el.textContent = b === 'en' ? el.getAttribute('data-en') : el.getAttribute('data-id');
        }

        var ph = document.querySelectorAll('[data-en-ph]');
        for (var j = 0; j < ph.length; j++) {
            var e2 = ph[j];
            if (!e2.hasAttribute('data-id-ph')) {
                e2.setAttribute('data-id-ph', e2.getAttribute('placeholder') || '');
            }
            e2.setAttribute('placeholder',
                b === 'en' ? e2.getAttribute('data-en-ph') : e2.getAttribute('data-id-ph'));
        }
    }

    // Dipasang SEKARANG, sebelum body digambar.
    document.documentElement.setAttribute('data-theme', temaTersimpan());

    document.addEventListener('DOMContentLoaded', function () {
        pasangBahasa(bahasaTersimpan());

        var tt = document.getElementById('sakelar-tema');
        var tb = document.getElementById('sakelar-bahasa');

        function labelTema() {
            var gelap = document.documentElement.getAttribute('data-theme') === 'dark';
            if (tt) { tt.textContent = gelap ? '☀ Terang' : '☾ Gelap'; }
        }
        function labelBahasa() {
            var b = document.documentElement.getAttribute('data-bahasa');
            if (tb) { tb.textContent = b === 'en' ? 'ID' : 'EN'; }
        }

        labelTema();
        labelBahasa();

        if (tt) {
            tt.addEventListener('click', function () {
                pasangTema(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
                labelTema();
            });
        }
        if (tb) {
            tb.addEventListener('click', function () {
                pasangBahasa(document.documentElement.getAttribute('data-bahasa') === 'en' ? 'id' : 'en');
                labelBahasa();
            });
        }
    });
})();
