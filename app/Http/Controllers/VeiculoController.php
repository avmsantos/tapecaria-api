<?php

namespace App\Http\Controllers;

use App\Models\Veiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VeiculoController extends Controller
{
    // GET /api/veiculos?cliente_id=1
    public function index(Request $request): JsonResponse
    {
        $query = Veiculo::with('cliente:id,nome,telefone')->orderBy('modelo');

        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->input('cliente_id'));
        }

        return response()->json($query->paginate(50));
    }

    // POST /api/veiculos
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'tipo'       => ['required', 'in:carro,moto'],
            'marca'      => ['nullable', 'string', 'max:60'],
            'modelo'     => ['required', 'string', 'max:80'],
            'placa'      => ['nullable', 'string', 'max:10'],
            'cor'        => ['nullable', 'string', 'max:30'],
            'ano'        => ['nullable', 'integer', 'between:1900,2100'],
        ]);

        return response()->json(Veiculo::create($data), 201);
    }

    // GET /api/veiculos/{veiculo}
    public function show(Veiculo $veiculo): JsonResponse
    {
        return response()->json($veiculo->load('cliente:id,nome,telefone'));
    }

    // PUT /api/veiculos/{veiculo}
    public function update(Request $request, Veiculo $veiculo): JsonResponse
    {
        $data = $request->validate([
            'tipo'   => ['sometimes', 'required', 'in:carro,moto'],
            'marca'  => ['nullable', 'string', 'max:60'],
            'modelo' => ['sometimes', 'required', 'string', 'max:80'],
            'placa'  => ['nullable', 'string', 'max:10'],
            'cor'    => ['nullable', 'string', 'max:30'],
            'ano'    => ['nullable', 'integer', 'between:1900,2100'],
        ]);

        $veiculo->update($data);

        return response()->json($veiculo->fresh());
    }
}
