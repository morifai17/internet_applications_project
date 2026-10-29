<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'title',
    'description',
    'price',
    'stock_quantity',
    'category_id',
    'image_path',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock_quantity' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where('title', 'like', '%'.$term.'%');
    }

    #[Scope]
    protected function inCategory(Builder $query, int|string|null $categoryId): void
    {
        if ($categoryId === null || $categoryId === '') {
            return;
        }

        $query->where('category_id', $categoryId);
    }

    #[Scope]
    protected function available(Builder $query): void
    {
        $query->where('stock_quantity', '>', 0);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->image_path
                ? Storage::disk('public')->url($this->image_path)
                : null,
        );
    }

    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity > 0 && $this->stock_quantity <= 5;
    }
}
