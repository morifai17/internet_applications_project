<?php

use App\Modules\Inventory\Contracts\InventoryGateway;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock now belongs to the inventory service. Existing quantities are handed
 * over to it through the gRPC contract, then the column is dropped so the
 * application cannot read a stale copy by mistake.
 *
 * If the inventory service is not reachable the migration fails before
 * dropping anything and simply runs again on the next start.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'stock_quantity')) {
            return;
        }

        $products = DB::table('products')->select(['id', 'stock_quantity'])->orderBy('id');

        if ($products->exists()) {
            $inventory = app(InventoryGateway::class);

            $products->each(function (object $product) use ($inventory): void {
                $inventory->setStock((int) $product->id, (int) $product->stock_quantity);
            });
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('stock_quantity')->default(0)->after('price');
        });

        $productIds = DB::table('products')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($productIds === []) {
            return;
        }

        foreach (app(InventoryGateway::class)->availability($productIds) as $productId => $units) {
            DB::table('products')->where('id', $productId)->update(['stock_quantity' => $units]);
        }
    }
};
