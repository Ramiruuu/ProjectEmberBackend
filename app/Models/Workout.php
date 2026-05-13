<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workout extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'activity_name',
        'duration_minutes',
        'calories_burned',
        'details',
        'workout_date',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'workout_date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
