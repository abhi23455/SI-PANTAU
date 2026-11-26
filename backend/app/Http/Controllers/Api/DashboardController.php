<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function adminStats(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $users = User::query()->whereNot(fn ($query) => $query
            ->where('name', 'DELETED_USER')
            ->where('status', 'rejected'));

        return response()->json([
            'total' => (clone $users)->count(),
            'pending' => (clone $users)->where('status', 'pending')->count(),
            'approved' => (clone $users)->where('status', 'active')->count(),
            'rejected' => (clone $users)->where('status', 'rejected')->count(),
        ]);
    }

    public function personalStats(Request $request): JsonResponse
    {
        $user = $request->user();
        $tasks = Task::query();
        if ($user->role === 'mentor') {
            $tasks->whereHas('group', fn ($groups) => $groups
                ->where('mentor_id', $user->id)
                ->orWhere('is_default', true));
        } elseif ($user->role === 'pemagang' || $user->role === 'intern') {
            $tasks->where(function ($query) use ($user) {
                $query->where('assigned_to', $user->id)
                    ->orWhereHas('assignees', fn ($assignees) => $assignees->where('user_id', $user->id));
            });
        } else {
            abort_unless($user->role === 'admin', 403);
        }

        $completed = 0;
        $scheduled = 0;
        $updated = 0;
        $overdue = 0;
        $today = Carbon::today();
        foreach ($tasks->get(['status', 'due_date']) as $task) {
            if (in_array($task->status, ['done', 'completed', 'Selesai'], true)) {
                $completed++;

                continue;
            }
            if ($task->status === 'todo') {
                $scheduled++;
            } elseif (in_array($task->status, ['inprogress', 'in_progress', 'review', 'in_review'], true)) {
                $updated++;
            } else {
                $scheduled++;
            }
            if ($task->due_date && Carbon::parse($task->due_date)->lt($today)) {
                $overdue++;
            }
        }

        return response()->json(compact('completed', 'scheduled', 'updated', 'overdue'));
    }

    public function taskReport(Request $request): JsonResponse
    {
        $tasks = $this->reportQuery($request)->with([
            'assignedUser:id,name,email',
            'group:id,name',
        ])->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('created_at')
            ->get();

        $today = Carbon::today();
        $summary = [
            'total' => $tasks->count(),
            'completed' => $tasks->filter(fn (Task $task) => in_array($task->status, ['done', 'completed', 'Selesai'], true))->count(),
            'in_progress' => $tasks->filter(fn (Task $task) => in_array($task->status, ['inprogress', 'in_progress', 'review', 'in_review'], true))->count(),
            'scheduled' => $tasks->filter(fn (Task $task) => ! in_array($task->status, ['done', 'completed', 'Selesai', 'inprogress', 'in_progress', 'review', 'in_review'], true))->count(),
            'overdue' => $tasks->filter(fn (Task $task) => $task->due_date
                && ! in_array($task->status, ['done', 'completed', 'Selesai'], true)
                && Carbon::parse($task->due_date)->lt($today))->count(),
            'upcoming' => $tasks->filter(fn (Task $task) => $task->due_date
                && ! in_array($task->status, ['done', 'completed', 'Selesai'], true)
                && Carbon::parse($task->due_date)->betweenIncluded($today, $today->copy()->addDays(7)))->count(),
        ];

        $rows = $tasks->map(fn (Task $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'type' => $task->type,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'start_date' => $task->start_date?->format('Y-m-d'),
            'group' => $task->group?->name,
            'assignee' => $task->assignedUser?->full_name,
            'created_at' => $task->created_at,
        ]);

        return response()->json([
            'summary' => $summary,
            'tasks' => $rows,
        ]);
    }

    public function exportTaskReport(Request $request)
    {
        $tasks = $this->reportQuery($request)->with([
            'assignedUser:id,name,email',
            'group:id,name',
        ])->orderBy('due_date')->get();

        $csv = "\xEF\xBB\xBF";
        $csv .= implode(';', ['ID', 'Judul', 'Kelompok', 'Penerima', 'Status', 'Prioritas', 'Jenis', 'Mulai', 'Deadline'])."\r\n";
        foreach ($tasks as $task) {
            $row = [
                $task->id,
                $task->title,
                $task->group?->name,
                $task->assignedUser?->full_name,
                $task->status,
                $task->priority,
                $task->type,
                $task->start_date?->format('Y-m-d'),
                $task->due_date?->format('Y-m-d'),
            ];
            $csv .= implode(';', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', $row))."\r\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="laporan-tugas-sipantau.csv"',
        ]);
    }

    private function reportQuery(Request $request)
    {
        $filters = $request->validate([
            'group_id' => ['sometimes', 'integer', 'exists:groups,id'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'status' => ['sometimes', 'string', 'max:128'],
            'priority' => ['sometimes', 'string', 'max:32'],
            'due_from' => ['sometimes', 'date'],
            'due_to' => ['sometimes', 'date', 'after_or_equal:due_from'],
        ]);
        $user = $request->user();
        $query = Task::query();

        if (isset($filters['group_id'])) {
            $groupId = (int) $filters['group_id'];
            $isMember = GroupMember::where('group_id', $groupId)->where('user_id', $user->id)->exists();
            $isMentor = $user->role === 'mentor' && Group::whereKey($groupId)
                ->where(fn ($group) => $group->where('mentor_id', $user->id)->orWhere('is_default', true))
                ->exists();
            abort_unless($user->role === 'admin' || $isMember || $isMentor, 403);
            $query->where('group_id', $groupId);
        } elseif ($user->role === 'mentor') {
            $query->whereHas('group', fn ($groups) => $groups
                ->where('mentor_id', $user->id)
                ->orWhere('is_default', true));
        } elseif (in_array($user->role, ['pemagang', 'intern'], true)) {
            $query->where(function ($tasks) use ($user) {
                $tasks->where('assigned_to', $user->id)
                    ->orWhereHas('assignees', fn ($assignees) => $assignees->where('user_id', $user->id));
            });
        } else {
            abort_unless($user->role === 'admin', 403);
        }

        if (isset($filters['group_id']) && in_array($user->role, ['pemagang', 'intern'], true)) {
            $query->where(function ($tasks) use ($user) {
                $tasks->where('assigned_to', $user->id)
                    ->orWhereHas('assignees', fn ($assignees) => $assignees->where('user_id', $user->id));
            });
        }

        if (isset($filters['status'])) {
            $statuses = array_filter(explode(',', $filters['status']));
            $query->whereIn('status', $statuses);
        }
        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (isset($filters['due_from'])) {
            $query->whereDate('due_date', '>=', $filters['due_from']);
        }
        if (isset($filters['due_to'])) {
            $query->whereDate('due_date', '<=', $filters['due_to']);
        }
        if (isset($filters['user_id'])) {
            abort_unless(in_array($user->role, ['admin', 'mentor'], true), 403);
            $userId = (int) $filters['user_id'];
            $query->where(function ($tasks) use ($userId) {
                $tasks->where('assigned_to', $userId)
                    ->orWhereHas('assignees', fn ($assignees) => $assignees->where('user_id', $userId));
            });
        }

        return $query;
    }

    public function activity(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $request->integer('limit', 20);
        abort_if($limit < 1 || $limit > 100, 422, 'Limit aktivitas harus antara 1 dan 100.');

        $query = ActivityLog::with(['user', 'task'])->latest();
        if ($request->filled('group_id')) {
            $groupId = $request->integer('group_id');
            $isMember = GroupMember::where('group_id', $groupId)->where('user_id', $user->id)->exists();
            $isMentor = $user->role === 'mentor' && Group::whereKey($groupId)
                ->where(fn ($group) => $group->where('mentor_id', $user->id)->orWhere('is_default', true))
                ->exists();
            abort_unless($user->role === 'admin' || $isMember || $isMentor, 403);
            $query->where('group_id', $groupId);
        } elseif ($user->role !== 'admin') {
            if ($user->role === 'mentor') {
                $query->where(function ($logs) use ($user) {
                    $logs->where('user_id', $user->id)
                        ->orWhereIn('group_id', Group::query()
                            ->where('mentor_id', $user->id)
                            ->orWhere('is_default', true)
                            ->select('id'));
                });
            } else {
                $groupIds = GroupMember::where('user_id', $user->id)->pluck('group_id');
                $query->where(function ($logs) use ($user, $groupIds) {
                    if ($groupIds->isNotEmpty()) {
                        $logs->whereIn('group_id', $groupIds);
                    }
                    $logs->orWhere('user_id', $user->id);
                });
            }
        }

        $logs = $query->limit($limit)->get()->map(fn (ActivityLog $log) => [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'group_id' => $log->group_id,
            'task_id' => $log->task_id,
            'description' => $log->description ?: $log->detail ?: $log->action,
            'action' => $log->action,
            'detail' => $log->detail,
            'created_at' => $log->created_at,
            'profiles' => $log->user ? [
                'full_name' => $log->user->full_name,
                'avatar_url' => $log->user->avatar_url,
            ] : null,
            'tasks' => $log->task?->only(['title']),
            'task' => $log->task?->only(['title']),
        ]);

        return response()->json($logs);
    }

    public function logActivity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
            'action' => ['nullable', 'string', 'max:255'],
            'detail' => ['nullable', 'string', 'max:2000'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
        ]);
        if (empty($data['description']) && empty($data['detail']) && empty($data['action'])) {
            abort(422, 'Aktivitas harus memiliki deskripsi.');
        }
        if (! empty($data['group_id'])) {
            $isMember = GroupMember::where('group_id', $data['group_id'])
                ->where('user_id', $request->user()->id)
                ->exists();
            $isMentor = Group::whereKey($data['group_id'])
                ->where(fn ($groups) => $groups
                    ->where('mentor_id', $request->user()->id)
                    ->orWhere('is_default', true))
                ->exists();
            abort_unless($request->user()->role === 'admin' || $isMember || $isMentor, 403);
        }
        if (! empty($data['task_id'])) {
            $task = Task::findOrFail($data['task_id']);
            if ($task->group_id) {
                $isMember = GroupMember::where('group_id', $task->group_id)
                    ->where('user_id', $request->user()->id)
                    ->exists();
                $isMentor = Group::whereKey($task->group_id)
                    ->where(fn ($groups) => $groups
                        ->where('mentor_id', $request->user()->id)
                        ->orWhere('is_default', true))
                    ->exists();
                abort_unless($request->user()->role === 'admin' || $isMember || $isMentor, 403);
            }
        }

        $log = ActivityLog::create($data + ['user_id' => $request->user()->id]);

        return response()->json($log, 201);
    }
}
