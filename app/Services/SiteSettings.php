<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SiteSettings
 *
 * Centralised store for all platform-wide configuration.
 * Every key is typed, documented, and sourced from the database
 * with an .env / hard-coded fallback.
 *
 * Usage (via facade helper):
 *   settings('platform.name')              // AjoSave
 *   settings('fees.creation_fee_pct')      // 5.0
 *   settings('limits.min_contribution')    // 500
 *   SiteSettings::get('platform.name')
 *   SiteSettings::all()
 *
 * Persistence (admin panel / artisan):
 *   SiteSettings::set('fees.creation_fee_pct', 5.0);
 *   SiteSettings::forget('fees.creation_fee_pct');
 *
 * The DB table `settings` has columns:
 *   id INT PRIMARY KEY
 *   key VARCHAR(120)
 *   value TEXT (JSON-encoded)
 *   settingable_id INT NULL
 *   settingable_type VARCHAR(255) NULL
 *   created_at TIMESTAMP
 *   updated_at TIMESTAMP
 */
class SiteSettings
{
    // ── Cache ─────────────────────────────────────────────────────────────────

    protected const CACHE_KEY = 'site_settings:all';
    protected const CACHE_TTL = 3600; // 1 hour

    // ── Defaults ──────────────────────────────────────────────────────────────
    // These are the hard-coded fallbacks used when the DB has no override.
    // Group them logically — the same groups power SiteSettings::all().

    protected array $defaults = [

        // ── Platform identity ────────────────────────────────────────────────
        'platform.name'             => 'AjoSave',
        'platform.tagline'          => 'Save together, grow together.',
        'platform.url'              => null,   // resolved from APP_URL at runtime
        'platform.support_email'    => 'support@ajosave.ng',
        'platform.support_phone'    => null,
        'platform.currency'         => 'NGN',
        'platform.currency_symbol'  => '₦',
        'platform.country'          => 'NG',
        'platform.timezone'         => 'Africa/Lagos',
        'platform.locale'           => 'en-NG',
        'platform.maintenance_mode' => false,
        'platform.registration_open'=> true,

        // ── Platform fees ────────────────────────────────────────────────────
        'fees.creation_fee_pct'     => 15.0,   // % of first contribution
        'fees.contribution_fee_pct' => 0.0,   // % per contribution
        'fees.withdrawal_fee_pct'   => 0.5,   // % of amount withdrawn
        'fees.withdrawal_fee_flat'  => 50.0,  // flat ₦ added to each withdrawal
        'fees.withdrawal_fee_cap'   => 2000.0,// max ₦ fee per withdrawal
        'fees.topup_fee_pct'        => 0.0,   // % of wallet top-up
        'fees.referral_reward'      => 1000.0,// ₦ credited to referrer

        // ── Contribution & withdrawal limits ────────────────────────────────
        'limits.min_contribution'   => 5000.0,
        'limits.max_contribution'   => 5_000_000.0,
        'limits.min_withdrawal'     => 500.0,
        'limits.max_withdrawal_daily'=> 2_000_000.0,
        'limits.min_group_size'     => 2,
        'limits.max_group_size'     => 100,
        'limits.max_cycles_per_group'=> 200,

        // ── Payout schedule ──────────────────────────────────────────────────
        'payouts.processing_window_hours' => 24,
        'payouts.payout_day'              => 'same_day', // same_day|next_day|scheduled
        'payouts.cycle_grace_period_hours'=> 48,
        'payouts.auto_trigger'            => true,
        'payouts.max_payout_per_cycle'    => 5_000_000.0, // 0 = unlimited
        'payouts.min_pool_before_payout'  => 0.0,

        // ── Penalty rules ────────────────────────────────────────────────────
        'penalties.late_payment_fee_pct'        => 10.0,
        'penalties.grace_period_hours'          => 24,
        'penalties.max_defaults_before_removal' => 3,
        'penalties.penalty_applies_after_hours' => 48,
        'penalties.auto_remove_on_default'      => false,
        'penalties.freeze_on_default'           => true,

        // ── Payment methods ──────────────────────────────────────────────────
        'payment_methods.card'         => true,
        'payment_methods.bank_transfer'=> true,
        'payment_methods.ussd'         => true,
        'payment_methods.wallet'       => true,
        'payment_methods.mobile_money' => false,

        // ── Provider routing ────────────────────────────────────────────────
        'provider.default'              => 'flutterwave',
        'provider.fallback'             => 'paystack',
        'provider.auto_failover'        => true,
        'provider.failover_threshold_pct'=> 90.0,

        // ── KYC / verification ───────────────────────────────────────────────
        'kyc.required_for_withdrawal'   => true,
        'kyc.required_above_amount'     => 500_000.0,
        'kyc.unverified_withdrawal_cap' => 50_000.0,

        // ── Referral programme ───────────────────────────────────────────────
        'referral.enabled'              => true,
        'referral.qualify_threshold'    => 2000.0,  // ₦ spend before reward fires
        'referral.reward_amount'        => 1000.0,  // ₦ credited to referrer

        // ── Notifications ────────────────────────────────────────────────────
        'notifications.email_enabled'   => true,
        'notifications.sms_enabled'     => true,
        'notifications.push_enabled'    => true,

        // ── Security ────────────────────────────────────────────────────────
        'security.2fa_required_for_withdrawal' => false,
        'security.session_lifetime_minutes'    => 120,
        'security.max_login_attempts'          => 5,
        'security.lockout_duration_minutes'    => 30,
    ];

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * Get a single setting by dot-notation key.
     *
     * @param  string  $key   e.g. 'fees.creation_fee_pct'
     * @param  mixed   $fallback  returned if neither DB nor defaults have the key
     * @return mixed
     */
    public function get(string $key, mixed $fallback = null): mixed
    {
        $all = $this->all();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return $fallback;
    }

