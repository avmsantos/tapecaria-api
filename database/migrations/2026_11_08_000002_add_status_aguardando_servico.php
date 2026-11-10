<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ordens_servico DROP CONSTRAINT IF EXISTS ordens_servico_status_check');
        DB::statement("ALTER TABLE ordens_servico ADD CONSTRAINT ordens_servico_status_check
            CHECK (status IN ('aguardando_entrada','aguardando_servico','em_andamento','pronto','entregue','cancelado'))");

        // OS que ainda não começaram, mas cujo veículo já está na loja, passam a "aguardando serviço"
        DB::statement("UPDATE ordens_servico o SET status = 'aguardando_servico'
            WHERE o.status = 'aguardando_entrada'
              AND (SELECT m.tipo FROM movimentacao_veiculos m
                    WHERE m.os_id = o.id ORDER BY m.data_hora DESC, m.id DESC LIMIT 1) = 'entrada'");
    }

    public function down(): void
    {
        DB::statement("UPDATE ordens_servico SET status = 'aguardando_entrada' WHERE status = 'aguardando_servico'");
        DB::statement('ALTER TABLE ordens_servico DROP CONSTRAINT IF EXISTS ordens_servico_status_check');
        DB::statement("ALTER TABLE ordens_servico ADD CONSTRAINT ordens_servico_status_check
            CHECK (status IN ('aguardando_entrada','em_andamento','pronto','entregue','cancelado'))");
    }
};
