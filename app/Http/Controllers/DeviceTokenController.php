<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ExpoPushToken;

class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'platform' => 'nullable|string',
        ]);


        // If using auth: $userId = $request->user()->id;
        $userId = $request->user()?->id ?? null;
        error_log($request->token);

        ExpoPushToken::updateOrCreate(
            ['token' => $request->token],
            ['user_id' => $userId, 'platform' => $request->platform]
        );

        return response()->json(['ok' => true]);
    }
}
