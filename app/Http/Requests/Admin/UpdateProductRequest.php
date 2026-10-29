<?php

namespace App\Http\Requests\Admin;

/**
 * The edit form uses the same rules as the create form. A missing image keeps
 * the current image, so the field is nullable in both cases.
 */
final class UpdateProductRequest extends StoreProductRequest {}
