<?php

namespace App\Http\Controllers;

use App\Models\VirtualBank;
use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class VirtualBankController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(VirtualBank $virtualBank)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, VirtualBank $virtualBank)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(VirtualBank $virtualBank)
    {
        //
    }

    public function generate(Request $request, PaymentService $paymentService){
        $request->validate([
            "user_id" => "required|exists:users,id",
            "amount" => "nullable|numeric|min:0"
        ]);
        return $paymentService->generateVirtualAccounts(["id" => $request->user_id, "amount" => $request->amount ?? 0]);
    }
}
