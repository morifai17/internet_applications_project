<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in customer's shopping cart, stored in the database. Only product
 * ids and quantities are stored; prices are always read from the database.
 */
final class CartService
{
    public function __construct(private readonly Request $request) {}

    /**
     * Raw cart contents keyed by product id.
     *
     * @return array<int, int>
     */
    public function raw(): array
    {
        return $this->readDatabase($this->owner());
    }

    /**
     * Cart lines with fresh product data and calculated line totals.
     *
     * @return list<array{product: Product, quantity: int, line_total: float}>
     */
    public function items(): array
    {
        $cart = $this->raw();

        if ($cart === []) {
            return [];
        }

        $products = Product::query()
            ->with('category')
            ->whereIn('id', array_keys($cart))
            ->get()
            ->keyBy('id');

        $items = [];
        $changed = false;

        foreach ($cart as $productId => $quantity) {
            $product = $products->get($productId);

            if ($product === null) {
                // The product was removed from the catalogue in the meantime.
                unset($cart[$productId]);
                $changed = true;

                continue;
            }

            $items[] = [
                'product' => $product,
                'quantity' => $quantity,
                'line_total' => round((float) $product->price * $quantity, 2),
            ];
        }

        if ($changed) {
            $this->store($cart);
        }

        return $items;
    }

    public function count(): int
    {
        return array_sum($this->raw());
    }

    public function subtotal(): float
    {
        return round(array_sum(array_column($this->items(), 'line_total')), 2);
    }

    public function isEmpty(): bool
    {
        return $this->raw() === [];
    }

    public function add(Product $product, int $quantity): void
    {
        $cart = $this->raw();
        $desired = ($cart[$product->id] ?? 0) + $quantity;

        if ($desired > $product->stock_quantity) {
            throw new InsufficientStockException(
                $product->title,
                $product->stock_quantity,
                $desired,
            );
        }

        $cart[$product->id] = $desired;
        $this->store($cart);
    }

    public function updateQuantity(Product $product, int $quantity): void
    {
        $cart = $this->raw();

        if ($quantity < 1) {
            unset($cart[$product->id]);
            $this->store($cart);

            return;
        }

        if ($quantity > $product->stock_quantity) {
            throw new InsufficientStockException(
                $product->title,
                $product->stock_quantity,
                $quantity,
            );
        }

        $cart[$product->id] = $quantity;
        $this->store($cart);
    }

    public function remove(int $productId): void
    {
        $cart = $this->raw();
        unset($cart[$productId]);
        $this->store($cart);
    }

    public function clear(): void
    {
        $user = $this->owner();

        CartItem::query()
            ->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))
            ->delete();
    }

    /**
     * The cart routes require authentication, so a missing user is a 401.
     */
    private function owner(): User
    {
        $user = $this->request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /**
     * @param  array<int, int>  $cart
     */
    private function store(array $cart): void
    {
        if ($cart === []) {
            $this->clear();

            return;
        }

        $this->writeDatabase($this->owner(), $cart);
    }

    /**
     * @return array<int, int>
     */
    private function readDatabase(User $user): array
    {
        return $this->normalize(
            CartItem::query()
                ->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))
                ->pluck('quantity', 'product_id')
                ->all(),
        );
    }

    /**
     * Replace the user's stored cart lines with the given contents.
     *
     * @param  array<int, int>  $cart
     */
    private function writeDatabase(User $user, array $cart): void
    {
        DB::transaction(function () use ($user, $cart): void {
            $storedCart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

            $storedCart->items()
                ->whereNotIn('product_id', array_keys($cart))
                ->delete();

            if ($cart === []) {
                return;
            }

            CartItem::query()->upsert(
                array_map(
                    fn (int $productId, int $quantity): array => [
                        'cart_id' => $storedCart->id,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                    ],
                    array_keys($cart),
                    $cart,
                ),
                ['cart_id', 'product_id'],
                ['quantity'],
            );
        });
    }

    /**
     * Drop malformed entries from stored cart data.
     *
     * @return array<int, int>
     */
    private function normalize(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $cart = [];

        foreach ($stored as $productId => $quantity) {
            $productId = (int) $productId;
            $quantity = (int) $quantity;

            if ($productId > 0 && $quantity > 0) {
                $cart[$productId] = $quantity;
            }
        }

        return $cart;
    }
}
