<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Referral;
use App\Models\ReferralEvent;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    /**
     * When a referred user's successful contributions reach the threshold,
     * the referrer should receive a single credited event and pending_referral increase.
     */
    public function test_referrer_gets_credit_when_referred_spend_reaches_threshold()
    {
        // Create a referrer
        $referrer = User::factory()->create(['email' => 'referrer@example.com']);

    // The User model auto-generates a referral code on creation; read it
    $referrer->refresh();
    $code = $referrer->referral_code;
    $this->assertNotNull($code, 'Referrer should have a generated referral code');

        // Register a referred user via API using the referral code
        $res = $this->postJson('/api/auth/register', [
            'name' => 'Referred User',
            'email' => 'referred@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'referral_code' => $code,
        ]);

        $res->assertStatus(201);

        $referred = User::where('email', 'referred@example.com')->first();
        $this->assertNotNull($referred, 'Referred user was not created');

        // Verify referral linked
        $ref = Referral::where('code', $code)->first();
        $this->assertNotNull($ref);
        $this->assertEquals($referred->id, $ref->referred_id);

        // Create a transaction below threshold and mark success -> should NOT credit yet
        $tx1 = Transaction::create([
            'user_id' => $referred->id,
            'amount' => 1000,
            'fee' => 0,
            'net_amount' => 1000,
            'currency' => 'NGN',
            'type' => Transaction::TYPE_CHARGE,
            'direction' => Transaction::DIRECTION_DEBIT,
            'status' => Transaction::STATUS_PENDING,
            'attempts' => 0,
        ]);

        // transition to success (observer will run)
        $tx1->status = Transaction::STATUS_SUCCESS;
        $tx1->save();

        // referrer should not be credited yet
        $referrer->refresh();
        $this->assertEquals(0, (float) $referrer->pending_referral, 'Referrer incorrectly credited before threshold');

        // Create second transaction that crosses threshold when combined
        $tx2 = Transaction::create([
            'user_id' => $referred->id,
            'amount' => 1200,
            'fee' => 0,
            'net_amount' => 1200,
            'currency' => 'NGN',
            'type' => Transaction::TYPE_CHARGE,
            'direction' => Transaction::DIRECTION_DEBIT,
            'status' => Transaction::STATUS_PENDING,
            'attempts' => 0,
        ]);

        // mark success - should trigger credit
        $tx2->status = Transaction::STATUS_SUCCESS;
        $tx2->save();

        // reload referrer and check pending_referral
        $referrer->refresh();
        $this->assertEquals(
            1000,
            (float) $referrer->pending_referral,
            'Referrer pending_referral should be incremented by referral reward once threshold reached'
        );

        // Ensure exactly one credited event exists for this referral
        $creditedCount = ReferralEvent::where('referral_id', $ref->id)->where('type', 'credited')->count();
        $this->assertEquals(1, $creditedCount, 'There should be exactly one credited event');

        // Now create another successful transaction - should NOT double-credit
        $tx3 = Transaction::create([
            'user_id' => $referred->id,
            'amount' => 5000,
            'fee' => 0,
            'net_amount' => 5000,
            'currency' => 'NGN',
            'type' => Transaction::TYPE_CHARGE,
            'direction' => Transaction::DIRECTION_DEBIT,
            'status' => Transaction::STATUS_PENDING,
            'attempts' => 0,
        ]);
        $tx3->status = Transaction::STATUS_SUCCESS;
        $tx3->save();

        // reload referrer and verify pending_referral unchanged (still only one credit)
        $referrer->refresh();
        $this->assertEquals(1000, (float) $referrer->pending_referral, 'Referrer should not be credited multiple times');

        // credited event count remains 1
        $creditedCount2 = ReferralEvent::where('referral_id', $ref->id)->where('type', 'credited')->count();
        $this->assertEquals(1, $creditedCount2, 'Credited event count should remain 1 after further transactions');
    }
}
