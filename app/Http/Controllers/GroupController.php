<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Transaction;
use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $g = Group::with(['users', 'owner', "transactions"])->get();
        return GroupResource::collection($g);

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                "name" => "required|string|min:8",
                "description" => "nullable|string",
                "goal" => 'required|numeric',
                "owner_id" => "required|exists:users,id",
                "frequency" => "required|in:daily,weekly,bi-weekly,monthly",
                "status" => "required|in:active,paused,closed",
                "start_date" => "nullable|date",
                "payout_order" => "required|in:rotational,random,bidding",
                "max_members" => "required|numeric",
                "contribution" => 'required|numeric',
                "is_private" => 'sometimes|boolean',
                "help" => 'sometimes|boolean',
            ]);

            // compute creation fee BEFORE creating anything
            $contribution = (float) ($data['contribution'] ?? 0);
            $maxMembers = (int) ($data['max_members'] ?? 0);
            $creationBase = $contribution * $maxMembers;
            $creationFee = round($creationBase * 0.05, 2);

            // eager-load owner and check balance first (pre-flight)
            $owner = User::findOrFail($data['owner_id']);
            if ($owner->available_wallet < $creationFee) {
                return response()->json([
                    'message' => "Insufficient wallet balance to cover group creation fee of {$creationFee}"
                ], 400);
            }

            $g = null;
            DB::transaction(function () use ($data, $creationFee, $owner, &$g) {
                $meta = [
                    "start_date" => $data['start_date'] ?? null,
                    "payout_order" => $data['payout_order'] ?? null,
                    "max_members" => $data['max_members'] ?? null,
                    "contribution" => $data['contribution'] ?? null,
                    "is_private" => !empty($data['is_private']),
                    "help" => !empty($data['help']),
                    "creation_fee" => $creationFee,
                ];

                // create the group
                $g = Group::create(array_merge($data, ["meta" => $meta]));

                // attach owner as admin
                $g->users()->attach($owner->id, [
                    'role' => "admin",
                    'joined_at' => now(),
                ]);

                // Charge the owner and create a transaction record (use your model methods)
                if ($creationFee > 0) {
                    // If your charge() method throws on insufficient balance, it's fine.
                    $owner->charge($creationFee, 'wallet', [
                        'note' => 'One-time group creation fee',
                        'group_id' => $g->id,
                        'type' => 'wallet'
                    ]);

                    Transaction::create([
                        'user_id' => $owner->id,
                        'group_id' => $g->id,
                        'amount' => $creationFee,
                        'fee' => 0,
                        'net_amount' => $creationFee,
                        'currency' => 'NGN',
                        'type' => Transaction::TYPE_CHARGE,
                        'direction' => Transaction::DIRECTION_DEBIT,
                        'method' => Transaction::METHOD_WALLET,
                        'status' => Transaction::STATUS_PENDING,
                        'meta' => [
                            'note' => 'One-time group creation fee',
                        ],
                    ]);
                }
            });

            if (!$g) {
                throw new \Exception('Failed to create group');
            }

            return new GroupResource($g->load(['users', 'owner', 'transactions']));
        } catch (\Throwable $e) {
        Log::error('Error creating group', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
        ]);

        // return a proper error response
        return response()->json(['message' => 'Failed to create group', 'error' => $e->getMessage()], 500);
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(Group $group)
    {
        return new GroupResource($group->load(['users', "owner", "transactions"]));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Group $group)
    {
        //
        $data = $request->validate([
            "name" => "sometimes|string",
            "description" => "sometimes|string",
            "goal" => 'sometimes|numeric',
            "owner_id" => "sometimes|exists:users,id",
            "frequency" => "sometimes|in:daily,weekly,bi-weekly,monthly",
            "status" => "sometimes|in:active,paused,closed",

        ]);
        $group->update($data);
        return new GroupResource($group->load(['users', "owner", "transactions"]));

    }

    public function addMember(Request $request, Group $group, $memberId){

        //code...
            $group->users()->attach($memberId, [
                'role' => $request->role,
                'joined_at' => now(),
            ]);


            return new GroupResource($group
                ->load("owner", "users"));

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Group $group)
    {
        //
        $group->delete();
        return [

            "message" => "Group deleted successfully"
        ];
    }

    /**
     * Member leaves a group.
     *
     * Route: POST /groups/{group}/leave
    */
    public function leave(Request $request, Group $group)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            // Ensure user is a member
            $member = $group->users()->where('users.id', $user->id)->first();
            if (!$member) {
                return response()->json(['message' => 'You are not a member of this group.'], 404);
            }

            $role = $member->pivot->role ?? null;

            // If user is an admin, ensure there's another admin to hand off to
            if ($role === 'admin') {
                $otherAdminsCount = $group->users()
                    ->wherePivot('role', 'admin')
                    ->where('users.id', '!=', $user->id)
                    ->count();

                if ($otherAdminsCount === 0) {
                    return response()->json([
                        'message' => 'You are the only admin. Transfer admin rights or delete the group before leaving.'
                    ], 400);
                }
            }

            DB::transaction(function () use ($group, $user) {
                // detach membership
                $group->users()->detach($user->id);

                // If the leaving user was the owner, transfer ownership to the first other admin (if present)
                if ($group->owner_id == $user->id) {
                    $newOwner = $group->users()->wherePivot('role', 'admin')->first();
                    if ($newOwner) {
                        $group->owner_id = $newOwner->id;
                        $group->save();
                    }
                }
            });

            return response()->json(['message' => 'You have left the group.'], 200);
        } catch (\Throwable $e) {
            Log::error('Error leaving group', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'group_id' => $group->id ?? null,
                'user_id' => $request->user()->id ?? null,
            ]);

            return response()->json(['message' => 'Failed to leave group', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove a member from the group.
     * Route example: DELETE /groups/{group}/members/{member}
     */
    public function removeMember(Request $request, Group $group, $memberId)
    {
        try {
            $actor = $request->user();
            if (!$actor) return response()->json(['message' => 'Unauthenticated'], 401);

            // Ensure actor is admin of this group
            $isAdmin = $group->users()
                ->where('users.id', $actor->id)
                ->wherePivot('role', 'admin')
                ->exists();

            if (!$isAdmin) {
                return response()->json(['message' => 'Forbidden: only admins can remove members.'], 403);
            }

            // Cannot remove a non-member
            $member = $group->users()->where('users.id', $memberId)->first();
            if (!$member) {
                return response()->json(['message' => 'Member not found in this group.'], 404);
            }

            // If removing an admin, ensure at least one other admin remains
            $role = $member->pivot->role ?? null;
            if ($role === 'admin') {
                $otherAdminsCount = $group->users()
                    ->wherePivot('role', 'admin')
                    ->where('users.id', '!=', $memberId)
                    ->count();

                if ($otherAdminsCount === 0) {
                    return response()->json([
                        'message' => 'Cannot remove the only admin. Assign another admin first.'
                    ], 400);
                }
            }

            // If member to remove is owner, transfer ownership first (attempt)
            if ($group->owner_id == $memberId) {
                $newOwner = $group->users()
                    ->wherePivot('role', 'admin')
                    ->where('users.id', '!=', $memberId)
                    ->first();

                if (!$newOwner) {
                    return response()->json([
                        'message' => 'Group owner cannot be removed unless ownership is transferred.'
                    ], 400);
                }

                $group->owner_id = $newOwner->id;
                $group->save();
            }

            DB::transaction(function () use ($group, $memberId) {
                $group->users()->detach($memberId);
            });

            // OPTIONAL: notify user via database notification (implement Notification class)
            // $member->notify(new \App\Notifications\RemovedFromGroup($group));

            return response()->json(['message' => 'Member removed successfully.'], 200);
        } catch (\Throwable $e) {
            Log::error('Error removing member', [
                'message' => $e->getMessage(),
                'group_id' => $group->id ?? null,
                'member_id' => $memberId,
                'actor_id' => $request->user()->id ?? null,
            ]);
            return response()->json(['message' => 'Failed to remove member', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Promote a member to admin.
     * Route example: POST /groups/{group}/members/{member}/promote
     */
    public function promoteMember(Request $request, Group $group, $memberId)
    {
        try {
            $actor = $request->user();
            if (!$actor) return response()->json(['message' => 'Unauthenticated'], 401);

            // actor must be admin
            $isAdmin = $group->users()
                ->where('users.id', $actor->id)
                ->wherePivot('role', 'admin')
                ->exists();

            if (!$isAdmin) {
                return response()->json(['message' => 'Forbidden: only admins can promote members.'], 403);
            }

            // ensure target is member of group
            $member = $group->users()->where('users.id', $memberId)->first();
            if (!$member) {
                // Option: attach as admin if you prefer
                return response()->json(['message' => 'Target user is not a member of this group.'], 404);
            }

            // update pivot role to admin
            $group->users()->updateExistingPivot($memberId, ['role' => 'admin']);

            // OPTIONAL: DB notification to user
            // $member->notify(new \App\Notifications\PromotedToAdmin($group));

            return response()->json(['message' => 'Member promoted to admin.'], 200);
        } catch (\Throwable $e) {
            Log::error('Error promoting member', [
                'message' => $e->getMessage(),
                'group_id' => $group->id ?? null,
                'member_id' => $memberId,
                'actor_id' => $request->user()->id ?? null,
            ]);
            return response()->json(['message' => 'Failed to promote member', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Demote an admin to member.
     * Route example: POST /groups/{group}/members/{member}/demote
     */
    public function demoteMember(Request $request, Group $group, $memberId)
    {
        try {
            $actor = $request->user();
            if (!$actor) return response()->json(['message' => 'Unauthenticated'], 401);

            // actor must be admin
            $isAdmin = $group->users()
                ->where('users.id', $actor->id)
                ->wherePivot('role', 'admin')
                ->exists();

            if (!$isAdmin) {
                return response()->json(['message' => 'Forbidden: only admins can demote members.'], 403);
            }

            // ensure target exists in group
            $member = $group->users()->where('users.id', $memberId)->first();
            if (!$member) {
                return response()->json(['message' => 'Target user is not a member of this group.'], 404);
            }

            // cannot demote the owner
            if ($group->owner_id == $memberId) {
                return response()->json(['message' => 'Cannot demote the owner. Transfer ownership first.'], 400);
            }

            // ensure we won't remove the last admin
            $role = $member->pivot->role ?? null;
            if ($role === 'admin') {
                $otherAdminsCount = $group->users()
                    ->wherePivot('role', 'admin')
                    ->where('users.id', '!=', $memberId)
                    ->count();

                if ($otherAdminsCount === 0) {
                    return response()->json([
                        'message' => 'Cannot demote the only admin. Assign another admin first.'
                    ], 400);
                }
            } else {
                return response()->json(['message' => 'Target user is not an admin.'], 400);
            }

            // perform demotion
            $group->users()->updateExistingPivot($memberId, ['role' => 'member']);

            // OPTIONAL: notify user
            // $member->notify(new \App\Notifications\DemotedFromAdmin($group));

            return response()->json(['message' => 'Member demoted to member.'], 200);
        } catch (\Throwable $e) {
            Log::error('Error demoting member', [
                'message' => $e->getMessage(),
                'group_id' => $group->id ?? null,
                'member_id' => $memberId,
                'actor_id' => $request->user()->id ?? null,
            ]);
            return response()->json(['message' => 'Failed to demote member', 'error' => $e->getMessage()], 500);
        }
    }


}
