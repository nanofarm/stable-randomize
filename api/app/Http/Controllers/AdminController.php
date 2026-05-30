<?php

namespace App\Http\Controllers;

use App\Models\Giveaway;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    private function envVar(string $key, string $default): string
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val !== false && $val !== null && $val !== '') {
            return trim((string) $val);
        }

        $path = base_path('.env');
        if (file_exists($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$k, $v] = explode('=', $line, 2);
                if (trim($k) === $key) {
                    return trim($v, " \t\n\r\0\x0B\"'");
                }
            }
        }

        return $default;
    }

    private function requireAdmin(Request $request): void
    {
        $expectedLogin = $this->envVar('ADMIN_LOGIN', 'admin');
        $expectedPass = $this->envVar('ADMIN_PASSWORD', 'secret');

        $auth = $request->header('Authorization');
        if (!$auth || !str_starts_with($auth, 'Basic ')) {
            abort(401, 'Unauthorized');
        }

        $decoded = base64_decode(substr($auth, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            abort(401, 'Unauthorized');
        }

        [$login, $pass] = array_pad(explode(':', $decoded, 2), 2, null);

        if (!hash_equals($expectedLogin, (string) ($login ?? '')) || !hash_equals($expectedPass, (string) ($pass ?? ''))) {
            abort(403, 'Forbidden');
        }
    }

    public function login(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        return response()->json(['ok' => true]);
    }

    public function giveaways(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(10, (int) $request->query('limit', 20)));

        $query = Giveaway::query()
            ->withCount('participants')
            ->with('channels')
            ->orderByDesc('created_at');

        $total = $query->count();
        $items = $query->offset(($page - 1) * $limit)->limit($limit)->get();

        return response()->json([
            'ok' => true,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $limit),
            'data' => $items->map(fn ($g) => [
                'id' => $g->id,
                'public_id' => $g->public_id,
                'title' => $g->title,
                'status' => $g->status,
                'creator_id' => $g->creator_id,
                'participant_count' => $g->participants_count,
                'channels' => $g->channels->map(fn ($c) => [
                    'username' => $c->username,
                    'title' => $c->title,
                    'invite_link' => $c->invite_link,
                ])->toArray(),
                'end_date' => $g->end_date?->toISOString(),
                'created_at' => $g->created_at?->toISOString(),
            ]),
        ]);
    }

    public function participants(Request $request, string $publicId): JsonResponse
    {
        $this->requireAdmin($request);

        $giveaway = Giveaway::where('public_id', $publicId)->first();
        if (!$giveaway) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(500, max(10, (int) $request->query('limit', 50)));

        $query = Participant::where('giveaway_id', $giveaway->id)
            ->orderByDesc('created_at');

        $total = $query->count();
        $items = $query->offset(($page - 1) * $limit)->limit($limit)->get();

        return response()->json([
            'ok' => true,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $limit),
            'data' => $items,
        ]);
    }

    public function updateParticipant(Request $request, int $id): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'Disabled'], 403);
    }

    public function deleteParticipant(Request $request, int $id): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'Disabled'], 403);
    }
}
