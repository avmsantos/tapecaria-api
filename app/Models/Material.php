<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    protected $table = 'materiais';
    protected $guarded = [];

    public function movimentacoes(): HasMany { return $this->hasMany(MovimentacaoMaterial::class, 'material_id'); }
}
