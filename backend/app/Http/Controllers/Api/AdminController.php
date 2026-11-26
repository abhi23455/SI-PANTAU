<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Notifications\AccountStatusNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class AdminController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(
            User::query()
                ->whereNot(fn ($query) => $query->where('name', 'DELETED_USER')->where('status', 'rejected'))
                ->latest()
                ->get()
        );
    }

    public function storeUser(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:15'],
            'address' => ['nullable', 'string', 'max:1000'],
            'institution' => ['nullable', 'string', 'max:255'],
            'major' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(['pemagang', 'mentor'])],
        ]);
        if ($data['role'] === 'mentor') {
            $data['institution'] = 'BPS Kota Semarang';
        }

        $user = User::create($data + ['status' => 'pending']);
        $this->log($request, "menambahkan akun baru sebagai {$user->role}");

        return response()->json($user, 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:15'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'institution' => ['sometimes', 'nullable', 'string', 'max:255'],
            'major' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::in(['pending', 'active', 'rejected'])],
        ]);
        $oldStatus = $user->status;
        if (array_key_exists('full_name', $data)) {
            $data['name'] = $data['full_name'];
            unset($data['full_name']);
        }

        $user->update($data);
        if (
            isset($data['status'])
            && $data['status'] === 'active'
            && $oldStatus !== 'active'
            && in_array($user->role, ['pemagang', 'intern'], true)
        ) {
            $defaultGroup = Group::where('is_default', true)->firstOrFail();
            GroupMember::firstOrCreate([
                'group_id' => $defaultGroup->id,
                'user_id' => $user->id,
            ]);
        } elseif (
            isset($data['status'])
            && $data['status'] !== 'active'
            && in_array($user->role, ['pemagang', 'intern'], true)
        ) {
            GroupMember::where('user_id', $user->id)->delete();
        }
        if (isset($data['status'])) {
            $verb = $data['status'] === 'active' ? 'menyetujui' : 'menolak';
            $this->log($request, "telah {$verb} pendaftaran akun {$user->full_name}");
        }

        $user = $user->fresh();
        $response = $user->toArray();
        if (
            isset($data['status'])
            && $data['status'] !== $oldStatus
            && in_array($data['status'], ['active', 'rejected'], true)
        ) {
            try {
                $user->notify(new AccountStatusNotification($data['status']));
            } catch (Throwable $error) {
                report($error);
                $response['notification_error'] = 'Status tersimpan, tetapi email pemberitahuan gagal dikirim. Periksa konfigurasi email.';
            }
        }

        return response()->json($response);
    }

    public function deleteUsers(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);
        abort_if(in_array($request->user()->id, $data['user_ids'], true), 422, 'Admin yang sedang login tidak dapat menghapus akunnya sendiri.');

        User::whereIn('id', $data['user_ids'])->update([
            'name' => 'DELETED_USER',
            'status' => 'rejected',
        ]);
        GroupMember::whereIn('user_id', $data['user_ids'])->delete();

        return response()->json(['message' => 'Akun berhasil dihapus.']);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403);
    }

    private function log(Request $request, string $description): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'description' => $description,
        ]);
    }
}
