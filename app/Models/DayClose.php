<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DayClose extends Model
{
    use HasFactory;

    protected $table = 'day_close';

    protected $fillable = [
        'five_kg',
        'twelve_kg',
        'twentyfive_kg',
        'thirtythree_kg',
        'thirtyfive_kg',
        'fourtyfive_kg',
        'empty_five_kg',
        'empty_twelve_kg',
        'empty_twentyfive_kg',
        'empty_thirtythree_kg',
        'empty_thirtyfive_kg',
        'empty_fourtyfive_kg',
        'total_sale',
        'total_pay',
        'due_pay',
        'total_due',
        'total_cost',
        'total_remain',
        'date',
    ];
};
