<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EnrollmentRejectMotif;

final class EnrollmentMotifs
{
    /**
     * @param  list<string>|null  $ids
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function resolve(?array $ids): array
    {
        if ($ids === null || $ids === []) {
            return [];
        }

        $normalized = [];
        foreach ($ids as $id) {
            $normalized[] = (string) $id;
        }

        $motifs = EnrollmentRejectMotif::query()
            ->whereIn('id', $normalized)
            ->get()
            ->keyBy('id');

        $resolved = [];
        foreach ($normalized as $id) {
            $motif = $motifs->get($id);
            $resolved[] = [
                'id' => $id,
                'title' => $motif !== null ? $motif->title : $id,
                'description' => $motif !== null ? (string) $motif->description : '',
            ];
        }

        return $resolved;
    }
}
