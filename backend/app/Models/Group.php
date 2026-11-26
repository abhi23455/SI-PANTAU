<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $fillable = [
        'name',
        'description',
        'mentor_id',
        'created_by',
        'is_deleted',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentor_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
