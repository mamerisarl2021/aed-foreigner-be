<?php

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class CreateEmployeeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email|unique:users,email',
            'structure_id' => 'required|integer|exists:structures,id',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'role' => 'sometimes|string|in:EMPLOYEE,MANAGER_ASSISTANT,VIEWER',
            'message' => 'sometimes|string|max:500',
        ];
    }
}
