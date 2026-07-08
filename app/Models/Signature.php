<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Signature extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['signature_document_id', 'user_id', 'status', 'location', 'toTimestamp'];

    public function document()
    {
        return $this->belongsTo(SignatureDocument::class, 'signature_document_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // public function scopeSent($query)
    // {
    //     return $query->whereHas('document', function ($query) {
    //         $query->where('user_id', auth()->id());
    //     });
    // }

    // public function scopeSigned($query)
    // {
    //     return $query->where('user_id', auth()->id())
    //         ->where('status', 'signed');
    // }

    // public function scopeReceived($query)
    // {
    //     return $query->where('user_id', auth()->id())
    //         ->where('status', '!=', 'signed');
    // }

    public function scopeSent($query)
    {
        $userId = auth()->id();

        return $query->whereHas('document', function ($query) use ($userId) {
            $query->where('user_id', $userId) // The authenticated user is the sender
                ->whereHas('signatures', function ($query) use ($userId) {
                    $query->where('status', '!=', 'signed')
                        ->where('user_id', '!=', $userId); // Exclude documents signed by the sender themselves
                });
        });
    }

    public function scopeSigned($query)
    {
        $userId = auth()->id();

        return $query->where('user_id', $userId)->whereHas('document', function ($query) {
            $query->whereDoesntHave('signatures', function ($query) {
                $query->where('status', '!=', 'signed');
            });
        });
    }

    public function scopeReceived($query)
    {
        $userId = auth()->id();

        return $query->where('user_id', $userId)
            ->where('status', '!=', 'signed')
            ->orWhereHas('document', function ($query) use ($userId) {
                $query->whereHas('signatures', function ($query) use ($userId) {
                    $query->where('user_id', $userId)
                        ->where('status', '!=', 'signed');
                });
            });
    }
}
