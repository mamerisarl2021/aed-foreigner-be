<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `POST /kyc/document/read` — pré-lecture assistée de la pièce.
 *
 * Le serveur Regula est simulé : ces tests portent sur le contrat rendu au
 * front (champs normalisés, défauts de qualité, dégradation) et non sur la
 * reconnaissance elle-même.
 */
final class DocumentReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.regula.document_url' => 'https://regula.test',
            'services.regula.document_scenario' => 'FullAuth',
        ]);
    }

    #[Test]
    public function it_returns_normalized_fields_quality_issues_and_portrait(): void
    {
        Http::fake([
            'regula.test/api/process' => Http::response($this->regulaPayload(), 200),
        ]);

        $response = $this->postJson($this->api('/kyc/document/read'), [
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.document_name', 'Benin - Passport')
            ->assertJsonPath('data.fields.nom', 'KOWAKOU')
            ->assertJsonPath('data.fields.prenoms', 'AMOUR')
            ->assertJsonPath('data.fields.sexe', 'M')
            // Regula rend les dates en jj/mm/aaaa ; le front attend de l'ISO.
            ->assertJsonPath('data.fields.date_naissance', '1990-05-12')
            ->assertJsonPath('data.fields.numero_piece', 'BJ1234567')
            ->assertJsonPath('data.quality_issues', ['IMAGE_FOCUS'])
            ->assertJsonPath('data.portrait', 'cG9ydHJhaXQ=');
    }

    #[Test]
    public function it_sends_both_pages_with_the_configured_scenario(): void
    {
        Http::fake([
            'regula.test/api/process' => Http::response($this->regulaPayload(), 200),
        ]);

        $this->postJson($this->api('/kyc/document/read'), [
            'recto' => UploadedFile::fake()->image('recto.jpg'),
            'verso' => UploadedFile::fake()->image('verso.jpg'),
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['processParam']['scenario'] === 'FullAuth'
                && count($body['List']) === 2
                && $body['List'][1]['page_idx'] === 1;
        });
    }

    #[Test]
    public function an_unreadable_document_degrades_without_failing_the_request(): void
    {
        Http::fake([
            'regula.test/api/process' => Http::response(['message' => 'bad recognition input data'], 400),
        ]);

        $this->postJson($this->api('/kyc/document/read'), [
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.quality_issues', ['UNREADABLE_DOCUMENT']);
    }

    #[Test]
    public function an_unreachable_regula_server_degrades_without_failing_the_request(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->postJson($this->api('/kyc/document/read'), [
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.quality_issues', ['READER_UNAVAILABLE']);
    }

    #[Test]
    public function the_recto_is_required(): void
    {
        Http::fake();

        $this->postJson($this->api('/kyc/document/read'), [])
            ->assertStatus(422)
            ->assertJsonPath('data.recto.0', 'Le recto de la pièce est obligatoire.');

        Http::assertNothingSent();
    }

    /**
     * Réponse `/api/process` réduite aux conteneurs que le service exploite.
     *
     * @return array<string, mixed>
     */
    private function regulaPayload(): array
    {
        return [
            'ContainerList' => [
                'List' => [
                    ['result_type' => 33, 'Status' => ['overallStatus' => 1]],
                    ['result_type' => 9, 'DocumentName' => 'Benin - Passport'],
                    [
                        'result_type' => 36,
                        'Text' => [
                            'fieldList' => [
                                ['fieldType' => 8, 'value' => 'KOWAKOU'],
                                ['fieldType' => 9, 'value' => 'AMOUR'],
                                ['fieldType' => 12, 'value' => 'M'],
                                ['fieldType' => 5, 'value' => '12/05/1990'],
                                ['fieldType' => 2, 'value' => 'BJ1234567'],
                                // Champ hors périmètre du formulaire : doit être ignoré.
                                ['fieldType' => 25, 'value' => 'KOWAKOU AMOUR'],
                                // Valeur vide : ne doit pas créer de clé.
                                ['fieldType' => 11, 'value' => '  '],
                            ],
                        ],
                    ],
                    [
                        'result_type' => 30,
                        'ImageQualityCheckList' => [
                            'List' => [
                                ['type' => 1, 'result' => 0],
                                // Contrôle réussi : ne doit pas remonter.
                                ['type' => 0, 'result' => 1],
                            ],
                        ],
                    ],
                    [
                        'result_type' => 37,
                        'Images' => [
                            'fieldList' => [
                                ['fieldType' => 201, 'valueList' => [['value' => 'cG9ydHJhaXQ=']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
