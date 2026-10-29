<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\OrderController;
use App\Http\Controllers\Shop\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (served under /api)
|--------------------------------------------------------------------------
|
| Requests authenticate with a JWT bearer token (guard "api").
| Every route name is prefixed with "api." so it never collides with the
| matching session based route in routes/web.php.
|
*/

Route::name('api.')->group(function (): void {
    Route::get('/', [ProductController::class, 'index'])->name('home');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

    Route::middleware('guest')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:auth')
            ->name('register');

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth')
            ->name('login');
    });

    // Outside the auth group so an expired token can still be refreshed
    // within the refresh window.
    Route::post('/refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:auth')
        ->name('refresh');

    Route::middleware('auth:api')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        // API requests have no session, so the cart lives in the database and
        // is tied to the token's user.
        Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
        Route::post('/cart/{product}', [CartController::class, 'store'])->name('cart.store');
        Route::patch('/cart/{product}', [CartController::class, 'update'])->name('cart.update');
        Route::delete('/cart/{product}', [CartController::class, 'destroy'])->name('cart.destroy');
        Route::delete('/cart', [CartController::class, 'clear'])->name('cart.clear');

        Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');

        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::post('/orders', [CheckoutController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    });

    Route::prefix('admin')
        ->name('admin.')
        ->middleware(['auth:api', 'admin'])
        ->group(function (): void {
            Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');

            Route::get('/products', [AdminProductController::class, 'index'])->name('products.index');
            Route::get('/products/create', [AdminProductController::class, 'create'])->name('products.create');
            Route::post('/products', [AdminProductController::class, 'store'])->name('products.store');
            Route::get('/products/{product}/edit', [AdminProductController::class, 'edit'])->name('products.edit');
            Route::put('/products/{product}', [AdminProductController::class, 'update'])->name('products.update');
            Route::patch('/products/{product}/stock', [AdminProductController::class, 'updateStock'])->name('products.stock');
            Route::delete('/products/{product}', [AdminProductController::class, 'destroy'])->name('products.destroy');

            Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
            Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.status');
        });
});