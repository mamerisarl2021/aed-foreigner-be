<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Revocation extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['group_id', 'status', 'structure_id', 'user_id', 'identity_id'];

    protected static function booted()
    {
        static::addGlobalScope('owned', function (Builder $builder) {
            $user = User::find(auth()->id());
            if ($user && $user->hasRole('client')) {
                $builder->where('user_id', $user->id);
            }
        });
    }

    protected $appends = ['userdata'];

    public function getUserdataAttribute()
    {
        return $this->user;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function structure()
    {
        return $this->belongsTo(Structure::class);
    }

    protected $hidden = ['user'];
}
