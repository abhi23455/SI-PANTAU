<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskHistory extends Model
{
    protected $table = 'task_history';

    protected $fillable = [
        'task_id',
        'changed_by',
        'name',
        'text',
        'time',
        'action',
        'old_status',
        'new_status',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
