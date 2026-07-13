<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class StructurePackage extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['prix', 'validity', 'quantity', 'type'];

    public function structureSubscriptions()
    {
        return $this->hasMany(StructureSubscription::class);
    }
}
