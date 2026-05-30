<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GeoIpService
{
    public function lookup(?string $ip): array
    {
        if (!$ip || empty($ip) || in_array($ip, ['127.0.0.1', '::1', '0.0.0.0']) || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.') || str_starts_with($ip, '172.')) {
            return ['country' => null, 'city' => null];
        }
        return Cache::remember("geoip:{$ip}", 86400, function () use ($ip) {
            try {
                $r = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", ['fields' => 'status,countryCode,city']);
                $d = $r->json();
                if (($d['status'] ?? '') === 'success') {
                    return ['country' => $d['countryCode'] ?? null, 'city' => $d['city'] ?? null];
                }
            } catch (\Exception $e) {
                Log::debug("GeoIP failed: {$e->getMessage()}");
            }
            return ['country' => null, 'city' => null];
        });
    }

    public static function guessGender(string $firstName): ?string
    {
        $name = mb_strtolower(trim(explode(' ', $firstName)[0]));
        if (empty($name)) return null;
        $maleExceptions = ['никита','илья','фома','лука','кузьма','савва','данила','миша','гоша','саша','женя','валя'];
        if (in_array($name, $maleExceptions)) return 'male';
        if (preg_match('/[а-яё]/u', $name)) {
            return in_array(mb_substr($name, -1), ['а', 'я']) ? 'female' : 'male';
        }
        return Cache::remember("gender:{$name}", 604800, function () use ($name) {
            try {
                $r = Http::timeout(2)->get("https://api.genderize.io", ['name' => $name]);
                $d = $r->json();
                if (($d['probability'] ?? 0) > 0.7) return $d['gender'];
            } catch (\Exception $e) {}
            return null;
        });
    }

    public static function estimateAccountAge(int $userId): ?string
    {
        $ranges = [[100000000,'2015-01-01'],[500000000,'2018-01-01'],[1000000000,'2020-06-01'],[2000000000,'2022-01-01'],[5000000000,'2023-01-01'],[7000000000,'2024-01-01'],[8000000000,'2024-06-01'],[9000000000,'2025-01-01']];
        foreach ($ranges as [$t, $d]) { if ($userId < $t) return $d; }
        return '2025-06-01';
    }
}
