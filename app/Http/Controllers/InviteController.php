<?php

namespace App\Http\Controllers;

use App\Events\InviteCreated;
use App\Events\InviteResponded;
use App\Models\Invite;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Notifications\GenericNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InviteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        //
         $user = $request->user();

    // Return invites where user is recipient OR where user is sender (optional)
        $invites = Invite::with(['group', 'sender', 'recipient'])
                    ->where(function($q) use ($user) {
                        $q->where('recipient_id', $user->id)
                        ->orWhere('sender_id', $user->id);
                    })
                    ->orderBy('created_at', 'desc')
                    ->get();

        return response()->json(['ok' => true, 'data' => $invites]);
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
    public function show(Invite $invite)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invite $invite)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Invite $invite)
    {
        //
    }

    /**
     * Search users on platform (used by admins when inviting).
     * GET /api/users/search?q=...
     */
    public function searchUsers(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:100'
        ]);

        $q = trim($request->get('q',''));
        $limit = (int) $request->get('limit', 25);

        $query = User::select('id','name','email','phone','image_url');

        if ($q !== '') {
            $query->where(function($w) use ($q) {
                $w->where('name','like', "%{$q}%")
                  ->orWhere('email','like', "%{$q}%")
                  ->orWhere('phone','like', "%{$q}%");
            });
        }

        $users = $query->limit($limit)->get();

        return response()->json(['ok' => true, 'data' => $users]);
    }

    /**
     * Admin invites an existing user to a group
     * POST /api/groups/{group}/invite
     * payload: recipient_id, role?, message?
     */
    public function inviteUser(Request $request, Group $group)
    {
        try {

            $user = $request->user();

            $data = $request->validate([
                'recipient_id' => 'required|exists:users,id',
                'role' => 'nullable|string',
                'message' => 'nullable|string|max:500',
            ]);

            // Check caller is admin (or owner) of group
            $isAdmin = $group->users()
                             ->where('users.id', $user->id)
                             ->wherePivot('role','admin')
                             ->exists();

            if (!$isAdmin) {
                return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
            }

            $recipientId = (int) $data['recipient_id'];

            // Prevent inviting existing member
            if ($group->users()->where('users.id', $recipientId)->exists()) {
                return response()->json(['ok' => false, 'message' => 'User is already a member of the group'], 400);
            }

            // Prevent duplicate pending invites
            $existing = Invite::where('group_id', $group->id)
            ->where('recipient_id', $recipientId)
            ->where('type','invite')
            ->where('status','pending')
            ->first();

            if ($existing) {
                return response()->json(['ok' => false, 'message' => 'An invite is already pending for this user'], 400);
            }

            $invite = null;
            DB::transaction(function() use ($group, $user, $data, $recipientId, &$invite) {
                $recipient = User::find($recipientId);
                $invite = Invite::create([
                    'group_id' => $group->id,
                    'sender_id' => $user->id,
                    'recipient_id' => $recipientId,
                    'type' => 'invite',
                    'status' => 'pending',
                    'role' => $data['role'] ?? 'member',
                    'message' => $data['message'] ?? null,
                    'token' => Str::random(40),
                ]);

                // hook for notifications (email/push) - add event/listener for InviteCreated
                event(new InviteCreated($invite));
                $recipient->notify(new GenericNotification([
                    'title' => "You've been invited",
                    'body' => "{$user->name} invited you to join {$group->name}",
                    "type" => 'invite',
                    "extra" => [
                        "groupId" => $group->id
                    ]
                ]));
            });

            return response()->json(['ok' => true, 'data' => $invite], 201);
        } catch (\Throwable $e) {
            Log::info($e);
            throw $e;
        }
    }

    /**
     * User requests to join a group
     * POST /api/groups/{group}/request
     */
    public function requestToJoin(Request $request, Group $group)
    {
        $user = $request->user();

        $data = $request->validate([
            'message' => 'nullable|string|max:500'
        ]);

        if ($group->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['ok' => false, 'message' => 'You are already a member of the group'], 400);
        }

        $existing = Invite::where('group_id', $group->id)
                         ->where('sender_id', $user->id)
                         ->where('type','request')
                         ->where('status','pending')
                         ->first();

        if ($existing) {
            return response()->json(['ok' => false, 'message' => 'You already have a pending request'], 400);
        }

        $invite = Invite::create([
            'group_id' => $group->id,
            'sender_id' => $user->id,
            'recipient_id' => $group->owner_id, // primary recipient is owner; admins can view all requests
            'type' => 'request',
            'status' => 'pending',
            'role' => 'member',
            'message' => $data['message'] ?? null,
            'token' => null,
        ]);

       event(new InviteCreated($invite));

        return response()->json(['ok' => true, 'data' => $invite], 201);
    }

    /**
     * Admin or recipient responds to an invite/request
     * POST /api/groups/{group}/invites/{invite}/respond
     * payload: action = accept|reject
     */
    public function respond(Request $request, Group $group, Invite $invite)
    {
        $user = $request->user();
        $request->validate([
            'action' => 'required|in:accept,reject'
        ]);

        if ($invite->group_id !== $group->id) {
            return response()->json(['ok' => false, 'message' => 'Invite does not belong to group'], 400);
        }

        // Admins can respond to all invites/requests; recipient can accept an invite targeted to them
        $isAdmin = $group->users()->where('users.id', $user->id)->wherePivot('role','admin')->exists();
        $isRecipient = $invite->recipient_id === $user->id;

        if (!$isAdmin && !$isRecipient) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        if ($invite->status !== 'pending') {
            return response()->json(['ok' => false, 'message' => 'Invite already handled'], 400);
        }

        $action = $request->input('action');

        DB::transaction(function() use ($invite, $action, $group) {
            if ($action === 'accept') {
                // Determine which user should be added
                $userToAdd = null;
                if ($invite->type === 'invite') {
                    // admin invited recipient -> add recipient
                    $userToAdd = $invite->recipient_id;
                } else { // request: sender requested to join -> add sender
                    $userToAdd = $invite->sender_id;
                }

                // Double-check: not already a member
                if (!$group->users()->where('users.id', $userToAdd)->exists()) {
                    // check max capacity if defined in group.meta->max_members
                    if (is_array($group->meta) && isset($group->meta['max_members'])) {
                        $max = (int) ($group->meta['max_members'] ?? 0);
                        $count = $group->users()->count();
                        if ($max > 0 && $count >= $max) {
                            throw new \Exception('Group is full');
                        }
                    }
                    $group->users()->attach($userToAdd, [
                        'role' => $invite->role ?? 'member',
                        'joined_at' => now(),
                    ]);
                }

                $invite->status = 'accepted';
                $invite->save();

            } else {
                $invite->status = 'rejected';
                $invite->save();
            }

            event(new InviteResponded($invite));
        });

        return response()->json(['ok' => true, 'data' => $invite->fresh()]);
    }

    /**
     * Get pending invites/requests for a group (admin)
     * GET /api/groups/{group}/invites/pending
     */
    public function pendingForGroup(Request $request, Group $group)
    {
        $user = $request->user();
        // $isAdmin = $group->users()->where('users.id', $user->id)->wherePivot('role','admin')->exists();

        // if (!$isAdmin) {
        //     return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        // }

        $items = Invite::with(['sender','recipient'])
                        ->where('group_id', $group->id)
                        ->where('status','pending')
                        ->orderBy('created_at','desc')
                        ->get();

        return response()->json(['ok' => true, 'data' => $items]);
    }
}
