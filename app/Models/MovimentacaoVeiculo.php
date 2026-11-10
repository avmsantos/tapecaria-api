<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimentacaoVeiculo extends Model
{
    protected $table = 'movimentacao_veiculos';
    protected $guarded = [];
    public $timestamps = false;

    public function os(): BelongsTo { return $this->belongsTo(OrdemServico::class, 'os_id'); }
    public function veiculo(): BelongsTo { return $this->belongsTo(Veiculo::class); }
    public function responsavel(): BelongsTo { return $this->belongsTo(User::class, 'responsavel_id'); }
}
