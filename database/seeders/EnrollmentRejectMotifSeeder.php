<?php

namespace Database\Seeders;

use App\Models\EnrollmentRejectMotif;
use Illuminate\Database\Seeder;

class EnrollmentRejectMotifSeeder extends Seeder
{
    public function run(): void
    {
        $motifs = [
            [
                'title' => 'Pièce d\'identité invalide ou illisible',
                'description' => 'La pièce fournie est illisible, endommagée ou non reconnue.',
            ],
            [
                'title' => 'Pièce d\'identité expirée',
                'description' => 'La date d\'expiration de la pièce est dépassée.',
            ],
            [
                'title' => 'Incohérence pièce / informations saisies',
                'description' => 'Les données du formulaire ne correspondent pas au document présenté.',
            ],
            [
                'title' => 'Photo / selfie ne correspond pas au document',
                'description' => 'La comparaison biométrique entre le selfie et le portrait du document a échoué.',
            ],
            [
                'title' => 'Contrôle de vivacité échoué',
                'description' => 'Le contrôle de vivacité (liveness) n\'a pas été validé.',
            ],
            [
                'title' => 'Informations KYC incomplètes ou incorrectes',
                'description' => 'Des champs obligatoires sont manquants ou incorrects.',
            ],
            [
                'title' => 'Identité déjà enrôlée / suspicion de doublon',
                'description' => 'Une identité similaire ou identique existe déjà dans le système.',
            ],
            [
                'title' => 'Éléments de preuve insuffisants',
                'description' => 'Les pièces jointes ne suffisent pas pour instruire favorablement la demande.',
            ],
            [
                'title' => 'Autre motif',
                'description' => 'Autre motif — voir le commentaire de l\'agent ou du responsable.',
            ],
            [
                'title' => 'Entreprise déjà enrôlée ou doublon',
                'description' => 'Une entreprise avec le même immatriculation / pays est déjà enregistrée.',
            ],
            [
                'title' => 'Pièces justificatives entreprise invalides',
                'description' => 'Les documents société (RCCM, statuts, etc.) sont invalides ou incomplets.',
            ],
            [
                'title' => 'Mandataire non habilité',
                'description' => 'La procuration est manquante ou le mandataire n\'est pas habilité.',
            ],
        ];

        foreach ($motifs as $motif) {
            EnrollmentRejectMotif::query()->updateOrCreate(
                ['title' => $motif['title']],
                ['description' => $motif['description']]
            );
        }
    }
}
