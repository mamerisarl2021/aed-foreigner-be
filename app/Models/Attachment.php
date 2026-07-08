<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Attachment extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['name', 'structure_id', 'status', 'message'];

    public function structure()
    {
        return $this->belongsTo(Structure::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }
}
