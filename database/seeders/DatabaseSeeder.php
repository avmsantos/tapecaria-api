<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin inicial (troque a senha no primeiro acesso)
        User::updateOrCreate(
            ['login' => 'admin'],
            [
                'nome' => 'Administrador',
                'password' => 'trocar123',   // o cast 'hashed' do model já criptografa
                'role' => 'admin',
                'ativo' => true,
                'must_change_password' => true,
            ]
        );
    }
}
