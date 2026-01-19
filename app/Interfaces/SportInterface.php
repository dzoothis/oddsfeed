<?php

namespace App\Interfaces;

interface SportInterface
{
    public function all();
    public function findById(int $id);
    public function findByName(string $name);
}
