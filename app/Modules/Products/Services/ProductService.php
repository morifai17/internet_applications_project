<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Exceptions\InsufficientStockException;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * All product writes, including image lifecycle, live here so controllers stay
 * thin and the rules are applied consistently.
 */
final class ProductService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Product
    {
        $image = $attributes['image'] ?? null;
        unset($attributes['image']);

        if ($image instanceof UploadedFile) {
            $attributes['image_path'] = $this->storeImage($image);
        }

        try {
            return Product::create($attributes);
        } catch (Throwable $exception) {
            if (isset($attributes['image_path'])) {
                $this->deleteImage($attributes['image_path']);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes): Product
    {
        $image = $attributes['image'] ?? null;
        $oldImage = $product->image_path;
        unset($attributes['image']);

        if ($image instanceof UploadedFile) {
            $attributes['image_path'] = $this->storeImage($image);
        }

        try {
            $product->fill($attributes)->save();
        } catch (Throwable $exception) {
            if (isset($attributes['image_path'])) {
                $this->deleteImage($attributes['image_path']);
            }

            throw $exception;
        }

        if ($image instanceof UploadedFile && $oldImage !== null) {
            $this->deleteImage($oldImage);
        }

        return $product->refresh();
    }

    public function updateStock(Product $product, int $quantity): Product
    {
        return DB::transaction(function () use ($product, $quantity): Product {
            $locked = Product::query()
                ->lockForUpdate()
                ->findOrFail($product->getKey());

            // Form validation already enforces a non-negative integer; max()
            // keeps the invariant even when the service is called elsewhere.
            $locked->stock_quantity = max(0, $quantity);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Lock products for a stock change, in id order so concurrent checkouts
     * and cancellations cannot deadlock. Must be called inside a transaction.
     *
     * @param  list<int>  $productIds
     * @return Collection<int, Product> keyed by product id
     */
    public function lockForStockChange(array $productIds): Collection
    {
        return Product::query()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Take units out of stock from a product locked by lockForStockChange().
     *
     * @throws InsufficientStockException
     */
    public function decreaseStock(Product $lockedProduct, int $quantity): void
    {
        if ($lockedProduct->stock_quantity < $quantity) {
            throw new InsufficientStockException(
                $lockedProduct->title,
                $lockedProduct->stock_quantity,
                $quantity,
            );
        }

        $lockedProduct->stock_quantity -= $quantity;
        $lockedProduct->save();
    }

    /**
     * Return units to stock. Must be called inside a transaction.
     *
     * @param  array<int, int>  $quantities  units keyed by product id
     */
    public function increaseStock(array $quantities): void
    {
        if ($quantities === []) {
            return;
        }

        $this->lockForStockChange(array_keys($quantities));

        foreach ($quantities as $productId => $quantity) {
            Product::query()
                ->whereKey($productId)
                ->increment('stock_quantity', $quantity);
        }
    }

    /**
     * Catalogue totals for the admin dashboard.
     *
     * @return array{total_products: int, stock_units: int}
     */
    public function catalogSummary(): array
    {
        $stats = Product::query()
            ->selectRaw('COUNT(*) AS total_products, COALESCE(SUM(stock_quantity), 0) AS stock_units')
            ->first();

        return [
            'total_products' => (int) $stats->total_products,
            'stock_units' => (int) $stats->stock_units,
        ];
    }

    public function delete(Product $product): void
    {
        $imagePath = $product->image_path;

        $product->delete();

        if ($imagePath !== null) {
            $this->deleteImage($imagePath);
        }
    }

    private function storeImage(UploadedFile $image): string
    {
        $extension = strtolower($image->extension() ?: $image->getClientOriginalExtension() ?: 'jpg');
        $filename = Str::uuid()->toString().'.'.$extension;

        $path = $image->storeAs('products', $filename, 'public');

        if ($path === false) {
            throw new RuntimeException('The product image could not be stored.');
        }

        return $path;
    }

    private function deleteImage(string $path): void
    {
        // Only ever remove files this application manages.
        if (str_starts_with($path, 'products/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
