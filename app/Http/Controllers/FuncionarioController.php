<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FuncionarioController extends Controller
{
    // GET /api/funcionarios?ativo=1
    public function index(Request $request): JsonResponse
    {
        $query = User::where('role', 'funcionario')->orderBy('nome');

        if ($request->filled('ativo')) {
            $query->where('ativo', $request->boolean('ativo'));
        }

        return response()->json($query->get());
    }

    // POST /api/funcionarios
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome'          => ['required', 'string', 'max:150'],
            'login'         => ['required', 'string', 'max:60', 'unique:users,login'],
            'telefone'      => ['nullable', 'string', 'max:20'],
            'funcao'        => ['nullable', 'string', 'max:60'],
            'valor_hora'    => ['nullable', 'numeric', 'min:0'],
            'data_admissao' => ['nullable', 'date'],
        ]);

        $senhaTemporaria = (string) random_int(100000, 999999);

        $funcionario = User::create($data + [
            'role'                 => 'funcionario',
            'password'             => $senhaTemporaria,
            'ativo'                => true,
            'must_change_password' => true,
        ]);

        return response()->json([
            'funcionario'      => $funcionario->fresh(),
            'senha_temporaria' => $senhaTemporaria,   // mostrada só agora, passe ao funcionário
        ], 201);
    }

    // GET /api/funcionarios/{funcionario}
    public function show(User $funcionario): JsonResponse
    {
        $this->garantirFuncionario($funcionario);

        return response()->json($funcionario);
    }

    // PUT /api/funcionarios/{funcionario}
    public function update(Request $request, User $funcionario): JsonResponse
    {
        $this->garantirFuncionario($funcionario);

        $data = $request->validate([
            'nome'          => ['sometimes', 'required', 'string', 'max:150'],
            'login'         => ['sometimes', 'required', 'string', 'max:60',
                                Rule::unique('users', 'login')->ignore($funcionario->id)],
            'telefone'      => ['nullable', 'string', 'max:20'],
            'funcao'        => ['nullable', 'string', 'max:60'],
            'valor_hora'    => ['nullable', 'numeric', 'min:0'],
            'data_admissao' => ['nullable', 'date'],
        ]);

        $funcionario->update($data);

        return response()->json($funcionario->fresh());
    }

    // PATCH /api/funcionarios/{funcionario}/desativar
    public function desativar(User $funcionario): JsonResponse
    {
        $this->garantirFuncionario($funcionario);

        $funcionario->update(['ativo' => false]);
        $funcionario->tokens()->delete();   // derruba o acesso na hora

        return response()->json(['message' => 'Funcionário desativado.']);
    }

    // PATCH /api/funcionarios/{funcionario}/ativar
    public function ativar(User $funcionario): JsonResponse
    {
        $this->garantirFuncionario($funcionario);

        $funcionario->update(['ativo' => true]);

        return response()->json(['message' => 'Funcionário ativado.']);
    }

    // POST /api/funcionarios/{funcionario}/redefinir-senha
    public function redefinirSenha(User $funcionario): JsonResponse
    {
        $this->garantirFuncionario($funcionario);

        $senhaTemporaria = (string) random_int(100000, 999999);

        $funcionario->update([
            'password'             => $senhaTemporaria,
            'must_change_password' => true,
        ]);
        $funcionario->tokens()->delete();

        return response()->json(['senha_temporaria' => $senhaTemporaria]);
    }

    // Esta área gerencia só funcionários (nunca outros admins)
    private function garantirFuncionario(User $user): void
    {
        abort_if($user->role !== 'funcionario', 404);
    }
}
