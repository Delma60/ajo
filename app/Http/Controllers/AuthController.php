<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cookie;
use App\Notifications\NewUserNotification;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Log;
use App\Services\ReferralService;

class AuthController extends Controller
{

    public function me(Request $request)
    {
        $user = $request->user();
        Log::info($user);
        if (!$user) {
            return response()->json(["meta" => ["auth" => ["user" => null]]], 200);
        }
        return response()->json(["meta" => ["auth" => ["user" => new UserResource($user)]]], 200);
    }

    /**
     * Login using email or phone and password. Uses session auth.
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'password' => 'required|string',
        ]);

        $credentials = [];
        if (!empty($data['email'])) {
            $credentials['email'] = $data['email'];
        } elseif (!empty($data['phone'])) {
            $credentials['phone'] = $data['phone'];
        } else {
            return response()->json(['message' => 'Please provide email or phone'], 422);
        }

        if (Auth::attempt(array_merge($credentials, ['password' => $data['password']]))) {
            $request->session()->regenerate();
            $user = Auth::user();
            return response()->json(["meta" => ["auth" => ["user" => new UserResource($user)]]], 200);
        }

        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    /**
     * Token-based login for mobile/native clients. Returns a bearer token.
     */
    public function tokenLogin(Request $request)
    {
        $data = $request->validate([
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            // 'referral_code' => 'nullable|string|exists:users,column',
            'password' => 'required|string',
            'device_name' => 'nullable|string'
        ]);

        $query = User::query();
        if (!empty($data['email'])) $query->where('email', $data['email']);
        if (!empty($data['phone'])) $query->orWhere('phone', $data['phone']);

        $user = $query->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $device = $data['device_name'] ?? 'mobile';
        $token = $user->createToken($device)->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'bearer',
                'user' => new UserResource($user)
            ]
        ], 200);
    }

    /**
     * Register a new user (basic).
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'nullable|email|unique:users,email',
            'phone' => ['nullable', 'string', 'unique:users,phone', 'regex:/^(?:070|091|080)\d{8}$/'],
            'password' => 'required|string|confirmed',
            'referral_code' => 'nullable|string',
            'device_name' => 'nullable|string'
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);

        // Handle referral if provided
        if (!empty($data['referral_code'])) {
            try {
                $svc = app(\App\Services\ReferralService::class);
                $svc->acceptReferral($data['referral_code'], $user);
            } catch (\Throwable $_) {
                // Ignore referral failures
            }
        }

        // Log the user in
        Auth::login($user);

        // Send welcome notification
        try {
            $user->notify(new \App\Notifications\NewUserNotification($user));
        } catch (\Throwable $_) {
            // Ignore notification errors
        }

        // ✅ Create token for API clients
        $device = $data['device_name'] ?? 'mobile';
        $token = $user->createToken($device)->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'bearer',
                'user' => new \App\Http\Resources\UserResource($user),
            ]
        ], 201);
    }


    /**
     * Send password reset instructions to user (email or phone).
     * This is a minimal implementation which accepts an identifier and
     * returns success. In a real app you would queue an email/SMS with a token.
     */
    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
        ]);

        // Attempt to find the user and (in a real app) send reset instructions.
        $query = User::query();
        if (!empty($data['email'])) $query->where('email', $data['email']);
        if (!empty($data['phone'])) $query->orWhere('phone', $data['phone']);

        $user = $query->first();
        if (!$user) {
            // Do not reveal whether the identifier exists
            return response()->json(['message' => 'If an account exists we will send reset instructions'], 200);
        }

        // TODO: enqueue email/SMS with reset token. For now just return success.
        return response()->json(['message' => 'Reset instructions sent if the account exists'], 200);
    }

    /**
     * Logout and invalidate session
     */
    public function logout(Request $request)
    {
        try {
            Auth::guard()->logout();
        } catch (\Throwable $_) {
            // ignore
        }

        // Invalidate session and regenerate CSRF token
        try {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } catch (\Throwable $_) {
            // ignore
        }

        // Prepare response and remove session cookies (laravel session cookie and XSRF token)
        $cookieName = config('session.cookie');
        $forgetSession = Cookie::forget($cookieName);
        $forgetXsrf = Cookie::forget('XSRF-TOKEN');

        return response()->json(['message' => 'Logged out'])->withCookie($forgetSession)->withCookie($forgetXsrf);
    }

    /**
     * Revoke current personal access token for API users
     */
    public function revokeToken(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Not authenticated'], 401);
        // If this request has an active access token (sanctum), revoke it
        try {
            $current = $user->currentAccessToken();
            if ($current) {
                // Delete by id via the relationship query to avoid calling methods
                // on a possibly untyped token instance (satisfies linters)
                $user->tokens()->where('id', $current->id)->delete();
                return response()->json(['message' => 'Token revoked']);
            }
        } catch (\Throwable $_) {
            // ignore
        }

        // Fallback: revoke all tokens for the user
        $user->tokens()->delete();
        return response()->json(['message' => 'All tokens revoked']);
    }
}
