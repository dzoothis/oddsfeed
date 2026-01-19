<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'password' => 'required'
        ]);

        $admin = Admin::first();

        if ($admin && Hash::check($request->password, $admin->password)) {
            return response()->json(['success' => true]);
        }

        return response()->json(['error' => 'Invalid password'], 401);
    }
}