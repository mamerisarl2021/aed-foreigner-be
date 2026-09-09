<?php

declare(strict_types=1);

namespace App\Http\Controllers\Singletons;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Configuration\ShowConfigurationRequest;
use App\Services\Configuration\PublicConfigurationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Infrastructure')]
class ConfigurationController extends BaseController
{
    public function __construct(
        private readonly PublicConfigurationService $configuration,
    ) {}

    /**
     * Public OIDC bootstrap (TrustedX + staff Keycloak)
     *
     * No token. Independent of KEYCLOAK_ENABLED. Does not expose TX_CLIENT_SECRET.
     */
    public function __invoke(ShowConfigurationRequest $request): JsonResponse
    {
        return $this->sendResponse(null, $this->configuration->publicPayload());
    }
}
