<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Payable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\DatabaseNotification;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, Payable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = ['contributions', 'next_thrift', 'pending_balance'];

    // protected $with = ["banks"];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function ownedGroups()
    {
        return $this->hasMany(Group::class, 'owner_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function notifications() {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->orderBy('created_at', 'desc');
    }
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_user')
                    ->withPivot(['role', 'joined_at', 'contributed', "last_payment_at"])
                    ->withTimestamps();
    }

    public function banks(){
        return $this->hasMany(Bank::class);
    }

    public function bankCards(){
        return $this->hasMany(BankCard::class);
    }

    public function userBankCards()
    {
        return $this->hasMany(UserBankCard::class);
    }

    public function pendingBalances(){
        return $this->hasMany(PendingAccountBalance::class) ;
    }
    public function getPendingBalanceAttribute()
    {
        return (float) $this->pendingBalances->sum("amount");
    }


    public function hasCustomerId(): bool
    {
        return $this->userBankCards()->whereNotNull('customer_id')->exists();
    }

    public function virtualBank(){
        return $this->hasOne(VirtualBank::class);
    }

    public function hasVirtualBank($provider="flutterwave"){
        return $this->virtualBank()->where("provider", $provider)->exists();
    }

    /**
     * Return the single nearest next thrift (ajo) due for this user.
     *
     * Selection priority:
     *  1) nearest future unpaid
     *  2) nearest future paid
     *  3) nearest past unpaid (closest overdue)
     *  4) nearest past paid
     *
     * @return array|null
     */
    // Replace existing getNextThriftAttribute() in App\Models\User with this:

        public function getNextThriftAttribute()
        {
            $now = Carbon::now();

            // load groups for this user (pivot will be available)
            $groups = $this->groups()->get();
            if ($groups->isEmpty()) {
                return null;
            }

            $candidates = [];

            foreach ($groups as $group) {
                // get the group's current period (anchor uses meta.start_date in Group::currentPeriod())
                try {
                    $period = method_exists($group, 'currentPeriod') ? $group->currentPeriod() : null;
                } catch (\Throwable $e) {
                    $period = null;
                }

                $periodStart = $period['start'] ?? ($group->created_at ? $group->created_at->copy()->startOfDay() : $now->copy()->startOfDay());
                $periodEnd   = $period['end']   ?? ($group->created_at ? $group->created_at->copy()->endOfDay()   : $now->copy()->endOfDay());

                // contribution amount (prefer explicit meta.contribution)
                $amount = null;
                if (!empty($group->meta) && array_key_exists('contribution', $group->meta) && $group->meta['contribution'] !== null) {
                    $amount = (float) $group->meta['contribution'];
                } else {
                    $membersCount = $group->users()->count() ?: 0;
                    if ($membersCount > 0 && $group->goal !== null) {
                        $amount = round(($group->goal / $membersCount), 2);
                    }
                }

                // last payment for THIS user in this group (via pivot)
                $lastPaymentAt = $group->pivot->last_payment_at ?? null;
                $paid = false;
                if (!empty($lastPaymentAt)) {
                    try {
                        $lp = Carbon::parse($lastPaymentAt);
                        // between(start, end)
                        $paid = $lp->between($periodStart, $periodEnd);
                    } catch (\Throwable $e) {
                        $paid = false;
                    }
                }

                // determine whether this period_end is future (due later) or past (overdue)
                $isFuture = $periodEnd->greaterThan($now);

                // seconds until due (positive); if past, seconds since due
                $secondsUntil = $isFuture ? $periodEnd->diffInSeconds($now) : $now->diffInSeconds($periodEnd);

                $candidates[] = [
                    'group' => $group,
                    'group_id' => $group->id,
                    'group_saved' => $group->saved,
                    'group_goal' => $group->goal,
                    'group_name' => $group->name,
                    'amount_due' => $amount !== null ? (float) $amount : null,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    // include anchor start_date from group meta for client usage
                    'start_date' => $group->meta['start_date'] ?? null,
                    'due_by' => $periodEnd,
                    'paid' => (bool) $paid, // legacy
                    'has_paid' => (bool) $paid, // front-end friendly name
                    'hasPaid' => (bool) $paid, // alternative camelCase key (frontend checks multiple)
                    'user_has_paid' => (bool) $paid, // extra alias
                    'last_payment_at' => $lastPaymentAt,
                    'is_future' => (bool) $isFuture,
                    'seconds_until' => (int) $secondsUntil,
                ];
            }

            // Partition into priority buckets
            $futureUnpaid = collect($candidates)->filter(fn($c) => $c['is_future'] && !$c['has_paid']);
            $futurePaid   = collect($candidates)->filter(fn($c) => $c['is_future'] && $c['has_paid']);
            $pastUnpaid   = collect($candidates)->filter(fn($c) => !$c['is_future'] && !$c['has_paid']);
            $pastPaid     = collect($candidates)->filter(fn($c) => !$c['is_future'] && $c['has_paid']);

            $pickNearest = function ($coll) {
                if ($coll->isEmpty()) return null;
                return $coll->sortBy('seconds_until')->first();
            };

            $selected = $pickNearest($futureUnpaid)
                    ?? $pickNearest($futurePaid)
                    ?? $pickNearest($pastUnpaid)
                    ?? $pickNearest($pastPaid);

            if (!$selected) {
                return null;
            }

            // return a clean payload with safe string dates for JSON
            return [
                'group_id' => $selected['group_id'],
                'group_name' => $selected['group_name'],
                'group_goal' => $selected['group_goal'],
                'group_saved' => $selected['group_saved'],
                'amount_due' => $selected['amount_due'],
                'start_date' => $selected['start_date'], // original meta.start_date (string or null)
                'period_start' => $selected['period_start'] ? $selected['period_start']->toDateTimeString() : null,
                'period_end'   => $selected['period_end']   ? $selected['period_end']->toDateTimeString()   : null,
                'due_by'       => $selected['due_by']       ? $selected['due_by']->toDateTimeString()       : null,
                'paid' => (bool) $selected['has_paid'],
                'has_paid' => (bool) $selected['has_paid'],
                'hasPaid' => (bool) $selected['hasPaid'],
                'user_has_paid' => (bool) $selected['user_has_paid'],
                'last_payment_at' => $selected['last_payment_at'],
                'is_future' => (bool) $selected['is_future'],
                'seconds_until' => $selected['seconds_until'],
            ];
        }


    public function inviteReceived(){
        return $this->hasMany(Invite::class, "recipient_id");
    }

    public function inviteSent(){
        return $this->hasMany(Invite::class, "sender_id");
    }

    /**
     * Boot the model and attach lifecycle listeners.
     */
    protected static function booted()
    {
        // Ensure newly created users receive a referral code and Referral record.
        static::created(function (self $user) {
            try {
                $svc = app(\App\Services\ReferralService::class);
                $code = $svc->generateCode($user);
                // write referral_code without firing observers again
                $user->referral_code = $code;
                $user->saveQuietly();
            } catch (\Throwable $_) {
                // If referral generation fails, do not block user creation
            }
        });
    }

}
