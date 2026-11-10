-- =====================================================================
-- Sistema de gestão: tapeçaria e capotaria (banco/moto)
-- PostgreSQL 14+
-- Uso fechado (uma única empresa). Funciona com Laravel (bigint identity).
-- =====================================================================

-- ---------------------------------------------------------------------
-- CLIENTES E VEÍCULOS
-- ---------------------------------------------------------------------
create table clientes (
    id            bigint generated always as identity primary key,
    nome          varchar(150) not null,
    telefone      varchar(20)  not null unique,
    observacoes   text,
    created_at    timestamptz not null default now(),
    updated_at    timestamptz not null default now()
);

create table veiculos (
    id          bigint generated always as identity primary key,
    cliente_id  bigint not null references clientes(id) on delete restrict,
    tipo        varchar(10) not null check (tipo in ('carro', 'moto')),
    marca       varchar(60),
    modelo      varchar(80) not null,
    placa       varchar(10),
    cor         varchar(30),
    ano         smallint,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);
create index idx_veiculos_cliente on veiculos(cliente_id);

-- ---------------------------------------------------------------------
-- LEADS (contatos do WhatsApp, inclusive os que não fecharam)
-- ---------------------------------------------------------------------
create table leads (
    id             bigint generated always as identity primary key,
    cliente_id     bigint references clientes(id) on delete set null,
    nome           varchar(150),
    telefone       varchar(20) not null,
    origem         varchar(40) default 'whatsapp',
    etapa          varchar(20) not null default 'novo'
                   check (etapa in ('novo', 'em_atendimento', 'orcamento', 'fechado', 'perdido')),
    motivo_perda   varchar(255),                                -- obrigatório quando 'perdido'
    resumo_ia      text,                                        -- resumo da conversa gerado pela IA
    created_at     timestamptz not null default now(),
    updated_at     timestamptz not null default now(),
    constraint chk_motivo_perda
        check (etapa <> 'perdido' or motivo_perda is not null)
);
create index idx_leads_etapa on leads(etapa);

-- ---------------------------------------------------------------------
-- ORDENS DE SERVIÇO
-- ---------------------------------------------------------------------
create table ordens_servico (
    id                bigint generated always as identity primary key,
    cliente_id        bigint not null references clientes(id) on delete restrict,
    veiculo_id        bigint not null references veiculos(id) on delete restrict,
    lead_id           bigint references leads(id) on delete set null,
    titulo            varchar(150) not null,                    -- ex.: "Troca de couro do banco"
    descricao         text,
    instrucoes        text,                                     -- o que o funcionário deve fazer
    valor_orcado      numeric(12,2) not null default 0,
    valor_final       numeric(12,2),
    status            varchar(25) not null default 'aguardando_entrada'
                      check (status in ('aguardando_entrada', 'em_andamento',
                                        'pronto', 'entregue', 'cancelado')),
    data_prevista     date,
    criado_por        bigint references users(id),
    concluido_em      timestamptz,
    created_at        timestamptz not null default now(),
    updated_at        timestamptz not null default now()
);
create index idx_os_status on ordens_servico(status);
create index idx_os_cliente on ordens_servico(cliente_id);

-- funcionários designados para a OS
create table os_funcionarios (
    os_id       bigint not null references ordens_servico(id) on delete cascade,
    user_id     bigint not null references users(id) on delete restrict,
    aceito_em   timestamptz,                                    -- check-in de aceite
    primary key (os_id, user_id)
);

-- ---------------------------------------------------------------------
-- AGENDA
-- ---------------------------------------------------------------------
create table agendamentos (
    id          bigint generated always as identity primary key,
    os_id       bigint not null references ordens_servico(id) on delete cascade,
    tipo        varchar(15) not null default 'entrada'
                check (tipo in ('entrada', 'entrega', 'retorno')),
    data_hora   timestamptz not null,
    status      varchar(15) not null default 'agendado'
                check (status in ('agendado', 'realizado', 'cancelado', 'remarcado')),
    observacao  varchar(255),
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);
create index idx_agenda_data on agendamentos(data_hora);

