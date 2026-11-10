<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pagamento extends Model
{
    protected $table = 'pagamentos';
    protected $guarded = [];
    public $timestamps = false;

    public function os(): BelongsTo { return $this->belongsTo(OrdemServico::class, 'os_id'); }
}
