<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Veiculo extends Model
{
    protected $table = 'veiculos';
    protected $guarded = [];

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class); }
}
