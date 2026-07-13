<?php

namespace App\Http\Requests\Management;

use App\Http\Requests\ApiFormRequest;

class UpdateAttachmentStatusRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Un ou plusieurs des champs renseignés sont invalides.';
    }

    public function rules(): array
    {
        return [
            'attachments' => 'required|array',
            'attachments.*.id' => 'required|integer|exists:attachments,id',
            'attachments.*.status' => 'required|in:SENT,VALIDATED,REJECTED',
            'attachments.*.message' => 'required_if:attachments.*.status,REJECTED|string',
        ];
    }
}
