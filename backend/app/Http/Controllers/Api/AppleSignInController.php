<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Identity\AppleIdTokenVerifier;
use App\Services\Identity\OAuthUserUpserter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/auth/apple — Sign in with Apple.
 *
 * Body:
 *   {
 *     "identity_token": "<JWT from Apple>",
 *     "authorization_code": "<optional, server-side token exchange>",
 *     "full_name": { "first": "...", "last": "..." },  // first sign-in only
 *     "email": "..."                                    // first sign-in only
 *   }
 *
 * Apple only sends `full_name` / `email` on the *first* sign-in for the user
 * — subsequent sign-ins must rely on `apple_id` (sub) lookup. We use the
 * client-provided full_name / email purely as display-time hints; the user
 * identity is authoritatively the verified `sub` from `identity_token`.
 *
 * Security:
 *   - identity_token is RS256-signed by Apple. Verified against
 *     https://appleid.apple.com/auth/keys (cached 1h, refreshed on kid miss).
 *   - iss must be https://appleid.apple.com, aud must be services.apple.client_id.
 *   - Email merge only happens when `email_verified=true` in the JWT claim
 *     (i.e. Apple confirmed the email belongs to the user; this is always
 *     true for primary Apple-ID emails, may be false for proxy relay emails
 *     that still resolve back to the user).
 */
class AppleSignInController extends Controller
{
    public function __construct(
        private readonly AppleIdTokenVerifier $verifier,
        private readonly OAuthUserUpserter $upserter,
    ) {}

    public function signin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identity_token' => ['required', 'string'],
            'authorization_code' => ['nullable', 'string'],
            'full_name' => ['nullable', 'array'],
            'full_name.first' => ['nullable', 'string', 'max:80'],
            'full_name.last' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email'],
        ]);

        $claims = $this->verifier->verify($data['identity_token']);
        if ($claims === null) {
            return response()->json([
                'message' => 'invalid_apple_identity_token',
            ], 401);
        }

        // Prefer Apple's verified email; fall back to client-provided email
        // (only meaningful on first-time sign-in, see class docblock).
        $email = $claims['email'] ?? ($data['email'] ?? null);

        $first = $data['full_name']['first'] ?? null;
        $last = $data['full_name']['last'] ?? null;
        $clientName = trim(implode(' ', array_filter([$first, $last]))) ?: null;

        $user = $this->upserter->upsert('apple_id', $claims['sub'], [
            'email' => $email,
            'email_verified' => $claims['email_verified'],
            'name' => $clientName,
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'data' => new UserResource($user),
            'token' => $token,
        ]);
    }
}
