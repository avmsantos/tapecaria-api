<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    protected $table = 'leads';
    protected $guarded = [];

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class); }
}
