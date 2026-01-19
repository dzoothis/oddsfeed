<?php

namespace App\Repositories;

use App\Interfaces\UserInterface;
use App\Models\User;

class UserRepository implements UserInterface
{
    public function all()
    {
        return User::all();
    }

    public function find(int $id)
    {
        return User::find($id);
    }
}
