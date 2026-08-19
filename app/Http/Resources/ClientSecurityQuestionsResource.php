<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientSecurityQuestionsResource extends JsonResource
{
    /**
     * @return array{configure: mixed, questions: mixed}
     */
    public function toArray(Request $request): array
    {
        /** @var array{configure: mixed, questions: mixed} $payload */
        $payload = $this->resource;

        return [
            'configure' => $payload['configure'],
            'questions' => $payload['questions'],
        ];
    }
}
