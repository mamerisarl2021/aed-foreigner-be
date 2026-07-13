<?php

namespace App\Http\Controllers;

use App\Http\Requests\IdentityReview\RejectIdentityRequest;
use App\Services\IdentityReview\IdentityReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IdentityReviewController extends BaseController
{
    public function __construct(
        private readonly IdentityReviewService $identityReview,
    ) {}

    public function index(Request $request)
    {
        return $this->sendResponse(
            'Liste filtrée des demandes.',
            $this->identityReview->list($request)
        );
    }

    public function show(int $id)
    {
        return $this->respond($this->identityReview->show($id));
    }

    public function claim(int $id)
    {
        $agentId = Auth::id();
        if (! $agentId) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->identityReview->claim($id, $agentId));
    }

    public function approve(Request $request, int $id)
    {
        $agentId = Auth::id();
        if (! $agentId) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->identityReview->approve($id, $agentId));
    }

    public function supervisorApprove(Request $request, int $id)
    {
        $supervisorId = Auth::id();
        if (! $supervisorId) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->identityReview->supervisorApprove($id, $supervisorId));
    }

    public function supervisorReject(RejectIdentityRequest $request, int $id)
    {
        return $this->respond($this->identityReview->reject(
            $id,
            $request->input('stage'),
            $request->input('reasons'),
            $request->input('comments'),
            'APPROVED_BY_AGENT',
        ));
    }

    public function reject(RejectIdentityRequest $request, int $id)
    {
        return $this->respond($this->identityReview->reject(
            $id,
            $request->input('stage'),
            $request->input('reasons'),
            $request->input('comments'),
            'PENDING',
        ));
    }
}
