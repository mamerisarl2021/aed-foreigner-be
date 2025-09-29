<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class IdRequest extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    
    use HasFactory;

    protected $fillable = ['type', 'status', 'structure_id'];

    public function structure()
    {
        return $this->belongsTo(Structure::class);
    }
}
