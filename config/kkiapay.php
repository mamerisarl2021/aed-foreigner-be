<?php

declare(strict_types=1);

return [

    'public_key' => env('KKIA_PUBLIC_KEY'),

    'private_key' => env('KKIA_PRIVATE_KEY'),

    'secret' => env('KKIA_SECRET_KEY'),

    'sandbox' => filter_var(env('KKIA_SANDBOX', true), FILTER_VALIDATE_BOOL),

];
