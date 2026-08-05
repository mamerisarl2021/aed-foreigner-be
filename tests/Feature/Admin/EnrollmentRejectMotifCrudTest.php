<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\EnrollmentRejectMotif;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EnrollmentRejectMotifCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            config('roles.administrateur_plateforme'),
            config('roles.agent'),
            config('roles.responsable_de_validation'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create(['email' => 'admin-motifs@example.com']);
        $this->admin->assignRole(config('roles.administrateur_plateforme'));

        $this->agent = User::factory()->create(['email' => 'agent-motifs@example.com']);
        $this->agent->assignRole(config('roles.agent'));
    }

    #[Test]
    public function admin_can_create_list_show_update_and_delete_motifs(): void
    {
        Sanctum::actingAs($this->admin);

        $create = $this->postJson($this->api('/admin/enrollment-reject-motifs'), [
            'title' => 'Document illisible',
            'description' => 'La pièce fournie ne peut pas être lue.',
        ]);

        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Document illisible')
            ->assertJsonPath('data.description', 'La pièce fournie ne peut pas être lue.');

        $id = $create->json('data.id');
        $this->assertNotEmpty($id);

        $this->getJson($this->api('/management/enrollment-reject-motifs'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['id' => $id, 'title' => 'Document illisible']);

        $this->getJson($this->api("/admin/enrollment-reject-motifs/{$id}"))
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.title', 'Document illisible');

        $this->patchJson($this->api("/admin/enrollment-reject-motifs/{$id}"), [
            'title' => 'Document illisible (maj)',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Document illisible (maj)');

        $this->deleteJson($this->api("/admin/enrollment-reject-motifs/{$id}"))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('enrollment_reject_motifs', ['id' => $id]);
    }

    #[Test]
    public function agent_can_list_motifs_but_cannot_mutate_via_admin_routes(): void
    {
        $motif = EnrollmentRejectMotif::query()->create([
            'title' => 'Autre motif',
            'description' => 'Description',
        ]);

        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/management/enrollment-reject-motifs'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson($this->api('/admin/enrollment-reject-motifs'), [
            'title' => 'Interdit',
            'description' => 'Pas autorisé',
        ])->assertForbidden();

        $this->getJson($this->api("/admin/enrollment-reject-motifs/{$motif->id}"))
            ->assertForbidden();

        $this->patchJson($this->api("/admin/enrollment-reject-motifs/{$motif->id}"), [
            'title' => 'Hack',
        ])->assertForbidden();

        $this->deleteJson($this->api("/admin/enrollment-reject-motifs/{$motif->id}"))
            ->assertForbidden();
    }

    #[Test]
    public function create_requires_title_and_description(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->api('/admin/enrollment-reject-motifs'), [
            'title' => 'Sans description',
        ])->assertStatus(422);

        $this->postJson($this->api('/admin/enrollment-reject-motifs'), [])
            ->assertStatus(422);
    }

    #[Test]
    public function show_rejects_invalid_uuid(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson($this->api('/admin/enrollment-reject-motifs/not-a-uuid'))
            ->assertStatus(422);
    }
}
