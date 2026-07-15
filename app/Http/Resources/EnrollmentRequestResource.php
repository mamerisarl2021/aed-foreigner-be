<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EnrollmentRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $docs = $this->documents ?? [];

        return [
            'id' => $this->id,
            'email' => $this->email,
            'phonenumber' => $this->phonenumber,
            'kyc_data' => $this->kyc_data,
            'liveness' => $this->liveness,
            'similarity' => $this->similarity,
            'risk_score' => $this->risk_score,
            'analysis_details' => $this->analysis_details,
            'status' => $this->status,
            'type' => $this->type,

            'assigned_agent_id' => $this->assigned_agent_id,
            'reject_stage' => $this->reject_stage,
            'reject_reasons' => $this->reject_reasons,
            'review_comments' => $this->review_comments,

            'visio_notes' => $this->visio_notes,
            'visio_requested_at' => $this->visio_requested_at,
            'visio_completed_at' => $this->visio_completed_at,
            'returned_at' => $this->returned_at,
            'return_reasons' => $this->return_reasons,
            'sla_deadline_at' => $this->sla_deadline_at,
            'sla_alert_level' => $this->sla_alert_level,

            'similar_enrollments' => $this->when(
                array_key_exists('similar_enrollments', $this->resource->getAttributes())
                    || isset($this->resource->similar_enrollments),
                $this->similar_enrollments ?? []
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'selfieUrl' => isset($docs['selfie']) && $docs['selfie'] ? Storage::cloud()->temporaryUrl($docs['selfie'], Carbon::now()->addDays(3)) : '',
            'rectoUrl' => isset($docs['recto']) && $docs['recto'] ? Storage::cloud()->temporaryUrl($docs['recto'], Carbon::now()->addDays(3)) : '',
            'versoUrl' => isset($docs['verso']) && $docs['verso'] ? Storage::cloud()->temporaryUrl($docs['verso'], Carbon::now()->addDays(3)) : '',
            'profileUrl' => isset($docs['profile']) && $docs['profile'] ? Storage::cloud()->temporaryUrl($docs['profile'], Carbon::now()->addDays(3)) : '',
        ];
    }
}
