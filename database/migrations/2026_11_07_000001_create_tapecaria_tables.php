<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Cria tabelas, trigger de estoque e views de relatório
        DB::unprepared(file_get_contents(database_path('sql/tapecaria.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop view if exists v_veiculos_status, v_leads_perdidos, v_financeiro_mensal,
                v_servicos_por_dia, v_custo_material_os, v_tempo_por_servico,
                v_horas_funcionario_semana;
            drop table if exists despesas, pagamentos, movimentacao_materiais, materiais,
                os_fotos, movimentacao_veiculos, apontamento_pausas, apontamentos_tempo,
                agendamentos, os_funcionarios, ordens_servico, leads, veiculos, clientes cascade;
            drop function if exists fn_atualiza_estoque();
        SQL);
    }
};
