<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Request;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TransactionController extends Controller
{



    public function index(HttpRequest $request)
    {
        $userId = Auth::id();

        // optional: allow client to set page size, with safe max
        $perPage = (int)$request->query('per_page', 15);
        $perPage = $perPage > 100 ? 100 : max(1, $perPage);

        $query = Transaction::where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        // eager load relationships if needed: ->with('merchant', 'cards')
        $paginated = $query->paginate($perPage);

        return TransactionResource::collection($paginated);
    }

    /**
     * Store a newly created transaction.
     */
    public function store(HttpRequest $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric',
            'currency' => 'required|string|max:8',
            'type' => 'required|string', // adapt rules to your schema
            'reference' => 'nullable|string|unique:transactions,reference',
            // add other fields/validation as needed
        ]);

        $data['user_id'] = Auth::id();

        $transaction = Transaction::create($data);

        return (new TransactionResource($transaction))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     * Uses route model binding and ensures ownership.
     */
    public function show(Transaction $transaction)
    {
        error_log("TransactionController@show called with transaction ID: " . $transaction->id);
        
        // ensure the authenticated user owns this transaction
        if ($transaction->user_id !== Auth::id()) {
            abort(Response::HTTP_FORBIDDEN, 'You are not authorized to view this transaction.');
        }

        return new TransactionResource($transaction);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(HttpRequest $request, Transaction $transaction)
    {
        if ($transaction->user_id !== Auth::id()) {
            abort(Response::HTTP_FORBIDDEN, 'You are not authorized to update this transaction.');
        }

        $data = $request->validate([
            'amount' => 'sometimes|numeric',
            'currency' => 'sometimes|string|max:8',
            'type' => 'sometimes|string',
            // other updatable fields; be careful with reference uniqueness if allowed
        ]);

        $transaction->update($data);

        return new TransactionResource($transaction);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Transaction $transaction)
    {
        if ($transaction->user_id !== Auth::id()) {
            abort(Response::HTTP_FORBIDDEN, 'You are not authorized to delete this transaction.');
        }

        $transaction->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
