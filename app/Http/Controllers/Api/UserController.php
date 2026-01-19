<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Interfaces\UserInterface;

class UserController extends Controller
{
    private UserInterface $userRepository;

    public function __construct(UserInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function index()
    {
        $users = $this->userRepository->all();
        return ApiResponse::success($users, 'Users retrieved successfully');
    }

    public function show(int $id)
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return ApiResponse::error('User not found', 404);
        }

        return ApiResponse::success($user, 'User retrieved successfully');
    }
}
