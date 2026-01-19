<?php

namespace App\Repositories;

use App\Interfaces\SportInterface;
use App\Services\PinnacleService;

class SportRepository implements SportInterface
{
    private PinnacleService $pinnacleService;

    public function __construct(PinnacleService $pinnacleService)
    {
        $this->pinnacleService = $pinnacleService;
    }

    public function all()
    {
        return $this->pinnacleService->getSports();
    }

    public function findById(int $id)
    {
        $sports = $this->pinnacleService->getSports();
        if (!is_array($sports)) {
            return null;
        }

        foreach ($sports as $sport) {
            if (isset($sport['id']) && $sport['id'] === $id) {
                return $sport;
            }
        }

        return null;
    }

    public function findByName(string $name)
    {
        $sports = $this->pinnacleService->getSports();
        if (!is_array($sports)) {
            return null;
        }

        foreach ($sports as $sport) {
            if (isset($sport['name']) && strtolower($sport['name']) === strtolower($name)) {
                return $sport;
            }
        }

        return null;
    }
}
