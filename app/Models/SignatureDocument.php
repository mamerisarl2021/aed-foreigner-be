<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Contracts\Auditable;

class SignatureDocument extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['user_id', 'title', 'file_path', 'status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    public function canBeDeletedBy(User $user)
    {
        return $this->user_id === $user->id;
    }

    protected $appends = ['link'];

    public function getLinkAttribute()
    {
        return $this->file_path ? Storage::cloud()->temporaryUrl($this->file_path, Carbon::now()->addDays(3)) : '';
    }
}
