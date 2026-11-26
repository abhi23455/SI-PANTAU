<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TaskComment;
use App\Models\TaskHistory;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Notifications\TaskNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TaskController extends Controller
{
    private const RELATIONS = [
        'assignees',
        'subtasks',
        'comments.user',
        'history',
        'assignedUser',
        'submissions.user',
        'submissions.reviewer',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Task::with(self::RELATIONS);

        if ($request->filled('group_id')) {
            $group = Group::findOrFail($request->integer('group_id'));
            $this->authorizeGroup($request, $group);
            $query->where('group_id', $group->id);
        }

        if ($user->role === 'mentor' && ! $request->filled('group_id')) {
            $query->whereHas('group', fn ($groups) => $groups
                ->where('mentor_id', $user->id)
                ->orWhere('is_default', true));
        } elseif ($user->role === 'pemagang' || $user->role === 'intern') {
            $query->where(function ($tasks) use ($user) {
                $tasks->where('assigned_to', $user->id)
                    ->orWhereHas('assignees', fn ($assignees) => $assignees->where('user_id', $user->id));
            });
        } elseif (! in_array($user->role, ['admin', 'mentor'], true)) {
            abort(403);
        }

        return response()->json($query->orderBy('due_date')->orderByDesc('created_at')->get()
            ->map(fn (Task $task) => $this->serializeTask($task)));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['admin', 'mentor'], true), 403);
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'desc' => ['nullable', 'string', 'max:20000'],
            'due_date' => ['nullable', 'date'],
            'date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'type' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'string', 'max:32'],
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'orang' => ['sometimes', 'array'],
            'orang.*' => ['string', 'max:8'],
        ]);
        $group = Group::findOrFail($data['group_id']);
        $this->authorizeGroup($request, $group, true);
        if (! empty($data['assigned_to'])) {
            $this->ensureAssigneeInGroup($group, $data['assigned_to']);
        }

        $task = DB::transaction(function () use ($data, $request, $group) {
            $task = Task::create([
                'group_id' => $group->id,
                'created_by' => $request->user()->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? $data['desc'] ?? null,
                'due_date' => $data['due_date'] ?? $data['date'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'type' => $data['type'] ?? 'Tugas',
                'priority' => $data['priority'] ?? 'medium',
                'status' => 'todo',
                'assigned_to' => $data['assigned_to'] ?? null,
            ]);

            if (! empty($data['orang'])) {
                $this->syncAssignees($task, $data['orang']);
            } elseif ($task->assigned_to) {
                TaskAssignee::create([
                    'task_id' => $task->id,
                    'user_id' => $task->assigned_to,
                    'member_initial' => $this->initialForUser($task->assigned_to),
                ]);
            }

            TaskHistory::create([
                'task_id' => $task->id,
                'changed_by' => $request->user()->id,
                'name' => $request->user()->full_name,
                'text' => 'telah menambahkan tugas baru',
                'time' => 'baru saja',
                'action' => 'created',
            ]);

            return $task->load(self::RELATIONS);
        });

        $this->logActivity($request, 'telah menambahkan tugas baru', $task);
        foreach ($task->assignees as $assignee) {
            if ($assignee->user_id) {
                $user = User::find($assignee->user_id);
                $user?->notify(new TaskNotification(
                    $task,
                    'assignment',
                    'Anda ditugaskan pada tugas "'.$task->title.'".',
                ));
            }
        }

        return response()->json($this->serializeTask($task), 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($request, $task, true);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'desc' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'date' => ['sometimes', 'nullable', 'date'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'type' => ['sometimes', 'string', 'max:64'],
            'priority' => ['sometimes', 'string', 'max:32'],
            'status' => ['sometimes', 'string', 'max:32'],
            'done' => ['sometimes', 'boolean'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'orang' => ['sometimes', 'array'],
            'orang.*' => ['string', 'max:8'],
        ]);
        if (! empty($data['assigned_to']) && $task->group) {
            $this->ensureAssigneeInGroup($task->group, $data['assigned_to']);
        }

        $oldStatus = $task->status;
        $changes = [];
        foreach ([
            'title' => 'title',
            'description' => 'description',
            'desc' => 'description',
            'due_date' => 'due_date',
            'date' => 'due_date',
            'start_date' => 'start_date',
            'type' => 'type',
            'priority' => 'priority',
            'status' => 'status',
            'assigned_to' => 'assigned_to',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $changes[$column] = $data[$input];
            }
        }
        if (array_key_exists('done', $data) && ! array_key_exists('status', $data)) {
            $changes['status'] = $data['done'] ? 'done' : ($oldStatus === 'done' ? 'todo' : $oldStatus);
        }
        if ($request->filled('orang')) {
            $changes['assigned_to'] = null;
        }

        DB::transaction(function () use ($data, $task, $changes, $request, $oldStatus) {
            $task->update($changes);
            if (array_key_exists('orang', $data)) {
                $this->syncAssignees($task, $data['orang']);
            } elseif (array_key_exists('assigned_to', $changes)) {
                $task->assignees()->delete();
                if ($task->assigned_to) {
                    TaskAssignee::create([
                        'task_id' => $task->id,
                        'user_id' => $task->assigned_to,
                        'member_initial' => $this->initialForUser($task->assigned_to),
                    ]);
                }
            }

            if (isset($changes['status']) && $changes['status'] !== $oldStatus) {
                TaskHistory::create([
                    'task_id' => $task->id,
                    'changed_by' => $request->user()->id,
                    'name' => $request->user()->full_name,
                    'text' => 'telah mengubah status tugas',
                    'time' => 'baru saja',
                    'action' => 'status_change',
                    'old_status' => $oldStatus,
                    'new_status' => $changes['status'],
                ]);
            }
        });

        $task->refresh()->load(self::RELATIONS);
        $this->logActivity($request, 'telah memperbarui tugas', $task);
        if (array_key_exists('assigned_to', $changes) || array_key_exists('orang', $data)) {
            foreach ($task->assignees as $assignee) {
                if ($assignee->user_id) {
                    $user = User::find($assignee->user_id);
                    $user?->notify(new TaskNotification(
                        $task,
                        'assignment',
                        'Penugasan tugas "'.$task->title.'" telah diperbarui untuk Anda.',
                    ));
                }
            }
        }

        return response()->json($this->serializeTask($task));
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($request, $task, true);
        DB::transaction(function () use ($task) {
            ActivityLog::where('task_id', $task->id)->get()->each(function (ActivityLog $log) use ($task) {
                $description = $log->description ?: $log->detail ?: $log->action ?: 'Aktivitas tugas';
                if (! str_contains($description, $task->title)) {
                    $description .= ' '.$task->title;
                }
                $log->update(['task_id' => null, 'description' => $description]);
            });
            $task->delete();
        });

        $this->logActivity($request, 'telah menghapus tugas '.$task->title, null, $task->group_id);

        return response()->json(['message' => 'Tugas berhasil dihapus.']);
    }

    public function addComment(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($request, $task);
        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'text' => ['sometimes', 'string', 'max:5000'],
        ]);
        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'content' => $data['content'] ?? $data['text'],
        ])->load('user');

        $this->logActivity($request, 'telah mengomentari tugas "'.$task->title.'"', $task);

        return response()->json($this->serializeComment($comment), 201);
    }

    public function submitEvidence(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($request, $task);
        $user = $request->user();
        abort_unless(
            $task->assigned_to === $user->id
                || $task->assignees()->where('user_id', $user->id)->exists(),
            403,
            'Bukti hanya dapat dikirim oleh pemagang yang ditugaskan.'
        );
        abort_if(in_array($task->status, ['done', 'completed'], true), 422, 'Tugas sudah selesai.');
        abort_if(
            $task->submissions()->where('user_id', $user->id)->where('status', 'pending')->exists(),
            422,
            'Bukti Anda sedang menunggu pemeriksaan.'
        );

        $data = $request->validate([
            'file' => ['required_without:evidence_url', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
            'evidence_url' => ['required_without:file', 'nullable', 'url:http,https', 'max:2048'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        $file = $data['file'] ?? null;
        $evidenceUrl = $data['evidence_url'] ?? null;
        $path = $file?->store('task-submissions/'.$task->id, 'local');
        if ($file && ! $path) {
            throw new RuntimeException('Bukti tugas gagal disimpan.');
        }

        $submission = DB::transaction(function () use ($data, $evidenceUrl, $file, $path, $request, $task) {
            $submission = $task->submissions()->create([
                'user_id' => $request->user()->id,
                'file_path' => $path,
                'file_name' => $file?->getClientOriginalName(),
                'file_type' => $file?->getMimeType(),
                'evidence_url' => $evidenceUrl,
                'note' => $data['note'] ?? null,
                'status' => 'pending',
            ]);
            $task->update(['status' => 'in_review']);
            TaskHistory::create([
                'task_id' => $task->id,
                'changed_by' => $request->user()->id,
                'name' => $request->user()->full_name,
                'text' => 'mengirim bukti untuk ditinjau',
                'time' => 'baru saja',
                'action' => 'evidence_submitted',
            ]);

            return $submission;
        });

        $recipients = collect([$task->created_by, $task->group?->mentor_id])
            ->filter()
            ->unique()
            ->reject(fn ($userId) => (int) $userId === $user->id);
        User::whereIn('id', $recipients)->get()->each(
            fn (User $manager) => $manager->notify(new TaskNotification(
                $task,
                'submitted',
                $user->full_name.' mengirim bukti untuk tugas "'.$task->title.'".',
            ))
        );

        return response()->json($this->serializeSubmission($submission->load('user', 'reviewer')), 201);
    }

    public function reviewEvidence(Request $request, TaskSubmission $submission): JsonResponse
    {
        $task = $submission->task;
        $this->authorizeTask($request, $task, true);
        abort_unless($submission->status === 'pending', 422, 'Bukti ini sudah pernah diperiksa.');
        $data = $request->validate([
            'status' => ['required', 'in:approved,needs_revision'],
            'feedback' => ['nullable', 'string', 'max:5000', 'required_if:status,needs_revision'],
        ]);

        DB::transaction(function () use ($data, $request, $submission, $task) {
            $submission->update([
                'status' => $data['status'],
                'feedback' => $data['feedback'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            $task->update(['status' => $data['status'] === 'approved' ? 'done' : 'in_progress']);
            TaskHistory::create([
                'task_id' => $task->id,
                'changed_by' => $request->user()->id,
                'name' => $request->user()->full_name,
                'text' => $data['status'] === 'approved' ? 'menyetujui bukti tugas' : 'meminta perbaikan bukti tugas',
                'time' => 'baru saja',
                'action' => 'evidence_reviewed',
            ]);
        });

        $submission->user->notify(new TaskNotification(
            $task,
            'reviewed',
            $data['status'] === 'approved'
                ? 'Bukti tugas "'.$task->title.'" telah disetujui.'
                : 'Bukti tugas "'.$task->title.'" perlu diperbaiki. Catatan: '.$data['feedback'],
        ));

        return response()->json($this->serializeSubmission($submission->fresh()->load('user', 'reviewer')));
    }

    public function downloadEvidence(Request $request, TaskSubmission $submission)
    {
        $this->authorizeTask($request, $submission->task);
        $user = $request->user();
        abort_unless(
            $user->role === 'admin'
                || ($user->role === 'mentor' && (
                    $submission->task->group?->mentor_id === $user->id
                    || $submission->task->group?->is_default
                ))
                || $submission->user_id === $user->id,
            403
        );
        abort_unless($submission->file_path, 404, 'Bukti ini berupa tautan, bukan file unduhan.');
        abort_unless(Storage::disk('local')->exists($submission->file_path), 404, 'File bukti tidak ditemukan.');

        return Storage::disk('local')->download($submission->file_path, $submission->file_name);
    }

    public function addSubtask(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($request, $task, true);
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $subtask = $task->subtasks()->create([
            'title' => $data['title'],
            'is_completed' => false,
        ]);

        return response()->json($subtask, 201);
    }

    public function updateSubtask(Request $request, Subtask $subtask): JsonResponse
    {
        $this->authorizeTask($request, $subtask->task, true);
        $data = $request->validate(['is_completed' => ['required', 'boolean']]);
        $subtask->update($data);

        return response()->json($subtask->fresh());
    }

    public function deleteSubtask(Request $request, Subtask $subtask): JsonResponse
    {
        $this->authorizeTask($request, $subtask->task, true);
        $subtask->delete();

        return response()->json(['message' => 'Subtugas berhasil dihapus.']);
    }

    public function addHistory(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($request, $task, true);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:2000'],
            'time' => ['nullable', 'string', 'max:64'],
        ]);
        $history = $task->history()->create([
            'changed_by' => $request->user()->id,
            'name' => $data['name'] ?? $request->user()->full_name,
            'text' => $data['text'],
            'time' => $data['time'] ?? 'baru saja',
        ]);

        return response()->json($history, 201);
    }

    private function authorizeTask(Request $request, Task $task, bool $canManage = false): void
    {
        if (! $task->group_id) {
            $user = $request->user();
            abort_unless(
                $user->role === 'admin'
                    || (! $canManage && ($task->created_by === $user->id || $task->assigned_to === $user->id)),
                403
            );

            return;
        }
        $this->authorizeGroup($request, $task->group, $canManage, $task);
    }

    private function authorizeGroup(Request $request, Group $group, bool $canManage = false, ?Task $task = null): void
    {
        $user = $request->user();
        $isAssignedIntern = ! $canManage
            && in_array($user->role, ['pemagang', 'intern'], true)
            && $task
            && ($task->assigned_to === $user->id
                || $task->assignees()->where('user_id', $user->id)->exists());
        $allowed = $user->role === 'admin'
            || ($user->role === 'mentor' && ($group->mentor_id === $user->id || $group->is_default))
            || (! $canManage && $group->members()->where('user_id', $user->id)->exists())
            || $isAssignedIntern;
        abort_unless($allowed, 403);
    }

    private function syncAssignees(Task $task, array $initials): void
    {
        $task->assignees()->delete();
        $groupMemberIds = $task->group_id
            ? GroupMember::where('group_id', $task->group_id)->pluck('user_id')
            : collect();
        $members = User::whereIn('id', $groupMemberIds)->get();

        foreach (array_unique($initials) as $initial) {
            $member = $members->first(function (User $user) use ($initial) {
                return mb_strtoupper(mb_substr($user->name, 0, 1)) === mb_strtoupper($initial);
            });
            TaskAssignee::create([
                'task_id' => $task->id,
                'user_id' => $member?->id,
                'member_initial' => $initial,
            ]);
        }
        $task->update(['assigned_to' => $task->assignees()->first()?->user_id]);
    }

    private function initialForUser(int $userId): string
    {
        return mb_strtoupper(mb_substr((string) User::whereKey($userId)->value('name'), 0, 1));
    }

    private function ensureAssigneeInGroup(Group $group, int $userId): void
    {
        abort_unless(
            GroupMember::where('group_id', $group->id)->where('user_id', $userId)->exists(),
            422,
            'Penerima tugas harus menjadi anggota kelompok.'
        );
    }

    private function serializeTask(Task $task): array
    {
        $result = $task->toArray();
        $result['group'] = $task->group ? ['name' => $task->group->name] : null;
        $result['assigned_user'] = $task->assignedUser ? [
            'id' => $task->assignedUser->id,
            'full_name' => $task->assignedUser->full_name,
            'avatar_url' => $task->assignedUser->avatar_url,
        ] : null;
        $result['assignees'] = $task->assignees->pluck('user_id')->filter()->values();
        $result['task_assignees'] = $task->assignees->map(fn (TaskAssignee $assignee) => [
            'id' => $assignee->id,
            'member_initial' => $assignee->member_initial,
            'user_id' => $assignee->user_id,
        ])->values();
        $result['subtasks'] = $task->subtasks;
        $result['task_comments'] = $task->comments->map(fn (TaskComment $comment) => $this->serializeComment($comment));
        $result['task_history'] = $task->history;
        $result['submissions'] = $task->submissions
            ->sortByDesc('id')
            ->map(fn (TaskSubmission $submission) => $this->serializeSubmission($submission))
            ->values();

        return $result;
    }

    private function serializeSubmission(TaskSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'task_id' => $submission->task_id,
            'user_id' => $submission->user_id,
            'file_name' => $submission->file_name,
            'file_type' => $submission->file_type,
            'evidence_url' => $submission->evidence_url,
            'note' => $submission->note,
            'status' => $submission->status,
            'feedback' => $submission->feedback,
            'reviewed_at' => $submission->reviewed_at,
            'created_at' => $submission->created_at,
            'download_url' => $submission->file_path ? '/tasks/submissions/'.$submission->id.'/file' : null,
            'user' => $submission->user?->only(['id', 'name', 'email']),
            'reviewer' => $submission->reviewer?->only(['id', 'name']),
        ];
    }

    private function serializeComment(TaskComment $comment): array
    {
        $user = $comment->user;

        return [
            'id' => $comment->id,
            'task_id' => $comment->task_id,
            'user_id' => $comment->user_id,
            'content' => $comment->content,
            'created_at' => $comment->created_at,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'full_name' => $user->full_name,
                'avatar_url' => $user->avatar_url,
            ] : null,
            'profiles' => $user ? [
                'full_name' => $user->full_name,
                'avatar_url' => $user->avatar_url,
            ] : null,
        ];
    }

    private function logActivity(Request $request, string $description, ?Task $task, ?int $groupId = null): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'description' => $description,
            'task_id' => $task?->id,
            'group_id' => $groupId ?? $task?->group_id,
        ]);
    }
}
