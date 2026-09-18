<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Akun sendiri: nama, email, dan kata sandi.
 *
 * Tabelnya milik aplikasi lama, jadi kolomnya yang diikuti — bukan
 * sebaliknya. Username sengaja tidak bisa diubah: dia dipakai sebagai
 * penanda di seluruh riwayat dan di halaman admin lama.
 */
class AkunController extends Controller
{
    public function edit(Request $request): Response
    {
        $u = $request->user();

        return Inertia::render('Akun', [
            'akun' => [
                'username'  => $u->username,
                'full_name' => $u->full_name,
                'email'     => $u->email,
                'role'      => $u->role,
                'status'    => $u->status,
                'sejak'     => $u->created_at?->toDateString(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $u = $request->user();

        $data = $request->validate([
            'full_name' => ['nullable', 'string', 'max:120'],
            'email'     => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($u->id)],
        ], [
            'email.unique' => 'Email itu sudah dipakai akun lain.',
        ]);

        DB::table('users')->where('id', $u->id)->update([
            'full_name' => $data['full_name'] ?? null,
            'email'     => isset($data['email']) ? Str::lower($data['email']) : null,
        ]);

        return back()->with('status', 'Profil tersimpan.');
    }

    public function sandi(Request $request): RedirectResponse
    {
        $u = $request->user();

        $data = $request->validate([
            'sandi_lama' => ['required', 'string'],
            'sandi'      => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'sandi.min'       => 'Kata sandi baru minimal 8 karakter.',
            'sandi.confirmed' => 'Ulangan kata sandinya belum sama.',
        ]);

        if (! Hash::check($data['sandi_lama'], $u->password_hash)) {
            return back()->withErrors(['sandi_lama' => 'Kata sandi lamanya salah.']);
        }

        DB::table('users')->where('id', $u->id)->update([
            'password_hash' => Hash::make($data['sandi']),
        ]);

        return back()->with('status', 'Kata sandi diganti.');
    }
}
