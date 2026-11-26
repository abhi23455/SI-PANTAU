<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['required', 'string', 'max:15', 'regex:/^\d+$/'],
            'address' => ['required', 'string', 'max:1000'],
            'institution' => ['required', 'string', 'max:255'],
            'major' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(['pemagang', 'mentor'])],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'phone' => $validated['phone'],
            'address' => $validated['address'],
            'institution' => $validated['role'] === 'mentor'
                ? 'BPS Kota Semarang'
                : $validated['institution'],
            'major' => $validated['major'] ?? null,
            'role' => $validated['role'],
            'status' => 'pending',
        ]);

        return response()->json($this->authenticatedPayload($user), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Email atau password tidak sesuai.',
            ], 422);
        }

        return response()->json($this->authenticatedPayload($user));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
            'profile' => $request->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    private function authenticatedPayload(User $user): array
    {
        return [
            'user' => $user,
            'profile' => $user,
            'access_token' => $user->createToken('sipantau-web')->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }
}
