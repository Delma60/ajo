<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BankController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Bank::all()->toResourceCollection();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            "user_id" => 'required|numeric|exists:users,id',
            "bank_name" => "required|string",
            "account_number" => "required|string",
            'account_name' => "required|string",
            'code' => "required|string",

        ]);

        $data['meta'] = [ "code" => $data['code'] ];
        $bank = Bank::create($data);
        return $bank->toResource();
    }

    /**
     * Display the specified resource.
     */
    public function show(Bank $bank)
    {
        //
        return $bank->toResource();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Bank $bank)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Bank $bank)
    {
        //
        $bank->delete();
        return [
            "message" => "Successful"
        ];
    }
}
