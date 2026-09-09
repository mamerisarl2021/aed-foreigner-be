<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy AES-256-CBC IV (pre-random-IV ciphertext only)
    |--------------------------------------------------------------------------
    |
    | storeEncFile() prepends a random 16-byte IV. Older files were encrypted
    | with this fixed IV and have no prefix. Changing the default breaks
    | decrypt of those files; do not rotate it in place.
    |
    */
    'legacy_cbc_iv' => env('ENCRYPTION_LEGACY_CBC_IV', '4921a67c51de4c8b'),

];
