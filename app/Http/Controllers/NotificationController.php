<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        //
        $data = $request->validate([
            "user_id" => "required|exists:users,id"
        ]);

        $user = User::find($data['user_id']);
        $notification = $user->notifications();
        return $notification->toResource();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    public function markUnread(Request $request){
        $data = $request->validate([
            "ids" => "required|array",
            "user_id" => "required|exists:users,id"
        ]);
        $ids = $request->input("ids", []);
        $user = User::find($data['user_id']);
        $notification = $user->notifications()
        ->whereIn("id", $ids)->update(['read_at' => null]);
        return ["status" => "successful"]; //$notification->toResource();
        // return $notification->toResource();
    }

    public function markRead(Request $request){
        try {
            $data = $request->validate([
                "ids" => "required|array",
                "user_id" => "required|exists:users,id"
            ]);
            $ids = $request->input("ids", []);
            $user = User::find($data['user_id']);
            $updated = $user->notifications()
            ->whereIn("id", $ids)->update(['read_at' => now()]);
            
            return ["status" => "successful"]; //$notification->toResource();
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //`
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
