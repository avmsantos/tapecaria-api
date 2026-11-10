<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'clientes';
    protected $guarded = [];

    public function veiculos(): HasMany { return $this->hasMany(Veiculo::class); }
    public function ordens(): HasMany { return $this->hasMany(OrdemServico::class, 'cliente_id'); }
}
