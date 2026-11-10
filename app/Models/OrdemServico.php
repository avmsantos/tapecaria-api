<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrdemServico extends Model
{
    protected $table = 'ordens_servico';
    protected $guarded = [];

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class); }
    public function veiculo(): BelongsTo { return $this->belongsTo(Veiculo::class); }
    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function agendamentos(): HasMany { return $this->hasMany(Agendamento::class, 'os_id'); }
    public function apontamentos(): HasMany { return $this->hasMany(ApontamentoTempo::class, 'os_id'); }
    public function fotos(): HasMany { return $this->hasMany(OsFoto::class, 'os_id'); }
    public function pagamentos(): HasMany { return $this->hasMany(Pagamento::class, 'os_id'); }
    public function movimentacoesVeiculo(): HasMany { return $this->hasMany(MovimentacaoVeiculo::class, 'os_id'); }
    public function funcionarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'os_funcionarios', 'os_id', 'user_id')
            ->withPivot('aceito_em');
    }
}
