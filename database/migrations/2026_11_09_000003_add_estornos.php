<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE pagamentos
                ADD COLUMN IF NOT EXISTS estornado_em    timestamptz,
                ADD COLUMN IF NOT EXISTS estornado_por   bigint REFERENCES users(id),
                ADD COLUMN IF NOT EXISTS motivo_estorno  varchar(255);

            ALTER TABLE movimentacao_materiais
                ADD COLUMN IF NOT EXISTS estornado_em    timestamptz,
                ADD COLUMN IF NOT EXISTS estornado_por   bigint REFERENCES users(id),
                ADD COLUMN IF NOT EXISTS motivo_estorno  varchar(255),
                ADD COLUMN IF NOT EXISTS estorno_de_id   bigint REFERENCES movimentacao_materiais(id);

            -- entrada comum atualiza o custo do material; a entrada gerada por um estorno não mexe no custo
            CREATE OR REPLACE FUNCTION fn_atualiza_estoque() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.tipo = 'entrada' THEN
                    UPDATE materiais
                       SET estoque_atual  = estoque_atual + NEW.quantidade,
                           custo_unitario = CASE WHEN NEW.custo_unitario > 0 AND NEW.estorno_de_id IS NULL
                                                 THEN NEW.custo_unitario ELSE custo_unitario END,
                           updated_at     = now()
                     WHERE id = NEW.material_id;
                ELSE
                    UPDATE materiais
                       SET estoque_atual = estoque_atual - NEW.quantidade,
                           updated_at    = now()
                     WHERE id = NEW.material_id;
                END IF;
                RETURN NEW;
            END $$;

            -- receita mensal ignora pagamentos estornados
            CREATE OR REPLACE VIEW v_financeiro_mensal AS
            WITH r AS (
                SELECT date_trunc('month', pago_em)::date AS mes, sum(valor) AS receita
                  FROM pagamentos WHERE estornado_em IS NULL GROUP BY 1),
            d AS (
                SELECT date_trunc('month', data)::date AS mes, sum(valor) AS despesa
                  FROM despesas GROUP BY 1)
            SELECT coalesce(r.mes, d.mes) AS mes,
                   coalesce(r.receita, 0) AS receita,
                   coalesce(d.despesa, 0) AS despesa,
                   coalesce(r.receita, 0) - coalesce(d.despesa, 0) AS saldo
              FROM r FULL JOIN d ON r.mes = d.mes;

            -- custo de material por OS ignora lançamentos estornados e os estornos em si
            CREATE OR REPLACE VIEW v_custo_material_os AS
            SELECT m.os_id, sum(m.quantidade * m.custo_unitario) AS custo_material
              FROM movimentacao_materiais m
             WHERE m.tipo = 'saida' AND m.os_id IS NOT NULL
               AND m.estornado_em IS NULL AND m.estorno_de_id IS NULL
             GROUP BY m.os_id;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW v_financeiro_mensal AS
            WITH r AS (
                SELECT date_trunc('month', pago_em)::date AS mes, sum(valor) AS receita FROM pagamentos GROUP BY 1),
            d AS (
                SELECT date_trunc('month', data)::date AS mes, sum(valor) AS despesa FROM despesas GROUP BY 1)
            SELECT coalesce(r.mes, d.mes) AS mes,
                   coalesce(r.receita, 0) AS receita,
                   coalesce(d.despesa, 0) AS despesa,
                   coalesce(r.receita, 0) - coalesce(d.despesa, 0) AS saldo
              FROM r FULL JOIN d ON r.mes = d.mes;

            CREATE OR REPLACE VIEW v_custo_material_os AS
            SELECT m.os_id, sum(m.quantidade * m.custo_unitario) AS custo_material
              FROM movimentacao_materiais m
             WHERE m.tipo = 'saida' AND m.os_id IS NOT NULL
             GROUP BY m.os_id;

            CREATE OR REPLACE FUNCTION fn_atualiza_estoque() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.tipo = 'entrada' THEN
                    UPDATE materiais
                       SET estoque_atual  = estoque_atual + NEW.quantidade,
                           custo_unitario = CASE WHEN NEW.custo_unitario > 0 THEN NEW.custo_unitario ELSE custo_unitario END,
                           updated_at     = now()
                     WHERE id = NEW.material_id;
                ELSE
                    UPDATE materiais
                       SET estoque_atual = estoque_atual - NEW.quantidade,
                           updated_at    = now()
                     WHERE id = NEW.material_id;
                END IF;
                RETURN NEW;
            END $$;

            ALTER TABLE movimentacao_materiais
                DROP COLUMN IF EXISTS estorno_de_id,
                DROP COLUMN IF EXISTS motivo_estorno,
                DROP COLUMN IF EXISTS estornado_por,
                DROP COLUMN IF EXISTS estornado_em;

            ALTER TABLE pagamentos
                DROP COLUMN IF EXISTS motivo_estorno,
                DROP COLUMN IF EXISTS estornado_por,
                DROP COLUMN IF EXISTS estornado_em;
        SQL);
    }
};
