<?php

namespace App\Http\Controllers;

use App\Models\Customers;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CustomerAuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_login_id' => 'required|string',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => $validator->errors()->first(),
                ], Response::HTTP_BAD_REQUEST);
            }

            $input = trim((string) $request->customer_login_id);
            $customer = Customers::where('customer_login_id', $input)->first();
            if (! $customer && ctype_digit($input)) {
                $customer = Customers::where('customer_login_id', str_pad($input, 6, '0', STR_PAD_LEFT))->first();
            }

            if (! $customer || empty($customer->password)) {
                return response()->json([
                    'error' => 'Invalid customer ID or password',
                ], Response::HTTP_UNAUTHORIZED);
            }

            if (! Hash::check($request->password, $customer->password)) {
                return response()->json([
                    'error' => 'Invalid customer ID or password',
                ], Response::HTTP_UNAUTHORIZED);
            }

            $customer->tokens()->delete();
            $token = $customer->createToken('customer-portal')->plainTextToken;

            return response()->json([
                'token' => $token,
                'data' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'customer_login_id' => $customer->customer_login_id,
                    'role' => 'customer',
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            \Log::error('Customer login error: '.$e->getMessage());

            return response()->json([
                'error' => 'Login failed',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
