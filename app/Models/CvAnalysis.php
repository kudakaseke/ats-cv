<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CvAnalysis extends Model
{
    protected $fillable = ['resume_id', 'job_title', 'job_description', 'status', 'score', 'results'];

    protected function casts(): array
    {
        return ['results' => 'array'];
    }

    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }
}
