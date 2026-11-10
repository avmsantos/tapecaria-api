<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agendamento extends Model
{
    protected $table = 'agendamentos';
    protected $guarded = [];

    public function os(): BelongsTo { return $this->belongsTo(OrdemServico::class, 'os_id'); }
}
