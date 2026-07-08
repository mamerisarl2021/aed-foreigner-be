<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\WelcomeUserJob;
use App\Models\Identity;
use App\Models\Structure;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IdentityReviewControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $agent;
    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        Artisan::call('vendor:publish', [
            '--provider' => 'Spatie\\Permission\\PermissionServiceProvider',
            '--tag' => 'migrations',
            '--force' => true,
        ]);

        try {
            DB::statement('SELECT 1 FROM roles');
        } catch (\Throwable $e) {
            Artisan::call('migrate');
        }

        Role::firstOrCreate(['name' => 'tech_one', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tech_two', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tech_three', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'superviseur', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);

        $this->agent = User::factory()->create(['status' => 'ACTIVE']);
        $this->agent->assignRole('tech_one');

        $this->supervisor = User::factory()->create(['status' => 'ACTIVE']);
        $this->supervisor->assignRole('superviseur');
    }

    private function actingAsAgent(?User $user = null): User
    {
        $user = $user ?? User::factory()->create(['status' => 'ACTIVE']);
        $user->assignRole('tech_one');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_filters_and_pagination(): void
    {
        $agent = $this->actingAsAgent();
        $me = $agent;
        $otherAgent = User::factory()->create();

        Identity::factory()->count(3)->create(['status' => 'PENDING', 'assigned_agent_id' => null]);
        Identity::factory()->count(2)->create(['status' => 'PENDING', 'assigned_agent_id' => $me->id]);
        Identity::factory()->count(1)->create(['status' => 'REJECTED']);
        Identity::factory()->count(1)->create(['status' => 'APPROVED', 'assigned_agent_id' => $otherAgent->id]);

        $resp = $this->getJson($this->api('/management/identity-reviews'));
        $resp->assertStatus(200)
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.per_page', 15)
            ->assertJsonStructure(['success', 'message', 'data' => ['data' => [['id', 'status', 'user_id']]]]);

        $resp2 = $this->getJson($this->api('/management/identity-reviews?assigned=false'));
        $resp2->assertStatus(200);
        $this->assertGreaterThanOrEqual(3, count($resp2->json('data.data')));

        $resp3 = $this->getJson($this->api('/management/identity-reviews?status=PENDING,REJECTED'));
        $resp3->assertStatus(200);
        $statuses = collect($resp3->json('data.data'))->pluck('status')->unique()->values()->all();
        $this->assertTrue(in_array('PENDING', $statuses) || in_array('REJECTED', $statuses));

        $resp4 = $this->getJson($this->api('/management/identity-reviews?per_page=2'));
        $resp4->assertStatus(200)->assertJsonPath('data.per_page', 2);
    }

    public function test_show_includes_structure_and_proof(): void
    {
        $this->actingAsAgent();
        $user = User::factory()->create();
        $identity = Identity::factory()->create(['user_id' => $user->id, 'proof' => json_encode(['foo' => 'bar'])]);
        Structure::factory()->create(['manager_id' => $user->id]);

        $resp = $this->getJson($this->api("/management/identity-reviews/{$identity->id}"));
        $resp->assertStatus(200)
            ->assertJsonPath('data.identity.id', $identity->id)
            ->assertJsonPath('data.proof.foo', 'bar')
            ->assertJsonStructure(['data' => ['structure']]);
    }

    public function test_claim_assigns_to_current_agent_or_conflict(): void
    {
        $agent = $this->actingAsAgent();
        $identity = Identity::factory()->create(['status' => 'PENDING', 'assigned_agent_id' => null]);

        $resp = $this->postJson($this->api("/management/identity-reviews/{$identity->id}/claim"));
        $resp->assertStatus(200)
            ->assertJsonPath('data.assigned_agent_id', $agent->id);

        $this->actingAsAgent(User::factory()->create());
        $resp2 = $this->postJson($this->api("/management/identity-reviews/{$identity->id}/claim"));
        $resp2->assertStatus(409);
    }

    public function test_approve_generates_npi_and_dispatches_email_and_tokens(): void
    {
        $this->actingAsAgent();
        $user = User::factory()->create(['npi' => null, 'email' => 'user@example.com']);
        $identity = Identity::factory()->create(['user_id' => $user->id, 'status' => 'PENDING']);

        $respAgent = $this->postJson($this->api("/management/identity-reviews/{$identity->id}/approve"), []);
        $respAgent->assertStatus(200)
            ->assertJsonPath('data.identity_id', $identity->id);
        $identity->refresh();
        $this->assertEquals('APPROVED_BY_AGENT', $identity->status);
        Bus::assertDispatched(SendEmailNotificationJob::class);

        $supervisor = User::factory()->create(['status' => 'ACTIVE']);
        $supervisor->assignRole('superviseur');
        Sanctum::actingAs($supervisor);

        $this->partialMock(\App\Http\Controllers\IdentityReviewController::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('register')->andReturn(['status' => true, 'has_user' => false]);
        });

        $respSup = $this->postJson($this->api("/management/identity-reviews/{$identity->id}/supervisor/approve"), []);
        $respSup->assertStatus(200)
            ->assertJsonPath('data.identity_id', $identity->id);

        $identity->refresh();
        $this->assertEquals('APPROVED', $identity->status);

        $user->refresh();
        $this->assertNotNull($user->npi);
        $rowAll = DB::table('password_resets')->where(['npi' => $user->npi, 'type' => 'all'])->first();
        $rowPin = DB::table('password_resets')->where(['npi' => $user->npi, 'type' => 'pin'])->first();
        $rowPassword = DB::table('password_resets')->where(['npi' => $user->npi, 'type' => 'password'])->first();
        $this->assertNotNull($rowAll);
        $this->assertNotNull($rowPin);
        $this->assertNotNull($rowPassword);

        Bus::assertDispatched(WelcomeUserJob::class);
    }

    public function test_supervisor_can_reject_after_agent_approval(): void
    {
        $this->actingAsAgent();
        $user = User::factory()->create(['email' => 'reject2@example.com']);
        $identity = Identity::factory()->create(['user_id' => $user->id, 'status' => 'PENDING']);

        $this->postJson($this->api("/management/identity-reviews/{$identity->id}/approve"))->assertStatus(200);
        $identity->refresh();
        $this->assertEquals('APPROVED_BY_AGENT', $identity->status);

        $supervisor = User::factory()->create(['status' => 'ACTIVE']);
        $supervisor->assignRole('superviseur');
        Sanctum::actingAs($supervisor);

        $payload = [
            'stage' => 'KYC',
            'reasons' => ['doc_invalid'],
            'comments' => 'Mismatch data',
        ];
        $resp = $this->postJson($this->api("/management/identity-reviews/{$identity->id}/supervisor/reject"), $payload);
        $resp->assertStatus(200);

        $identity->refresh();
        $this->assertEquals('REJECTED', $identity->status);
        Bus::assertDispatched(SendEmailNotificationJob::class);
    }

    #[Test]
    public function it_filters_identities_by_status_approved_by_agent()
    {
        $userPending = User::factory()->create();
        $userApprovedByAgent = User::factory()->create();
        $userApproved = User::factory()->create();
        $userRejected = User::factory()->create();

        Identity::factory()->create([
            'user_id' => $userPending->id,
            'status' => 'PENDING',
            'assigned_agent_id' => $this->agent->id,
        ]);

        $identityApprovedByAgent = Identity::factory()->create([
            'user_id' => $userApprovedByAgent->id,
            'status' => 'APPROVED_BY_AGENT',
            'assigned_agent_id' => $this->agent->id,
        ]);

        Identity::factory()->create([
            'user_id' => $userApproved->id,
            'status' => 'APPROVED',
            'assigned_agent_id' => $this->agent->id,
        ]);

        Identity::factory()->create([
            'user_id' => $userRejected->id,
            'status' => 'REJECTED',
            'assigned_agent_id' => $this->agent->id,
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson($this->api('/management/identity-reviews?status=APPROVED_BY_AGENT&assigned=true&per_page=10'));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'current_page',
                    'data' => [
                        '*' => [
                            'id',
                            'status',
                            'assigned_agent_id',
                            'user_id',
                            'user',
                            'structure',
                        ],
                    ],
                    'per_page',
                    'total',
                ],
            ]);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('APPROVED_BY_AGENT', $data[0]['status']);
        $this->assertEquals($this->agent->id, $data[0]['assigned_agent_id']);
        $this->assertEquals($identityApprovedByAgent->id, $data[0]['id']);
    }

    #[Test]
    public function it_filters_identities_by_multiple_statuses()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        Identity::factory()->create([
            'user_id' => $user1->id,
            'status' => 'PENDING',
        ]);

        Identity::factory()->create([
            'user_id' => $user2->id,
            'status' => 'APPROVED_BY_AGENT',
        ]);

        Identity::factory()->create([
            'user_id' => $user3->id,
            'status' => 'APPROVED',
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson($this->api('/management/identity-reviews?status=PENDING,APPROVED_BY_AGENT'));
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(2, $data);

        $statuses = collect($data)->pluck('status')->toArray();
        $this->assertContains('PENDING', $statuses);
        $this->assertContains('APPROVED_BY_AGENT', $statuses);
    }

    #[Test]
    public function it_filters_by_assignment_status()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Identity::factory()->create([
            'user_id' => $user1->id,
            'status' => 'PENDING',
            'assigned_agent_id' => $this->agent->id,
        ]);

        Identity::factory()->create([
            'user_id' => $user2->id,
            'status' => 'PENDING',
            'assigned_agent_id' => null,
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson($this->api('/management/identity-reviews?assigned=true'));
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertNotNull($data[0]['assigned_agent_id']);

        $response = $this->getJson($this->api('/management/identity-reviews?assigned=false'));
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertNull($data[0]['assigned_agent_id']);
    }

    #[Test]
    public function it_filters_by_type_and_level()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Identity::factory()->create([
            'user_id' => $user1->id,
            'status' => 'PENDING',
            'type' => 'IN_PERSON',
            'level' => 'ADVANCED',
        ]);

        Identity::factory()->create([
            'user_id' => $user2->id,
            'status' => 'PENDING',
            'type' => 'ONLINE',
            'level' => 'SIMPLE',
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson($this->api('/management/identity-reviews?type=IN_PERSON'));
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('IN_PERSON', $data[0]['type']);

        $response = $this->getJson($this->api('/management/identity-reviews?level=ADVANCED'));
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('ADVANCED', $data[0]['level']);
    }

    #[Test]
    public function it_searches_by_user_information()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'name' => 'John Doe',
            'phonenumber' => '+33123456789',
        ]);

        Identity::factory()->create([
            'user_id' => $user->id,
            'status' => 'PENDING',
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson($this->api('/management/identity-reviews?q=test@example.com'));
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));

        $response = $this->getJson($this->api('/management/identity-reviews?q=John'));
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));

        $response = $this->getJson($this->api('/management/identity-reviews?q=123456789'));
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    #[Test]
    public function it_filters_by_date_range()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $identity1 = Identity::factory()->create([
            'user_id' => $user1->id,
            'status' => 'PENDING',
        ]);
        $identity1->created_at = now()->subDay();
        $identity1->save();

        Identity::factory()->create([
            'user_id' => $user2->id,
            'status' => 'PENDING',
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson($this->api('/management/identity-reviews?from=' . now()->toDateString()));
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));

        $response = $this->getJson($this->api('/management/identity-reviews?to=' . now()->subDay()->toDateString()));
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    #[Test]
    public function it_handles_pagination_correctly()
    {
        $users = User::factory()->count(25)->create();

        foreach ($users as $user) {
            Identity::factory()->create([
                'user_id' => $user->id,
                'status' => 'PENDING',
            ]);
        }

        Sanctum::actingAs($this->agent);

        $response = $this->getJson($this->api('/management/identity-reviews?per_page=10&page=1'));
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(10, count($data['data']));
        $this->assertEquals(25, $data['total']);
        $this->assertEquals(1, $data['current_page']);
        $this->assertEquals(10, $data['per_page']);
    }

    #[Test]
    public function it_includes_structure_information()
    {
        $user = User::factory()->create();

        Identity::factory()->create([
            'user_id' => $user->id,
            'status' => 'PENDING',
        ]);

        $structure = Structure::factory()->create([
            'manager_id' => $user->id,
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson($this->api('/management/identity-reviews'));
        $response->assertStatus(200);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertNotNull($data[0]['structure']);
        $this->assertEquals($structure->id, $data[0]['structure']['id']);
    }

    #[Test]
    public function it_handles_invalid_status_gracefully()
    {
        $user = User::factory()->create();

        Identity::factory()->create([
            'user_id' => $user->id,
            'status' => 'PENDING',
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson($this->api('/management/identity-reviews?status=INVALID_STATUS'));
        $response->assertStatus(200);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('PENDING', $data[0]['status']);
    }

    #[Test]
    public function it_sorts_results_correctly()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $identity1 = Identity::factory()->create([
            'user_id' => $user1->id,
            'status' => 'PENDING',
        ]);

        $identity2 = Identity::factory()->create([
            'user_id' => $user2->id,
            'status' => 'PENDING',
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson($this->api('/management/identity-reviews'));
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEquals($identity2->id, $data[0]['id']);

        $response = $this->getJson($this->api('/management/identity-reviews?order_by=id&order_dir=asc'));
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEquals($identity1->id, $data[0]['id']);
    }
}
