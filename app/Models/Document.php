<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Contracts\Auditable;

class Document extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    
    use HasFactory; 
    protected $appends = ['link'];

    protected $fillable = ['name', 'type', 'path', 'attachment_id', 'status'];

    public function attachment()
    {
        return $this->belongsTo(Attachment::class);
    }

    public function getLinkAttribute()
    {
        return $this->path ? Storage::cloud()->temporaryUrl($this->path, Carbon::now()->addDays(3)): "";
    }
}
