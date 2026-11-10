<?php

namespace App\Http\Controllers;

use App\Models\OrdemServico;
use App\Models\OsFoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FotoController extends Controller
{
    // POST /api/ordens-servico/{os}/fotos   (multipart/form-data)
    // Admin envia em qualquer OS; funcionário só nas OS em que foi designado.
    public function store(Request $request, OrdemServico $os): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            $designado = $os->funcionarios()->where('users.id', $user->id)->exists();
            abort_unless($designado, 404);
        }

        $data = $request->validate([
            'foto'            => ['required', 'image', 'max:8192'],   // até 8 MB
            'momento'         => ['nullable', 'in:entrada,andamento,saida'],
            'legenda'         => ['nullable', 'string', 'max:150'],
            'movimentacao_id' => ['nullable', 'integer', 'exists:movimentacao_veiculos,id'],
        ]);

        $path = $request->file('foto')->store("os/{$os->id}", 'public');

        $foto = OsFoto::create([
            'os_id'           => $os->id,
            'movimentacao_id' => $data['movimentacao_id'] ?? null,
            'momento'         => $data['momento'] ?? 'andamento',
            'path'            => $path,
            'legenda'         => $data['legenda'] ?? null,
            'user_id'         => $user->id,
        ]);

        return response()->json([
            'foto' => $foto->fresh(),
            'url'  => Storage::disk('public')->url($path),
        ], 201);
    }

    // DELETE /api/fotos/{foto}  (admin)
    public function destroy(OsFoto $foto): JsonResponse
    {
        Storage::disk('public')->delete($foto->path);
        $foto->delete();

        return response()->json(['message' => 'Foto removida.']);
    }
}
