<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Structure extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasFactory;


    protected $fillable = ['name', 'ifu', 'manager_id','searchbase', 'status'];

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function userSubscriptions()
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function structureSubscriptions()
    {
        return $this->hasMany(StructureSubscription::class);
    }
}
