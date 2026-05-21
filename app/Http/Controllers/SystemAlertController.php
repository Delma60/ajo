<?php

namespace App\Http\Controllers;

use App\Models\SystemAlert;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSystemAlertRequest;
use App\Http\Requests\UpdateSystemAlertRequest;
use Illuminate\Http\Request;

class SystemAlertController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSystemAlertRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(SystemAlert $systemAlert)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SystemAlert $systemAlert)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSystemAlertRequest $request, SystemAlert $systemAlert)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SystemAlert $systemAlert)
    {
        //
    }

    // app/Http/Controllers/SystemAlertController.php

    // ... standard resource methods ...

    public function summary(Request $request)
    {
        // Return summary stats for alerts
    }

    public function resolve(Request $request, SystemAlert $systemAlert)
    {
        // Logic to mark a single alert as resolved
    }

    public function markRead(Request $request, SystemAlert $systemAlert)
    {
        // Logic to mark a single alert as read
    }

    public function resolveAll(Request $request)
    {
        // Logic to resolve all alerts for a user/system
    }

    public function markAllRead(Request $request)
    {
        // Logic to mark all alerts as read
    }
}
