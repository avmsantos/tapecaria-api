<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // POST /api/login
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login'       => ['required', 'string'],
            'password'    => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'fcm_token'   => ['nullable', 'string'],
        ]);

        $user = User::where('login', $data['login'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Login ou senha inválidos.'], 401);
        }

        if (! $user->ativo) {
            return response()->json(['message' => 'Acesso desativado. Fale com a administração.'], 403);
        }

        if (! empty($data['fcm_token'])) {
            $user->update(['fcm_token' => $data['fcm_token']]);
        }

        $token = $user->createToken($data['device_name'] ?? 'app')->plainTextToken;

        return response()->json([
            'token'                => $token,
            'must_change_password' => $user->must_change_password,
            'user'                 => $user->makeHidden('valor_hora'),
        ]);
    }

    // GET /api/me
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->makeHidden('valor_hora'));
    }

    // POST /api/logout
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    // POST /api/trocar-senha
    public function trocarSenha(Request $request): JsonResponse
    {
        $data = $request->validate([
            'senha_atual' => ['required', 'string'],
            'nova_senha'  => ['required', 'string', 'min:6', 'different:senha_atual'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['senha_atual'], $user->password)) {
            return response()->json(['message' => 'A senha atual está incorreta.'], 422);
        }

        $user->update([
            'password'             => $data['nova_senha'],  // o model já criptografa
            'must_change_password' => false,
        ]);

        // encerra as outras sessões (outros aparelhos), mantém a atual
        $user->tokens()
            ->where('id', '!=', $request->user()->currentAccessToken()->id)
            ->delete();

        return response()->json(['message' => 'Senha alterada com sucesso.']);
    }

    // PUT /api/fcm-token  (para notificações push)
    public function atualizarFcmToken(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => ['required', 'string']]);

        $request->user()->update(['fcm_token' => $data['fcm_token']]);

        return response()->json(['message' => 'Token atualizado.']);
    }
}
