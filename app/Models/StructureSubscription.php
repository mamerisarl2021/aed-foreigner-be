<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class StructureSubscription extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasFactory;


    protected $fillable = ['structure_id', 'structure_package_id'];
    protected $appends = ['packagedata', 'package', 'structuredata'];


    public function structure()
    {
        return $this->belongsTo(Structure::class);
    }

    public function getPackagedataAttribute()
    {
        return $this->structurePackage;
    }

    public function getPackageAttribute()
    {
        return $this->structurePackage;
    }

    public function getStructuredataAttribute()
    {
        return $this->structure;
    }

    public function structurePackage()
    {
        return $this->belongsTo(StructurePackage::class);
    }

    protected $hidden = ['structurePackage', 'structure'];
}
