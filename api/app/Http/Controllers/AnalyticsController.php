<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Giveaway;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function giveawayAnalytics(Request $request, string $publicId): JsonResponse
    {
        return $this->statisticForAdmin($request, $publicId);
    }

    public function exportCsv(Request $request, string $publicId): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $giveaway = Giveaway::where('public_id', $publicId)->first();
        if (!$giveaway) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }

        $userId = (int) $request->attributes->get('tg_user_id');
        if (!$userId) {
            $token = (string) $request->query('token', '');
            $uid = (int) $request->query('uid', 0);
            $ts = (int) $request->query('ts', 0);
            $secret = config('services.telegram.bot_token');

            if ($token && $uid && $ts && $secret) {
                if (abs(time() - $ts) > 300) {
                    return response()->json(['ok' => false, 'error' => 'Token expired'], 403);
                }
                $expected = hash_hmac('sha256', "{$uid}:{$publicId}:{$ts}", $secret);
                if (hash_equals($expected, $token)) {
                    $userId = $uid;
                }
            }
        }

        if (!$userId || (int) $giveaway->creator_id !== $userId) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $channelIds = Participant::where('giveaway_id', $giveaway->id)
            ->whereNotNull('source_channel_id')->distinct()->pluck('source_channel_id');
        $channelMap = \App\Models\Channel::whereIn('id', $channelIds)->pluck('title', 'id');

        return response()->streamDownload(function () use ($giveaway, $channelMap) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['user_id','username','user_name','gender','age','language','country','city','is_premium','source','source_channel','referred_by','joined_at_msk','ip_masked','account_age']);

            Participant::where('giveaway_id', $giveaway->id)
                ->orderBy('id')
                ->chunkById(500, function ($chunk) use ($out, $channelMap) {
                    foreach ($chunk as $p) {
                        fputcsv($out, [
                            $p->user_id,
                            $p->username ?? '',
                            $p->user_name,
                            $p->gender ?? '',
                            $p->age ?? '',
                            $p->language_code ?? '',
                            $p->country ?? '',
                            $p->city ?? '',
                            $p->is_premium ? 'yes' : 'no',
                            $p->source ?? 'direct',
                            $p->source_channel_id ? ($channelMap[$p->source_channel_id] ?? $p->source_channel_id) : '',
                            $p->referred_by ?? '',
                            $p->created_at?->timezone('Europe/Moscow')->format('d.m.Y H:i:s'),
                            $this->maskIp($p->ip_address ?? ''),
                            $p->account_created_at ? $p->account_created_at->format('Y-m-d') : '',
                        ]);
                    }
                });
            fclose($out);
        }, "giveaway_{$publicId}_participants.csv", [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }

    
    public function csvToken(Request $request, string $publicId): JsonResponse
    {
        $userId = (int) $request->attributes->get('tg_user_id');
        if (!$userId) return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);

        $giveaway = Giveaway::where('public_id', $publicId)->first();
        if (!$giveaway) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if ((int) $giveaway->creator_id !== $userId) return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);

        $ts = time();
        $secret = config('services.telegram.bot_token');
        $token = hash_hmac('sha256', "{$userId}:{$publicId}:{$ts}", $secret);

        $url = rtrim(config('app.url'), '/') . "/api/analytics/{$publicId}/csv?uid={$userId}&ts={$ts}&token={$token}";

        if (str_contains($url, 'localhost') || str_contains($url, '://api') || str_contains($url, '://127.0.0.1')) {
            if ($request->getSchemeAndHttpHost()) {
                $url = rtrim($request->getSchemeAndHttpHost(), '/') . "/api/analytics/{$publicId}/csv?uid={$userId}&ts={$ts}&token={$token}";
            }
        }

        return response()->json(['ok' => true, 'url' => $url]);
    }

    public function overview(Request $request): JsonResponse
    {
        $creatorId = (int) $request->attributes->get('tg_user_id');
        if (!$creatorId) return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);

        $giveaways = Giveaway::where('creator_id', $creatorId)->get();
        $giveawayIds = $giveaways->pluck('id');

        $totalP = Participant::whereIn('giveaway_id', $giveawayIds)->count();
        $uniqueP = Participant::whereIn('giveaway_id', $giveawayIds)->distinct('user_id')->count('user_id');

        $topCountries = Participant::whereIn('giveaway_id', $giveawayIds)->whereNotNull('country')
            ->select('country', DB::raw('count(*) as cnt'))->groupBy('country')
            ->orderByDesc('cnt')->limit(5)->get()->toArray();

        $genderSplit = Participant::whereIn('giveaway_id', $giveawayIds)->whereNotNull('gender')
            ->select('gender', DB::raw('count(*) as cnt'))->groupBy('gender')->get()->toArray();

        return response()->json([
            'ok' => true,
            'total_giveaways' => $giveaways->count(),
            'active_giveaways' => $giveaways->where('status', 'active')->count(),
            'total_participants' => $totalP,
            'unique_participants' => $uniqueP,
            'top_countries' => $topCountries,
            'gender_split' => $genderSplit,
        ]);
    }

    private function ageCategory(Participant $p): string
    {
        if (!$p->account_created_at) return 'unknown';
        $days = now()->diffInDays($p->account_created_at);
        if ($days < 30) return 'very_new';
        if ($days < 180) return 'new';
        if ($days < 730) return 'medium';
        return 'old';
    }

    private function maskIp(?string $ip): string
    {
        if (!$ip) return '';
        $parts = explode('.', $ip);
        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.*.*" : '***';
    }
    public function statisticForAdmin(Request $request, string $publicId): JsonResponse
    {
        $userId = (int) $request->attributes->get('tg_user_id');
        if (!$userId) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        $giveaway = Giveaway::where('public_id', $publicId)->with('channels', 'prizes')->first();
        if (!$giveaway) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }

        if ((int) $giveaway->creator_id !== $userId) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $participants = Participant::where('giveaway_id', $giveaway->id)->get();
        $total = $participants->count();

        if ($total === 0) {
            return response()->json([
                'ok' => true,
                'giveaway' => [
                    'id' => $giveaway->id,
                    'public_id' => $giveaway->public_id,
                    'title' => $giveaway->title,
                    'status' => $giveaway->status,
                    'channels_count' => $giveaway->channels->count(),
                    'created_at' => $giveaway->created_at?->toISOString(),
                    'start_date' => $giveaway->start_date?->toISOString(),
                    'end_date' => $giveaway->end_date?->toISOString(),
                ],
                'kpi' => [
                    'participants' => 0,
                    'new_today' => 0,
                    'channels' => $giveaway->channels->count(),
                    'premium_percent' => 0,
                ],
                'timeline' => [],
                'geo' => [],
                'cities' => [],
                'languages' => [],
                'sources' => [],
                'premium' => ['premium' => 0, 'regular' => 0, 'premium_percent' => 0],
                'gender' => [],
                'age_groups' => [
                    '13-17' => 0,
                    '18-24' => 0,
                    '25-34' => 0,
                    '35-44' => 0,
                    '45+' => 0,
                    'unknown' => 0,
                ],
                'average_age' => null,
                'referrals' => [],
                'hourly' => [],
                'account_ages' => ['very_new' => 0, 'new' => 0, 'medium' => 0, 'old' => 0],
                'suspicious_count' => 0,
                'ip_duplicates' => [],
                'top_channels' => [],
                'devices' => ['ios' => 0, 'android' => 0, 'desktop' => 0, 'unknown' => 0],
            ]);
        }

        $todayStart = now()->startOfDay();

        $geo = $participants->groupBy('country')
            ->map(fn($g, $c) => ['country' => $c ?: 'Unknown', 'count' => $g->count(), 'percent' => round($g->count() / $total * 100, 1)])
            ->sortByDesc('count')->values()->toArray();

        $cities = $participants->whereNotNull('city')->groupBy('city')
            ->map(fn($g, $c) => ['city' => $c, 'count' => $g->count()])
            ->sortByDesc('count')->take(10)->values()->toArray();

        $languages = $participants->groupBy('language_code')
            ->map(fn($g, $l) => ['language' => $l ?: 'unknown', 'count' => $g->count(), 'percent' => round($g->count() / $total * 100, 1)])
            ->sortByDesc('count')->values()->toArray();

        $sources = $participants->groupBy('source')
            ->map(fn($g, $s) => ['source' => $s ?: 'direct', 'count' => $g->count(), 'percent' => round($g->count() / $total * 100, 1)])
            ->sortByDesc('count')->values()->toArray();

        $premiumCount = $participants->where('is_premium', true)->count();
        $premium = ['premium' => $premiumCount, 'regular' => $total - $premiumCount, 'premium_percent' => round($premiumCount / $total * 100, 1)];

        $genderData = $participants->groupBy('gender')
            ->map(fn($g, $gen) => ['gender' => $gen ?: 'unknown', 'count' => $g->count(), 'percent' => round($g->count() / $total * 100, 1)])
            ->sortByDesc('count')->values()->toArray();

        $withAge = $participants->whereNotNull('age');
        $ageGroups = [
            '13-17' => $withAge->whereBetween('age', [13, 17])->count(),
            '18-24' => $withAge->whereBetween('age', [18, 24])->count(),
            '25-34' => $withAge->whereBetween('age', [25, 34])->count(),
            '35-44' => $withAge->whereBetween('age', [35, 44])->count(),
            '45+' => $withAge->where('age', '>=', 45)->count(),
            'unknown' => $total - $withAge->count(),
        ];
        $avgAge = $withAge->count() > 0 ? round($withAge->avg('age'), 1) : null;

        $referrals = $participants->whereNotNull('referred_by')->groupBy('referred_by')
            ->map(fn($g, $r) => [
                'user_id' => (int) $r,
                'user_name' => $participants->firstWhere('user_id', $r)?->user_name ?? "ID:{$r}",
                'invited_count' => $g->count(),
            ])->sortByDesc('invited_count')->take(20)->values()->toArray();

        $hourly = $participants->groupBy(fn($p) => $p->created_at->format('Y-m-d H:00'))
            ->map(fn($g, $h) => ['hour' => $h, 'count' => $g->count()])
            ->sortKeys()->values()->toArray();

        $timeline = $participants->groupBy(fn($p) => $p->created_at->format('Y-m-d'))
            ->map(fn($g, $day) => ['day' => $day, 'count' => $g->count()])
            ->sortKeys()
            ->values()
            ->toArray();

        $accountAges = [
            'very_new' => $participants->filter(fn($p) => $this->ageCategory($p) === 'very_new')->count(),
            'new' => $participants->filter(fn($p) => $this->ageCategory($p) === 'new')->count(),
            'medium' => $participants->filter(fn($p) => $this->ageCategory($p) === 'medium')->count(),
            'old' => $participants->filter(fn($p) => $this->ageCategory($p) === 'old')->count(),
        ];

        $suspicious = $participants->filter(fn($p) => $this->ageCategory($p) === 'very_new' && !$p->is_premium)->count();

        $ipDuplicates = $participants->whereNotNull('ip_address')->groupBy('ip_address')
            ->filter(fn($g) => $g->count() > 1)
            ->map(fn($g, $ip) => ['ip' => $this->maskIp($ip), 'count' => $g->count(), 'users' => $g->pluck('user_name')->toArray()])
            ->values()->toArray();

        $channelIds = $participants->whereNotNull('source_channel_id')->pluck('source_channel_id')->unique()->values();
        $channelMap = Channel::whereIn('id', $channelIds)->get()->keyBy('id');
        $topChannels = $participants->whereNotNull('source_channel_id')
            ->groupBy('source_channel_id')
            ->map(function ($g, $channelId) use ($channelMap, $total) {
                $channel = $channelMap->get((int) $channelId);
                $memberCount = (int) ($channel?->member_count ?? 0);
                $participantsCount = $g->count();

                return [
                    'channel_id' => (int) $channelId,
                    'title' => $channel?->title ?? "Channel #{$channelId}",
                    'username' => $channel?->username,
                    'participants' => $participantsCount,
                    'percent' => round($participantsCount / $total * 100, 1),
                    'member_count' => $memberCount,
                    'conversion_percent' => $memberCount > 0 ? round($participantsCount / $memberCount * 100, 1) : null,
                ];
            })
            ->sortByDesc('participants')
            ->values()
            ->toArray();

        $sourceChannels = $participants->whereNotNull('source_channel_id')
            ->groupBy('source_channel_id')
            ->map(function ($g, $channelId) use ($channelMap, $total) {
                $channel = $channelMap->get((int) $channelId);
                return [
                    'name' => $channel?->username ? '@' . $channel->username : ($channel?->title ?? "Channel #{$channelId}"),
                    'val' => $g->count(),
                    'percent' => round($g->count() / $total * 100, 1),
                ];
            })
            ->sortByDesc('val')
            ->values();

        $directSources = collect($sources)
            ->reject(fn($source) => ($source['source'] ?? '') === 'webapp')
            ->map(fn($source) => [
                'name' => $source['source'] === 'channel_button' ? 'Кнопка канала' : ($source['source'] ?: 'Прямой'),
                'val' => $source['count'],
                'percent' => $source['percent'],
            ]);

        $combinedSources = $sourceChannels
            ->concat($directSources)
            ->sortByDesc('val')
            ->values()
            ->all();

        $deviceCounts = ['ios' => 0, 'android' => 0, 'desktop' => 0, 'unknown' => 0];
        foreach ($participants as $participant) {
            $platform = $this->detectPlatform((string) ($participant->user_agent ?? ''));
            $deviceCounts[$platform]++;
        }

        $deviceTotal = array_sum($deviceCounts);
        $devices = $deviceTotal > 0
            ? [
                'ios' => round($deviceCounts['ios'] / $deviceTotal * 100, 1),
                'android' => round($deviceCounts['android'] / $deviceTotal * 100, 1),
                'desktop' => round($deviceCounts['desktop'] / $deviceTotal * 100, 1),
                'unknown' => round($deviceCounts['unknown'] / $deviceTotal * 100, 1),
            ]
            : ['ios' => 0, 'android' => 0, 'desktop' => 0, 'unknown' => 0];

        return response()->json([
            'ok' => true,
            'giveaway' => [
                'id' => $giveaway->id,
                'public_id' => $giveaway->public_id,
                'title' => $giveaway->title,
                'status' => $giveaway->status,
                'channels_count' => $giveaway->channels->count(),
                'created_at' => $giveaway->created_at?->toISOString(),
                'start_date' => $giveaway->start_date?->toISOString(),
                'end_date' => $giveaway->end_date?->toISOString(),
            ],
            'kpi' => [
                'participants' => $total,
                'new_today' => $participants->filter(fn($p) => $p->created_at && $p->created_at->gte($todayStart))->count(),
                'channels' => $giveaway->channels->count(),
                'premium_percent' => $premium['premium_percent'],
            ],
            'total' => $total,
            'timeline' => $timeline,
            'geo' => $geo,
            'cities' => $cities,
            'languages' => $languages,
            'sources' => $combinedSources,
            'premium' => $premium,
            'gender' => $genderData,
            'age_groups' => $ageGroups,
            'average_age' => $avgAge,
            'referrals' => $referrals,
            'hourly' => $hourly,
            'account_ages' => $accountAges,
            'suspicious_count' => $suspicious,
            'ip_duplicates' => $ipDuplicates,
            'top_channels' => $topChannels,
            'devices' => $devices,
        ]);
    }

    private function detectPlatform(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        if ($ua === '') return 'unknown';
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios')) return 'ios';
        if (str_contains($ua, 'android')) return 'android';
        if (str_contains($ua, 'windows') || str_contains($ua, 'macintosh') || str_contains($ua, 'linux')) return 'desktop';
        return 'unknown';
    }
}
