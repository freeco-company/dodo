<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Identity\LineIdTokenVerifier;
use App\Services\Identity\OAuthUserUpserter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/auth/line — LINE Login.
 *
 * Body:
 *   {
 *     "id_token": "<JWT from LINE Login>",
 *     "access_token": "<optional; not used server-side today>"
 *   }
 *
 * Verification path (LineIdTokenVerifier):
 *   - In prod we delegate to https://api.line.me/oauth2/v2.1/verify which
 *     validates signature + iss/aud/exp server-side and returns claims.
 *   - We re-check iss / aud / sub / exp / iat defensively.
 *   - access_token is currently unused — the id_token already carries `sub`,
 *     `email`, `name`. Captured in the request body so the iOS / Android
 *     plugin payload doesn't need a translator layer; we may use it later
 *     to call /v2/profile for richer profile data.
 *
 * LINE typically supplies `email` only when the user opted in to email scope
 * during channel registration. Treat email as verified when present (LINE
 * confirms email at signup). If absent we just create a no-email user.
 */
class LineSignInController extends Controller
{
    public function __construct(
        private readonly LineIdTokenVerifier $verifier,
        private readonly OAuthUserUpserter $upserter,
    ) {}

    public function signin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
            'access_token' => ['nullable', 'string'],
        ]);

        $claims = $this->verifier->verify($data['id_token']);
        if ($claims === null) {
            return response()->json([
                'message' => 'invalid_line_id_token',
            ], 401);
        }

        $user = $this->upserter->upsert('line_id', $claims['sub'], [
            'email' => $claims['email'],
            'email_verified' => true, // LINE confirms email at signup
            'name' => $claims['name'],
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'data' => new UserResource($user),
            'token' => $token,
        ]);
    }
}
