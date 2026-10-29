<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shopping cart. Signed-in users (session or API token) keep their cart in the
 * database; guests on the web routes keep it in the session until they sign
 * in. Only product ids and quantities are stored; prices are always read from
 * the database.
 */
final class CartService
{
    private const string SESSION_KEY = 'cart';

    public function __construct(private readonly Request $request) {}

    /**
     * Raw cart contents keyed by product id.
     *
     * @return array<int, int>
     */
    public function raw(): array
    {
        $user = $this->owner();

        return $user !== null
            ? $this->readDatabase($user)
            : $this->normalize($this->request->session()->get(self::SESSION_KEY, []));
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

        if ($user !== null) {
            CartItem::query()
                ->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))
                ->delete();

            return;
        }

        $this->request->session()->forget(self::SESSION_KEY);
    }

    /**
     * Move the guest's session cart into the user's stored cart after they
     * sign in. Quantities are merged and capped at the available stock.
     */
    public function absorbGuestCart(User $user): void
    {
        $guestCart = $this->normalize($this->request->session()->pull(self::SESSION_KEY, []));

        if ($guestCart === []) {
            return;
        }

        $cart = $this->readDatabase($user);

        $stock = Product::query()
            ->whereIn('id', array_keys($guestCart))
            ->pluck('stock_quantity', 'id');

        foreach ($guestCart as $productId => $quantity) {
            if (! $stock->has($productId)) {
                continue;
            }

            $cart[$productId] = min(($cart[$productId] ?? 0) + $quantity, (int) $stock->get($productId));
        }

        $this->writeDatabase($user, array_filter($cart, fn (int $quantity): bool => $quantity > 0));
    }

    private function owner(): ?User
    {
        $user = $this->request->user();

        return $user instanceof User ? $user : null;
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

        $user = $this->owner();

        if ($user !== null) {
            $this->writeDatabase($user, $cart);

            return;
        }

        $this->request->session()->put(self::SESSION_KEY, $cart);
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
