<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Enrollment\ForeignerFinalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

final class FinalisationController extends BaseController
{
    public function __construct(
        private readonly ForeignerFinalizationService $finalizationService,
    ) {}

    /**
     * @OA\Get(
     *      path="/api/v1/enrolements/finalisation",
     *      operationId="enrollmentFinalisationShow",
     *      tags={"Enrollment - Physique"},
     *      summary="Validate finalisation token and return UI payload",
     *      description="Diagram §4 — open link from invitation email.",
     *
     *      @OA\Parameter(
     *          name="token",
     *          in="query",
     *          required=true,
     *
     *          @OA\Schema(type="string")
     *      ),
     *
     *      @OA\Response(response=200, description="Demande éligible à la finalisation"),
     *      @OA\Response(response=400, description="Token expiré"),
     *      @OA\Response(response=404, description="Token ou demande introuvable")
     * )
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        return $this->respond($this->finalizationService->showByToken($request->input('token')));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/enrolements/{id}/finalisation",
     *      operationId="enrollmentFinalisationStore",
     *      tags={"Enrollment - Physique"},
     *      summary="Complete enrollment (password, PIN, security questions)",
     *      description="Diagram §4. TrustedX identity must already exist (APPROUVEE). Sets statut ENROLEE.",
     *
     *      @OA\Parameter(
     *          name="id",
     *          in="path",
     *          required=true,
     *          description="demande_id",
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"token", "password", "pin"},
     *
     *              @OA\Property(property="token", type="string"),
     *              @OA\Property(property="password", type="string", format="password", minLength=8),
     *              @OA\Property(property="pin", type="string", minLength=4, example="1234"),
     *              @OA\Property(
     *                  property="security_questions",
     *                  type="array",
     *
     *                  @OA\Items(
     *                      type="object",
     *
     *                      @OA\Property(property="question", type="string"),
     *                      @OA\Property(property="answer", type="string")
     *                  )
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Enrôlement finalisé (ENROLEE)"),
     *      @OA\Response(response=422, description="Demande non éligible")
     * )
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8',
            'pin' => 'required|string|min:4',
            'security_questions' => 'nullable|array',
        ]);

        return $this->respond($this->finalizationService->finalize(
            $id,
            $request->input('token'),
            $request->input('password'),
            $request->input('pin'),
            $request->input('security_questions'),
        ));
    }
}
