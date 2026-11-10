<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\OrdemServico;
use App\Models\Veiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrdemServicoController extends Controller
{
    private const STATUS = ['aguardando_entrada', 'aguardando_servico', 'em_andamento', 'pronto', 'entregue', 'cancelado'];

    // GET /api/ordens-servico?status=em_andamento&cliente_id=1
    public function index(Request $request): JsonResponse
    {
        $query = OrdemServico::with([
            'cliente:id,nome,telefone',
            'veiculo:id,tipo,modelo,placa',
            'funcionarios:id,nome',
        ])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->input('cliente_id'));
        }

        return response()->json($query->paginate(30));
    }

    // POST /api/ordens-servico
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cliente_id'              => ['required', 'integer', 'exists:clientes,id'],
            'veiculo_id'              => ['required', 'integer', 'exists:veiculos,id'],
            'lead_id'                 => ['nullable', 'integer', 'exists:leads,id'],
            'titulo'                  => ['required', 'string', 'max:150'],
            'descricao'               => ['nullable', 'string'],
            'instrucoes'              => ['nullable', 'string'],
            'valor_orcado'            => ['nullable', 'numeric', 'min:0'],
            'data_prevista'           => ['nullable', 'date'],
            'funcionarios'            => ['nullable', 'array'],
            'funcionarios.*'          => ['integer', $this->regraFuncionarioAtivo()],
            'agendamento'             => ['nullable', 'array'],
            'agendamento.tipo'        => ['required_with:agendamento', 'in:entrada,entrega,retorno'],
            'agendamento.data_hora'   => ['required_with:agendamento', 'date'],
            'agendamento.observacao'  => ['nullable', 'string', 'max:255'],
        ]);

        // o veículo precisa ser do cliente informado
        $veiculoDoCliente = Veiculo::where('id', $data['veiculo_id'])
            ->where('cliente_id', $data['cliente_id'])
            ->exists();

        if (! $veiculoDoCliente) {
            return response()->json(['message' => 'Este veículo não pertence ao cliente informado.'], 422);
        }

        $os = DB::transaction(function () use ($data, $request) {
            $campos = Arr::only($data, [
                'cliente_id', 'veiculo_id', 'lead_id', 'titulo',
                'descricao', 'instrucoes', 'valor_orcado', 'data_prevista',
            ]);
            $campos['valor_orcado'] = $campos['valor_orcado'] ?? 0;
            $campos['criado_por'] = $request->user()->id;

            $os = OrdemServico::create($campos);

            if (! empty($data['funcionarios'])) {
                $os->funcionarios()->sync($data['funcionarios']);
            }
            if (! empty($data['agendamento'])) {
                $os->agendamentos()->create($data['agendamento']);
            }
            if (! empty($data['lead_id'])) {
                Lead::whereKey($data['lead_id'])->update(['etapa' => 'fechado']);
            }

            return $os;
        });

        return response()->json($this->carregar($os), 201);
    }

    // GET /api/ordens-servico/{os}
    public function show(OrdemServico $os): JsonResponse
    {
        return response()->json($this->carregar($os));
    }

    // PUT /api/ordens-servico/{os}
    public function update(Request $request, OrdemServico $os): JsonResponse
    {
        $data = $request->validate([
            'titulo'        => ['sometimes', 'required', 'string', 'max:150'],
            'descricao'     => ['nullable', 'string'],
            'instrucoes'    => ['nullable', 'string'],
            'valor_orcado'  => ['nullable', 'numeric', 'min:0'],
            'valor_final'   => ['nullable', 'numeric', 'min:0'],
            'data_prevista' => ['nullable', 'date'],
            'status'        => ['sometimes', Rule::in(self::STATUS)],
            'veiculo_id'    => ['sometimes', 'integer', 'exists:veiculos,id'],
        ]);

        // o veículo escolhido precisa ser do mesmo cliente da OS
        if (isset($data['veiculo_id'])) {
            $pertence = Veiculo::where('id', $data['veiculo_id'])->where('cliente_id', $os->cliente_id)->exists();
            if (! $pertence) {
                return response()->json(['message' => 'Este veículo não pertence ao cliente da OS.'], 422);
            }
        }

        // a coluna do orçamento não aceita vazio
        if (array_key_exists('valor_orcado', $data) && $data['valor_orcado'] === null) {
            $data['valor_orcado'] = 0;
        }

        $os->update($data);

        // horário de conclusão gravado pelo banco (não pelo celular)
        if (isset($data['status']) && in_array($data['status'], ['pronto', 'entregue'], true)
            && $os->concluido_em === null) {
            OrdemServico::whereKey($os->id)->update(['concluido_em' => DB::raw('now()')]);
        }

        return response()->json($this->carregar($os));
    }

    // POST /api/ordens-servico/{os}/funcionarios   { "user_ids": [2, 3] }
    public function atribuirFuncionarios(Request $request, OrdemServico $os): JsonResponse
    {
        $data = $request->validate([
            'user_ids'   => ['required', 'array'],
            'user_ids.*' => ['integer', $this->regraFuncionarioAtivo()],
        ]);

        // sync mantém quem já estava (e o aceite dele) e remove quem saiu
        $os->funcionarios()->sync($data['user_ids']);

        return response()->json($this->carregar($os));
    }

    private function regraFuncionarioAtivo()
    {
        return Rule::exists('users', 'id')->where(
            fn ($q) => $q->where('role', 'funcionario')->where('ativo', true)
        );
    }

    private function carregar(OrdemServico $os): OrdemServico
    {
        return $os->fresh()->load([
            'cliente', 'veiculo', 'funcionarios:id,nome,funcao',
            'agendamentos', 'apontamentos.funcionario:id,nome', 'fotos',
        ]);
    }
}
