<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Add these columns on table `customers` (run SQL yourself if needed):
 * - customer_login_id VARCHAR(16) NULL UNIQUE  (6-digit portal login id)
 * - password VARCHAR(255) NULL  (bcrypt hash)
 */
class Customers extends Model
{
    use HasApiTokens, HasFactory;

    protected $table = 'customers';

    protected $fillable = [
        'name',
        'phone',
        'address',
        'customer_login_id',
        'password',
        'is_retailer',
    ];

    protected $casts = [
        'is_retailer' => 'integer',
    ];

    protected $hidden = [
        'password',
    ];

    public function sales()
    {
        return $this->hasMany(Sales::class, 'customer_id', 'id');
    }
}
