<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAssignee extends Model
{
    protected $fillable = ['task_id', 'user_id', 'member_initial'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
