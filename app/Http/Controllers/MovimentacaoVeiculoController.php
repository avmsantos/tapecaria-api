<?php

namespace App\Http\Controllers;

use App\Models\MovimentacaoVeiculo;
use App\Models\OrdemServico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MovimentacaoVeiculoController extends Controller
{
    // POST /api/ordens-servico/{os}/entrada-veiculo
    public function entrada(Request $request, OrdemServico $os): JsonResponse
    {
        $data = $request->validate(['observacoes' => ['nullable', 'string']]);

        if ($this->ultimoTipo($os) === 'entrada') {
            return response()->json(['message' => 'O veículo já está na loja.'], 422);
        }

        $mov = MovimentacaoVeiculo::create([
            'os_id'          => $os->id,
            'veiculo_id'     => $os->veiculo_id,
            'tipo'           => 'entrada',
            'responsavel_id' => $request->user()->id,
            'observacoes'    => $data['observacoes'] ?? null,
        ])->refresh();   // data_hora vem do now() do banco

        // veículo na loja: a OS fica aguardando um funcionário pegar o serviço
        if ($os->status === 'aguardando_entrada') {
            $os->update(['status' => 'aguardando_servico']);
        }

        return response()->json($mov, 201);
    }

    // POST /api/ordens-servico/{os}/saida-veiculo
    public function saida(Request $request, OrdemServico $os): JsonResponse
    {
        $data = $request->validate([
            'quem_retirou' => ['required', 'string', 'max:150'],
            'observacoes'  => ['nullable', 'string'],
        ]);

        if ($this->ultimoTipo($os) !== 'entrada') {
            return response()->json(['message' => 'O veículo não está na loja.'], 422);
        }

        if (! in_array($os->status, ['pronto', 'entregue'], true)) {
            return response()->json(['message' => 'O serviço ainda não está pronto. Marque a OS como "pronto" antes da saída.'], 422);
        }

        $mov = DB::transaction(function () use ($request, $os, $data) {
            $mov = MovimentacaoVeiculo::create([
                'os_id'          => $os->id,
                'veiculo_id'     => $os->veiculo_id,
                'tipo'           => 'saida',
                'responsavel_id' => $request->user()->id,
                'quem_retirou'   => $data['quem_retirou'],
                'observacoes'    => $data['observacoes'] ?? null,
            ])->refresh();

            $os->update(['status' => 'entregue']);

            if ($os->concluido_em === null) {
                OrdemServico::whereKey($os->id)->update(['concluido_em' => DB::raw('now()')]);
            }

            return $mov;
        });

        return response()->json($mov, 201);
    }

    // GET /api/ordens-servico/{os}/movimentacoes
    public function historico(OrdemServico $os): JsonResponse
    {
        $lista = MovimentacaoVeiculo::with('responsavel:id,nome')
            ->where('os_id', $os->id)
            ->orderBy('data_hora')
            ->get();

        return response()->json($lista);
    }

    // GET /api/veiculos-status?localizacao=na_loja
    public function status(Request $request): JsonResponse
    {
        $query = DB::table('v_veiculos_status')
            ->whereNotIn('status_os', ['entregue', 'cancelado']);

        if ($request->filled('localizacao')) {
            $query->where('localizacao', $request->input('localizacao'));
        }

        return response()->json($query->get());
    }

    private function ultimoTipo(OrdemServico $os): ?string
    {
        return MovimentacaoVeiculo::where('os_id', $os->id)
            ->orderByDesc('data_hora')
            ->orderByDesc('id')
            ->value('tipo');
    }
}
