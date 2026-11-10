<?php

namespace App\Http\Controllers;

use App\Models\ApontamentoPausa;
use App\Models\ApontamentoTempo;
use App\Models\OrdemServico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo do funcionário: ver seus serviços e os disponíveis para pegar, aceitar,
 * iniciar, pausar, retomar e finalizar. Todos os horários são gravados pelo
 * banco de dados (now()), nunca pelo relógio do celular.
 */
class MinhaOsController extends Controller
{
    // GET /api/minhas-os  -> serviços designados a mim + serviços disponíveis para qualquer um
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $lista = OrdemServico::where(function ($q) use ($userId) {
                // meus serviços (ainda não encerrados)
                $q->where(function ($meus) use ($userId) {
                    $meus->whereHas('funcionarios', fn ($f) => $f->where('users.id', $userId))
                         ->whereNotIn('status', ['entregue', 'cancelado']);
                })
                // serviços livres: veículo na loja e ninguém designado
                ->orWhere(function ($livres) {
                    $livres->where('status', 'aguardando_servico')->whereDoesntHave('funcionarios');
                });
            })
            ->with([
                'cliente:id,nome',
                'veiculo:id,tipo,modelo,placa',
                'agendamentos',
                'funcionarios' => fn ($q) => $q->where('users.id', $userId)->select('users.id'),
            ])
            ->orderBy('data_prevista')
            ->orderBy('id')
            ->get();

