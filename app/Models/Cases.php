<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Cases extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['titre', 'type', 'status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
