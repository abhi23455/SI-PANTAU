<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Task $task,
        private readonly string $type = 'assignment',
        private readonly ?string $message = null,
        private readonly ?string $reminderDate = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $title = match ($this->type) {
            'deadline' => 'Pengingat deadline tugas',
            'submitted' => 'Bukti tugas menunggu ditinjau',
            'reviewed' => 'Hasil peninjauan tugas',
            default => 'Anda mendapatkan tugas baru',
        };

        return [
            'title' => $title,
            'message' => $this->message ?? $this->task->title,
            'task_id' => $this->task->id,
            'group_id' => $this->task->group_id,
            'task_title' => $this->task->title,
            'due_date' => $this->task->due_date?->format('Y-m-d'),
            'type' => $this->type,
            'reminder_date' => $this->reminderDate,
        ];
    }
}
