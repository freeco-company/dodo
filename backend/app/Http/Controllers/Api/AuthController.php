<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\TargetCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            // Pre-launch hardening: 10-char min + mixed-case + numbers.
            // HIBP `uncompromised()` rule omitted on purpose — needs network call
            // to api.pwnedpasswords.com which can flake / leak in offline CI.
            // Re-evaluate post-launch with HIBP cache layer.
            'password' => ['nullable', 'string', \Illuminate\Validation\Rules\Password::min(10)->mixedCase()->numbers()],
            // Pre-launch security: register MUST NOT accept raw OAuth ids — there
            // is no signed-token verification yet, so any client could claim
            // arbitrary apple_id / line_id and hijack a future OAuth login.
            // Once AppleSignInController / LineSignInController land (separate
            // PR), those flows will mint these fields after verifying signed
            // tokens from Apple / LINE and the `prohibited` rule moves there.
            // @todo OAuth wiring PR — remove `prohibited`, add controller path.
            'line_id' => ['nullable', 'prohibited'],
            'apple_id' => ['nullable', 'prohibited'],

            // 2026-05-01 — onboarding fields demoted to optional so first-run flow
            // can defer height/weight prompts to a step-2 screen post-ceremony
            // (App Store first-impression UX; ux-research audit). TargetCalculator
            // falls back to averages when missing.
            'height_cm' => ['nullable', 'numeric', 'between:100,250'],
            'current_weight_kg' => ['nullable', 'numeric', 'between:30,250'],
            'target_weight_kg' => ['nullable', 'numeric', 'between:30,250'],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'gender' => ['nullable', 'in:female,male,other'],
            'activity_level' => ['nullable', 'in:sedentary,light,moderate,active'],
            'dietary_type' => ['nullable', 'string', 'max:30'],
            'allergies' => ['nullable', 'array'],
            'dislike_foods' => ['nullable', 'array'],
            'favorite_foods' => ['nullable', 'array'],
            'fp_ref_code' => ['nullable', 'string', 'max:30'],
            'avatar_animal' => ['nullable', 'string', 'in:rabbit,cat,tiger,penguin,bear,dog,fox,dinosaur,sheep,pig,robot'],
        ]);

        $age = isset($data['birth_date'])
            ? (int) Carbon::parse($data['birth_date'])->age
            : 30;

        // Use sensible defaults when height/weight not provided at signup
        // (deferred to post-ceremony step-2 screen). 60kg / 160cm are population
        // medians for Taiwan adults; user updates them later via /me/settings.
        $weightKg = $data['current_weight_kg'] ?? 60;
        $heightCm = $data['height_cm'] ?? 160;

        $targets = TargetCalculator::compute([
            'weight_kg' => $weightKg,
            'height_cm' => $heightCm,
            'age' => $age,
            'gender' => $data['gender'] ?? 'female',
            'activity_level' => $data['activity_level'] ?? 'light',
            'goal_weight_kg' => $data['target_weight_kg'] ?? null,
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'password' => isset($data['password']) ? Hash::make($data['password']) : null,
            'line_id' => $data['line_id'] ?? null,
            'apple_id' => $data['apple_id'] ?? null,

            'height_cm' => $data['height_cm'] ?? null,
            'current_weight_kg' => $data['current_weight_kg'] ?? null,
            'start_weight_kg' => $data['current_weight_kg'] ?? null,
            'target_weight_kg' => $data['target_weight_kg'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'gender' => $data['gender'] ?? null,
            'activity_level' => $data['activity_level'] ?? 'light',
            'dietary_type' => $data['dietary_type'] ?? 'normal',
            'allergies' => $data['allergies'] ?? [],
            'dislike_foods' => $data['dislike_foods'] ?? [],
            'favorite_foods' => $data['favorite_foods'] ?? [],
            'outfits_owned' => ['none'],
            'fp_ref_code' => $data['fp_ref_code'] ?? null,
            'avatar_animal' => $data['avatar_animal'] ?? 'cat',

            'daily_calorie_target' => $targets['daily_calorie_target'],
            'daily_protein_target_g' => $targets['daily_protein_target_g'],
            'daily_water_goal_ml' => $targets['daily_water_goal_ml'],

            'onboarded_at' => now(),
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'data' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['email 或密碼錯誤'],
            ]);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'data' => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
