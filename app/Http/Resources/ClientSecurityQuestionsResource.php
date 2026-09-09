<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DataTransferObjects\ClientSecurityQuestionsPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientSecurityQuestionsPayload
 */
class ClientSecurityQuestionsResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct(ClientSecurityQuestionsPayload::from($resource));
    }

    /**
     * @return array{configure: bool, questions: list<array{question: string}>}
     */
    public function toArray(Request $request): array
    {
        /** @var ClientSecurityQuestionsPayload $payload */
        $payload = $this->resource;

        return $payload->toArray();
    }
}
