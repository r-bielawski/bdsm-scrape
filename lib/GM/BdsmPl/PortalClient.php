<?php

namespace GM\BdsmPl;

use App\Text;
use GM\BdsmPl\Exceptions\LoginFailed;
use GM\Browser\SimpleCurlBrowser;

class PortalClient
{
    private SimpleCurlBrowser $browser;
    private const PLACEHOLDER_IMAGES = [
        'z/kobieta-duze.jpg',
        'z/kobieta-male.jpg',
        'z/mezczyzna-duze.jpg',
        'z/mezczyzna-male.jpg',
        'z/para-duze.jpg',
        'z/para-male.jpg',
        'z/trans-duze.jpg',
        'z/trans-male.jpg',
    ];

    public function __construct(SimpleCurlBrowser $browser)
    {
        $this->browser = $browser;
    }

    public function login(string $username, string $password): void
    {
        $this->browser->navigate('https://bdsm.pl/login.php');
        $this->browser->navigatePost(
            'https://bdsm.pl/login.php',
            [
                'email' => $username,
                'pass' => $password,
            ]
        );

        $html = $this->getUtf8Html();
        if (!preg_match('/Zalogowany:\s*<b>/iu', $html)) {
            throw new LoginFailed();
        }
    }

    public function searchProfileIds(array $searchFormData, int $pages = 3): array
    {
        $pages = max(1, min(10, $pages));
        // As of the current portal version, `search.php` uses GET (form has no `method`).
        // Posting the same params returns results but ignores filters.
        $query = http_build_query(array_merge(['s' => 1], $searchFormData));
        $this->browser->navigate('https://bdsm.pl/search.php?' . $query);

        $ids = $this->extractProfileIds($this->getUtf8Html());
        for ($page = 2; $page <= $pages; $page++) {
            $this->browser->navigate("https://bdsm.pl/search.php?page={$page}");
            $ids = array_merge($ids, $this->extractProfileIds($this->getUtf8Html()));
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        rsort($ids);

        return $ids;
    }

    public function fetchProfile(int $profileId): array
    {
        $this->browser->navigate("https://bdsm.pl/user.php?id={$profileId}");
        $html = $this->getUtf8Html();
        $profileSection = $html;
        if (preg_match('/<div id=data2>(.*?)<div id=koniec>/isu', $html, $sectionMatch)) {
            $profileSection = $sectionMatch[1];
        }

        $data = [
            'profile_id' => $profileId,
            'last_seen' => date('Y-m-d'),
            'updated_at' => date('Y-m-d H:i:s'),
            'active' => 1,
        ];

        $images = $this->extractProfileImages($html);
        $images = array_values(array_unique(array_filter($images, fn($u) => !$this->isPlaceholderImage($u))));
        if (count($images) > 0) {
            $data['image'] = $images[0];
            $data['images_json'] = json_encode($images, JSON_UNESCAPED_SLASHES);
        } else {
            $data['image'] = null;
            $data['images_json'] = json_encode([], JSON_UNESCAPED_SLASHES);
        }

        if (preg_match('/<h1>(.*?)<\/h1>/isu', $profileSection, $match)) {
            $data['nick'] = $this->stripAndClean($match[1]);
        }
        if (preg_match('/<p><b>(.*?)<\/b><\/p>/isu', $profileSection, $match)) {
            $data['motto'] = $this->stripAndClean($match[1]);
        }

        $rows = [];
        if (preg_match_all('/<tr><td>([^<]+):<\/td><td><b>(.*?)<\/b><\/td><\/tr>/isu', $profileSection, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $row) {
                $label = $this->stripAndClean($row[1]);
                $value = $this->stripAndClean($row[2]);
                $rows[$label] = $value;
            }
        }

        $mapping = [
            'Płeć' => 'plec',
            'Orientacja' => 'orientacja',
            'Miasto' => 'miasto',
            'Województwo' => 'wojewodztwo',
            'Wsparcie materialne' => 'sponsoring',
            'Stan cywilny' => 'stan_cywilny',
            'Poznam' => 'poznam',
            'Wzrost' => 'wzrost',
            'Waga' => 'waga',
            'Najbardziej lubię' => 'najbardziej_lubie',
            'Lekko czy ostro' => 'lekko_ostro',
            'Lubię' => 'lubi',
            'Ostatnio online' => 'online_status',
        ];

        foreach ($mapping as $label => $field) {
            if (isset($rows[$label])) {
                $data[$field] = $rows[$label];
            }
        }

        if (isset($rows['Wiek']) && preg_match('/([0-9]{1,3})/u', $rows['Wiek'], $match)) {
            $data['wiek'] = (int) $match[1];
        }
        if (isset($rows['Waga']) && preg_match('/([0-9]{1,3})/u', $rows['Waga'], $match)) {
            $data['waga_kg'] = (int) $match[1];
        }
        if (isset($rows['Wzrost']) && preg_match('/([0-9]{2,3})/u', $rows['Wzrost'], $match)) {
            $data['wzrost_cm'] = (int) $match[1];
        }

        $description = '';
        if (preg_match('/<\/table>.*?<br><br>(.*)$/isu', $profileSection, $match)) {
            $description = $this->stripAndClean($match[1], true);
        }
        if ($description !== '') {
            $data['desc'] = $description;
        }

        if (preg_match('/id=enterlink/iu', $html) || !preg_match('/napisz_form\.php\?id=' . preg_quote((string) $profileId, '/') . '/iu', $html)) {
            $data['active'] = 0;
        }

        return $data;
    }

    public function getUsersSentTo(): array
    {
        $this->browser->navigate('https://bdsm.pl/wiadomosci_wyslane.php?all=1');
        $html = $this->getUtf8Html();

        $ids = $this->extractProfileIds($html);
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $ids;
    }

    public function sendMessage(int $recipientId, string $message): void
    {
        $this->browser->navigate("https://bdsm.pl/napisz_form.php?id={$recipientId}");
        $formHtml = $this->getUtf8Html();

        if (!preg_match('/<form\s+action=([^\s>]+)\s+method=post>/iu', $formHtml, $match)) {
            throw new \RuntimeException('Nie udało się znaleźć formularza wysyłki wiadomości.');
        }

        $action = trim($match[1], '\'"');
        if (strpos($action, 'http') !== 0) {
            $action = 'https://bdsm.pl/' . ltrim($action, '/');
        }

        // bdsm.pl pages are served in legacy encodings; sending UTF-8 results in mojibake in the outbox.
        // Convert to ISO-8859-2 bytes before POST.
        $messageLegacy = @mb_convert_encoding($message, 'ISO-8859-2', 'UTF-8');
        if (!is_string($messageLegacy) || $messageLegacy === '') {
            $messageLegacy = $message;
        }

        $this->browser->navigatePost($action, ['message' => $messageLegacy]);
        $response = $this->getUtf8Html();

        if (!preg_match('/Twoja wiadomo|Wiadomość została wysłana|Wysłano wiadomość/iu', $response)) {
            throw new \RuntimeException('Portal nie potwierdził wysyłki wiadomości.');
        }
    }

    private function extractProfileIds(string $html): array
    {
        if (!preg_match_all('/user\.php\?id=([0-9]+)/iu', $html, $matches)) {
            return [];
        }

        return $matches[1];
    }

    private function extractProfileImages(string $html): array
    {
        $images = [];

        if (preg_match('/<div id=photo>(.*?)<\/div>/isu', $html, $m)) {
            $images = array_merge($images, $this->extractImgSrcs($m[1]));
        }
        if (preg_match('/<div id=photos>(.*?)<\/div>/isu', $html, $m)) {
            $images = array_merge($images, $this->extractImgSrcs($m[1]));
        }

        return array_values(array_unique($images));
    }

    private function extractImgSrcs(string $html): array
    {
        if (!preg_match_all('/<img[^>]+src=([^\\s>]+)[^>]*>/iu', $html, $matches)) {
            return [];
        }

        $out = [];
        foreach ($matches[1] as $src) {
            $src = trim((string) $src, '\'"');
            if ($src === '') {
                continue;
            }
            if (strpos($src, 'http') !== 0) {
                $src = 'https://bdsm.pl/' . ltrim($src, '/');
            }
            $out[] = $src;
        }
        return $out;
    }

    private function isPlaceholderImage(string $url): bool
    {
        $path = preg_replace('#^https?://bdsm\\.pl/#i', '', $url);
        $path = ltrim((string) $path, '/');
        foreach (self::PLACEHOLDER_IMAGES as $ph) {
            if (strcasecmp($path, $ph) === 0) {
                return true;
            }
        }
        return false;
    }

    private function getUtf8Html(): string
    {
        return Text::normalizeMultiline((string) $this->browser->getContents());
    }

    private function stripAndClean(string $value, bool $allowNewLines = false): string
    {
        if ($allowNewLines) {
            $value = str_replace(['<br>', '<br/>', '<br />'], "\n", $value);
        }
        $value = strip_tags($value);

        return $allowNewLines
            ? trim(preg_replace('/[ \t]+/u', ' ', Text::normalizeMultiline($value)) ?? '')
            : Text::toUtf8($value);
    }
}
