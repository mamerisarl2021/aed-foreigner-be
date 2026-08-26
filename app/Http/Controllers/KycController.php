<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\ReadDocumentRequest;
use App\Http\Requests\Enrollment\VerifyDocumentRequest;
use App\Http\Requests\Enrollment\VerifyKycRequest;
use App\Models\EnrollmentRequest;
use App\Services\Enrollment\DocumentReadService;
use App\Services\Enrollment\KycVerificationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Enrollment - KYC')]
final class KycController extends BaseController
{
    public function __construct(
        private readonly KycVerificationService $kycVerification,
        private readonly DocumentReadService $documentRead,
    ) {}

    /**
     * Sync KYC verification (Document Reader + Face match)
     *
     * Diagram §2.3. Guest physique: both OTP channels must be verified first.
     * Authenticated ACTIVE client: OTP skipped; session is cached on the user. Personne morale
     * now uses POST /kyc/document/verify instead — document only, no liveness session.
     * When REGULA_MOCK=false: selfie + recto required; verso optional.
     * Optional liveness / liveness_transaction_id = Face liveness transaction id (not a client score).
     * Client similarity is ignored for the OK/KO gate; scores come from Face /api/match.
     * Success caches KYC session ~30 min for submit; data includes kyc_valid, risk_score, similarity.
     * Optional capture_le (ISO-8601 with timezone, e.g. 2026-08-17T14:30:00+01:00):
     * when the selfie / liveness was captured. Must be within the last 60 minutes
     * (and at most 5 minutes in the future). Stored on enrollment_requests.selfie_captured_at
     * and returned as analyse_kyc.selfie.capture_le. Defaults to verification time.
     * phonenumber: optional leading +, then 8–20 digits; spaces/dashes/parentheses allowed and stripped.
     */
    public function verify(VerifyKycRequest $request): JsonResponse
    {
        return $this->respond($this->kycVerification->verify($request));
    }

    /**
     * Sync identity document verification (Document Reader only)
     *
     * Étape 2 du parcours personne morale : le document d'identité du demandeur est lu et
     * contrôlé par Regula, **sans selfie, sans session de liveness et sans comparaison faciale**
     * (le visage a déjà été contrôlé lors de son enrôlement physique).
     * Authenticated ACTIVE client with an approved IN_PERSON identity (policy `submitMorale`).
     * When REGULA_MOCK=false: recto required; verso optional.
     * Success caches the KYC session ~30 min for `POST /enrolements/morales` (étape 3).
     * data: { kyc_valid, risk_score, similarity } — `risk_score` and `similarity` are null:
     * no face score is computed on this path.
     */
    public function verifyDocument(VerifyDocumentRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        $this->authorize('submitMorale', EnrollmentRequest::class);

        return $this->respond($this->kycVerification->verifyDocument($request, $user));
    }

    /**
     * Assisted document pre-read (OCR + image quality)
     *
     * Diagram §2.2, capture screen. Guest, no OTP gate: assists form pre-fill and
     * warns about an unusable photo before the KYC step. Nothing is persisted and
     * nothing is enforced — `POST /kyc/verify` remains the authoritative check and
     * replays the read with the same Regula scenario.
     * Runs the scenario configured by REGULA_DOCUMENT_SCENARIO (deployed value: FullAuth).
     * Always answers 200 when the request is well formed: an unreadable photo returns
     * `ok: false` with `quality_issues`, not an HTTP error.
     * data: { ok, document_name, fields, quality_issues, portrait }.
     * `quality_issues` are stable codes, not sentences — wording and language belong to
     * the client (same convention as `details.error` on /kyc/verify). Values:
     * IMAGE_GLARES, IMAGE_FOCUS, IMAGE_RESOLUTION, IMAGE_COLORNESS, PERSPECTIVE, BOUNDS,
     * PORTRAIT, BRIGHTNESS, OCCLUSION, QUALITY_UNKNOWN, UNREADABLE_DOCUMENT (photo the
     * user can retake), READER_UNAVAILABLE (Regula unreachable).
     * `fields` keys (all optional, present only when read): nom, prenoms, sexe (M|F),
     * date_naissance, date_expiration (YYYY-MM-DD), numero_piece, nationalite,
     * ville_naissance, pays_naissance. `portrait` is base64, no `data:` prefix.
     */
    public function readDocument(ReadDocumentRequest $request): JsonResponse
    {
        return $this->respond($this->documentRead->read($request));
    }
}
