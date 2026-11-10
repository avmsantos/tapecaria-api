<?php

namespace App\Http\Controllers;

use App\Models\Agendamento;
use App\Models\Lead;
use App\Models\Material;
use App\Models\OrdemServico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RelatorioController extends Controller
{
    // segundos trabalhados em um apontamento "a", já descontando as pausas
    private const SEGUNDOS = "extract(epoch from (a.fim - a.inicio))
        - coalesce((select sum(extract(epoch from (p.fim - p.inicio)))
                      from apontamento_pausas p
                     where p.apontamento_id = a.id and p.fim is not null), 0)";

    // GET /api/relatorios/dashboard
    public function dashboard(): JsonResponse
    {
        $hoje   = today()->toDateString();
        $amanha = today()->addDay()->toDateString();
        $mesIni = today()->startOfMonth()->toDateString();
        $mesFim = today()->startOfMonth()->addMonth()->toDateString();

        $concluidas = fn (string $de, string $ate) => OrdemServico::where('concluido_em', '>=', $de)
            ->where('concluido_em', '<', $ate)->count();

        return response()->json([
            'servicos_concluidos_hoje' => $concluidas($hoje, $amanha),
            'servicos_concluidos_mes'  => $concluidas($mesIni, $mesFim),
            'os_por_status'            => OrdemServico::select('status', DB::raw('count(*) as total'))
                                            ->groupBy('status')->pluck('total', 'status'),
            'agendamentos_hoje'        => Agendamento::where('status', 'agendado')
                                            ->where('data_hora', '>=', $hoje)->where('data_hora', '<', $amanha)->count(),
            'veiculos_na_loja'         => DB::table('v_veiculos_status')
                                            ->where('localizacao', 'na_loja')
                                            ->whereNotIn('status_os', ['entregue', 'cancelado'])->count(),
            'leads_novos_mes'          => Lead::where('created_at', '>=', $mesIni)->where('created_at', '<', $mesFim)->count(),
            'leads_perdidos_mes'       => Lead::where('etapa', 'perdido')
                                            ->where('updated_at', '>=', $mesIni)->where('updated_at', '<', $mesFim)->count(),
            'materiais_estoque_baixo'  => Material::where('ativo', true)->where('estoque_minimo', '>', 0)
                                            ->whereColumn('estoque_atual', '<=', 'estoque_minimo')->count(),
        ]);
    }

    // GET /api/relatorios/funcionarios?de=&ate=   (padrão: mês atual)
    public function funcionarios(Request $request): JsonResponse
    {
        [$ini, $fim, $de, $ate] = $this->periodo($request);

        $linhas = DB::select("
            select u.id, u.nome, u.funcao,
                   count(distinct x.os_id) as servicos,
                   coalesce(round(sum(x.segundos) / 3600.0, 2), 0) as horas
              from users u
              left join (
                    select a.user_id, a.os_id, (" . self::SEGUNDOS . ") as segundos
                      from apontamentos_tempo a
                     where a.fim is not null and a.inicio >= ? and a.inicio < ?
              ) x on x.user_id = u.id
             where u.role = 'funcionario'
             group by u.id, u.nome, u.funcao
             order by u.nome
        ", [$ini, $fim]);

        return response()->json([
            'periodo'      => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'funcionarios' => $linhas,
        ]);
    }

    // GET /api/relatorios/funcionarios/{funcionario}?de=&ate=
    public function funcionarioDetalhe(Request $request, User $funcionario): JsonResponse
    {
        abort_if($funcionario->role !== 'funcionario', 404);

        [$ini, $fim, $de, $ate] = $this->periodo($request);

        $servicos = DB::select("
            select a.id as apontamento_id, a.os_id, o.titulo, a.inicio, a.fim,
                   round((" . self::SEGUNDOS . ") / 3600.0, 2) as horas
              from apontamentos_tempo a
              join ordens_servico o on o.id = a.os_id
             where a.user_id = ? and a.fim is not null and a.inicio >= ? and a.inicio < ?
             order by a.inicio
        ", [$funcionario->id, $ini, $fim]);

        $semanas = DB::table('v_horas_funcionario_semana')
            ->where('user_id', $funcionario->id)
            ->where('semana', '>=', Carbon::parse($ini)->startOfWeek()->toDateString())
            ->where('semana', '<', $fim)
            ->orderBy('semana')->get();

        return response()->json([
            'funcionario'    => $funcionario->only(['id', 'nome', 'funcao']),
            'periodo'        => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'servicos'       => $servicos,
            'horas_por_semana' => $semanas,
            'total_horas'    => round(collect($servicos)->sum('horas'), 2),
        ]);
    }

    // GET /api/relatorios/servicos-por-dia?de=&ate=
    public function servicosPorDia(Request $request): JsonResponse
    {
        [$ini, $fim, $de, $ate] = $this->periodo($request);

        $dias = DB::table('v_servicos_por_dia')
            ->where('dia', '>=', $ini)->where('dia', '<', $fim)
            ->orderBy('dia')->get();

        return response()->json([
            'periodo' => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'dias'    => $dias,
            'total'   => (int) $dias->sum('total'),
        ]);
    }

    // GET /api/relatorios/materiais?de=&ate=
    public function materiais(Request $request): JsonResponse
    {
        [$ini, $fim, $de, $ate] = $this->periodo($request);

        $consumo = DB::select("
            select m.id, m.nome, m.unidade,
                   sum(mm.quantidade) as quantidade,
                   round(sum(mm.quantidade * mm.custo_unitario), 2) as custo
              from movimentacao_materiais mm
              join materiais m on m.id = mm.material_id
             where mm.tipo = 'saida' and mm.data_hora >= ? and mm.data_hora < ?
               and mm.estornado_em is null and mm.estorno_de_id is null
             group by m.id, m.nome, m.unidade
             order by custo desc
        ", [$ini, $fim]);

        $comprado = DB::table('movimentacao_materiais')
            ->where('tipo', 'entrada')
            ->whereNull('estornado_em')->whereNull('estorno_de_id')
            ->where('data_hora', '>=', $ini)->where('data_hora', '<', $fim)
            ->sum(DB::raw('quantidade * custo_unitario'));

        return response()->json([
            'periodo'          => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'consumo'          => $consumo,
            'total_consumido'  => round(collect($consumo)->sum('custo'), 2),
            'total_comprado'   => round((float) $comprado, 2),
            'estoque_baixo'    => Material::where('ativo', true)->where('estoque_minimo', '>', 0)
                                    ->whereColumn('estoque_atual', '<=', 'estoque_minimo')
                                    ->orderBy('nome')->get(['id', 'nome', 'unidade', 'estoque_atual', 'estoque_minimo']),
        ]);
    }

    // GET /api/relatorios/leads?de=&ate=
    public function leads(Request $request): JsonResponse
    {
        [$ini, $fim, $de, $ate] = $this->periodo($request);

        $motivos = Lead::select('motivo_perda', DB::raw('count(*) as total'))
            ->where('etapa', 'perdido')
            ->where('updated_at', '>=', $ini)->where('updated_at', '<', $fim)
            ->groupBy('motivo_perda')->orderByDesc('total')->get();

        $porEtapa = Lead::select('etapa', DB::raw('count(*) as total'))
            ->where('created_at', '>=', $ini)->where('created_at', '<', $fim)
            ->groupBy('etapa')->pluck('total', 'etapa');

        return response()->json([
            'periodo'        => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'motivos_perda'  => $motivos,
            'leads_por_etapa' => $porEtapa,
        ]);
    }

    private function periodo(Request $request): array
    {
        $request->validate(['de' => ['nullable', 'date'], 'ate' => ['nullable', 'date']]);

        $de  = Carbon::parse($request->input('de', today()->startOfMonth()->toDateString()));
        $ate = Carbon::parse($request->input('ate', today()->endOfMonth()->toDateString()));

        return [$de->toDateString(), $ate->copy()->addDay()->toDateString(), $de, $ate];
    }
}
