<?php

namespace App\Http\Controllers;

use App\Models\Agendamento;
use App\Models\OrdemServico;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgendaController extends Controller
{
    // GET /api/agenda?de=2026-11-01&ate=2026-11-30  (padrão: hoje até +30 dias)
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'de'  => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
        ]);

        $de  = Carbon::parse($request->input('de', today()->toDateString()))->startOfDay();
        $ate = Carbon::parse($request->input('ate', today()->addDays(30)->toDateString()))->endOfDay();

        $agenda = Agendamento::with([
                'os:id,titulo,status,cliente_id,veiculo_id',
                'os.cliente:id,nome,telefone',
                'os.veiculo:id,tipo,modelo,placa',
            ])
            ->whereBetween('data_hora', [$de, $ate])
            ->orderBy('data_hora')
            ->get();

        return response()->json($agenda);
    }

    // POST /api/ordens-servico/{os}/agendamentos
    public function store(Request $request, OrdemServico $os): JsonResponse
    {
        $data = $request->validate([
            'tipo'       => ['required', 'in:entrada,entrega,retorno'],
            'data_hora'  => ['required', 'date'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($os->agendamentos()->create($data), 201);
    }

    // PATCH /api/agendamentos/{agendamento}
    public function update(Request $request, Agendamento $agendamento): JsonResponse
    {
        $data = $request->validate([
            'tipo'       => ['sometimes', 'in:entrada,entrega,retorno'],
            'data_hora'  => ['sometimes', 'date'],
            'status'     => ['sometimes', 'in:agendado,realizado,cancelado,remarcado'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        $agendamento->update($data);

        return response()->json($agendamento->fresh());
    }
}
