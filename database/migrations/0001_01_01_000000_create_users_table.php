<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 150);
            $table->string('login', 60)->unique();      // telefone ou nome de usuário
            $table->string('telefone', 20)->nullable();
            $table->string('password');
            $table->string('role', 20)->default('funcionario'); // admin | funcionario
            $table->string('funcao', 60)->nullable();            // estofador, costureiro...
            $table->decimal('valor_hora', 10, 2)->nullable();    // visível só para admin
            $table->date('data_admissao')->nullable();
            $table->boolean('ativo')->default(true);             // desativar, nunca apagar
            $table->boolean('must_change_password')->default(true);
            $table->text('fcm_token')->nullable();
            $table->string('foto_path')->nullable();
            $table->timestamps();
        });

        // Mantida porque o Laravel usa por padrão (SESSION_DRIVER=database)
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
