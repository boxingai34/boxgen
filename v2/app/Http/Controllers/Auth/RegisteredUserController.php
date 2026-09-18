<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daftar akun baru.
 *
 * Statusnya "pending": admin yang menyetujui, sama seperti aplikasi lama.
 * Jadi sesudah mendaftar orangnya TIDAK langsung masuk — dan halaman
 * masuk mengatakannya dengan jelas, bukan membiarkan orang mencoba
 * berkali-kali dengan kata sandi yang sebenarnya sudah benar.
 */
class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-zA-Z0-9._-]+$/',
                           Rule::unique('users', 'username')],
            'full_name' => ['nullable', 'string', 'max:120'],
            'email'     => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')],
            'password'  => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'username.regex'  => 'Username hanya boleh huruf, angka, titik, garis bawah, dan strip.',
            'username.unique' => 'Username itu sudah dipakai.',
            'email.unique'    => 'Email itu sudah terdaftar.',
            'password.min'    => 'Kata sandi minimal 8 karakter.',
            'password.confirmed' => 'Ulangan kata sandinya belum sama.',
        ]);

        User::create([
            'username'      => $data['username'],
            'full_name'     => $data['full_name'] ?? null,
            'email'         => isset($data['email']) ? Str::lower($data['email']) : null,
            'password_hash' => Hash::make($data['password']),
            'role'          => 'user',
            'status'        => 'pending',
        ]);

        return to_route('login')->with('status',
            'Pendaftaranmu masuk. Tunggu admin menyetujui akunnya, lalu masuk dengan username dan kata sandi tadi.');
    }
}
