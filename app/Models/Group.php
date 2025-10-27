<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    //
    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'nextDue',
        'goal',
        'saved',
        'frequency',
        'status',
        'meta'
    ];

    protected $casts = [
        'meta' => 'array',
        'nextDue' => 'datetime',
        'goal' => 'float',
        'saved' => 'float',
    ];

    protected $appends = ["is_private", "group_transaction", 'next_payout', 'next_payout_human'];


    // protected $with = ["owner", 'users'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function cycles () {
        return $this->hasMany(GroupCycle::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'group_user')
                    ->withPivot(['role', 'joined_at', 'contributed', 'last_payment_at', 'status'])
                    ->withTimestamps();
    }

    public function getNextPayoutAttribute()
    {
        return $this->calculateNextPayout();
    }

    /**
     * Human readable next payout (e.g. "in 3 days")
     */
    public function getNextPayoutHumanAttribute()
    {
        $next = $this->calculateNextPayout();
        return $next ? $next->diffForHumans() : null;
    }

    /**
     * Core calculation: return a Carbon date for the next payout strictly > now()
     *
     * Logic:
     * - base date priority: nextDue -> meta.start_date -> created_at
     * - increment base by frequency until it's in future
     *
     * @param Carbon|null $from optional "now" reference (for tests)
     * @return Carbon|null
     */
    // in App\Models\Group

    public function calculateNextPayout(?Carbon $from = null)
    {
        $now = $from ?? Carbon::now();

        // Anchor: prefer meta.start_date, fallback to created_at
        $anchor = null;
        if (!empty($this->meta['start_date'])) {
            try {
                $anchor = Carbon::parse($this->meta['start_date'])->startOfDay();
            } catch (\Exception $e) {
                // fallback to created_at below
            }
        }
        if (!$anchor && $this->created_at) {
            $anchor = $this->created_at->copy()->startOfDay();
        }
        if (!$anchor) {
            $anchor = Carbon::now()->startOfDay();
        }

        $frequency = strtolower($this->frequency ?? 'monthly');
        $frequency = str_replace('_', '-', $frequency); // accept bi_weekly, etc.

        // helper: add N intervals to anchor
        $addInterval = function (Carbon $date, int $n) use ($frequency) : Carbon {
            $d = $date->copy();
            if ($n <= 0) {
                return $d;
            }
            switch ($frequency) {
                case 'daily':
                    return $d->addDays($n);
                case 'weekly':
                    return $d->addWeeks($n);
                case 'bi-weekly':
                case 'biweekly':
                    return $d->addWeeks(2 * $n);
                case 'monthly':
                default:
                    return $d->addMonths($n);
            }
        };

        // If now is before anchor: next payout = end of first cycle (anchor + 1 interval)
        if ($now->lt($anchor)) {
            return $addInterval($anchor, 1);
        }

        // Compute how many whole intervals have elapsed since anchor
        $elapsed = 0;
        switch ($frequency) {
            case 'daily':
                $elapsed = (int) floor($anchor->diffInDays($now) / 1);
                break;
            case 'weekly':
                $elapsed = (int) floor($anchor->diffInWeeks($now) / 1);
                break;
            case 'bi-weekly':
            case 'biweekly':
                $elapsed = (int) floor($anchor->diffInWeeks($now) / 2);
                break;
            case 'monthly':
            default:
                // diffInMonths counts whole month boundaries
                $elapsed = (int) floor($anchor->diffInMonths($now));
                break;
        }

        // Next payout happens at anchor + (elapsed + 1) intervals
        $next = $addInterval($anchor, $elapsed + 1);

        // Safety: if something odd happens, keep a cap (shouldn't be needed normally)
        $cap = 1000;
        if ($elapsed > $cap) {
            return null;
        }

        return $next;
    }

     /**
     * Returns the current period start/end (the cycle that contains now).
     * Useful for checking whether a member has contributed in the current cycle.
     *
     * @return array{start: \Carbon\Carbon, end: \Carbon\Carbon}
     */
    public function currentPeriod()
    {
        $now = Carbon::now();

        // Anchor: prefer meta.start_date then created_at
        if (!empty($this->meta['start_date'])) {
            try {
                $anchor = Carbon::parse($this->meta['start_date'])->startOfDay();
            } catch (\Exception $e) {
                $anchor = $this->created_at ? $this->created_at->copy()->startOfDay() : Carbon::now()->startOfDay();
            }
        } else {
            $anchor = $this->created_at ? $this->created_at->copy()->startOfDay() : Carbon::now()->startOfDay();
        }

        $frequency = strtolower($this->frequency ?? 'monthly');
        $frequency = str_replace('_', '-', $frequency);

        $addInterval = function (Carbon $date, int $n) use ($frequency) : Carbon {
            $d = $date->copy();
            if ($n <= 0) return $d;
            switch ($frequency) {
                case 'daily': return $d->addDays($n);
                case 'weekly': return $d->addWeeks($n);
                case 'bi-weekly':
                case 'biweekly': return $d->addWeeks(2 * $n);
                case 'monthly':
                default: return $d->addMonths($n);
            }
        };

        // If now < anchor => we are in "pre-first" period: [anchor, anchor + 1 interval)
        if ($now->lt($anchor)) {
            $start = $anchor->copy()->startOfDay();
            $end = $addInterval($anchor, 1)->copy()->subSecond()->endOfDay();
            return ['start' => $start, 'end' => $end];
        }

        // compute elapsed cycles since anchor (0 means current cycle is first one)
        $elapsed = 0;
        switch ($frequency) {
            case 'daily':
                $elapsed = (int) floor($anchor->diffInDays($now) / 1);
                break;
            case 'weekly':
                $elapsed = (int) floor($anchor->diffInWeeks($now) / 1);
                break;
            case 'bi-weekly':
            case 'biweekly':
                $elapsed = (int) floor($anchor->diffInWeeks($now) / 2);
                break;
            case 'monthly':
            default:
                $elapsed = (int) floor($anchor->diffInMonths($now));
                break;
        }

        $start = $addInterval($anchor, $elapsed)->startOfDay();
        $end = $addInterval($anchor, $elapsed + 1)->copy()->subSecond()->endOfDay();

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Return the zero-based index of the current cycle (how many full cycles completed).
     * If now < anchor, returns -1 (meaning no cycles completed yet).
     */
    public function currentCycleIndex(): int
    {
        $now = Carbon::now();
        $anchor = !empty($this->meta['start_date']) ? Carbon::parse($this->meta['start_date'])->startOfDay() : ($this->created_at ? $this->created_at->copy()->startOfDay() : Carbon::now()->startOfDay());

        if ($now->lt($anchor)) return -1;

        $frequency = strtolower($this->frequency ?? 'monthly');
        $frequency = str_replace('_', '-', $frequency);

        switch ($frequency) {
            case 'daily': return (int) floor($anchor->diffInDays($now));
            case 'weekly': return (int) floor($anchor->diffInWeeks($now));
            case 'bi-weekly':
            case 'biweekly': return (int) floor($anchor->diffInWeeks($now) / 2);
            case 'monthly':
            default: return (int) floor($anchor->diffInMonths($now));
        }
    }

    /**
     * Return the user (model) who should receive the next payout.
     * Uses meta.payout_order if provided (array of user IDs). Falls back to users ordered by pivot.joined_at.
     */
    public function getNextPayoutMember()
    {
        $members = $this->users()->get(); // collection of User models
        $count = $members->count();
        if ($count === 0) return null;

        // determine index for the next payout: if k cycles completed, payout goes to index k % count
        $index = $this->currentCycleIndex();
        // if index == -1 (no cycles yet), next payout is index 0
        if ($index < 0) $index = 0;

        // prefer explicit payout order in meta
        if (!empty($this->meta['payout_order']) && is_array($this->meta['payout_order'])) {
            $order = array_values($this->meta['payout_order']);
            $orderCount = count($order);
            if ($orderCount > 0) {
                $selectedUserId = $order[$index % $orderCount];
                return $members->firstWhere('id', $selectedUserId) ?: $members->values()[$index % $count];
            }
        }

        // fallback: sort members by pivot.joined_at if available
        $sorted = $members->sortBy(function ($u) {
            return $u->pivot->joined_at ?? $u->created_at ?? null;
        })->values();

        return $sorted[$index % $count] ?? $sorted->first();
    }

    /**
     * Close group if enough cycles (payouts) have been completed to have paid every member at least once.
     */
    public function closeIfComplete()
    {
        $membersCount = $this->users()->count();
        $completedPayouts = $this->cycles()->count(); // assuming each GroupCycle represents one payout
        if ($membersCount > 0 && $completedPayouts >= $membersCount) {
            $this->status = 'closed';
            $this->save();
            return true;
        }
        return false;
    }

    /**
     * Convenience: is group closed
     */
    public function isClosed(): bool
    {
        return strtolower($this->status ?? '') === 'closed';
    }



    public function getIsPrivateAttribute(){
        // read actual flag from meta, default false
        return !empty($this->meta['is_private']);
    }

    public function getGroupTransactionAttribute(){
        // return aggregate of transactions associated with this group
        if (method_exists($this, 'transactions')) {
            return $this->transactions()->orderBy('created_at', 'desc')->get();
        }

        // fallback: try to pull transactions by group_id
        return \App\Models\Transaction::where('group_id', $this->id)->orderBy('created_at', 'desc')->get();
    }

    public function transactions()
    {
        return $this->hasMany(\App\Models\Transaction::class, 'group_id');
    }

    public function invites()
    {
        return $this->hasMany(Invite::class, 'group_id');
    }

    /**
     * Pending invites for the group.
     * Usage: $group->pendingInvites()->get();
     */
    public function pendingInvites()
    {
        return $this->invites()
        ->where('status', 'pending')
        ->where('type', 'invite')
        ->with(['sender:id,name,email', 'recipient:id,name,email']);
    }

    public function pendingRequests()
    {
        return $this->invites()
        ->where('status', 'pending')
        ->where('type', 'request')
        ->with(['sender:id,name,email', 'recipient:id,name,email']);
    }

    /**
     * Count of pending invites (helper).
     * Usage: $group->pending_invites_count
     */
    public function getPendingInvitesCountAttribute()
    {
        return $this->pendingInvites()->count();
    }

}
