<?php
namespace App\Http\Controllers;
use App\Services\IngredientCatalogService;
use Illuminate\Http\Request;
class IngredientCatalogController extends Controller {
 public function search(Request $request, IngredientCatalogService $catalog) { $data = $request
 ->validate(['q' => 'nullable|string|max:100', 'limit' => 'nullable|integer|min:1|max:25']); return response()->json(['ingredients' => $catalog->search($data['q'] ?? '', $data['limit'] ?? 10)]); }
 public function resolve(Request $request, IngredientCatalogService $catalog) { return response()
 ->json($catalog->resolve($request->validate(['name' => 'required|string|max:255'])['name'])); }
}
