<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApontamentoTempo extends Model
{
    protected $table = 'apontamentos_tempo';
    protected $guarded = [];
    public $timestamps = false;

    public function os(): BelongsTo { return $this->belongsTo(OrdemServico::class, 'os_id'); }
    public function funcionario(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function pausas(): HasMany { return $this->hasMany(ApontamentoPausa::class, 'apontamento_id'); }
}
