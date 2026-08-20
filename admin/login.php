<?php
declare(strict_types=1);

/**
 * Halaman ini tinggal pengalih.
 *
 * Login sekarang terpusat di ../login.php untuk semua orang — admin cuma
 * akun dengan role = 'admin'. Berkasnya dipertahankan supaya tautan lama
 * dan bookmark tidak jadi 404.
 */

require_once __DIR__ . '/../config.php';

header('Location: ../login.php?next=' . urlencode('/admin/'));
exit;
