<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserDirectoryController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->whereNot(fn ($query) => $query->where('name', 'DELETED_USER')->where('status', 'rejected'))
            ->latest()
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'avatar_url' => $user->avatar_url,
            ]);

        return response()->json($users);
    }
}
