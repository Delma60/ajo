<?php

namespace App\Classes;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class GroupPayoutService
{
    /**
     * Accepts either:
     *  - a JsonResource (e.g. new GroupResource($group))
     *  - OR an array payload (resolved resource) with keys like 'members' => [...]
     *
     * Returns a Collection of selected member arrays (each member is an associative array).
     *
     * @param JsonResource|array $groupResourceOrArray
     * @param int $count
     * @return Collection
     */
    public function determineRecipient($groupResourceOrArray, int $count = 1): Collection
    {
        // normalize to array payload
        $payload = $this->resolvePayload($groupResourceOrArray);
        // Log::info(["members" =>$payload['members']]);

        // read payout order from meta or fallback
        $order = Str::lower(data_get($payload, 'payout_order', data_get($payload, 'meta.payout_order', 'rotational')));

        $members = collect(data_get($payload, 'members', []));

        // eligibility: filter members whose pivot->paid_at is null OR paid_at key missing
        $eligible = $members->filter(function ($member) {
            // check both 'pivot.paid_at' and top-level 'paid_at' for defensive handling
            $paidAt = data_get($member, 'pivot.paid_at', data_get($member, 'paid_at', null));
            return $paidAt === null;
        })->values();

        switch ($order) {
            // case 'bidding':
            // case 'bid':
            //     return $this->selectByBidding($payload, $eligible, $count);

            case 'random':
            case 'shuffle':
                return $this->selectRandom($payload, $eligible, $count);

            case 'rotational':
            default:
                return $this->selectRotational($payload, $eligible, $count);
        }
    }

    /**
     * Resolve JsonResource or array into an array payload.
     */
    protected function resolvePayload($resourceOrArray): array
    {
        // if a JsonResource (GroupResource), call toArray(request())
        if ($resourceOrArray instanceof JsonResource) {
            // pass the current request so whenLoaded() checks work correctly
            return $resourceOrArray->toArray(request());
        }

        // if object with toArray method (just in case)
        if (is_object($resourceOrArray) && method_exists($resourceOrArray, 'toArray')) {
            return $resourceOrArray->toArray();
        }

        // otherwise assume it's an array-like
        return is_array($resourceOrArray) ? $resourceOrArray : (array) $resourceOrArray;
    }

    /**
     * Rotational selection: sort strictly by joined_at ascending.
     *
     * Expects $members to be a Collection of member arrays.
     */
    protected function selectRotational(array $groupPayload, Collection $members, int $count): Collection
    {
        if ($members->isEmpty()) {
            return collect([]);
        }

        $ordered = $members->sortBy(function ($member) {
            // prefer pivot.joined_at; fallback to joined_at or null -> send to end
            $joined = data_get($member, 'pivot.joined_at', data_get($member, 'joined_at', null));
            if ($joined) {
                try {
                    return Carbon::parse($joined)->getTimestamp();
                } catch (\Throwable $e) {
                    return PHP_INT_MAX;
                }
            }
            return PHP_INT_MAX;
        })->values();

        return $ordered->take($count)->values();
    }

    /**
     * Random selection from eligible members.
     */
    protected function selectRandom(array $groupPayload, Collection $members, int $count): Collection
    {
        if ($members->isEmpty()) return collect([]);
        return $members->shuffle()->take($count)->values();
    }

    /**
     * Bidding selection: expects bids available in payload['bids'] or fallback behavior.
     * If no bids, falls back to rotational.
     *
     * Each bid entry expected to have 'user_id', 'amount', 'created_at'.
     */
    // protected function selectByBidding(array $groupPayload, Collection $members, int $count): Collection
    // {
    //     // try to find bids in the resolved payload first
    //     $bids = collect(data_get($groupPayload, 'bids', []));

    //     // if no bids in payload, and if you want DB fallback you can query your Bid model here.
    //     if ($bids->isEmpty() && class_exists(\App\Models\Bid::class)) {
    //         $bids = \App\Models\Bid::where('group_id', data_get($groupPayload, 'id'))
    //             ->whereIn('user_id', $members->pluck('id')->all())
    //             ->orderByDesc('amount')
    //             ->orderBy('created_at')
    //             ->get()
    //             ->map(function ($b) {
    //                 return [
    //                     'user_id' => $b->user_id,
    //                     'amount' => $b->amount,
    //                     'created_at' => (string) ($b->created_at ?? null),
    //                 ];
    //             });
    //     }

    //     if ($bids->isEmpty()) {
    //         // fallback to rotation
    //         return $this->selectRotational($groupPayload, $members, $count);
    //     }

    //     // pick top bid per user
    //     $topPerUser = $bids->groupBy('user_id')->map(function ($userBids) {
    //         return $userBids->sortByDesc('amount')->values()->first();
    //     })->values()->sortByDesc('amount')->values();

    //     $selected = collect();
    //     foreach ($topPerUser as $bid) {
    //         if ($selected->count() >= $count) break;
    //         $user = $members->firstWhere('id', $bid['user_id']);
    //         if ($user) $selected->push($user);
    //     }

    //     // fill remaining by rotational if needed
    //     if ($selected->count() < $count) {
    //         $remaining = $count - $selected->count();
    //         $left = $members->whereNotIn('id', $selected->pluck('id'));
    //         $rot = $this->selectRotational($groupPayload, $left, $remaining);
    //         $selected = $selected->merge($rot);
    //     }

    //     return $selected->values();
    // }
}
