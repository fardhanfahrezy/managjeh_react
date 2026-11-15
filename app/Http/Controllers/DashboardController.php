<?php

namespace App\Http\Controllers;

use App\Services\DashboardAggregatorService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardAggregatorService $aggregator): Response
    {
        return Inertia::render('dashboard', $aggregator->aggregate($request->user()));
    }
}
