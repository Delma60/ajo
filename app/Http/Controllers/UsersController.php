<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Resources\UserResource;
use App\Services\PaymentService;

class UsersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::all();
        return UserResource::collection($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $data = $request->validate([
            "name" => "required|string",
            "email" => "required|email|unique:users,email",
            "phone" => "required|string|unique:users,phone",
            "referral_code" => 'nullable|string',
            "imageUrl" => "nullable|string",
            "password" => "required|string"
        ]);

        $user = User::create($data);
        return new UserResource($user
        ->load(["banks"]));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id, PaymentService $paymentService)
    {
        //
        $user = User::find($id);
        if(!$user) return response()->json([ "message" => 'User not found' ], 404);
        // PaymentService::;
        $paymentService->generateVirtualAccounts($user->toArray());
        return new UserResource($user
        ->load(["banks"]));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
        $data = $request->validate([
            "name" => "nullable|string",
            "email" => "nullable|email|unique:users,email",
            "phone" => "nullable|string|unique:users,phone",
            "referral_code" => 'nullable|string',
            "imageUrl" => "nullable|string",
        ]);

        $user = User::find($id);
        if(!$user) return response()->json([ "message" => 'User not found' ], 404);
        $user->update($data);

        return new UserResource($user);
        // ->toResource()
        // ->additional([
        //     'message' => "successfully"
        // ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
        $user = User::find($id);
        if(!$user) return response()->json([ "message" => 'User not found' ], 404);
        $user->delete();
        return [
            "message" => "successfully deleted"
        ];

    }
}
