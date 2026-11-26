<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Group::query()
            ->where('is_deleted', false)
            ->with(['members.user', 'tasks:id,group_id,status']);

        if ($user->role === 'mentor') {
            $query->where(function ($groups) use ($user) {
                $groups->where('mentor_id', $user->id)->orWhere('is_default', true);
            });
        } elseif (in_array($user->role, ['pemagang', 'intern'], true)) {
            $query->whereHas('members', fn ($members) => $members->where('user_id', $user->id));
        } elseif ($user->role !== 'admin') {
            abort(403);
        }

        return response()->json($query->latest()->get()->map(
            fn (Group $group) => $this->serializeGroup($group, false)
        ));
    }

    public function show(Request $request, Group $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        $group->load([
            'members.user',
            'tasks.assignees',
            'tasks.subtasks',
            'tasks.comments.user',
            'tasks.history',
        ]);
        if (in_array($request->user()->role, ['pemagang', 'intern'], true)) {
            $group->setRelation('tasks', $group->tasks->filter(
                fn ($task) => $task->assigned_to === $request->user()->id
                    || $task->assignees->contains('user_id', $request->user()->id)
            )->values());
        }

        return response()->json($this->serializeGroup($group, true));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['admin', 'mentor'], true), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'mentor_id' => ['nullable', 'integer', 'exists:users,id'],
            'members' => ['sometimes', 'array'],
            'members.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $mentorId = $request->user()->role === 'mentor'
            ? $request->user()->id
            : ($data['mentor_id'] ?? null);

        if ($mentorId) {
            $mentor = User::findOrFail($mentorId);
            abort_unless($mentor->role === 'mentor', 422, 'Penanggung jawab harus memiliki role mentor.');
        }

        $group = DB::transaction(function () use ($data, $request, $mentorId) {
            $group = Group::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'mentor_id' => $mentorId,
                'created_by' => $request->user()->id,
            ]);

            $this->syncMembers($group, $data['members'] ?? []);

            return $group;
        });

        $this->log($request, "telah membuat kelompok magang: {$group->name}", null, $group->id);

        return response()->json($this->serializeGroup($group->load('members.user')), 201);
    }

    public function update(Request $request, Group $group): JsonResponse
    {
        $this->authorizeGroup($request, $group, true);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_deleted' => ['sometimes', 'boolean'],
        ]);
        abort_if($group->is_default && ($data['is_deleted'] ?? false), 422, 'Kelompok bersama tidak dapat dihapus.');

        $group->update($data);

        return response()->json($this->serializeGroup($group->fresh()->load('members.user')));
    }

    public function destroy(Request $request, Group $group): JsonResponse
    {
        $this->authorizeGroup($request, $group, true);
        abort_if($group->is_default, 422, 'Kelompok bersama tidak dapat dihapus.');
        $hardDelete = $request->boolean('hard_delete');
        abort_if($hardDelete && $request->user()->role !== 'admin', 403);

        if ($hardDelete) {
            $group->delete();
        } else {
            $group->update(['is_deleted' => true]);
        }

        return response()->json(['message' => 'Kelompok berhasil dihapus.']);
    }

    public function addMember(Request $request, Group $group): JsonResponse
    {
        $this->authorizeGroup($request, $group, true);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $member = User::findOrFail($data['user_id']);
        abort_unless(
            in_array($member->role, ['pemagang', 'intern'], true) && $member->status === 'active',
            422,
            'Anggota harus memiliki akun pemagang aktif.'
        );

        GroupMember::firstOrCreate([
            'group_id' => $group->id,
            'user_id' => $member->id,
        ]);

        return response()->json(['message' => 'Anggota berhasil ditambahkan.'], 201);
    }

    public function removeMember(Request $request, Group $group, User $member): JsonResponse
    {
        $this->authorizeGroup($request, $group, true);
        abort_unless(in_array($member->role, ['pemagang', 'intern'], true), 422, 'Anggota harus memiliki role pemagang.');
        GroupMember::where('group_id', $group->id)->where('user_id', $member->id)->delete();

        return response()->json(['message' => 'Anggota berhasil dihapus.']);
    }

    private function authorizeGroup(Request $request, Group $group, bool $canManage = false): void
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';
        $isMentor = $user->role === 'mentor' && ($group->mentor_id === $user->id || $group->is_default);
        $isMember = $group->members()->where('user_id', $user->id)->exists();

        abort_unless($isAdmin || $isMentor || (! $canManage && $isMember), 403);
    }

    private function syncMembers(Group $group, array $memberIds): void
    {
        foreach (array_unique($memberIds) as $memberId) {
            $member = User::findOrFail($memberId);
            abort_unless(
                in_array($member->role, ['pemagang', 'intern'], true) && $member->status === 'active',
                422,
                'Anggota harus memiliki akun pemagang aktif.'
            );
            GroupMember::firstOrCreate(['group_id' => $group->id, 'user_id' => $member->id]);
        }
    }

    private function serializeGroup(Group $group, bool $includeTasks = false): array
    {
        $result = $group->only([
            'id',
            'name',
            'description',
            'mentor_id',
            'created_by',
            'is_deleted',
            'is_default',
            'created_at',
        ]);
        $result['group_members'] = $group->members->map(fn (GroupMember $member) => [
            'user_id' => $member->user_id,
            'profiles' => $member->user ? $this->profileSummary($member->user) : null,
        ])->values();
        $result['tasks'] = $includeTasks
            ? $group->tasks->map(fn ($task) => $this->serializeTask($task))->values()
            : $group->tasks->map(fn ($task) => $task->only(['id', 'status']))->values();

        return $result;
    }

    private function serializeTask($task): array
    {
        $result = $task->toArray();
        $result['assignees'] = $task->assignees
            ->pluck('user_id')
            ->filter()
            ->values();
        $result['task_assignees'] = $task->assignees->map(fn ($assignee) => [
            'id' => $assignee->id,
            'member_initial' => $assignee->member_initial,
            'user_id' => $assignee->user_id,
        ])->values();
        $result['task_comments'] = $task->comments->map(fn ($comment) => [
            'id' => $comment->id,
            'task_id' => $comment->task_id,
            'user_id' => $comment->user_id,
            'content' => $comment->content,
            'created_at' => $comment->created_at,
            'user' => $comment->user ? $this->profileSummary($comment->user) : null,
        ])->values();
        $result['task_history'] = $task->history;

        return $result;
    }

    private function profileSummary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'role' => $user->role,
        ];
    }

    private function log(Request $request, string $description, ?int $taskId, ?int $groupId): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'description' => $description,
            'task_id' => $taskId,
            'group_id' => $groupId,
        ]);
    }
}
