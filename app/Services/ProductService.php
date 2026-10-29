<?php

namespace App\Services;

use App\Models\Product;
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
