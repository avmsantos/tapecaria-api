<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimentacaoMaterial extends Model
{
    protected $table = 'movimentacao_materiais';
    protected $guarded = [];
    public $timestamps = false;

    public function material(): BelongsTo { return $this->belongsTo(Material::class); }
    public function os(): BelongsTo { return $this->belongsTo(OrdemServico::class, 'os_id'); }
}
