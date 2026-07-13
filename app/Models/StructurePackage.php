<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class StructurePackage extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use  HasFactory;


    protected $fillable = ['prix', 'validity', 'quantity', 'type'];

    public function structureSubscriptions()
    {
        return $this->hasMany(StructureSubscription::class);
    }
}
