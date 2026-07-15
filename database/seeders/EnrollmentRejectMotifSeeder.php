<?php

namespace Database\Seeders;

use App\Models\EnrollmentRejectMotif;
use Illuminate\Database\Seeder;

class EnrollmentRejectMotifSeeder extends Seeder
{
    public function run(): void
    {
        $motifs = [
            ['code' => 'doc_invalid', 'label_fr' => 'Pièce d\'identité invalide ou illisible', 'stage' => 'DOCUMENT'],
            ['code' => 'doc_expired', 'label_fr' => 'Pièce d\'identité expirée', 'stage' => 'DOCUMENT'],
            ['code' => 'doc_mismatch', 'label_fr' => 'Incohérence entre la pièce et les informations saisies', 'stage' => 'DOCUMENT'],
            ['code' => 'photo_mismatch', 'label_fr' => 'Photo / selfie ne correspond pas au document', 'stage' => 'BIOMETRY'],
            ['code' => 'liveness_failed', 'label_fr' => 'Contrôle de vivacité échoué', 'stage' => 'BIOMETRY'],
            ['code' => 'kyc_incomplete', 'label_fr' => 'Informations KYC incomplètes ou incorrectes', 'stage' => 'KYC'],
            ['code' => 'kyc_inconsistent', 'label_fr' => 'Incohérence des données personnelles', 'stage' => 'KYC'],
            ['code' => 'duplicate_identity', 'label_fr' => 'Identité déjà enrôlée / suspicion de doublon', 'stage' => 'KYC'],
            ['code' => 'insufficient_evidence', 'label_fr' => 'Éléments de preuve insuffisants', 'stage' => 'OTHER'],
            ['code' => 'other', 'label_fr' => 'Autre motif (voir commentaires)', 'stage' => 'OTHER'],
        ];

        foreach ($motifs as $motif) {
            EnrollmentRejectMotif::query()->updateOrCreate(
                ['code' => $motif['code']],
                [
                    'label_fr' => $motif['label_fr'],
                    'stage' => $motif['stage'],
                    'active' => true,
                ]
            );
        }
    }
}
