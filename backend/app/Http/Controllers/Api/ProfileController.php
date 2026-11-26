<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:15', 'regex:/^\d+$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'institution' => ['sometimes', 'nullable', 'string', 'max:255'],
            'major' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar_url' => ['sometimes', 'nullable', 'string', 'max:5000000'],
            'role' => ['sometimes', 'required', 'in:pemagang,mentor'],
            'status' => ['sometimes', 'required', 'in:pending'],
        ]);

        if (array_key_exists('full_name', $validated)) {
            $validated['name'] = $validated['full_name'];
            unset($validated['full_name']);
        }

        $user = $request->user();
        if (array_key_exists('status', $validated)) {
            abort_unless($user->status === 'rejected', 403, 'Hanya akun yang ditolak dapat mengajukan ulang.');
        }
        if (($validated['role'] ?? null) === 'mentor') {
            $validated['institution'] = 'BPS Kota Semarang';
            $validated['major'] = null;
        }
        $user->fill($validated);
        $user->save();

        return response()->json([
            'user' => $user->refresh(),
            'profile' => $user,
        ]);
    }
}
