<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const CURRENT_STATUSES = [
        'AWAITING_CONTACT_VERIFICATION',
        'EN_ATTENTE_AGENT',
        'EN_COURS_AGENT',
        'EN_ATTENTE_RESPONSABLE',
        'EN_COURS_RESPONSABLE',
        'APPROUVEE',
        'REJETEE',
        'ENROLEE',
    ];

    /** @var list<string> */
    private const NEW_STATUSES = [
        'AWAITING_CONTACT_VERIFICATION',
        'EN_ATTENTE_AGENT',
        'EN_COURS_AGENT',
        'EN_ATTENTE_RESPONSABLE',
        'EN_COURS_RESPONSABLE',
        'A_CORRIGER',
        'APPROUVEE',
        'REJETEE',
        'ENROLEE',
    ];

    public function up(): void
    {
        Schema::create('enrolled_companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('identifiant', 32)->unique();
            $table->foreignUuid('enrollment_request_id')->unique()->constrained('enrollment_requests')->restrictOnDelete();
            $table->foreignUuid('manager_user_id')->constrained('users')->restrictOnDelete();
            $table->string('legal_name');
            $table->string('legal_form')->nullable();
            $table->string('country_of_incorporation');
            $table->string('registration_number', 100);
            $table->date('incorporation_date')->nullable();
            $table->string('headquarters_address', 500);
            $table->string('activity_sector');
            $table->string('legal_representative_name');
            $table->string('legal_representative_first_name');
            $table->string('company_email');
            $table->string('company_phone')->nullable();
            $table->json('documents')->nullable();
            $table->string('status', 32)->default('ACTIVE');
            $table->timestamp('approved_at');
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['registration_number', 'country_of_incorporation'], 'enrolled_companies_reg_country_idx');
            $table->index('status');
        });

        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->timestamp('correction_deadline_at')->nullable()->after('sla_alert_level');
            $table->timestamp('correction_reminder_sent_at')->nullable()->after('correction_deadline_at');
        });

        $this->setStatusEnum(self::NEW_STATUSES, 'EN_ATTENTE_AGENT');
    }

    public function down(): void
    {
        DB::table('enrollment_requests')
            ->where('status', 'A_CORRIGER')
            ->update(['status' => 'REJETEE']);

        $this->setStatusEnum(self::CURRENT_STATUSES, 'EN_ATTENTE_AGENT');

        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->dropColumn(['correction_deadline_at', 'correction_reminder_sent_at']);
        });

        Schema::dropIfExists('enrolled_companies');
    }

    /**
     * @param  list<string>  $statuses
     */
    private function setStatusEnum(array $statuses, string $default): void
    {
        $values = array_values(array_unique($statuses));

        Schema::table('enrollment_requests', function (Blueprint $table) use ($values, $default) {
            $table->enum('status', $values)->default($default)->nullable(false)->change();
        });
    }
};
