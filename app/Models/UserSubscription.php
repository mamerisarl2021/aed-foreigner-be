<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class UserSubscription extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['type', 'status', 'structure_id', 'user_id', 'package_id', 'current'];

    protected $appends = ['package', 'userdata', 'structuredata'];

    protected static function booted()
    {
        static::addGlobalScope('owned', function (Builder $builder) {
            $user = User::find(auth()->id());
            if ($user && $user->hasRole('client')) {
                $builder->where('user_id', $user->id);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function structure()
    {
        return $this->belongsTo(Structure::class);
    }

    public function userPackage()
    {
        return $this->belongsTo(UserPackage::class, 'package_id');
    }

    public function getStructuredataAttribute()
    {
        return $this->structure()->with('manager')->first();
    }

    public function getUserdataAttribute()
    {
        return $this->user;
    }

    public function structurePackage()
    {
        return $this->belongsTo(StructurePackage::class, 'package_id');
    }

    public function getPackageAttribute()
    {
        if ($this->type === 'EMPLOYEE') {
            return $this->structurePackage;
        }

        return $this->userPackage;
    }

    protected $hidden = ['userPackage', 'structurePackage', 'user', 'structure'];
}
