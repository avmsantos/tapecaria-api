<?php

namespace App\Http\Controllers;

use App\Models\Despesa;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceiroController extends Controller
{
    // GET /api/ordens-servico/{os}/pagamentos
    public function pagamentos(OrdemServico $os): JsonResponse
    {
        return response()->json($this->resumoOs($os));
    }

    // POST /api/ordens-servico/{os}/pagamentos
    public function registrarPagamento(Request $request, OrdemServico $os): JsonResponse
    {
        $data = $request->validate([
            'valor'      => ['required', 'numeric', 'gt:0'],
            'forma'      => ['nullable', 'in:pix,dinheiro,cartao_debito,cartao_credito,transferencia'],
            'pago_em'    => ['nullable', 'date'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        $campos = [
            'os_id'      => $os->id,
            'valor'      => $data['valor'],
            'forma'      => $data['forma'] ?? 'pix',
            'observacao' => $data['observacao'] ?? null,
            'user_id'    => $request->user()->id,
        ];
        if (! empty($data['pago_em'])) {
            $campos['pago_em'] = $data['pago_em'];   // se não vier, o banco usa now()
        }

        Pagamento::create($campos);

        return response()->json($this->resumoOs($os), 201);
    }

    // GET /api/despesas?de=2026-11-01&ate=2026-11-30
    public function despesas(Request $request): JsonResponse
    {
        [$ini, $fim] = $this->periodo($request);

        $lista = Despesa::where('data', '>=', $ini)->where('data', '<', $fim)
            ->orderByDesc('data')->orderByDesc('id')->get();

        return response()->json(['despesas' => $lista, 'total' => (float) $lista->sum('valor')]);
    }

    // POST /api/despesas
    public function registrarDespesa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'categoria' => ['nullable', 'string', 'max:40'],
            'descricao' => ['required', 'string', 'max:150'],
            'valor'     => ['required', 'numeric', 'gt:0'],
            'data'      => ['nullable', 'date'],
        ]);

        $campos = [
            'categoria' => $data['categoria'] ?? 'outros',
            'descricao' => $data['descricao'],
            'valor'     => $data['valor'],
            'user_id'   => $request->user()->id,
        ];
        if (! empty($data['data'])) {
            $campos['data'] = $data['data'];
        }

        return response()->json(Despesa::create($campos)->fresh(), 201);
    }

    // GET /api/relatorios/financeiro?de=&ate=   (padrão: mês atual)
    public function resumo(Request $request): JsonResponse
    {
        [$ini, $fim, $de, $ate] = $this->periodo($request);

        $receita  = (float) Pagamento::whereNull('estornado_em')
            ->where('pago_em', '>=', $ini)->where('pago_em', '<', $fim)->sum('valor');
        $despesas = (float) Despesa::where('data', '>=', $ini)->where('data', '<', $fim)->sum('valor');

        $material = (float) DB::table('movimentacao_materiais')
            ->where('tipo', 'saida')
            ->whereNull('estornado_em')->whereNull('estorno_de_id')
            ->where('data_hora', '>=', $ini)->where('data_hora', '<', $fim)
            ->sum(DB::raw('quantidade * custo_unitario'));

        // mão de obra estimada = horas trabalhadas x valor da hora de cada funcionário
        $maoDeObra = DB::selectOne("
            select coalesce(sum(
                (extract(epoch from (a.fim - a.inicio))
                 - coalesce((select sum(extract(epoch from (p.fim - p.inicio)))
                               from apontamento_pausas p
                              where p.apontamento_id = a.id and p.fim is not null), 0)
                ) / 3600.0 * coalesce(u.valor_hora, 0)
            ), 0) as total
              from apontamentos_tempo a
              join users u on u.id = a.user_id
             where a.fim is not null and a.inicio >= ? and a.inicio < ?
        ", [$ini, $fim]);

        $aReceber = DB::selectOne("
            select coalesce(sum(greatest(coalesce(o.valor_final, o.valor_orcado) - coalesce(p.pago, 0), 0)), 0) as total
              from ordens_servico o
              left join (select os_id, sum(valor) as pago from pagamentos where estornado_em is null group by os_id) p on p.os_id = o.id
             where o.status <> 'cancelado'
        ");

        $meses = DB::table('v_financeiro_mensal')
            ->where('mes', '>=', Carbon::parse($ini)->startOfMonth()->toDateString())
            ->where('mes', '<', $fim)
            ->orderBy('mes')->get();

        return response()->json([
            'periodo' => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'totais'  => [
                'receita'               => $receita,
                'despesas'              => $despesas,
                'resultado'             => $receita - $despesas,
                'material_consumido'    => $material,
                'mao_de_obra_estimada'  => round((float) $maoDeObra->total, 2),
                'a_receber'             => round((float) $aReceber->total, 2),
            ],
            'por_mes' => $meses,
        ]);
    }

    // POST /api/pagamentos/{pagamento}/estornar   { "motivo": "valor digitado errado" }
    public function estornarPagamento(Request $request, Pagamento $pagamento): JsonResponse
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        if ($pagamento->estornado_em !== null) {
            return response()->json(['message' => 'Este pagamento já foi estornado.'], 422);
        }

        // o lançamento continua registrado, só deixa de contar (quem estornou, quando e por quê ficam salvos)
        Pagamento::whereKey($pagamento->id)->update([
            'estornado_em'   => DB::raw('now()'),
            'estornado_por'  => $request->user()->id,
            'motivo_estorno' => $data['motivo'],
        ]);

        return response()->json($this->resumoOs(OrdemServico::findOrFail($pagamento->os_id)));
    }

    // PUT /api/despesas/{despesa}
    public function atualizarDespesa(Request $request, Despesa $despesa): JsonResponse
    {
        $data = $request->validate([
            'categoria' => ['sometimes', 'string', 'max:40'],
            'descricao' => ['sometimes', 'required', 'string', 'max:150'],
            'valor'     => ['sometimes', 'numeric', 'gt:0'],
            'data'      => ['sometimes', 'date'],
        ]);

        $despesa->update($data);

        return response()->json($despesa->fresh());
    }

    // DELETE /api/despesas/{despesa}
    public function excluirDespesa(Despesa $despesa): JsonResponse
    {
        $despesa->delete();

        return response()->json(['message' => 'Despesa excluída.']);
    }

    private function resumoOs(OrdemServico $os): array
    {
        $pagamentos = Pagamento::where('os_id', $os->id)->orderBy('pago_em')->get();
        $total = (float) ($os->valor_final ?? $os->valor_orcado);
        $pago  = (float) $pagamentos->whereNull('estornado_em')->sum('valor');   // estornados não contam

        return [
            'valor_total' => $total,
            'total_pago'  => $pago,
            'saldo'       => max($total - $pago, 0),
            'pagamentos'  => $pagamentos,
        ];
    }

    private function periodo(Request $request): array
    {
        $request->validate(['de' => ['nullable', 'date'], 'ate' => ['nullable', 'date']]);

        $de  = Carbon::parse($request->input('de', today()->startOfMonth()->toDateString()));
        $ate = Carbon::parse($request->input('ate', today()->endOfMonth()->toDateString()));

        return [$de->toDateString(), $ate->copy()->addDay()->toDateString(), $de, $ate];
    }
}
