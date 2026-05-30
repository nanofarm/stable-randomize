<?php
namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Giveaway;
use App\Models\GiveawayDrawAudit;
use App\Models\GiveawayMessage;
use App\Models\Prize;
use App\Models\Participant;
use App\Services\TelegramService;
use App\Services\BotSenderService;
use App\Services\GeoIpService;
use App\Services\GiveawayDrawAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GiveawayController extends Controller
{
    private string $botUsername;
    private ?string $miniAppShortName;

    public function __construct(
        private TelegramService $telegram,
        private BotSenderService $botSender,
        private GeoIpService $geoIp,
        private GiveawayDrawAuditService $drawAuditService,
    ) {
        $this->botUsername = 'YourBotUsername';
        $this->miniAppShortName = config('services.telegram.mini_app_short_name');
    }

    private function resolveBotUsername(): string
    {
        if ($this->botUsername !== 'YourBotUsername') {
            return $this->botUsername;
        }

        try {
            $resolved = $this->telegram->getBotUsername();
            if (is_string($resolved) && $resolved !== '') {
                $this->botUsername = $resolved;
            }
        } catch (\Throwable $e) {
        }

        return $this->botUsername;
    }

    private function drawAuditSummary(?GiveawayDrawAudit $audit): ?array
    {
        if (!$audit) {
            return null;
        }
        return $this->drawAuditService->publicSummary($audit);
    }

    
    private function userId(Request $request): ?int
    {
        $tgId = $request->attributes->get('tg_user_id');
        return $tgId ? (int) $tgId : null;
    }

    
    private function requireAuth(Request $request): int
    {
        $userId = $this->userId($request);
        if (!$userId) {
            abort(401, 'Unauthorized');
        }
        return $userId;
    }

    
    private function isTrustedBotCall(Request $request): bool
    {
        $tgUser = $request->attributes->get('tg_user');
        return is_array($tgUser) && !empty($tgUser['__bot']);
    }

    public function all(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));
        $p = Giveaway::active()->withCount('participants')
            ->with(['channels', 'prizes'])
            ->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'ok' => true,
            'giveaways' => collect($p->items())->map(fn($g) => $this->format($g)),
            'pagination' => [
                'total' => $p->total(),
                'per_page' => $p->perPage(),
                'current_page' => $p->currentPage(),
                'last_page' => $p->lastPage(),
            ],
        ]);
    }

    public function userGiveaways(Request $request): JsonResponse
    {
        $uid = $this->userId($request);
        if (!$uid) return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);

        $limit = min(100, max(10, (int) $request->query('limit', 50)));

        $created = Giveaway::where('creator_id', $uid)
            ->whereIn('status', ['draft', 'active', 'finished'])
            ->withCount('participants')
            ->with(['winners:id,giveaway_id,user_id,user_name,winner_place', 'channels', 'prizes'])
            ->orderByDesc('created_at')->limit($limit)->get()
            ->map(fn($g) => $this->format($g));

        $pIds = Participant::where('user_id', $uid)->pluck('giveaway_id');
        $participated = Giveaway::whereIn('id', $pIds)->withCount('participants')
            ->with(['winners:id,giveaway_id,user_id,user_name,winner_place', 'channels', 'prizes'])
            ->orderByDesc('created_at')->limit($limit)->get()
            ->map(fn($g) => $this->format($g));

        return response()->json(['ok' => true, 'created' => $created, 'participated' => $participated]);
    }

    public function store(Request $request): JsonResponse
    {
        $creatorId = $this->requireAuth($request);

        $v = Validator::make($request->all(), [
            'title' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'prize' => 'nullable|string|max:200',
            'winners_count' => 'nullable|integer|min:1|max:50',
            'creator_id' => 'required|integer|in:' . $creatorId,
            'creator_name' => 'required|string|max:100',
            'channel_ids' => 'nullable|array',
            'channel_ids.*' => 'integer|exists:channels,id',
            'prizes' => 'nullable|array',
            'prizes.*.place' => 'required|integer|min:1',
            'prizes.*.title' => 'required|string|max:200',
            'tasks' => 'nullable|array|max:5',
            'tasks.*.text' => 'required|string|max:300',
            'tasks.*.price' => 'required|integer|min:1|max:30',
            'nickname_bonus_multiplier' => 'nullable|integer|min:2|max:100',
            'referral_tickets' => 'nullable|integer|min:1|max:20',
            'end_date' => 'nullable|date|after:now',
            'start_date' => 'nullable|date',
            'nickname_condition' => 'nullable|string|max:50',
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $tasks = $request->input('tasks', []) ?? [];
        $totalTaskTickets = array_sum(array_map(fn($t) => (int) ($t['price'] ?? 0), $tasks));
        if ($totalTaskTickets > 30) {
            return response()->json([
                'ok' => false,
                'error' => "Слишком много билетов в заданиях ({$totalTaskTickets}). Максимум — 30. Уменьши цены",
            ], 422);
        }

        $draftCount = Giveaway::where('creator_id', $creatorId)->where('status', 'draft')->count();
        if ($draftCount >= 20) {
            return response()->json([
                'ok' => false,
                'error' => 'Слишком много черновиков (макс. 20). Удали или запусти существующие.',
            ], 429);
        }

        $giveaway = DB::transaction(function () use ($request, $creatorId, $tasks) {
            $tasksWithIds = [];
            foreach ($tasks as $i => $t) {
                $tasksWithIds[] = [
                    'id' => $i + 1,
                    'text' => trim((string) $t['text']),
                    'price' => (int) $t['price'],
                ];
            }

            $giveaway = Giveaway::create(array_merge($request->only([
                'title', 'description', 'prize', 'winners_count', 'creator_name', 'start_date', 'end_date',
                'nickname_condition', 'nickname_bonus_multiplier', 'referral_tickets',
            ]), [
                'status' => 'draft',
                'creator_id' => $creatorId,
                'tasks' => $tasksWithIds ?: null,
            ]));
            if ($request->has('channel_ids')) {
                $validIds = Channel::whereIn('id', $request->input('channel_ids'))
                    ->where('owner_id', $creatorId)->pluck('id');
                $giveaway->channels()->attach($validIds);
            }
            if ($request->has('prizes')) {
                foreach ($request->input('prizes') as $prize) {
                    Prize::create([
                        'giveaway_id' => $giveaway->id,
                        'place' => $prize['place'],
                        'title' => $prize['title'],
                    ]);
                }
            }
            return $giveaway;
        });
        $giveaway->load('channels', 'prizes');
        return response()->json(['ok' => true, 'giveaway' => $this->format($giveaway)], 201);
    }

    
    public function submitTask(Request $request): JsonResponse
    {
        if ($this->isTrustedBotCall($request)) {
            $userId = (int) $request->input('user_id');
            if (!$userId) {
                return response()->json(['ok' => false, 'error' => 'user_id required'], 422);
            }
        } else {
            $userId = $this->requireAuth($request);
        }

        $v = Validator::make($request->all(), [
            'giveaway_id' => 'required|string',
            'task_id' => 'required|integer|min:1',
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $g = Giveaway::where('public_id', trim((string) $request->input('giveaway_id')))->first();
        if (!$g) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if ($g->status !== 'active') return response()->json(['ok' => false, 'error' => 'Розыгрыш не активен'], 400);

        $isParticipant = $g->participants()->where('user_id', $userId)->exists();
        if (!$isParticipant) return response()->json(['ok' => false, 'error' => 'Сначала прими участие'], 403);

        $taskId = (int) $request->input('task_id');
        $tasks = is_array($g->tasks) ? $g->tasks : [];
        $task = collect($tasks)->firstWhere('id', $taskId);
        if (!$task) return response()->json(['ok' => false, 'error' => 'Задание не найдено'], 404);

        $existing = \App\Models\GiveawayTaskSubmission::where('giveaway_id', $g->id)
            ->where('user_id', $userId)
            ->where('task_id', $taskId)
            ->first();
        if ($existing) {
            $msg = match($existing->status) {
                'approved' => 'Уже подтверждено',
                'rejected' => 'Задание отклонено',
                default => 'Уже на проверке',
            };
            return response()->json(['ok' => false, 'error' => $msg], 400);
        }

        try {
            $sub = \App\Models\GiveawayTaskSubmission::create([
                'giveaway_id' => $g->id,
                'user_id' => $userId,
                'task_id' => $taskId,
                'status' => 'pending',
                'submitted_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json(['ok' => false, 'error' => 'Уже на проверке'], 409);
            }
            throw $e;
        }

        return response()->json(['ok' => true, 'submission_id' => $sub->id]);
    }

    
    public function taskDecision(Request $request): JsonResponse
    {
        if ($this->isTrustedBotCall($request)) {
            $deciderId = (int) $request->input('user_id');
            if (!$deciderId) {
                return response()->json(['ok' => false, 'error' => 'user_id required'], 422);
            }
        } else {
            $deciderId = $this->requireAuth($request);
        }
        $v = Validator::make($request->all(), [
            'submission_id' => 'required|integer',
            'approve' => 'required|boolean',
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $sub = \App\Models\GiveawayTaskSubmission::find((int) $request->input('submission_id'));
        if (!$sub) return response()->json(['ok' => false, 'error' => 'Заявка не найдена'], 404);

        $g = Giveaway::find($sub->giveaway_id);
        if (!$g) return response()->json(['ok' => false, 'error' => 'Розыгрыш не найден'], 404);

        if ((int) $g->creator_id !== $deciderId) {
            return response()->json(['ok' => false, 'error' => 'Только создатель розыгрыша может решать'], 403);
        }

        $approve = (bool) $request->input('approve');
        $tasks = is_array($g->tasks) ? $g->tasks : [];
        $task = collect($tasks)->firstWhere('id', $sub->task_id);
        $taskText = $task['text'] ?? '';
        $taskPrice = (int) ($task['price'] ?? 0);

        $result = DB::transaction(function () use ($sub, $g, $approve, $taskPrice) {
            $locked = \App\Models\GiveawayTaskSubmission::where('id', $sub->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                return ['__error' => 'Решение уже принято'];
            }

            $ticketsAwarded = 0;

            if ($approve) {
                $participant = $g->participants()->where('user_id', $locked->user_id)->first();
                if ($participant) {
                    $participant->tickets = ($participant->tickets ?? 1) + $taskPrice;
                    $participant->save();
                    $ticketsAwarded = $taskPrice;
                }
            }

            $locked->update([
                'status' => $approve ? 'approved' : 'rejected',
                'tickets_awarded' => $ticketsAwarded,
                'decided_at' => now(),
            ]);

            return ['tickets_awarded' => $ticketsAwarded];
        });

        if (is_array($result) && isset($result['__error'])) {
            return response()->json(['ok' => false, 'error' => $result['__error']], 400);
        }

        return response()->json([
            'ok' => true,
            'user_id' => $sub->user_id,
            'tickets_awarded' => $result['tickets_awarded'],
            'task_text' => $taskText,
            'giveaway_title' => $g->title,
        ]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        try {
            $userId = $this->requireAuth($request);

            $fileId = $request->input('file_id');
            if ($fileId) {
                $v = Validator::make($request->all(), [
                    'giveaway_id' => 'required|string',
                    'file_id' => 'required|string',
                ]);
                if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);
                $g = Giveaway::where('public_id', $request->input('giveaway_id'))->first();
                if (!$g) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
                if ((int)$g->creator_id !== $userId)
                    return response()->json(['ok' => false, 'error' => 'Only creator'], 403);
                $g->update(['photo_file_id' => $fileId, 'photo_path' => 'tg:' . $fileId]);
                return response()->json(['ok' => true, 'file_id' => $fileId]);
            }

            $v = Validator::make($request->all(), [
                'photo' => 'required|image|mimes:jpeg,png,webp,gif|max:5120',
                'giveaway_id' => 'required|string',
            ]);
            if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

            $g = Giveaway::where('public_id', $request->input('giveaway_id'))->first();
            if (!$g) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
            if ((int)$g->creator_id !== $userId)
                return response()->json(['ok' => false, 'error' => 'Only creator'], 403);
            $file = $request->file('photo');
            if (!$file) {
                return response()->json(['ok' => false, 'error' => 'Файл не получен сервером'], 422);
            }
            $tmpPath = $file->getRealPath();
            $origName = $file->getClientOriginalName();
            \Illuminate\Support\Facades\Log::info("uploadPhoto: original={$origName}, size={$file->getSize()}, tmp={$tmpPath}");

            $fileId = null;
            try {
                $fileId = $this->botSender->uploadPhoto($tmpPath, $userId);
                \Illuminate\Support\Facades\Log::info("uploadPhoto fileId from bot: " . ($fileId ?? 'null'));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Bot uploadPhoto failed: " . $e->getMessage());
            }

            if ($fileId) {
                $g->update(['photo_file_id' => $fileId, 'photo_path' => 'tg:' . $fileId]);
                return response()->json(['ok' => true, 'file_id' => $fileId]);
            }

            $fileContents = file_get_contents($tmpPath);
            $fileName = 'giveaway-photos/' . uniqid() . '.' . $file->getClientOriginalExtension();
            $diskRoot = storage_path('app/public');
            if (!is_dir($diskRoot . '/giveaway-photos')) {
                mkdir($diskRoot . '/giveaway-photos', 0775, true);
            }
            $written = file_put_contents($diskRoot . '/' . $fileName, $fileContents);
            \Illuminate\Support\Facades\Log::info("uploadPhoto fallback write: " . ($written !== false ? "{$written} bytes" : 'FAIL') . " to {$diskRoot}/{$fileName}");

            if ($written === false) {
                return response()->json(['ok' => false, 'error' => 'Бот недоступен и файл не удалось сохранить'], 500);
            }

            $g->update(['photo_path' => $fileName]);
            return response()->json(['ok' => true, 'file_id' => null]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("uploadPhoto CRASH: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['ok' => false, 'error' => 'Ошибка загрузки: ' . $e->getMessage()], 500);
        }
    }

    public function show(string $publicId, Request $request): JsonResponse
    {
        $g = Giveaway::where('public_id', $publicId)->withCount('participants')
            ->with(['winners', 'channels', 'prizes', 'taskSubmissions'])->first();
        if (!$g) return response()->json(['ok' => false, 'error' => 'Not found'], 404);

        $data = $this->format($g);

        $userId = $this->userId($request);

        if (!$userId && $this->isTrustedBotCall($request)) {
            $viewerId = $request->query('viewer_id');
            if ($viewerId && ctype_digit((string) $viewerId)) {
                $userId = (int) $viewerId;
            }
        }

        $isOwner = $userId && (int) $g->creator_id === $userId;

        if ($isOwner) {
            $participantLimit = min(200, max(10, (int) $request->query('participant_limit', 200)));
            $participants = $g->participants()
                ->select(['id','giveaway_id','user_id','user_name','is_winner','winner_place','referred_by','tickets','nickname_bonus','created_at'])
                ->orderByDesc('created_at')
                ->limit($participantLimit)
                ->get();

            $isFinished = $g->status === 'finished';
            $data['participants'] = $participants->map(fn($p) => [
                'user_id' => $p->user_id, 'user_name' => $p->user_name,
                'is_winner' => $isFinished ? $p->is_winner : false,
                'winner_place' => $isFinished ? $p->winner_place : null,
                'joined_at' => $p->created_at->toISOString(),
                'tickets' => $p->tickets ?? 1, 'referred_by' => $p->referred_by,
                'nickname_bonus' => $p->nickname_bonus ?? false,
            ]);
            if ($g->relationLoaded('taskSubmissions')) {
                $data['task_submissions'] = $g->taskSubmissions->map(fn($s) => [
                    'task_id' => $s->task_id,
                    'user_id' => $s->user_id,
                    'status' => $s->status,
                    'tickets_awarded' => $s->tickets_awarded,
                ])->toArray();
            }
        } elseif ($userId) {
            $myParticipant = $g->participants()
                ->where('user_id', $userId)
                ->select(['id','user_id','tickets','nickname_bonus','created_at'])
                ->first();
            if ($myParticipant) {
                $data['is_participant'] = true;
                $data['my_tickets'] = $myParticipant->tickets ?? 1;
                $data['my_nickname_bonus'] = $myParticipant->nickname_bonus ?? false;
                $tasks = is_array($g->tasks) ? $g->tasks : [];
                $submissions = $g->taskSubmissions
                    ->where('user_id', $userId)
                    ->keyBy('task_id');
                $data['my_submissions'] = collect($tasks)->mapWithKeys(function ($task) use ($submissions) {
                    $taskId = $task['id'] ?? null;
                    $sub = $taskId ? $submissions->get($taskId) : null;
                    return [$taskId => $sub ? $sub->status : null];
                })->all();
            }
        }
        return response()->json(['ok' => true, 'giveaway' => $data]);
    }

    public function join(Request $request): JsonResponse
    {
        $authId = $this->userId($request);
        if (!$authId) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        $v = Validator::make($request->all(), [
            'giveaway_id' => 'required|string',
            'user_id' => 'required|integer|in:' . $authId,
            'user_name' => 'required|string|max:100',
            'source' => 'nullable|string|in:webapp,channel_button,bot,direct',
            'source_channel_id' => 'nullable|integer',
            'referred_by' => 'nullable|integer',
            'username' => 'nullable|string|max:100',
            'language_code' => 'nullable|string|max:10',
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $giveaway = Giveaway::where('public_id', $request->input('giveaway_id'))->with('channels')->first();
        if (!$giveaway) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if ($giveaway->status !== 'active') return response()->json(['ok' => false, 'error' => 'Finished'], 400);

        if ($giveaway->channels->isNotEmpty()) {
            $chatIds = $giveaway->channels->pluck('chat_id')->toArray();
            $check = $this->telegram->checkMultipleSubscriptions($chatIds, (int) $request->input('user_id'));

            if (!$check['all_subscribed']) {
                $missing = $giveaway->channels->whereIn('chat_id', $check['not_subscribed'])
                    ->map(fn($ch) => [
                        'title' => $ch->title, 'username' => $ch->username,
                        'chat_id' => $ch->chat_id,
                        'link' => $ch->username ? "https://t.me/{$ch->username}" : $ch->invite_link,
                    ])->values()->toArray();

                return response()->json([
                    'ok' => false, 'error' => 'not_subscribed',
                    'message' => 'Subscribe to all channels',
                    'missing_channels' => $missing,
                ], 403);
            }
        }

        $userId = (int) $request->input('user_id');
        $ip = $request->header('X-Real-IP') ?? $request->header('X-Forwarded-For') ?? $request->ip();
        if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);
        $geo = ['country' => null, 'city' => null];
        $accountAge = null;
        $age = null; $birthdateStr = null;
        try {
            $geo = $this->geoIp->lookup($ip);
            $accountAge = GeoIpService::estimateAccountAge($userId);
            $birthdate = $this->telegram->getUserBirthdate($userId);
            if ($birthdate && isset($birthdate['year'])) {
                $birthdateStr = sprintf('%04d-%02d-%02d', $birthdate['year'], $birthdate['month'], $birthdate['day']);
                $age = (int) now()->diffInYears($birthdateStr);
            } elseif ($birthdate) {
                $birthdateStr = sprintf('2000-%02d-%02d', $birthdate['month'], $birthdate['day']);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug("join analytics enrichment failed: {$e->getMessage()}");
        }

        $tickets = 1;
        $nicknameBonus = false;
        $tgUser = $request->attributes->get('tg_user');
        $verifiedFirstName = $tgUser['first_name'] ?? '';
        $verifiedLastName = $tgUser['last_name'] ?? '';
        $verifiedUsername = $tgUser['username'] ?? '';
        $verifiedFullName = trim($verifiedFirstName . ' ' . $verifiedLastName);
        if ($giveaway->nickname_condition && $giveaway->nickname_condition !== '') {
            $cond = $giveaway->nickname_condition;
            $multiplier = $giveaway->nickname_bonus_multiplier ?: 10;
            if (str_contains($verifiedFullName, $cond) || str_contains($verifiedUsername, $cond)) {
                $tickets = $multiplier;
                $nicknameBonus = true;
            }
        }

        $isTrustedBotCall = !empty($tgUser['__bot']);
        try {
            $result = DB::transaction(function () use ($giveaway, $request, $userId, $ip, $geo, $accountAge, $birthdateStr, $age, $tickets, $nicknameBonus, $tgUser, $verifiedFullName, $verifiedUsername, $isTrustedBotCall) {
                $lockedGiveaway = Giveaway::where('id', $giveaway->id)->lockForUpdate()->first();
                if (!$lockedGiveaway || $lockedGiveaway->status !== 'active') {
                    return ['__error' => 'Finished'];
                }

                $exists = Participant::where('giveaway_id', $lockedGiveaway->id)
                    ->where('user_id', $userId)->lockForUpdate()->exists();
                if ($exists) {
                    return null;
                }

                Participant::create([
                    'giveaway_id'       => $lockedGiveaway->id,
                    'user_id'           => $userId,
                    'user_name'         => $verifiedFullName ?: $request->input('user_name'),
                    'username'          => $verifiedUsername ?: $request->input('username'),
                    'language_code'     => $tgUser['language_code'] ?? $request->input('language_code'),
                    'is_premium'        => !empty($tgUser['is_premium']) || ($isTrustedBotCall && $request->boolean('is_premium')),
                    'gender'            => GeoIpService::guessGender($verifiedFullName ?: $request->input('user_name')),
                    'birthdate'         => $birthdateStr,
                    'age'               => $age,
                    'ip_address'        => $ip,
                    'country'           => $geo['country'],
                    'city'              => $geo['city'],
                    'referred_by'       => $request->input('referred_by'),
                    'source'            => $request->input('source', 'webapp'),
                    'source_channel_id' => $request->input('source_channel_id'),
                    'tickets'           => $tickets,
                    'nickname_bonus'    => $nicknameBonus,
                    'user_agent'        => $request->userAgent(),
                    'account_created_at' => $accountAge,
                ]);

                $referredBy = $request->input('referred_by');
                if ($referredBy && (int)$referredBy !== $userId) {
                    $referrer = Participant::where('giveaway_id', $lockedGiveaway->id)
                        ->where('user_id', (int) $referredBy)->first();
                    if ($referrer) {
                        $bonus = $lockedGiveaway->referral_tickets ?: 1;
                        $referrer->increment('tickets', $bonus);
                    }
                }

                return true;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json(['ok' => false, 'error' => 'Already joined'], 409);
            }
            throw $e;
        }

        if (is_array($result) && isset($result['__error'])) {
            return response()->json(['ok' => false, 'error' => $result['__error']], 400);
        }

        if ($result === null) {
            return response()->json(['ok' => false, 'error' => 'Already joined'], 409);
        }

        $count = $giveaway->participants()->count();

        try {
            $this->updateChannelPosts($giveaway, $count);
        } catch (\Exception $e) {
        }

        return response()->json([
            'ok' => true,
            'participant_count' => $count,
            'tickets' => $tickets,
            'nickname_bonus' => $nicknameBonus,
        ]);
    }

    public function checkSubscription(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        if (!$userId) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        $v = Validator::make($request->all(), [
            'giveaway_id' => 'required|string',
            'user_id' => 'required|integer|in:' . $userId,
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $giveaway = Giveaway::where('public_id', $request->input('giveaway_id'))->with('channels')->first();
        if (!$giveaway) return response()->json(['ok' => false, 'error' => 'Not found'], 404);

        if ($giveaway->channels->isEmpty()) {
            return response()->json(['ok' => true, 'all_subscribed' => true, 'channels' => []]);
        }

        $chatIds = $giveaway->channels->pluck('chat_id')->toArray();
        $check = $this->telegram->checkMultipleSubscriptions($chatIds, (int) $request->input('user_id'));

        $channels = $giveaway->channels->map(fn($ch) => [
            'chat_id' => $ch->chat_id,
            'title' => $ch->title, 'username' => $ch->username,
            'link' => $ch->username ? "https://t.me/{$ch->username}" : $ch->invite_link,
            'is_subscribed' => !in_array($ch->chat_id, $check['not_subscribed']),
        ]);

        return response()->json(['ok' => true, 'all_subscribed' => $check['all_subscribed'], 'channels' => $channels]);
    }

    public function draw(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $giveaway = Giveaway::where('public_id', $request->input('giveaway_id'))->first();
        if (!$giveaway) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if ((int)$giveaway->creator_id !== $userId) {
            return response()->json(['ok' => false, 'error' => 'Only creator'], 403);
        }

        if ($giveaway->status === 'finished') {
            $existingWinners = $giveaway->winners()->get();
            if ($existingWinners->isNotEmpty()) {
                $freshGiveaway = $giveaway->fresh()->load('channels', 'winners', 'drawAudit');
                return response()->json([
                    'ok' => true,
                    'already_finished' => true,
                    'winners' => $existingWinners->map(fn ($w) => [
                        'user_id' => $w->user_id,
                        'user_name' => $w->user_name,
                        'place' => $w->winner_place,
                    ])->toArray(),
                    'audit' => $this->drawAuditSummary($freshGiveaway->drawAudit),
                    'giveaway' => $this->format($freshGiveaway),
                ]);
            }
            return response()->json(['ok' => false, 'error' => 'Already finished'], 400);
        }

        $drawResult = DB::transaction(function () use ($giveaway) {
            $locked = Giveaway::where('id', $giveaway->id)->lockForUpdate()->first();
            if (!$locked || $locked->status === 'finished') {
                $existing = $locked ? $locked->winners()->get() : collect();
                if ($existing->isNotEmpty()) {
                    return ['__already' => $existing];
                }
                return ['__error' => 'already_finished'];
            }

            $existing = $locked->winners()->get();
            if ($existing->isNotEmpty()) {
                $locked->load('winners', 'drawAudit');
                if ($this->drawAuditService->isAuditVerified($locked)) {
                    $locked->update(['status' => 'finished']);
                    return ['__already' => $locked->winners];
                }

                $locked->participants()
                    ->where('is_winner', true)
                    ->update([
                        'is_winner' => false,
                        'winner_place' => null,
                    ]);
            }

            $participantSnapshot = $this->drawAuditService->buildParticipantSnapshot($locked);
            if (empty($participantSnapshot)) {
                return ['__error' => 'no_participants'];
            }

            $winners = $locked->drawWinnersFromSnapshot($participantSnapshot);
            $audit = $this->drawAuditService->createAudit($locked, $participantSnapshot, $winners);

            return [
                'winners' => $winners,
                'audit' => $audit,
            ];
        });

        if (is_array($drawResult) && isset($drawResult['__error'])) {
            $err = $drawResult['__error'];
            $msg = $err === 'already_finished' ? 'Already finished' : 'No participants';
            return response()->json(['ok' => false, 'error' => $msg], 400);
        }

        if (is_array($drawResult) && isset($drawResult['__already'])) {
            $existing = $drawResult['__already'];
            $freshGiveaway = $giveaway->fresh()->load('channels', 'winners', 'drawAudit');
            return response()->json([
                'ok' => true,
                'already_finished' => true,
                'winners' => $existing->map(fn ($w) => [
                    'user_id' => $w->user_id,
                    'user_name' => $w->user_name,
                    'place' => $w->winner_place,
                ])->toArray(),
                'audit' => $this->drawAuditSummary($freshGiveaway->drawAudit),
                'giveaway' => $this->format($freshGiveaway),
            ]);
        }

        $winners = $drawResult['winners'];
        $audit = $drawResult['audit'];
        $safeTitle = htmlspecialchars($giveaway->title);
        $safePrize = $giveaway->prize ? htmlspecialchars($giveaway->prize) : '';
        $winnerNames = collect($winners)->map(function (array $winner) {
            $medal = match((int) ($winner['winner_place'] ?? 0)) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => '' };
            $link = '<a href="tg://user?id=' . $winner['user_id'] . '">' . htmlspecialchars($winner['user_name']) . '</a>';
            return $medal ? "{$medal} {$link}" : $link;
        })->join("\n");

        foreach ($winners as $winner) {
            try {
                $place = (int) ($winner['winner_place'] ?? 0);
                $placeText = $place ? " ({$place} место)" : '';
                $this->telegram->sendMessage(
                    (int) $winner['user_id'],
                    "🎉 Вы победили в розыгрыше <b>{$safeTitle}</b>{$placeText}!\n\n"
                    . ($safePrize ? "🎁 Приз: {$safePrize}" : "")
                );
            } catch (\Exception $e) {
            }
        }

        $postUpdateError = null;
        try {
            $messages = GiveawayMessage::where('giveaway_id', $giveaway->id)->get();
            if ($messages->isNotEmpty()) {
                $this->updateChannelPostsFinished($giveaway, $winnerNames);
            }
        } catch (\Throwable $e) {
            $postUpdateError = $e->getMessage();
            \Illuminate\Support\Facades\Log::warning("draw: post update failed: {$postUpdateError}");
        }

        try {
            $this->publishAuditToChannel($giveaway, $audit);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("draw: audit channel publish failed: {$e->getMessage()}");
        }

        try {
            $this->sendParticipantListToCreator($giveaway, $audit);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("draw: participant list send failed: {$e->getMessage()}");
        }

        $freshGiveaway = $giveaway->fresh()->load('channels', 'winners', 'drawAudit');
        $response = [
            'ok' => true,
            'winners' => collect($winners)->map(fn (array $w) => [
                'user_id' => $w['user_id'],
                'user_name' => $w['user_name'],
                'place' => $w['winner_place'] ?? null,
            ])->toArray(),
            'audit' => $this->drawAuditSummary($audit),
            'giveaway' => $this->format($freshGiveaway),
        ];
        if ($postUpdateError) {
            $response['post_update_warning'] = $postUpdateError;
        }
        return response()->json($response);
    }

    
    public function launch(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $v = Validator::make($request->all(), [
            'giveaway_id' => 'required|string',
            'creator_id'  => 'required|integer|in:' . $userId,
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $publicId = trim((string) $request->input('giveaway_id'));
        $giveaway = Giveaway::where('public_id', $publicId)->first();
        if (!$giveaway) {
            return response()->json([
                'ok' => false,
                'error' => 'Not found',
                'giveaway_id_received' => $publicId,
            ], 404);
        }
        if ((int)$giveaway->creator_id !== $this->userId($request))
            return response()->json(['ok' => false, 'error' => 'Only creator'], 403);

        $launched = DB::transaction(function () use ($giveaway) {
            $locked = Giveaway::where('id', $giveaway->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'draft') return false;
            $locked->update(['status' => 'active']);
            return true;
        });
        if (!$launched) {
            return response()->json(['ok' => false, 'error' => 'Already launched'], 400);
        }
        $giveaway->refresh();
        $giveaway->load('channels', 'prizes');

        if ($giveaway->channels->isNotEmpty()) {
            $text = $this->buildPostText($giveaway, 0);
            $photo = $this->resolvePhoto($giveaway);

            foreach ($giveaway->channels as $channel) {
                try {
                    $button = $this->buildPostButton($giveaway, (int) $channel->id);
                    \Illuminate\Support\Facades\Log::info("Sending giveaway post. Photo: " . ($photo ?? 'none'));
                    $messageId = $this->botSender->sendPost((int) $channel->chat_id, $text, $button, $photo);

                    if ($messageId) {
                        GiveawayMessage::create([
                            'giveaway_id' => $giveaway->id,
                            'chat_id' => $channel->chat_id,
                            'message_id' => $messageId,
                        ]);
                    }
                } catch (\Exception $e) {
                }
            }
        }

        return response()->json(['ok' => true, 'giveaway' => $this->format($giveaway->fresh()->load('channels', 'prizes'))]);
    }

    
    public function attachChannel(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $v = Validator::make($request->all(), [
            'giveaway_id' => 'required|string',
            'channel_id'  => 'required|integer|exists:channels,id',
            'owner_id'    => 'required|integer|in:' . $userId,
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $giveaway = Giveaway::where('public_id', $request->input('giveaway_id'))->first();
        if (!$giveaway) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if ($giveaway->status !== 'draft')
            return response()->json(['ok' => false, 'error' => 'Розыгрыш уже запущен'], 400);

        $channel = Channel::where('id', $request->input('channel_id'))
            ->where('owner_id', $userId)->first();
        if (!$channel) return response()->json(['ok' => false, 'error' => 'Channel not yours'], 404);

        if ($giveaway->channels()->where('channels.id', $channel->id)->exists()) {
            return response()->json(['ok' => false, 'error' => 'Already attached'], 409);
        }

        $giveaway->channels()->attach($channel->id);

        return response()->json([
            'ok' => true,
            'channel' => $this->formatChannel($channel),
            'giveaway_title' => $giveaway->title,
            'creator_id' => (int) $giveaway->creator_id,
        ]);
    }

    
    public function updateDate(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $v = Validator::make($request->all(), [
            'end_date' => 'required|date|after:now',
            'giveaway_id' => 'required|string',
            'creator_id' => 'required|integer|in:' . $userId,
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $publicId = trim((string) $request->input('giveaway_id'));
        $giveaway = Giveaway::where('public_id', $publicId)->first();
        if (!$giveaway) {
            return response()->json([
                'ok' => false,
                'error' => 'Not found',
                'giveaway_id_received' => $publicId,
            ], 404);
        }
        if ((int)$giveaway->creator_id !== $this->userId($request))
            return response()->json(['ok' => false, 'error' => 'Only creator'], 403);
        $giveaway->update(['end_date' => $request->input('end_date')]);
        return response()->json(['ok' => true]);
    }

    
    public function checkNickname(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $giveaway = Giveaway::where('public_id', $request->input('giveaway_id'))->first();
        if (!$giveaway) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if (!$giveaway->nickname_condition) return response()->json(['ok' => false, 'error' => 'No condition'], 400);

        $participant = Participant::where('giveaway_id', $giveaway->id)->where('user_id', $userId)->first();
        if (!$participant) return response()->json(['ok' => false, 'error' => 'Not a participant'], 400);

        if ($participant->nickname_bonus) {
            return response()->json(['ok' => true, 'already' => true, 'tickets' => $participant->tickets]);
        }

        $chatMember = $this->telegram->getChat($userId);
        if (!$chatMember) {
            return response()->json(['ok' => false, 'error' => 'Cannot get user info from Telegram'], 503);
        }
        $firstName = $chatMember['first_name'] ?? '';
        $lastName = $chatMember['last_name'] ?? '';
        $fullName = trim($firstName . ' ' . $lastName);
        $username = $chatMember['username'] ?? '';

        $cond = $giveaway->nickname_condition;
        $matched = str_contains($fullName, $cond) || str_contains($username, $cond);

        if ($matched) {
            $multiplier = $giveaway->nickname_bonus_multiplier ?: 10;
            $result = DB::transaction(function () use ($giveaway, $userId, $multiplier) {
                $locked = Participant::where('giveaway_id', $giveaway->id)
                    ->where('user_id', $userId)->lockForUpdate()->first();
                if (!$locked) return ['__error' => 'Not a participant'];
                if ($locked->nickname_bonus) return ['already' => true, 'tickets' => $locked->tickets];

                $taskTickets = (int) $giveaway->taskSubmissions()
                    ->where('user_id', $userId)
                    ->where('status', 'approved')
                    ->sum('tickets_awarded');
                $currentTickets = (int) $locked->tickets;
                $extraTickets = max(0, $currentTickets - 1 - $taskTickets);

                $locked->update([
                    'tickets' => $multiplier + $taskTickets + $extraTickets,
                    'nickname_bonus' => true,
                ]);
                return ['matched' => true, 'tickets' => $locked->fresh()->tickets];
            });

            if (isset($result['__error'])) {
                return response()->json(['ok' => false, 'error' => $result['__error']], 400);
            }
            if (isset($result['already'])) {
                return response()->json(['ok' => true, 'already' => true, 'tickets' => $result['tickets']]);
            }
            return response()->json(['ok' => true, 'matched' => true, 'tickets' => $result['tickets']]);
        }

        return response()->json(['ok' => true, 'matched' => false]);
    }

    
    public function broadcast(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $v = Validator::make($request->all(), [
            'giveaway_id' => 'required|string',
            'creator_id'  => 'required|integer|in:' . $userId,
            'message'     => 'required|string|max:1000',
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $giveaway = Giveaway::where('public_id', $request->input('giveaway_id'))->first();
        if (!$giveaway) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if ((int)$giveaway->creator_id !== $this->userId($request))
            return response()->json(['ok' => false, 'error' => 'Only creator'], 403);

        $total = Participant::where('giveaway_id', $giveaway->id)->count();
        if ($total === 0)
            return response()->json(['ok' => false, 'error' => 'No participants'], 400);

        $text = "📢 <b>Сообщение от организатора</b>\n"
            . "🎰 <b>" . htmlspecialchars($giveaway->title) . "</b>\n\n"
            . htmlspecialchars($request->input('message'));

        if ($total > 1000 && !app()->environment('testing') && config('queue.default') !== 'sync') {
            \App\Jobs\BroadcastJob::dispatch($giveaway->id, $text);
            return response()->json(['ok' => true, 'total' => $total, 'queued' => true]);
        }

        $result = $this->sendBroadcastMessages($giveaway->id, $text);

        return response()->json(array_merge(['ok' => true], $result));
    }

    private function sendBroadcastMessages(int $giveawayId, string $text): array
    {
        $sent = 0;
        $failed = 0;

        Participant::where('giveaway_id', $giveawayId)
            ->select(['id', 'user_id'])
            ->chunkById(200, function ($chunk) use ($text, &$sent, &$failed) {
                foreach ($chunk as $participant) {
                    try {
                        $result = $this->telegram->sendMessage((int) $participant->user_id, $text);
                        if ($result) {
                            $sent++;
                        } else {
                            $failed++;
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        \Illuminate\Support\Facades\Log::warning("broadcast send failed for {$participant->user_id}: {$e->getMessage()}");
                    }

                    if (!app()->environment('testing')) {
                        usleep(40000);
                    }
                }
            });

        return [
            'total' => $sent + $failed,
            'sent' => $sent,
            'failed' => $failed,
            'queued' => false,
        ];
    }

    
    public function detachChannel(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $v = Validator::make($request->all(), [
            'giveaway_id' => 'required|string',
            'creator_id'  => 'required|integer|in:' . $userId,
            'channel_id'  => 'required|integer',
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $giveaway = Giveaway::where('public_id', $request->input('giveaway_id'))->first();
        if (!$giveaway) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if ((int)$giveaway->creator_id !== $this->userId($request))
            return response()->json(['ok' => false, 'error' => 'Only creator'], 403);
        if ($giveaway->status !== 'draft')
            return response()->json(['ok' => false, 'error' => 'Нельзя отвязать канал после запуска розыгрыша'], 400);
        $giveaway->channels()->detach((int) $request->input('channel_id'));
        return response()->json(['ok' => true]);
    }

    public function requestChannelConnect(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $botUsername = $this->resolveBotUsername();

        $text = "📺 <b>Подключение канала</b>\n\n" .
                "Чтобы подключить канал, нажми кнопку ниже и выбери его.\n" .
                "Либо просто добавь бота @{$botUsername} в администраторы своего канала.";

        $replyMarkup = [
            'keyboard' => [
                [
                    [
                        'text' => '📺 Выбрать канал',
                        'request_chat' => [
                            'request_id' => 1,
                            'chat_is_channel' => true,
                            'user_administrator_rights' => [
                                'can_post_messages' => true,
                                'can_edit_messages' => true,
                                'can_invite_users' => true,
                            ],
                            'bot_administrator_rights' => [
                                'can_post_messages' => true,
                                'can_edit_messages' => true,
                                'can_invite_users' => true,
                            ],
                        ]
                    ]
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ];

        $this->telegram->sendMessage($userId, $text, $replyMarkup);

        return response()->json(['ok' => true]);
    }

    public function requestChannelAdd(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $v = Validator::make($request->all(), ['giveaway_id' => 'required|string']);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $publicId = $request->input('giveaway_id');
        $botUsername = $this->resolveBotUsername();

        $text = "➕ <b>Привязка канала к розыгрышу</b>\n\n" .
                "Выбери канал, который хочешь добавить в розыгрыш:";

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => '📺 Выбрать канал', 'callback_data' => "pick_ch:{$publicId}"]
                ]
            ]
        ];

        $this->telegram->sendMessage($userId, $text, $replyMarkup);

        return response()->json(['ok' => true]);
    }

    public function listChannels(Request $request): JsonResponse
    {
        $ownerId = $this->userId($request);
        if (!$ownerId) return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        $ch = Channel::where('owner_id', $ownerId)->orderByDesc('created_at')->get()
            ->map(fn($ch) => $this->formatChannel($ch));
        return response()->json(['ok' => true, 'channels' => $ch]);
    }

    public function connectChannel(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $v = Validator::make($request->all(), ['owner_id' => 'required|integer|in:' . $userId, 'chat_id' => 'required']);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $chatId = $request->input('chat_id');
        $ownerId = (int) $request->input('owner_id');

        $isAdmin = $this->telegram->isBotAdmin($chatId);
        if (!$isAdmin) {
            return response()->json(['ok' => false, 'error' => 'bot_not_admin', 'message' => 'Бот не админ канала'], 400);
        }

        $chatInfo = $this->telegram->getChat($chatId);
        if (!$chatInfo) return response()->json(['ok' => false, 'error' => 'Cannot get chat info'], 400);

        if (!in_array($chatInfo['type'] ?? '', ['channel', 'supergroup'])) {
            return response()->json(['ok' => false, 'error' => 'Only channels/supergroups'], 400);
        }

        $memberCount = $this->telegram->getChatMemberCount($chatId);
        $realChatId = (int) $chatInfo['id'];

        $existing = Channel::where('chat_id', $realChatId)->first();
        if ($existing && $existing->owner_id !== $ownerId) {
            return response()->json(['ok' => false, 'error' => 'Канал уже подключён другим пользователем'], 409);
        }
        $username = $chatInfo['username'] ?? null;
        $inviteLink = $chatInfo['invite_link'] ?? null;
        if (!$username && !$inviteLink) {
            $inviteLink = $this->telegram->getInviteLink($realChatId);
        }

        $channel = Channel::updateOrCreate(
            ['chat_id' => $realChatId],
            [
                'owner_id' => $ownerId,
                'title' => $chatInfo['title'] ?? 'Channel',
                'username' => $username,
                'type' => $chatInfo['type'],
                'member_count' => $memberCount,
                'bot_is_admin' => true,
                'invite_link' => $inviteLink,
            ]
        );

        return response()->json(['ok' => true, 'channel' => $this->formatChannel($channel)]);
    }

    public function disconnectChannel(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $v = Validator::make($request->all(), [
            'channel_id' => 'required|integer',
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $ch = Channel::where('id', (int) $request->input('channel_id'))->where('owner_id', $userId)->first();
        if (!$ch) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        $ch->delete();
        return response()->json(['ok' => true]);
    }

    
    public function destroy(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);

        $v = Validator::make($request->all(), [
            'giveaway_id' => 'required|string',
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $giveaway = Giveaway::where('public_id', trim((string) $request->input('giveaway_id')))->first();
        if (!$giveaway) return response()->json(['ok' => false, 'error' => 'Not found'], 404);

        if ((int) $giveaway->creator_id !== $userId) {
            return response()->json(['ok' => false, 'error' => 'Only creator can delete'], 403);
        }

        if ($giveaway->status === 'active') {
            return response()->json(['ok' => false, 'error' => 'Нельзя удалить активный розыгрыш. Сначала определи победителей или дождись автозавершения.'], 400);
        }

        DB::transaction(function () use ($giveaway) {
            $giveaway->channels()->detach();
            $giveaway->taskSubmissions()->delete();
            Participant::where('giveaway_id', $giveaway->id)->delete();
            Prize::where('giveaway_id', $giveaway->id)->delete();
            GiveawayMessage::where('giveaway_id', $giveaway->id)->delete();
            $giveaway->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function botInfo(): JsonResponse
    {
        $username = $this->resolveBotUsername();
        return response()->json(['username' => $username]);
    }

    public function phoneVerify(Request $request): JsonResponse
    {
        $tgUser = $request->attributes->get('tg_user');
        if (!$tgUser || empty($tgUser['__bot'])) {
            return response()->json(['ok' => false, 'error' => 'Only bot can verify phone'], 403);
        }

        $userId = $this->requireAuth($request);
        $bodyUserId = (int) $request->input('user_id');
        if ($bodyUserId && $bodyUserId !== $userId) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $exists = DB::table('phone_verifications')->where('user_id', $userId)->exists();
        if ($exists) {
            DB::table('phone_verifications')->where('user_id', $userId)->update([
                'is_russian' => (bool) $request->input('is_russian'),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('phone_verifications')->insert([
                'user_id' => $userId,
                'is_russian' => (bool) $request->input('is_russian'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return response()->json(['ok' => true]);
    }

    public function phoneCheck(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);

        $row = DB::table('phone_verifications')->where('user_id', $userId)->first();
        if (!$row) return response()->json(['verified' => false]);

        return response()->json([
            'verified' => true,
            'is_russian' => (bool) $row->is_russian,
        ]);
    }

    public function buildPostText(Giveaway $g, int $count): string
    {
        $text = "";
        if ($g->description) {
            $text .= "<blockquote>" . htmlspecialchars($g->description) . "</blockquote>\n\n";
        }
        if (!$g->relationLoaded('prizes')) $g->load('prizes');
        if ($g->prizes->isNotEmpty()) {
            $text .= "🎁 <b>Призы:</b>\n";
            foreach ($g->prizes as $prize) {
                $medal = match($prize->place) {
                    1 => '🥇',
                    2 => '🥈',
                    3 => '🥉',
                    default => "#{$prize->place}"
                };
                $text .= "{$medal} " . htmlspecialchars($prize->title) . "\n";
            }
            $text .= "\n";
        } elseif ($g->prize) {
            $text .= "🎁 Приз: <b>" . htmlspecialchars($g->prize) . "</b>\n\n";
        }
        if ($g->relationLoaded('channels') && $g->channels->isNotEmpty()) {
            $text .= "📺 <b>Каналы:</b>\n";
            foreach ($g->channels as $ch) {
                $link = $ch->username ? "https://t.me/{$ch->username}" : ($ch->invite_link ?? null);
                if (!$link && $ch->chat_id) {
                    try {
                        $link = $this->telegram->getInviteLink($ch->chat_id);
                        if ($link) {
                            $ch->update(['invite_link' => $link]);
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::debug("buildPostText invite link unavailable for {$ch->chat_id}: {$e->getMessage()}");
                        $link = null;
                    }
                }
                $title = htmlspecialchars($ch->title);
                if ($link) {
                    $text .= "<a href=\"{$link}\">{$title}</a>\n";
                } else {
                    $text .= "{$title}\n";
                }
            }
            $text .= "\n";
        }

        if ($g->end_date) {
            $text .= "⏰ Завершение: <b>" . $g->end_date->timezone('Europe/Moscow')->format('d.m.Y H:i') . " МСК</b>\n\n";
        }
        $text .= "👇 Нажми чтобы участвовать!";
        return $text;
    }

    public function buildPostButton(Giveaway $g, ?int $channelId = null, int $count = null): array
    {
        if ($count === null) $count = $g->participants()->count();
        $btnText = $count > 0 ? "🎲 Участвовать ({$count})" : '🎲 Участвовать';
        $botUsername = $this->resolveBotUsername();
        $startParam = 'g_' . $g->public_id;
        if ($channelId) $startParam .= '_ch_' . $channelId;
        $shortName = trim((string) ($this->miniAppShortName ?? ''));
        $encodedStartParam = rawurlencode($startParam);
        $url = $shortName !== ''
            ? "https://t.me/{$botUsername}/{$shortName}?startapp={$encodedStartParam}"
            : "https://t.me/{$botUsername}?start={$encodedStartParam}";
        return [
            'inline_keyboard' => [[
                ['text' => $btnText, 'url' => $url]
            ]]
        ];
    }

    
    private function updateChannelPosts(Giveaway $g, int $count): void
    {
        $lockKey = "upd_posts:{$g->id}";
        $cache = \Illuminate\Support\Facades\Cache::store();
        if (!$cache->add($lockKey, 1, 3)) return;

        if (!$g->relationLoaded('channels')) $g->load('channels');
        $messages = GiveawayMessage::where('giveaway_id', $g->id)->get();
        if ($messages->isEmpty()) return;

        $text = $this->buildPostText($g, $count);
        $photo = $this->resolvePhoto($g);

        foreach ($messages as $msg) {
            $ch = $g->channels->firstWhere('chat_id', $msg->chat_id);
            $button = $this->buildPostButton($g, $ch ? (int) $ch->id : null, $count);
            try {
                $this->botSender->editPost((int) $msg->chat_id, (int) $msg->message_id, $text, $button, $photo);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("editMessage failed: {$e->getMessage()}");
            }
        }
    }

    private function updateChannelPostsFinished(Giveaway $g, string $winnerNames): void
    {
        $messages = GiveawayMessage::where('giveaway_id', $g->id)->get();
        \Illuminate\Support\Facades\Log::info("updateChannelPostsFinished: Found {$messages->count()} messages for giveaway {$g->public_id}");
        $count = $g->participants()->count();

        $text = "";
        if ($g->description) {
            $text .= "<blockquote>" . htmlspecialchars($g->description) . "</blockquote>\n\n";
        }
        if (!$g->relationLoaded('prizes')) $g->load('prizes');
        if ($g->prizes->isNotEmpty()) {
            $text .= "🎁 <b>Призы:</b>\n";
            foreach ($g->prizes as $prize) {
                $medal = match($prize->place) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => "#{$prize->place}" };
                $text .= "{$medal} " . htmlspecialchars($prize->title) . "\n";
            }
            $text .= "\n";
        } elseif ($g->prize) {
            $text .= "🎁 Приз: <b>" . htmlspecialchars($g->prize) . "</b>\n\n";
        }
        $text .= "🎉 <b>Победители:</b>\n{$winnerNames}\n\n";

        if (!$g->relationLoaded('drawAudit')) $g->load('drawAudit');
        $audit = $g->drawAudit;
        if ($audit) {
            $text .= "✅ <b>Verified</b> • <code>" . substr($audit->result_token, 0, 12) . "...</code>";
        }

        foreach ($messages as $msg) {
            \Illuminate\Support\Facades\Log::info("updateChannelPostsFinished: Editing message {$msg->message_id} in chat {$msg->chat_id}");
            try {
                $result = $this->botSender->editPost((int) $msg->chat_id, (int) $msg->message_id, $text);
                if (!$result) {
                    throw new \Exception("editPost returned false. Check bot permissions in chat {$msg->chat_id}");
                }
                \Illuminate\Support\Facades\Log::info("updateChannelPostsFinished: Edit result: success");
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Failed to edit message {$msg->message_id} in chat {$msg->chat_id}: " . $e->getMessage());
                throw $e;
            }
        }
    }

    
    public function updateDraft(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $v = Validator::make($request->all(), [
            'giveaway_id' => 'required|string',
            'creator_id'  => 'required|integer|in:' . $userId,
            'title'       => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'prize'       => 'nullable|string|max:200',
            'winners_count' => 'nullable|integer|min:1|max:50',
            'nickname_condition' => 'nullable|string|max:50',
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $g = Giveaway::where('public_id', $request->input('giveaway_id'))->first();
        if (!$g) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if ((int)$g->creator_id !== $userId) return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        if ($g->status !== 'draft') {
            $data = $request->only(['title', 'description', 'prize']);
        } else {
            $data = $request->only(['title', 'description', 'prize', 'winners_count', 'nickname_condition']);
        }
        foreach ($data as $k => $val) {
            if ($k === 'winners_count' && ($val === null || $val === 'null')) {
                unset($data[$k]);
                continue;
            }
            if ($val === null || $val === 'null') $data[$k] = null;
        }

        if (empty($data)) return response()->json(['ok' => true]);
        $g->update($data);
        return response()->json(['ok' => true]);
    }

    
    public function refreshPosts(Request $request): JsonResponse
    {
        $userId = $this->requireAuth($request);
        $v = Validator::make($request->all(), [
            'giveaway_id' => 'required|string',
            'creator_id'  => 'required|integer|in:' . $userId,
        ]);
        if ($v->fails()) return response()->json(['ok' => false, 'error' => $v->errors()->first()], 422);

        $g = Giveaway::where('public_id', $request->input('giveaway_id'))->first();
        if (!$g) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if ((int)$g->creator_id !== $userId) return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);

        $count = Participant::where('giveaway_id', $g->id)->count();
        $this->updateChannelPosts($g, $count);

        return response()->json(['ok' => true]);
    }

    private function format(Giveaway $g): array
    {
        return [
            'id' => $g->public_id,
            'title' => $g->title,
            'description' => $g->description ?? '',
            'photo' => (bool) ($g->photo_path || $g->photo_file_id),
            'prize' => $g->prize,
            'winners_count' => $g->winners_count,
            'creator_id' => $g->creator_id,
            'creator_name' => $g->creator_name,
            'status' => $g->status,
            'participant_count' => $g->participants_count ?? $g->participant_count ?? 0,
            'winners' => ($g->status === 'finished')
                ? $g->winners->map(fn($w) => ['user_id' => $w->user_id, 'user_name' => $w->user_name, 'place' => $w->winner_place])->toArray() : [],
            'channels' => $g->channels->map(fn($ch) => $this->formatChannel($ch))->toArray(),
            'prizes' => $g->prizes->map(fn($p) => ['place' => $p->place, 'title' => $p->title])->toArray(),
            'start_date' => $g->start_date?->toISOString(),
            'end_date' => $g->end_date?->toISOString(),
            'created_at' => $g->created_at?->toISOString(),
            'nickname_condition' => $g->nickname_condition,
            'nickname_bonus_multiplier' => $g->nickname_bonus_multiplier ?? 10,
            'referral_tickets' => $g->referral_tickets ?? 1,
            'tasks' => is_array($g->tasks) ? $g->tasks : [],
            'draw_audit' => $g->relationLoaded('drawAudit') ? $this->drawAuditSummary($g->drawAudit) : null,
        ];
    }

    private function formatChannel(Channel $ch): array
    {
        return [
            'id' => $ch->id, 'chat_id' => $ch->chat_id, 'title' => $ch->title,
            'username' => $ch->username, 'type' => $ch->type, 'member_count' => $ch->member_count,
            'link' => $ch->username ? "https://t.me/{$ch->username}" : $ch->invite_link,
        ];
    }

    private function resolvePhoto(Giveaway $g): ?string
    {
        $photo = null;
        if ($g->photo_file_id) {
            $photo = $g->photo_file_id;
        } elseif ($g->photo_path) {
            if (str_starts_with($g->photo_path, 'tg:')) {
                $photo = substr($g->photo_path, 3);
            } else {
                try {
                    $fullPath = storage_path('app/public/' . $g->photo_path);
                    if (file_exists($fullPath)) {
                        $fileId = $this->botSender->uploadPhoto($fullPath, (int)$g->creator_id);
                        if ($fileId) {
                            $g->update(['photo_file_id' => $fileId]);
                            $photo = $fileId;
                        }
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("On-the-fly upload failed: " . $e->getMessage());
                }

                if (!$photo) {
                    $photo = rtrim(config('app.url'), '/') . '/storage/' . $g->photo_path;
                    if (str_contains($photo, 'localhost') || str_contains($photo, '://api') || str_contains($photo, '://127.0.0.1')) {
                        if (request()) {
                            $photo = rtrim(request()->getSchemeAndHttpHost(), '/') . '/storage/' . $g->photo_path;
                        }
                    }
                }
            }
        }
        return $photo;
    }

    public function verifyIntegrity(string $publicId): JsonResponse
    {
        $giveaway = Giveaway::where('public_id', $publicId)->with(['winners', 'drawAudit'])->first();
        if (!$giveaway) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }

        $verification = $this->drawAuditService->verify($giveaway);

        return response()->json([
            'ok' => true,
            'giveaway' => [
                'id' => $giveaway->public_id,
                'title' => $giveaway->title,
                'status' => $giveaway->status,
            ],
            'verification' => $verification,
        ]);
    }

    private function publishAuditToChannel(Giveaway $giveaway, GiveawayDrawAudit $audit): void
    {
        $channelId = config('services.telegram.audit_channel_id');
        if (!$channelId) {
            return;
        }

        $safeTitle = htmlspecialchars($giveaway->title);
        $winners = is_array($audit->winner_snapshot) ? $audit->winner_snapshot : [];

        $text = "🏆 <b>{$safeTitle}</b> — ИТОГИ\n\n";
        $text .= "👥 Участников: {$audit->total_participants}\n";
        $text .= "🎫 Билетов: {$audit->total_tickets}\n\n";

        if (!empty($winners)) {
            $text .= "🎉 <b>Победители:</b>\n";
            foreach ($winners as $w) {
                $place = (int) ($w['winner_place'] ?? 0);
                $medal = match($place) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => "#{$place}" };
                $name = htmlspecialchars($w['user_name'] ?? '');
                $uid = (int) ($w['user_id'] ?? 0);
                $link = $uid ? "<a href=\"tg://user?id={$uid}\">{$name}</a>" : $name;
                $text .= "{$medal} {$link}\n";
            }
            $text .= "\n";
        }

        $text .= "🕐 " . $audit->drawn_at?->format('d.m.Y H:i:s') . " UTC\n";
        $text .= "✅ <b>Verified</b> • <code>" . substr($audit->result_token, 0, 12) . "...</code>";

        $this->telegram->sendMessage((int) $channelId, $text);
    }

    private function formatCsvDateValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null || $value === '') {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    private function sanitizeCsvValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (!is_scalar($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return str_replace(["\r", "\n", ';'], [' ', ' ', ','], (string) $value);
    }

    private function buildParticipantCsv(array $participants, array $winnerIds): string
    {
        $hasAccountCreatedAt = false;
        foreach ($participants as $participant) {
            if (is_array($participant) && array_key_exists('account_created_at', $participant)) {
                $hasAccountCreatedAt = true;
                break;
            }
        }

        $csv = "\xEF\xBB\xBF";
        $csv .= $hasAccountCreatedAt
            ? "№;Имя;User ID;Билеты;Победитель;Место;Аккаунт создан\n"
            : "№;Имя;User ID;Билеты;Победитель;Место\n";

        foreach ($participants as $i => $p) {
            $name = $this->sanitizeCsvValue($p['user_name'] ?? '');
            $uid = (int) ($p['user_id'] ?? 0);
            $tickets = (int) ($p['tickets'] ?? 1);
            $pid = (int) ($p['participant_id'] ?? 0);
            $isWinner = isset($winnerIds[$pid]) ? 'Да' : 'Нет';
            $place = $winnerIds[$pid] ?? '';
            $accountCreatedAt = $hasAccountCreatedAt
                ? ';' . $this->sanitizeCsvValue($this->formatCsvDateValue($p['account_created_at'] ?? null))
                : '';

            $csv .= ($i + 1) . ";{$name};{$uid};{$tickets};{$isWinner};{$place}{$accountCreatedAt}\n";
        }

        return $csv;
    }

    private function sendParticipantListToCreator(Giveaway $giveaway, GiveawayDrawAudit $audit): void
    {
        $creatorId = (int) $giveaway->creator_id;
        if (!$creatorId) {
            return;
        }

        $participants = is_array($audit->participant_snapshot) ? $audit->participant_snapshot : [];
        if (empty($participants)) {
            return;
        }

        $winners = is_array($audit->winner_snapshot) ? $audit->winner_snapshot : [];
        $winnerIds = [];
        foreach ($winners as $w) {
            $winnerIds[(int) ($w['participant_id'] ?? 0)] = (int) ($w['winner_place'] ?? 0);
        }

        $csv = $this->buildParticipantCsv($participants, $winnerIds);

        $safeTitle = preg_replace('/[^a-zA-Zа-яА-Я0-9_\- ]/u', '', $giveaway->title) ?: 'giveaway';
        $fileName = "participants_{$safeTitle}_{$giveaway->public_id}.csv";
        $total = count($participants);

        $this->telegram->sendDocument(
            $creatorId,
            $csv,
            $fileName,
            "📋 Участники розыгрыша <b>{$safeTitle}</b>\n👥 Всего: {$total}"
        );
    }
}
