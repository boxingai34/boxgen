<?php

// Kelompok rute untuk @routes('publik'): halaman depan hanya perlu ini,
// jadi daftar rute privat generator tidak ikut terkirim ke pengunjung.
return [
    'groups' => [
        'publik' => ['home', 'login', 'register', 'dashboard'],
    ],
];
