<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Contracts\Auditable;

class Identity extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'type',
        'level',
        'proof',
        'user_id',
        'status',
        'date',
        'risk_score',
        'analysis_details',
    ];

    protected $appends = ['selfieUrl', 'rectoUrl', 'versoUrl'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getSelfieUrlAttribute()
    {
        $proof = json_decode($this->proof, true);
        Log::debug('Proof: '.json_encode($proof));

        return isset($proof['selfiePath']) && $proof['selfiePath'] != '' ? Storage::cloud()->temporaryUrl($proof['selfiePath'], Carbon::now()->addDays(3)) : '';
    }

    public function getRectoUrlAttribute()
    {
        $proof = json_decode($this->proof, true);

        return isset($proof['rectoPath']) && $proof['rectoPath'] != '' ? Storage::cloud()->temporaryUrl($proof['rectoPath'], Carbon::now()->addDays(3)) : '';
    }

    public function getVersoUrlAttribute()
    {
        $proof = json_decode($this->proof, true);

        return isset($proof['versoPath']) && $proof['versoPath'] != '' ? Storage::cloud()->temporaryUrl($proof['versoPath'], Carbon::now()->addDays(3)) : '';
    }
}
