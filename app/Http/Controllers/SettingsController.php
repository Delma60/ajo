<?php

namespace App\Http\Controllers;

use App\Models\Settings;
use App\Http\Requests\StoreSettingsRequest;
use App\Http\Requests\UpdateSettingsRequest;
use Illuminate\Http\Request;

class SettingsController extends Controller
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
    public function store(StoreSettingsRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Settings $settings)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Settings $settings)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSettingsRequest $request, Settings $settings)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Settings $settings)
    {
        //
    }

    public function updateNotifications(Request $request)
    {
        $validated = $request->validate([
            'email_payouts' => 'boolean',
            'email_invites' => 'boolean',
            'email_marketing' => 'boolean',
            'push_activity' => 'boolean',
            'push_reminders' => 'boolean',
        ]);

        $request->user()->updateSetting('notifications', $validated);

        return response()->json(['message' => 'Notification preferences updated.']);
    }

    public function resetToDefaults(Request $request)
    {
        // Reset all settings to defaults
        settings()->resetToDefaults();
        return response()->json(['message' => 'All settings reset to defaults.']);
    }

    public function appSettings(Request $request)
    {
        $appSettings = settings()->all();
        return response()->json($appSettings);
    }

    
}
