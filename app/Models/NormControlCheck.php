<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormControlCheck extends Model
{
    protected $fillable = ['file_name', 'file_hash', 'errors_count'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
