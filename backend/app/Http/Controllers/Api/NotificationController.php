<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Notifications\TaskNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::today();

        $upcomingTasks = Task::query()
            ->where(function ($query) use ($user) {
                $query->where('assigned_to', $user->id)
                    ->orWhereHas('assignees', fn ($assignees) => $assignees->where('user_id', $user->id));
            })
            ->whereNotIn('status', ['done', 'completed', 'Selesai'])
            ->whereBetween('due_date', [$today->toDateString(), $today->copy()->addDays(3)->toDateString()])
            ->get();

        foreach ($upcomingTasks as $task) {
            $alreadyReminded = $user->notifications()
                ->where('type', TaskNotification::class)
                ->get()
                ->contains(function ($notification) use ($task, $today) {
                    $data = $notification->data;

                    return ($data['type'] ?? null) === 'deadline'
                        && (int) ($data['task_id'] ?? 0) === $task->id
                        && ($data['reminder_date'] ?? null) === $today->toDateString();
                });

            if (! $alreadyReminded) {
                $notification = new TaskNotification(
                    $task,
                    'deadline',
                    'Tugas "'.$task->title.'" memiliki deadline pada '.$task->due_date->format('d M Y').'.',
                    $today->toDateString(),
                );
                $user->notify($notification);
            }
        }

        $notifications = $user->notifications()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'type' => $notification->data['type'] ?? 'info',
                'title' => $notification->data['title'] ?? 'Notifikasi',
                'message' => $notification->data['message'] ?? '',
                'task_id' => $notification->data['task_id'] ?? null,
                'group_id' => $notification->data['group_id'] ?? null,
                'due_date' => $notification->data['due_date'] ?? null,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at,
            ]);

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'notifications' => $notifications,
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return response()->json(['status' => 'ok']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['status' => 'ok']);
    }
}
