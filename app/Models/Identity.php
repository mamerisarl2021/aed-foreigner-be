<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property array<string, mixed>|null $proof
 * @property array<string, mixed>|null $analysis_details
 */
class Identity extends Model implements Auditable
{
    use HasFactory;
    use HasUuids;
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proof' => 'array',
            'analysis_details' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getSelfieUrlAttribute(): string
    {
        $path = $this->proofString('selfiePath');

        return $path !== '' ? Storage::cloud()->temporaryUrl($path, Carbon::now()->addDays(3)) : '';
    }

    public function getRectoUrlAttribute(): string
    {
        $path = $this->proofString('rectoPath');

        return $path !== '' ? Storage::cloud()->temporaryUrl($path, Carbon::now()->addDays(3)) : '';
    }

    public function getVersoUrlAttribute(): string
    {
        $path = $this->proofString('versoPath');

        return $path !== '' ? Storage::cloud()->temporaryUrl($path, Carbon::now()->addDays(3)) : '';
    }

    private function proofString(string $key): string
    {
        $proof = $this->proof ?? [];
        $value = $proof[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
