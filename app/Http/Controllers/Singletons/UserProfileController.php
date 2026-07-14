<?php

declare(strict_types=1);

namespace App\Http\Controllers\Singletons;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    /**
     * @return mixed
     */
    public function __invoke(Request $request)
    {
        return $request->user();
    }
}