-- ---------------------------------------------------------------------
-- APONTAMENTO DE TEMPO (check-in / finalizar do funcionário)
-- Horários SEMPRE gravados pelo servidor (now()), nunca pelo celular.
-- ---------------------------------------------------------------------
create table apontamentos_tempo (
    id           bigint generated always as identity primary key,
    os_id        bigint not null references ordens_servico(id) on delete cascade,
    user_id      bigint not null references users(id) on delete restrict,
    inicio       timestamptz not null default now(),
    fim          timestamptz,
    created_at   timestamptz not null default now(),
    constraint chk_fim_apos_inicio check (fim is null or fim >= inicio)
);
create index idx_apont_user on apontamentos_tempo(user_id, inicio);
create index idx_apont_os on apontamentos_tempo(os_id);
-- um funcionário não pode ter dois apontamentos abertos ao mesmo tempo
create unique index uq_apont_aberto_por_user
    on apontamentos_tempo(user_id) where fim is null;

create table apontamento_pausas (
    id              bigint generated always as identity primary key,
    apontamento_id  bigint not null references apontamentos_tempo(id) on delete cascade,
    inicio          timestamptz not null default now(),
    fim             timestamptz,
    motivo          varchar(100)                                -- almoço, falta de material...
);

-- ---------------------------------------------------------------------
-- ENTRADA E SAÍDA DE VEÍCULOS
-- ---------------------------------------------------------------------
create table movimentacao_veiculos (
    id             bigint generated always as identity primary key,
    os_id          bigint not null references ordens_servico(id) on delete cascade,
    veiculo_id     bigint not null references veiculos(id) on delete restrict,
    tipo           varchar(10) not null check (tipo in ('entrada', 'saida')),
    data_hora      timestamptz not null default now(),
    responsavel_id bigint references users(id),                 -- quem recebeu/entregou
    quem_retirou   varchar(150),                                -- na saída: nome de quem levou
    observacoes    text
);
create index idx_mov_veiculo on movimentacao_veiculos(veiculo_id, data_hora);

create table os_fotos (
    id               bigint generated always as identity primary key,
    os_id            bigint not null references ordens_servico(id) on delete cascade,
    movimentacao_id  bigint references movimentacao_veiculos(id) on delete set null,
    momento          varchar(15) not null default 'andamento'
                     check (momento in ('entrada', 'andamento', 'saida')),
    path             varchar(255) not null,
    legenda          varchar(150),
    user_id          bigint references users(id),
    created_at       timestamptz not null default now()
);

-- ---------------------------------------------------------------------
-- MATERIAIS E ESTOQUE
-- ---------------------------------------------------------------------
create table materiais (
    id              bigint generated always as identity primary key,
    nome            varchar(120) not null,
    unidade         varchar(10)  not null default 'un',         -- m, m2, un, kg, l
    estoque_atual   numeric(12,3) not null default 0,
    estoque_minimo  numeric(12,3) not null default 0,
    custo_unitario  numeric(12,2) not null default 0,           -- último custo
    ativo           boolean not null default true,
    created_at      timestamptz not null default now(),
    updated_at      timestamptz not null default now()
);

create table movimentacao_materiais (
    id              bigint generated always as identity primary key,
    material_id     bigint not null references materiais(id) on delete restrict,
    tipo            varchar(10) not null check (tipo in ('entrada', 'saida')),
    quantidade      numeric(12,3) not null check (quantidade > 0),
    custo_unitario  numeric(12,2) not null default 0,           -- congelado no momento
    os_id           bigint references ordens_servico(id) on delete set null,
    user_id         bigint references users(id),
    observacao      varchar(255),
    data_hora       timestamptz not null default now()
);
create index idx_movmat_material on movimentacao_materiais(material_id, data_hora);
create index idx_movmat_os on movimentacao_materiais(os_id);

-- mantém o estoque atual sincronizado
create or replace function fn_atualiza_estoque() returns trigger
language plpgsql as $$
begin
    if new.tipo = 'entrada' then
        update materiais
           set estoque_atual  = estoque_atual + new.quantidade,
               custo_unitario = case when new.custo_unitario > 0
                                     then new.custo_unitario else custo_unitario end,
               updated_at     = now()
         where id = new.material_id;
    else
        update materiais
           set estoque_atual = estoque_atual - new.quantidade,
               updated_at    = now()
         where id = new.material_id;
    end if;
    return new;
