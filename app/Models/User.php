<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'nome', 'login', 'telefone', 'password', 'role', 'funcao',
        'valor_hora', 'data_admissao', 'ativo', 'must_change_password',
        'fcm_token', 'foto_path',
    ];

    protected $hidden = ['password', 'fcm_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'ativo' => 'boolean',
            'must_change_password' => 'boolean',
            'data_admissao' => 'date',
            'valor_hora' => 'decimal:2',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function apontamentos(): HasMany
    {
        return $this->hasMany(ApontamentoTempo::class, 'user_id');
    }
}
