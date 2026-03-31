<?php

namespace App\Services;

use GM\BdsmPl\PortalClient;

class ProfileSyncService
{
    private PortalClient $portal;
    private const AGE_MIN = 18;
    private const AGE_MAX = 34;
    private const WEIGHT_MIN = 40;
    private const WEIGHT_MAX = 60;

    public function __construct(PortalClient $portal)
    {
        $this->portal = $portal;
    }

    public function sync(array $searchFormData, int $pages = 3): array
    {
        $ids = $this->portal->searchProfileIds($searchFormData, $pages);
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $profileData = $this->portal->fetchProfile((int) $id);
            if (!$this->shouldKeepProfile($profileData)) {
                $skipped++;
                continue;
            }
            $profile = \R::findOne('profile', ' profile_id = ? ORDER BY id DESC ', [$id]);
            if (!$profile) {
                $profile = \R::dispense('profile');
                $profile->created_at = date('Y-m-d');
                $created++;
            } else {
                $updated++;
            }

            $profile->import($profileData);
            \R::store($profile);
        }

        $details = json_encode(
            [
                'pages' => $pages,
                'fetched_ids' => count($ids),
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ],
            JSON_UNESCAPED_UNICODE
        );
        \R::exec('INSERT INTO sync_run(kind, details, created_at) VALUES(?, ?, ?)', [
            'profiles',
            $details ?: null,
            date('Y-m-d H:i:s'),
        ]);

        return [
            'fetched_ids' => count($ids),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    private function shouldKeepProfile(array $profileData): bool
    {
        $age = isset($profileData['wiek']) ? (int) $profileData['wiek'] : 0;
        if ($age < self::AGE_MIN || $age > self::AGE_MAX) {
            return false;
        }

        $weight = isset($profileData['waga_kg']) ? (int) $profileData['waga_kg'] : 0;
        if ($weight < self::WEIGHT_MIN || $weight > self::WEIGHT_MAX) {
            return false;
        }

        $sex = mb_strtolower((string) ($profileData['plec'] ?? ''), 'UTF-8');
        if ($sex !== '' && !str_contains($sex, 'kobiet')) {
            return false;
        }

        // "Poznam: mężczyznę" is required. Skip profiles searching only women/couples.
        $poznam = mb_strtolower((string) ($profileData['poznam'] ?? ''), 'UTF-8');
        if (!str_contains($poznam, 'mężczyzn') && !str_contains($poznam, 'mezczyzn')) {
            return false;
        }

        // We only keep submissive women ("Uległa / uległy"). Delete dominant/switch/unknown.
        $orientacja = mb_strtolower(trim((string) ($profileData['orientacja'] ?? '')), 'UTF-8');
        if ($orientacja === '' || !str_contains($orientacja, 'uleg')) {
            return false;
        }
        if (str_contains($orientacja, 'domin') || str_contains($orientacja, 'switch')) {
            return false;
        }

        return true;
    }
}
