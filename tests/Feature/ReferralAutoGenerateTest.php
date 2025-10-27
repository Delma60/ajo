<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Referral;

class ReferralAutoGenerateTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creation_generates_referral()
    {
        $user = User::factory()->create(['email' => 'auto@example.com']);
        $this->assertNotNull($user->referral_code, 'User should have referral_code set');

        $ref = Referral::where('referrer_id', $user->id)->first();
        $this->assertNotNull($ref, 'Referral record should be created for new user');
        $this->assertEquals($user->referral_code, $ref->code);
    }
}
