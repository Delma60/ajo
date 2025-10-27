<?php

namespace App\Http\Controllers;

use App\Models\Investment;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class InvestmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $i = Investment::all();
        return $i->toResourceCollection();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $data = $request->validate([
            "title" => "required|string",
            "subtitle" => "required|string",
            "description" => "required|string",
            "min_investment" => "required|numeric",
            "status" => "required|in:active,paused,closed,draft",
            "risk" => "required|in:low,medium,high",
            // "raised" => "required|numeric",
            "target" => "required|numeric",
            "apy" => "required|numeric",
            "duration" => "required|numeric",
            "start_date" => "required|date",
            "end_date" => "required|date",
        ]);

        $i = Investment::create($data);
        return $i->toResource();
    }

    /**
     * Display the specified resource.
     */
    public function show(Investment $i)
    {
        $i->load('investors');
        return $i->toResource();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Investment $investment)
    {
        //
        $data = $request->validate([
            "title" => "sometimes|string",
            "subtitle" => "sometimes|string",
            "description" => "sometimes|string",
            "min_investment" => "sometimes|numeric",
            "status" => "sometimes|in:active,paused,closed,draft",
            "risk" => "sometimes|in:low,medium,high",
            // "raised" => "sometimes|numeric",
            "target" => "sometimes|numeric",
            "apy" => "sometimes|numeric",
            "duration" => "sometimes|numeric",
            "start_date" => "sometimes|date",
            "end_date" => "sometimes|date",
        ]);
        $investment->update($data);
        return $investment->toResource();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Investment $investment)
    {
        //
        $investment->delete();
        return [
            'message' => "successfully deleted"
        ];
    }

    /**
     * Invest in an investment/project.
     * Body: { amount }
     */
    public function invest(Request $request, Investment $i)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        $user = $request->user() ?? User::find($request->input('user_id'));
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // basic checks
        if (($i->status ?? 'draft') !== 'active') {
            return response()->json(['error' => 'This investment is not active'], 400);
        }

        $amount = (float) $data['amount'];
        $min = (float) ($i->min_investment ?? 0);
        if ($amount < $min) {
            return response()->json(['error' => "Amount must be at least {$min}"], 400);
        }

        // prevent overfunding beyond target
        if (!is_null($i->target) && ($i->raised ?? 0) + $amount > $i->target) {
            return response()->json(['error' => 'Investment would exceed project target'], 400);
        }

        try {
            $tx = null;
            DB::transaction(function () use ($user, $i, $amount, &$tx) {
                // create a simple transaction record for audit/history
                $tx = Transaction::create([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'currency' => 'NGN',
                    'type' => Transaction::TYPE_CHARGE,
                    'direction' => Transaction::DIRECTION_DEBIT,
                    'status' => Transaction::STATUS_SUCCESS,
                    'meta' => [
                        'investment_id' => $i->id,
                        'investment_name' => $i->title,
                    ],
                ]);

                // update or insert pivot record
                $existing = DB::table('investment_user')
                    ->where('investment_id', $i->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($existing) {
                    DB::table('investment_user')
                        ->where('id', $existing->id)
                        ->update([
                            'amount' => ($existing->amount ?? 0) + $amount,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('investment_user')->insert([
                        'investment_id' => $i->id,
                        'user_id' => $user->id,
                        'amount' => $amount,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // update raised amount on investment
                $i->raised = (float) ($i->raised ?? 0) + $amount;
                $i->save();
            });

            return response()->json([
                'ok' => true,
                'transaction' => $tx,
                'invested' => $amount,
                'raised' => $i->raised,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Check current user's investment for a project.
     */
    public function check(Request $request, Investment $i)
    {
        $user = $request->user() ?? User::find($request->input('user_id'));
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $row = DB::table('investment_user')
            ->where('investment_id', $i->id)
            ->where('user_id', $user->id)
            ->first();

        return response()->json([
            'ok' => true,
            'invested' => (bool) $row,
            'amount' => $row ? (float) $row->amount : 0.0,
            'investment' => $i->toArray(),
        ]);
    }
}
