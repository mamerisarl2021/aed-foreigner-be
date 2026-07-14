<?php

declare(strict_types=1);

namespace App\Http\Controllers\Singletons;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckTokenEligibilityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['message' => 'You can buy a token.', 'can_buy' => true]);
    }
}
