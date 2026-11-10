<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    private const ETAPAS = 'novo,em_atendimento,orcamento,fechado,perdido';

    // GET /api/leads?etapa=perdido
    public function index(Request $request): JsonResponse
    {
        $query = Lead::with('cliente:id,nome')->latest();

        if ($request->filled('etapa')) {
            $query->where('etapa', $request->input('etapa'));
        }

        return response()->json($query->paginate(30));
    }

    // POST /api/leads
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome'         => ['nullable', 'string', 'max:150'],
            'telefone'     => ['required', 'string', 'max:20'],
            'origem'       => ['nullable', 'string', 'max:40'],
            'etapa'        => ['nullable', 'in:' . self::ETAPAS],
            'motivo_perda' => ['required_if:etapa,perdido', 'nullable', 'string', 'max:255'],
            'resumo_ia'    => ['nullable', 'string'],
            'cliente_id'   => ['nullable', 'integer', 'exists:clientes,id'],
        ]);

        return response()->json(Lead::create($data)->fresh(), 201);
    }

    // PUT /api/leads/{lead}   (para marcar como perdido, envie etapa + motivo_perda)
    public function update(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'nome'         => ['nullable', 'string', 'max:150'],
            'etapa'        => ['sometimes', 'in:' . self::ETAPAS],
            'motivo_perda' => ['required_if:etapa,perdido', 'nullable', 'string', 'max:255'],
            'resumo_ia'    => ['nullable', 'string'],
            'cliente_id'   => ['nullable', 'integer', 'exists:clientes,id'],
        ]);

        $lead->update($data);

        return response()->json($lead->fresh());
    }
}
