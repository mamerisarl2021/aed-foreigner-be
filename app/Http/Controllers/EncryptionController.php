<?php

namespace App\Http\Controllers;

use App\Traits\EncryptionTrait;

class EncryptionController extends Controller
{
    use EncryptionTrait;
    public function decryptAndDisplay(string $filename)
    {
        // Continue with file processing if the token is valid

        $base64EncodedContent = $this->getEncFile($filename, 'docs');
        // Decode the base64 content
        $fileContent = base64_decode($base64EncodedContent);

        // Use finfo to get file information
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $fileType = $finfo->buffer($fileContent);

        // Set the appropriate headers based on the detected file type
        $headers = [
            'Content-Type' => $fileType,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ];

        return response()->make($fileContent, 200, $headers);
    }
}
