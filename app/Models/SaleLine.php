<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleLine extends Model
{
    use HasFactory;

    protected $table = 'sale_lines';

    protected $fillable = [
        'sale_id',
        'size',
        'quantity',
        'empty_quantity',
        'unit_price',
        'line_total',
    ];

    public function sale()
    {
        return $this->belongsTo(Sales::class, 'sale_id');
    }
}
