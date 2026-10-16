<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Advisory extends Model
{
    protected $fillable = ['title', 'message', 'priority', 'target', 'status', 'published_at', 'published_by'];
    protected $hidden = ['published_by'];
    protected function casts(): array { return ['published_at' => 'immutable_datetime']; }
}
