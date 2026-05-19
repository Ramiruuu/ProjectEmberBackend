<?php

namespace App\Http\Controllers;

use App\Models\Workout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WorkoutController extends Controller
{
    public function index(Request $request)
    {
        $workouts = $request->user()->workouts()->orderBy('workout_date', 'desc')->get();

        return response()->json($workouts);
    }

    public function storeRun(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'distance_km' => 'required|numeric|min:0',
            'duration_minutes' => 'required|integer|min:1',
            'workout_date' => 'required|date',
            'location' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        if (!$user->weight_kg) {
            return response()->json(['message' => 'User weight is required to calculate calories'], 400);
        }

        $met = 9.8;
        $calories = $met * $user->weight_kg * ($request->duration_minutes / 60);

        $workout = Workout::create([
            'user_id' => $user->id,
            'type' => 'run',
            'activity_name' => 'Running',
            'duration_minutes' => $request->duration_minutes,
            'calories_burned' => round($calories, 2),
            'details' => [
                'distance_km' => $request->distance_km,
                'location' => $request->location,
            ],
            'workout_date' => $request->workout_date,
        ]);

        $this->syncWorkoutToSupabase($workout);

        return response()->json($workout, 201);
    }

    public function storeGym(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exercise_name' => 'required|string|max:255',
            'duration_minutes' => 'required|integer|min:1',
            'workout_date' => 'required|date',
            'location' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        if (!$user->weight_kg) {
            return response()->json(['message' => 'User weight is required to calculate calories'], 400);
        }

        $met = 6.0;
        $calories = $met * $user->weight_kg * ($request->duration_minutes / 60);

        $workout = Workout::create([
            'user_id' => $user->id,
            'type' => 'gym',
            'activity_name' => $request->exercise_name,
            'duration_minutes' => $request->duration_minutes,
            'calories_burned' => round($calories, 2),
            'details' => [
                'location' => $request->location,
            ],
            'workout_date' => $request->workout_date,
        ]);

        $this->syncWorkoutToSupabase($workout);

        return response()->json($workout, 201);
    }

    public function update(Request $request, $id)
    {
        $workout = $request->user()->workouts()->find($id);

        if (!$workout) {
            return response()->json(['message' => 'Workout not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'duration_minutes' => 'required|integer|min:1',
            'workout_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        if (!$user->weight_kg) {
            return response()->json(['message' => 'User weight is required to calculate calories'], 400);
        }

        $met = $workout->type === 'run' ? 9.8 : 6.0;
        $calories = $met * $user->weight_kg * ($request->duration_minutes / 60);

        $workout->update([
            'duration_minutes' => $request->duration_minutes,
            'calories_burned' => round($calories, 2),
            'workout_date' => $request->workout_date,
        ]);

        $this->syncWorkoutToSupabase($workout);

        return response()->json($workout);
    }

    public function destroy(Request $request, $id)
    {
        $workout = $request->user()->workouts()->find($id);

        if (!$workout) {
            return response()->json(['message' => 'Workout not found'], 404);
        }

        $this->deleteWorkoutFromSupabase($workout);

        $workout->delete();

        return response()->json(['message' => 'Workout deleted successfully']);
    }

    public function getCalorieComparison(Request $request)
    {
        $user = $request->user();

        $runningStats = $user->workouts()
            ->where('type', 'run')
            ->selectRaw('SUM(calories_burned) as total_calories, SUM(duration_minutes) as total_minutes')
            ->first();

        $gymStats = $user->workouts()
            ->where('type', 'gym')
            ->selectRaw('SUM(calories_burned) as total_calories, SUM(duration_minutes) as total_minutes')
            ->first();

        $runningAvg = $runningStats->total_minutes > 0 ? ($runningStats->total_calories / $runningStats->total_minutes) : 0;
        $gymAvg = $gymStats->total_minutes > 0 ? ($gymStats->total_calories / $gymStats->total_minutes) : 0;

        return response()->json([
            'running' => round($runningAvg, 2),
            'gym' => round($gymAvg, 2),
        ]);
    }

    public function testSupabaseConnection(Request $request)
    {
        $supabaseUrl = config('services.supabase.url');
        $serviceKey = config('services.supabase.service_role_key');
        $table = config('services.supabase.workouts_table', 'workout_logs');

        if (!$supabaseUrl || !$serviceKey) {
            return response()->json([
                'success' => false,
                'message' => 'Supabase configuration is missing.',
            ], 500);
        }

        try {
            $response = Http::withHeaders([
                'apikey' => $serviceKey,
                'Authorization' => "Bearer {$serviceKey}",
                'Content-Type' => 'application/json',
            ])->get(rtrim($supabaseUrl, '/') . "/rest/v1/{$table}?select=*&limit=5");

            return response()->json([
                'success' => true,
                'status' => $response->status(),
                'data' => $response->json(),
            ], $response->status());
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    private function syncWorkoutToSupabase(Workout $workout): void
    {
        $supabaseUrl = config('services.supabase.url');
        $serviceKey = config('services.supabase.service_role_key');
        $table = config('services.supabase.workouts_table', 'workout_logs');

        if (!$supabaseUrl || !$serviceKey) {
            return;
        }

        try {
            Http::withHeaders([
                'apikey' => $serviceKey,
                'Authorization' => "Bearer {$serviceKey}",
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation',
            ])->post(rtrim($supabaseUrl, '/') . "/rest/v1/{$table}", [[
                'source_workout_id' => $workout->id,
                'user_id' => $workout->user_id,
                'user_email' => $workout->user?->email,
                'type' => $workout->type,
                'activity_name' => $workout->activity_name,
                'duration_minutes' => $workout->duration_minutes,
                'calories_burned' => $workout->calories_burned,
                'details' => $workout->details ?: [],
                'workout_date' => $workout->workout_date?->toDateString(),
            ]]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function deleteWorkoutFromSupabase(Workout $workout): void
    {
        $supabaseUrl = config('services.supabase.url');
        $serviceKey = config('services.supabase.service_role_key');
        $table = config('services.supabase.workouts_table', 'workout_logs');

        if (!$supabaseUrl || !$serviceKey) {
            return;
        }

        try {
            Http::withHeaders([
                'apikey' => $serviceKey,
                'Authorization' => "Bearer {$serviceKey}",
                'Content-Type' => 'application/json',
            ])->delete(rtrim($supabaseUrl, '/') . "/rest/v1/{$table}?source_workout_id=eq.{$workout->id}");
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
