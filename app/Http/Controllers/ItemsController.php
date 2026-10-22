<?php

namespace App\Http\Controllers;

use App\Models\Items;
use Illuminate\Http\Request;

class ItemsController extends Controller
{
    public function index()
    {
        $products = Items::latest()->paginate(10);

        return response()->json([
            'status' => true,
            'data' => $products,
        ]);
    }

    // ✅ Create a new product
    public function store(Request $request)
    {
        $request->validate([
            'name'         => 'required|string|max:255',
            'price'        => 'required|integer',
            'description'  => 'nullable|string',
            'is_cylinder'  => 'nullable|boolean',
            'image'        => 'nullable|image|max:2048',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = time() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/products'), $filename);
        
            $imagePath = 'uploads/products/' . $filename;
        }

        $product = Items::create([
            'name'         => $request->name,
            'description'  => $request->description,
            'price'        => $request->price,
            'is_cylinder'  => (int) $request->boolean('is_cylinder'),
            'image'        => $imagePath,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Product created successfully',
            'data' => $product,
        ]);
    }

    public function show(Items $product)
    {
        return response()->json([
            'status' => true,
            'data' => $product,
        ]);
    }

    // ✅ Update product
    public function update(Request $request, $id)
    {
        $product = Items::find($id);
        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
            ], 404);
        }

        $request->validate([
            'name'         => 'sometimes|required|string|max:255',
            'price'        => 'sometimes|required|integer',
            'description'  => 'nullable|string',
            'is_cylinder'  => 'nullable|boolean',
            'image'        => 'nullable|image|max:2048',
        ]);

        $data = $request->only(['name', 'price', 'description']);
        if ($request->has('is_cylinder')) {
            $data['is_cylinder'] = (int) $request->boolean('is_cylinder');
        }

        if ($request->hasFile('image')) {
            if ($product->image && file_exists(public_path($product->image))) {
                @unlink(public_path($product->image));
            }
            $file = $request->file('image');
            $filename = time() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/products'), $filename);
            $data['image'] = 'uploads/products/' . $filename;
        }

        $product->update($data);

        return response()->json([
            'status' => true,
            'message' => 'Product updated successfully',
            'data' => $product->fresh(),
        ]);
    }


    public function destroy($id)
    {
        $product = Items::find($id);
        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
            ], 404);
        }
        if ($product->image && file_exists(public_path($product->image))) {
            @unlink(public_path($product->image));
        }

        $product->delete();

        return response()->json([
            'status' => true,
            'message' => 'Product deleted successfully',
        ]);
    }
}