    /**
     * Get all settings merged: defaults ← DB overrides.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $overrides = $this->loadFromDb();
            return array_merge($this->resolvedDefaults(), $overrides);
        });
    }

    /**
     * Return settings for a given group prefix (e.g. 'fees').
     *
     * @return array<string, mixed>  Keys are stripped of the prefix.
     */
    public function group(string $prefix): array
    {
        $prefix = rtrim($prefix, '.') . '.';
        $result = [];

        foreach ($this->all() as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $result[substr($key, strlen($prefix))] = $value;
            }
        }

        return $result;
    }

    /**
     * Check whether a boolean setting is truthy.
     */
    public function isEnabled(string $key): bool
    {
        return (bool) $this->get($key, false);
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Persist a setting override to the database and bust the cache.
     *
     * @param  string  $key
     * @param  mixed   $value   Will be JSON-encoded in the DB.
     * @param  int|null $updatedBy  User ID of the admin making the change.
     */
    public function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        $this->assertKnownKey($key);

        DB::table('settings')->upsert(
            [
                'key'        => $key,
                'value'      => json_encode($value),
                'settingable_id'   => null,   // global — not tied to any model
                'settingable_type' => null,
                'updated_at'       => now(),
            ],
            ['key', 'settingable_id', 'settingable_type'],  // unique composite
            ['value', 'updated_at']
        );

        $this->bust();
    }

    /**
     * Remove a DB override (reverts to default).
     */
    public function forget(string $key): void
    {
        DB::table('settings')->where('key', $key)->delete();
        $this->bust();
    }

    /**
     * Bust the settings cache.
     */
    public function bust(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ── Convenience typed accessors ───────────────────────────────────────────
    // Add more of these as the codebase grows. They make call-sites cleaner
    // and avoid littering magic strings throughout the code.

    public function platformName(): string       { return (string)  $this->get('platform.name'); }
    public function currency(): string           { return (string)  $this->get('platform.currency', 'NGN'); }
    public function currencySymbol(): string     { return (string)  $this->get('platform.currency_symbol', '₦'); }
    public function isMaintenanceMode(): bool    { return (bool)    $this->get('platform.maintenance_mode'); }
    public function isRegistrationOpen(): bool   { return (bool)    $this->get('platform.registration_open'); }

    public function creationFeePct(): float      { return (float)   $this->get('fees.creation_fee_pct'); }
    public function withdrawalFeePct(): float    { return (float)   $this->get('fees.withdrawal_fee_pct'); }
    public function withdrawalFeeFlat(): float   { return (float)   $this->get('fees.withdrawal_fee_flat'); }
    public function withdrawalFeeCap(): float    { return (float)   $this->get('fees.withdrawal_fee_cap'); }
    public function referralReward(): float      { return (float)   $this->get('fees.referral_reward'); }

    public function minContribution(): float     { return (float)   $this->get('limits.min_contribution'); }
    public function maxContribution(): float     { return (float)   $this->get('limits.max_contribution'); }
    public function minWithdrawal(): float       { return (float)   $this->get('limits.min_withdrawal'); }
    public function maxDailyWithdrawal(): float  { return (float)   $this->get('limits.max_withdrawal_daily'); }
    public function minGroupSize(): int          { return (int)     $this->get('limits.min_group_size'); }
    public function maxGroupSize(): int          { return (int)     $this->get('limits.max_group_size'); }

    public function defaultProvider(): string    { return (string)  $this->get('provider.default', 'flutterwave'); }
    public function fallbackProvider(): string   { return (string)  $this->get('provider.fallback', 'paystack'); }
    public function autoFailover(): bool         { return (bool)    $this->get('provider.auto_failover'); }
    public function failoverThreshold(): float   { return (float)   $this->get('provider.failover_threshold_pct'); }

    public function kycRequiredForWithdrawal(): bool  { return (bool)  $this->get('kyc.required_for_withdrawal'); }
    public function unverifiedWithdrawalCap(): float  { return (float) $this->get('kyc.unverified_withdrawal_cap'); }
    public function kycRequiredAbove(): float         { return (float) $this->get('kyc.required_above_amount'); }

    public function latePenaltyPct(): float      { return (float)   $this->get('penalties.late_payment_fee_pct'); }
    public function gracePeriodHours(): int      { return (int)     $this->get('penalties.grace_period_hours'); }
    public function maxDefaultsBeforeRemoval(): int { return (int)  $this->get('penalties.max_defaults_before_removal'); }

    // ── Internals ─────────────────────────────────────────────────────────────

    protected function loadFromDb(): array
    {
        try {
            $rows = DB::table('settings')->get(['key', 'value']);
        } catch (\Throwable) {
            // Table not yet migrated — fall back gracefully.
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row->value, true);
            $result[$row->key] = ($decoded === null && $row->value !== 'null')
                ? $row->value  // store plain string fallback
                : $decoded;
        }

        return $result;
    }

    /**
     * Resolve runtime-computed defaults (e.g. APP_URL).
     */
    protected function resolvedDefaults(): array
    {
        return array_merge($this->defaults, [
            'platform.url' => $this->defaults['platform.url'] ?? config('app.url'),
        ]);
    }
    // reset to defaults by deleting all overrides
     public function resetToDefaults(): void
    {
        DB::table('settings')->delete();
        $this->bust();
        $this->set('', null);
    }

    protected function assertKnownKey(string $key): void
    {
        if (!array_key_exists($key, $this->defaults)) {
            throw new \InvalidArgumentException(
                "Unknown settings key [{$key}]. Add it to SiteSettings::\$defaults first."
            );
        }
    }

    // ── Static proxy (enables SiteSettings::get() without injection) ──────────

    public static function __callStatic(string $name, array $args): mixed
    {
        return app(self::class)->{$name}(...$args);
    }

    /**
     * Convert a flat dot-notated array to a nested array.
     *
     * @param array $array
     * @return array
     */
    /**
     * Convert a flat dot-notated array to a nested array.
     * Handles conflicts gracefully (if a parent key is already a value, it will be overwritten by an array).
     *
     * @param array $array
     * @return array
     */
    public static function undot(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (strpos($key, '.') === false) {
                $result[$key] = $value;
                continue;
            }
            $segments = explode('.', $key);
            $temp = &$result;
            foreach ($segments as $i => $segment) {
                if ($i === count($segments) - 1) {
                    $temp[$segment] = $value;
                } else {
                    if (!isset($temp[$segment]) || !is_array($temp[$segment])) {
                        $temp[$segment] = [];
                    }
                    $temp = &$temp[$segment];
                }
            }
            unset($temp);
        }
        return $result;
    }
}