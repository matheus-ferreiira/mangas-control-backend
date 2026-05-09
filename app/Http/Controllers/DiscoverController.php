<?php

namespace App\Http\Controllers;

use App\Services\DiscoverService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class DiscoverController extends Controller
{
    use ApiResponse;

    public function __construct(private DiscoverService $discoverService) {}

    public function home(): JsonResponse
    {
        $data = $this->discoverService->getHome(auth()->id());

        return $this->success($data);
    }
}
