<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApontamentoPausa extends Model
{
    protected $table = 'apontamento_pausas';
    protected $guarded = [];
    public $timestamps = false;

    public function apontamento(): BelongsTo { return $this->belongsTo(ApontamentoTempo::class, 'apontamento_id'); }
}
