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
            'password' => ['nullable', 'string', 'min:8'],
            'line_id' => ['nullable', 'string', 'unique:users,line_id'],
            'apple_id' => ['nullable', 'string', 'unique:users,apple_id'],

            'height_cm' => ['required', 'numeric', 'between:100,250'],
            'current_weight_kg' => ['required', 'numeric', 'between:30,250'],
            'target_weight_kg' => ['nullable', 'numeric', 'between:30,250'],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'gender' => ['nullable', 'in:female,male,other'],
            'activity_level' => ['nullable', 'in:sedentary,light,moderate,active'],
            'dietary_type' => ['nullable', 'string', 'max:30'],
            'allergies' => ['nullable', 'array'],
            'dislike_foods' => ['nullable', 'array'],
            'favorite_foods' => ['nullable', 'array'],
            'fp_ref_code' => ['nullable', 'string', 'max:30'],
            'avatar_animal' => ['nullable', 'string', 'in:cat,rabbit,bear,hamster,fox,shiba,dinosaur,penguin,tuxedo'],
        ]);

        $age = isset($data['birth_date'])
            ? (int) Carbon::parse($data['birth_date'])->age
            : 30;

        $targets = TargetCalculator::compute([
            'weight_kg' => $data['current_weight_kg'],
            'height_cm' => $data['height_cm'],
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

            'height_cm' => $data['height_cm'],
            'current_weight_kg' => $data['current_weight_kg'],
            'start_weight_kg' => $data['current_weight_kg'],
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
