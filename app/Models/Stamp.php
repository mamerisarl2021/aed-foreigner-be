<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Stamp extends Model
{
    use HasFactory;

    protected $appends = ['link'];

    protected $fillable = [
        'fichier',
        'user_id',
        'type'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function getLinkAttribute()
    {
        return $this->fichier ? Storage::cloud()->temporaryUrl($this->fichier, Carbon::now()->addDays(1)): "";
    }
}
