<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\SendExpoSdkPushJob;
use App\Models\ExpoPushToken;
use ExpoSDK\ExpoMessage;
use Illuminate\Http\Request;

class ExpoPushController extends Controller
{
    public function sendNow(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'body' => 'required|string',
            'tokens' => 'nullable|array',
        ]);

        $tokens = $request->input('tokens', []);
        if (empty($tokens)) {
            $tokens = ExpoPushToken::pluck('token')->toArray();
        }

        $messages = array_map(function ($t) use ($request) {
            return new ExpoMessage([
                'to' => $t,
                'title' => $request->title,
                'body' => $request->body,
                'data' => $request->input('data', []),
            ]);
        }, $tokens);

        // dispatch a queued job for large volumes; for small tests you can call service directly
        SendExpoSdkPushJob::dispatch($messages);

        return response()->json(['queued' => true]);
    }
}