end $$;

create trigger trg_atualiza_estoque
after insert on movimentacao_materiais
for each row execute function fn_atualiza_estoque();

-- ---------------------------------------------------------------------
-- FINANCEIRO
-- ---------------------------------------------------------------------
create table pagamentos (
    id          bigint generated always as identity primary key,
    os_id       bigint not null references ordens_servico(id) on delete restrict,
    valor       numeric(12,2) not null check (valor > 0),
    forma       varchar(20) not null default 'pix'
                check (forma in ('pix', 'dinheiro', 'cartao_debito', 'cartao_credito', 'transferencia')),
    pago_em     timestamptz not null default now(),
    observacao  varchar(255),
    user_id     bigint references users(id)
);
create index idx_pag_data on pagamentos(pago_em);

create table despesas (
    id          bigint generated always as identity primary key,
    categoria   varchar(40) not null default 'outros',          -- aluguel, energia, ferramentas...
    descricao   varchar(150) not null,
    valor       numeric(12,2) not null check (valor > 0),
    data        date not null default current_date,
    user_id     bigint references users(id)
);
create index idx_desp_data on despesas(data);

-- =====================================================================
-- VIEWS PARA OS RELATÓRIOS
-- =====================================================================

-- Horas trabalhadas por funcionário por semana (já descontando pausas)
create or replace view v_horas_funcionario_semana as
select
    a.user_id,
    u.nome,
    date_trunc('week', a.inicio)::date as semana,
    count(distinct a.os_id)            as servicos,
    round(sum(
        extract(epoch from (a.fim - a.inicio))
        - coalesce((select sum(extract(epoch from (p.fim - p.inicio)))
                      from apontamento_pausas p
                     where p.apontamento_id = a.id and p.fim is not null), 0)
    ) / 3600.0, 2)                     as horas_trabalhadas
from apontamentos_tempo a
join users u on u.id = a.user_id
where a.fim is not null
group by a.user_id, u.nome, date_trunc('week', a.inicio);

-- Tempo gasto por funcionário em cada serviço
create or replace view v_tempo_por_servico as
select
    a.os_id,
    o.titulo,
    a.user_id,
    u.nome,
    a.inicio,
    a.fim,
    round(extract(epoch from (a.fim - a.inicio)) / 3600.0, 2) as horas
from apontamentos_tempo a
join ordens_servico o on o.id = a.os_id
join users u on u.id = a.user_id
where a.fim is not null;

-- Custo de material por OS
create or replace view v_custo_material_os as
select
    m.os_id,
    sum(m.quantidade * m.custo_unitario) as custo_material
from movimentacao_materiais m
where m.tipo = 'saida' and m.os_id is not null
group by m.os_id;

-- Serviços concluídos por dia
create or replace view v_servicos_por_dia as
select concluido_em::date as dia, count(*) as total
from ordens_servico
where status in ('pronto', 'entregue') and concluido_em is not null
group by concluido_em::date;

-- Receita, despesa e saldo por mês
create or replace view v_financeiro_mensal as
with r as (
    select date_trunc('month', pago_em)::date as mes, sum(valor) as receita
      from pagamentos group by 1),
d as (
    select date_trunc('month', data)::date as mes, sum(valor) as despesa
      from despesas group by 1)
select coalesce(r.mes, d.mes) as mes,
       coalesce(r.receita, 0) as receita,
       coalesce(d.despesa, 0) as despesa,
       coalesce(r.receita, 0) - coalesce(d.despesa, 0) as saldo
from r full join d on r.mes = d.mes;

-- Leads perdidos e motivos
create or replace view v_leads_perdidos as
select motivo_perda, count(*) as total
from leads
where etapa = 'perdido'
group by motivo_perda
order by total desc;

-- Onde está cada veículo agora (última movimentação)
create or replace view v_veiculos_status as
select distinct on (o.id)
    o.id as os_id,
    o.titulo,
    v.modelo,
    v.placa,
    o.status as status_os,
    case when mv.tipo = 'entrada' then 'na_loja' else 'com_cliente' end as localizacao
from ordens_servico o
join veiculos v on v.id = o.veiculo_id
left join movimentacao_veiculos mv on mv.os_id = o.id
order by o.id, mv.data_hora desc nulls last;
