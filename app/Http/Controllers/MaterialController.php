<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MovimentacaoMaterial;
use App\Models\OrdemServico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaterialController extends Controller
{
    // GET /api/materiais   (admin e funcionário; funcionário não vê custo)
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user()->isAdmin();

        $lista = Material::where('ativo', true)->orderBy('nome')->get()
            ->each(function (Material $m) use ($admin) {
                $m->setAttribute(
                    'abaixo_do_minimo',
                    (float) $m->estoque_minimo > 0 && (float) $m->estoque_atual <= (float) $m->estoque_minimo
                );
                if (! $admin) {
                    $m->makeHidden('custo_unitario');
                }
            });

        return response()->json($lista);
    }

    // POST /api/materiais
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome'            => ['required', 'string', 'max:120'],
            'unidade'         => ['nullable', 'string', 'max:10'],
            'estoque_minimo'  => ['nullable', 'numeric', 'min:0'],
            'custo_unitario'  => ['nullable', 'numeric', 'min:0'],
            'estoque_inicial' => ['nullable', 'numeric', 'min:0'],
        ]);

        $material = DB::transaction(function () use ($data, $request) {
            $material = Material::create([
                'nome'           => $data['nome'],
                'unidade'        => $data['unidade'] ?? 'un',
                'estoque_minimo' => $data['estoque_minimo'] ?? 0,
                'custo_unitario' => $data['custo_unitario'] ?? 0,
            ]);

            if (! empty($data['estoque_inicial']) && $data['estoque_inicial'] > 0) {
                MovimentacaoMaterial::create([
                    'material_id'    => $material->id,
                    'tipo'           => 'entrada',
                    'quantidade'     => $data['estoque_inicial'],
                    'custo_unitario' => $material->custo_unitario,
                    'user_id'        => $request->user()->id,
                    'observacao'     => 'Estoque inicial',
                ]);
            }

            return $material;
        });

        return response()->json($material->fresh(), 201);
    }

    // PUT /api/materiais/{material}
    public function update(Request $request, Material $material): JsonResponse
    {
        $data = $request->validate([
            'nome'           => ['sometimes', 'required', 'string', 'max:120'],
            'unidade'        => ['sometimes', 'required', 'string', 'max:10'],
            'estoque_minimo' => ['nullable', 'numeric', 'min:0'],
            'custo_unitario' => ['nullable', 'numeric', 'min:0'],
            'ativo'          => ['sometimes', 'boolean'],
        ]);

        $material->update($data);

        return response()->json($material->fresh());
    }

    // POST /api/materiais/{material}/entrada   (compra / reposição)
    public function entrada(Request $request, Material $material): JsonResponse
    {
        $data = $request->validate([
            'quantidade'     => ['required', 'numeric', 'gt:0'],
            'custo_unitario' => ['nullable', 'numeric', 'min:0'],
            'observacao'     => ['nullable', 'string', 'max:255'],
        ]);

        $mov = MovimentacaoMaterial::create([
            'material_id'    => $material->id,
            'tipo'           => 'entrada',
            'quantidade'     => $data['quantidade'],
            'custo_unitario' => $data['custo_unitario'] ?? $material->custo_unitario,
            'user_id'        => $request->user()->id,
            'observacao'     => $data['observacao'] ?? null,
        ]);

        // o trigger do banco atualiza estoque_atual e o último custo
        return response()->json(['movimentacao' => $mov->fresh(), 'material' => $material->fresh()], 201);
    }

    // POST /api/materiais/{material}/saida   (admin)
    public function saida(Request $request, Material $material): JsonResponse
    {
        $data = $request->validate([
            'quantidade' => ['required', 'numeric', 'gt:0'],
            'os_id'      => ['nullable', 'integer', 'exists:ordens_servico,id'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        $mov = $this->registrarSaida(
            $material->id, (float) $data['quantidade'], $data['os_id'] ?? null,
            $request->user()->id, $data['observacao'] ?? null
        );

        return response()->json(['movimentacao' => $mov, 'material' => $material->fresh()], 201);
    }

    // POST /api/minhas-os/{os}/materiais   (funcionário baixa o que usou)
    public function saidaFuncionario(Request $request, OrdemServico $os): JsonResponse
    {
        $user = $request->user();
        abort_unless($os->funcionarios()->where('users.id', $user->id)->exists(), 404);

        $data = $request->validate([
            'material_id' => ['required', 'integer', 'exists:materiais,id'],
            'quantidade'  => ['required', 'numeric', 'gt:0'],
            'observacao'  => ['nullable', 'string', 'max:255'],
        ]);

        $mov = $this->registrarSaida(
            $data['material_id'], (float) $data['quantidade'], $os->id,
            $user->id, $data['observacao'] ?? null
        );

        return response()->json($mov, 201);
    }

    // GET /api/materiais/{material}/movimentacoes
    public function movimentacoes(Material $material): JsonResponse
    {
        $lista = MovimentacaoMaterial::where('material_id', $material->id)
            ->orderByDesc('data_hora')->orderByDesc('id')
            ->limit(100)->get();

        return response()->json($lista);
    }

    // POST /api/movimentacoes-material/{movimentacao}/estornar   { "motivo": "quantidade errada" }
    // Gera um lançamento contrário (entrada vira saída e vice-versa) e marca o original como estornado.
    public function estornar(Request $request, MovimentacaoMaterial $movimentacao): JsonResponse
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'max:200']]);

        return DB::transaction(function () use ($request, $movimentacao, $data) {
            $orig = MovimentacaoMaterial::whereKey($movimentacao->id)->lockForUpdate()->firstOrFail();

            if ($orig->estornado_em !== null || $orig->estorno_de_id !== null) {
                abort(response()->json(['message' => 'Este lançamento não pode ser estornado.'], 422));
            }

            $material = Material::whereKey($orig->material_id)->lockForUpdate()->firstOrFail();

            // estornar uma ENTRADA tira material do estoque: precisa haver saldo
            if ($orig->tipo === 'entrada' && (float) $material->estoque_atual < (float) $orig->quantidade) {
                abort(response()->json([
                    'message' => "Não dá para estornar: o estoque atual de {$material->nome} "
                        . "({$material->estoque_atual} {$material->unidade}) é menor que a entrada.",
                ], 422));
            }

            MovimentacaoMaterial::create([
                'material_id'    => $orig->material_id,
                'tipo'           => $orig->tipo === 'entrada' ? 'saida' : 'entrada',
                'quantidade'     => $orig->quantidade,
                'custo_unitario' => $orig->custo_unitario,
                'os_id'          => $orig->os_id,
                'user_id'        => $request->user()->id,
                'observacao'     => mb_substr('Estorno do lançamento #' . $orig->id . ': ' . $data['motivo'], 0, 255),
                'estorno_de_id'  => $orig->id,
            ]);

            MovimentacaoMaterial::whereKey($orig->id)->update([
                'estornado_em'   => DB::raw('now()'),
                'estornado_por'  => $request->user()->id,
                'motivo_estorno' => $data['motivo'],
            ]);

            return response()->json(['material' => $material->fresh()]);
        });
    }

    // Baixa de estoque com trava, para dois lançamentos simultâneos não estourarem o saldo
    private function registrarSaida(int $materialId, float $qtd, ?int $osId, int $userId, ?string $obs): MovimentacaoMaterial
    {
        return DB::transaction(function () use ($materialId, $qtd, $osId, $userId, $obs) {
            $material = Material::whereKey($materialId)->lockForUpdate()->firstOrFail();

            if ((float) $material->estoque_atual < $qtd) {
                abort(response()->json([
                    'message' => "Estoque insuficiente de {$material->nome}. Disponível: {$material->estoque_atual} {$material->unidade}.",
                ], 422));
            }

            return MovimentacaoMaterial::create([
                'material_id'    => $material->id,
                'tipo'           => 'saida',
                'quantidade'     => $qtd,
                'custo_unitario' => $material->custo_unitario,   // custo congelado no momento
                'os_id'          => $osId,
                'user_id'        => $userId,
                'observacao'     => $obs,
            ])->fresh();
        });
    }
}