        return response()->json($lista);
    }

    // GET /api/minhas-os/{os}
    public function show(Request $request, OrdemServico $os): JsonResponse
    {
        $userId = $request->user()->id;
        $this->garantirAcesso($os, $userId);

        $os->load([
            'cliente:id,nome,telefone',
            'veiculo',
            'agendamentos',
            'fotos',
            'funcionarios' => fn ($q) => $q->where('users.id', $userId)->select('users.id'),
        ]);

        return response()->json([
            'os'                 => $os,
            'apontamento_aberto' => $this->apontamentoAberto($userId, $os->id)?->load('pausas'),
        ]);
    }

    // POST /api/minhas-os/{os}/aceitar
    // Se a OS estiver livre, quem aceitar primeiro passa a ser o responsável.
    public function aceitar(Request $request, OrdemServico $os): JsonResponse
    {
        $userId = $request->user()->id;
        $this->garantirAcesso($os, $userId);

        DB::transaction(function () use ($os, $userId) {
            // trava a OS para dois funcionários não pegarem o mesmo serviço ao mesmo tempo
            $os = OrdemServico::whereKey($os->id)->lockForUpdate()->firstOrFail();

            $designado = $os->funcionarios()->where('users.id', $userId)->exists();

            if ($designado) {
                $os->funcionarios()->updateExistingPivot($userId, ['aceito_em' => DB::raw('now()')]);
                return;
            }

            $alguemJaPegou = $os->funcionarios()->exists();
            if ($alguemJaPegou || $os->status !== 'aguardando_servico') {
                abort(response()->json(['message' => 'Este serviço não está mais disponível.'], 409));
            }

            $os->funcionarios()->attach($userId, ['aceito_em' => DB::raw('now()')]);
        });

        return response()->json(['message' => 'Serviço aceito.']);
    }

    // POST /api/minhas-os/{os}/iniciar
    public function iniciar(Request $request, OrdemServico $os): JsonResponse
    {
        $userId = $request->user()->id;
        $this->garantirAcesso($os, $userId);

        $aceito = DB::table('os_funcionarios')
            ->where('os_id', $os->id)->where('user_id', $userId)
            ->whereNotNull('aceito_em')->exists();

        if (! $aceito) {
            return response()->json(['message' => 'Aceite o serviço antes de iniciar.'], 422);
        }

        if ($os->status === 'aguardando_entrada') {
            return response()->json(['message' => 'O veículo ainda não deu entrada na loja.'], 422);
        }

        if (in_array($os->status, ['pronto', 'entregue', 'cancelado'], true)) {
            return response()->json(['message' => 'Este serviço não pode ser iniciado.'], 422);
        }

        $aberto = ApontamentoTempo::where('user_id', $userId)->whereNull('fim')->first();
        if ($aberto) {
            return response()->json([
                'message' => 'Você já tem um serviço em andamento. Finalize-o antes de iniciar outro.',
                'os_id'   => $aberto->os_id,
            ], 422);
        }

        // 'inicio' usa o default now() do banco; refresh() traz o valor gravado
        $apontamento = ApontamentoTempo::create(['os_id' => $os->id, 'user_id' => $userId])->refresh();

        if ($os->status === 'aguardando_servico') {
            $os->update(['status' => 'em_andamento']);
        }

        return response()->json($apontamento, 201);
    }

    // POST /api/minhas-os/{os}/pausar   { "motivo": "almoço" }
    public function pausar(Request $request, OrdemServico $os): JsonResponse
    {
        $userId = $request->user()->id;
        $this->garantirDesignado($os, $userId);

        $data = $request->validate(['motivo' => ['nullable', 'string', 'max:100']]);

        $apontamento = $this->apontamentoAberto($userId, $os->id);
        if (! $apontamento) {
            return response()->json(['message' => 'Nenhum serviço em andamento para pausar.'], 422);
        }

        $pausaAberta = ApontamentoPausa::where('apontamento_id', $apontamento->id)->whereNull('fim')->exists();
        if ($pausaAberta) {
            return response()->json(['message' => 'O serviço já está pausado.'], 422);
        }

        $pausa = ApontamentoPausa::create([
            'apontamento_id' => $apontamento->id,
            'motivo'         => $data['motivo'] ?? null,
        ])->refresh();

        return response()->json($pausa, 201);
    }

    // POST /api/minhas-os/{os}/retomar
    public function retomar(Request $request, OrdemServico $os): JsonResponse
    {
        $userId = $request->user()->id;
        $this->garantirDesignado($os, $userId);

        $apontamento = $this->apontamentoAberto($userId, $os->id);
        if (! $apontamento) {
            return response()->json(['message' => 'Nenhum serviço em andamento.'], 422);
        }

        $atualizadas = ApontamentoPausa::where('apontamento_id', $apontamento->id)
            ->whereNull('fim')
            ->update(['fim' => DB::raw('now()')]);

        if ($atualizadas === 0) {
            return response()->json(['message' => 'O serviço não está pausado.'], 422);
        }

        return response()->json(['message' => 'Serviço retomado.']);
    }

    // POST /api/minhas-os/{os}/finalizar   { "concluir_servico": true }
    public function finalizar(Request $request, OrdemServico $os): JsonResponse
    {
        $userId = $request->user()->id;
        $this->garantirDesignado($os, $userId);

        $data = $request->validate(['concluir_servico' => ['nullable', 'boolean']]);

        $apontamento = $this->apontamentoAberto($userId, $os->id);
        if (! $apontamento) {
            return response()->json(['message' => 'Nenhum serviço em andamento para finalizar.'], 422);
        }

        DB::transaction(function () use ($apontamento, $os, $data) {
            // fecha pausa que tenha ficado aberta
            ApontamentoPausa::where('apontamento_id', $apontamento->id)
                ->whereNull('fim')->update(['fim' => DB::raw('now()')]);

            ApontamentoTempo::whereKey($apontamento->id)->update(['fim' => DB::raw('now()')]);

            // "finalizar" encerra o turno de trabalho; "concluir_servico" marca a OS como pronta
            if (! empty($data['concluir_servico'])) {
                OrdemServico::whereKey($os->id)->update([
                    'status'       => 'pronto',
                    'concluido_em' => DB::raw('now()'),
                ]);
            }
        });

        $resumo = DB::selectOne(
            "select extract(epoch from (a.fim - a.inicio))
                    - coalesce((select sum(extract(epoch from (p.fim - p.inicio)))
                                  from apontamento_pausas p
                                 where p.apontamento_id = a.id and p.fim is not null), 0) as segundos
               from apontamentos_tempo a where a.id = ?",
            [$apontamento->id]
        );

        return response()->json([
            'apontamento'          => $apontamento->fresh(),
            'segundos_trabalhados' => (int) round($resumo->segundos),
            'servico_concluido'    => ! empty($data['concluir_servico']),
        ]);
    }

    // GET /api/meu-apontamento  (serviço em andamento, se houver)
    public function atual(Request $request): JsonResponse
    {
        $aberto = ApontamentoTempo::where('user_id', $request->user()->id)
            ->whereNull('fim')
            ->with(['os:id,titulo', 'pausas'])
            ->first();

        return response()->json($aberto);
    }

    // Pode ver/aceitar: quem está designado ou qualquer um, se a OS estiver livre
    private function garantirAcesso(OrdemServico $os, int $userId): void
    {
        $designado = $os->funcionarios()->where('users.id', $userId)->exists();
        $livre = $os->status === 'aguardando_servico' && ! $os->funcionarios()->exists();

        abort_unless($designado || $livre, 404);
    }

    // Só quem já pegou o serviço pode pausar, retomar ou finalizar
    private function garantirDesignado(OrdemServico $os, int $userId): void
    {
        abort_unless($os->funcionarios()->where('users.id', $userId)->exists(), 404);
    }

    private function apontamentoAberto(int $userId, int $osId): ?ApontamentoTempo
    {
        return ApontamentoTempo::where('user_id', $userId)
            ->where('os_id', $osId)
            ->whereNull('fim')
            ->first();
    }
}
