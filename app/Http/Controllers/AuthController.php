<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Serves both clients of the backend: requests on the web routes carry a
 * session and are signed in through it, while stateless API requests receive
 * a short-lived JWT bearer token instead.
 */
final class AuthController extends Controller
{
    public function register(RegisterRequest $request, CartService $cart): JsonResponse
    {
        $user = User::query()->create([
            'username' => $request->validated('username'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        return response()->json([
            'message' => 'Account created successfully.',
            ...$this->signIn($request, $user, $cart),
        ], 201);
    }

    public function login(LoginRequest $request, CartService $cart): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return response()->json([
            'message' => 'Signed in successfully.',
            ...$this->signIn($request, $user, $cart),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => (new UserResource($request->user()))->resolve(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->hasSession()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } else {
            // Blacklists the token so it cannot be used again before it expires.
            Auth::guard('api')->logout();
        }

        return response()->json(['message' => 'Signed out successfully.']);
    }

    /**
     * Exchange a valid or recently expired token for a new one. The old token
     * is blacklisted.
     */
    public function refresh(): JsonResponse
    {
        return response()->json([
            'message' => 'Token refreshed.',
            ...$this->tokenPayload(Auth::guard('api')->refresh()),
        ]);
    }

    /**
     * Start a session for web requests or issue a token for API requests.
     *
     * @return array<string, mixed>
     */
    private function signIn(Request $request, User $user, CartService $cart): array
    {
        $payload = [];

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            // Keep whatever the visitor added to the cart before signing in.
            $cart->absorbGuestCart($user);
        } else {
            $payload = $this->tokenPayload(Auth::guard('api')->login($user));
        }

        return [
            ...$payload,
            'data' => (new UserResource($user))->resolve(),
            'redirect_to' => $user->homeUrl(),
        ];
    }

    /**
     * @return array{token: string, token_type: string, expires_in: int}
     */
    private function tokenPayload(string $token): array
    {
        return [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
        ];
    }
}
