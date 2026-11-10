<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OsFoto extends Model
{
    protected $table = 'os_fotos';
    protected $guarded = [];
    public $timestamps = false;

    public function os(): BelongsTo { return $this->belongsTo(OrdemServico::class, 'os_id'); }
}
