<?php

namespace App\Http\Responses;

class ApiResponse
{
    public static function success($data = null, $message = 'Success', $code = 200, $meta = null)
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta) {
            $response = array_merge($response, $meta);
        }

        return response()->json($response, $code);
    }

    public static function error($message = 'Error', $code = 400, $errors = null)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }
}
