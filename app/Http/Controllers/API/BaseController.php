<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class BaseController extends Controller
{
    /**
     * Success response method.
     *
     * @param $result
     * @param $message
     * @return \Illuminate\Http\Response
     */
    public function sendResponse($result, $message)
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data'    => $result,
        ];

        return response()->json($response, Response::HTTP_OK);
    }

    /**
     * Return error response.
     *
     * @param $error
     * @param array $errorMessages
     * @param int $code
     * @return \Illuminate\Http\Response
     */
    public function sendError($error, $errorMessages = [], $code = Response::HTTP_NOT_FOUND)
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if (!empty($errorMessages)) {
            $response['data'] = $errorMessages;
        }

        return response()->json($response, $code);
    }

    /**
     * Handle validation errors.
     *
     * @param Request $request
     * @param array $rules
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public function validateRequest(Request $request, array $rules)
    {
        return Validator::make($request->all(), $rules);
    }

    public function getInitials($name) {
        // Explode the name into words
        $words = explode(' ', $name);

        // Get the first character of each word
        $initials = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $initials .= strtoupper($word[0]);
            }
        }

        return $initials;
    }

    public function saveBase64Image(string $base64Image, string $folder): string
    {
        // Split the base64 string into two parts: the metadata and the actual data
        list($metadata, $data) = explode(',', $base64Image);

        // Extract the image type from the metadata (e.g., 'jpeg', 'png')
        preg_match('/data:image\/(\w+);base64/', $metadata, $matches);
        $imageType = $matches[1];

        // Decode the base64 data
        $imageData = base64_decode($data);

        // Generate a unique file name
        $fileName = uniqid() . '.' . $imageType;

        // Define the path where the image will be saved
        $filePath = $folder . '/' . $fileName;

        // Save the image to the specified folder within the storage/app/public directory
        Storage::disk('public')->put($filePath, $imageData);

        // Return the path where the image is saved
        return $filePath;
    }
}
