<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    // GET /api/clientes?busca=maria
    public function index(Request $request): JsonResponse
    {
        $query = Cliente::orderBy('nome');

        if ($request->filled('busca')) {
            $busca = '%' . $request->input('busca') . '%';
            $query->where(function ($q) use ($busca) {
                $q->where('nome', 'ilike', $busca)->orWhere('telefone', 'like', $busca);
            });
        }

        return response()->json($query->paginate(50));
    }

    // POST /api/clientes
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome'        => ['required', 'string', 'max:150'],
            'telefone'    => ['required', 'string', 'max:20', 'unique:clientes,telefone'],
            'observacoes' => ['nullable', 'string'],
        ]);

        return response()->json(Cliente::create($data), 201);
    }

    // GET /api/clientes/{cliente}
    public function show(Cliente $cliente): JsonResponse
    {
        $cliente->load([
            'veiculos',
            'ordens' => fn ($q) => $q->latest()->limit(20),
        ]);

        return response()->json($cliente);
    }

    // PUT /api/clientes/{cliente}
    public function update(Request $request, Cliente $cliente): JsonResponse
    {
        $data = $request->validate([
            'nome'        => ['sometimes', 'required', 'string', 'max:150'],
            'telefone'    => ['sometimes', 'required', 'string', 'max:20',
                              Rule::unique('clientes', 'telefone')->ignore($cliente->id)],
            'observacoes' => ['nullable', 'string'],
        ]);

        $cliente->update($data);

        return response()->json($cliente->fresh());
    }
}
