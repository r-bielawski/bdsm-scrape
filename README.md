# Gemini BDSM Wrapper

Projekt pobiera profile z `bdsm.pl` przez `curl` (brak API), zapisuje je w bazie oraz umożliwia podgląd i wysyłkę wiadomości.

## Uruchomienie (Docker)

1. Start:
```sh
docker compose up -d --build
```

2. UI:
- http://localhost:8099
- Admin (konta/sync): http://localhost:8099/admin.php

## Panel kont (UI)

W UI jest panel **Konta**, który pozwala:
- dodać konto (login/hasło/proxy/enabled + domyślna treść wiadomości),
- edytować konto (hasło opcjonalnie; puste = bez zmian),
- usunąć konto (soft-delete).

## Skrypty (CLI)

Wszystkie komendy uruchamiaj przez kontener `gemini_bdsm_app`:

### Sync profili
```sh
docker exec -i gemini_bdsm_app php scripts/sync_profiles.php 5
docker exec -i gemini_bdsm_app php scripts/cleanup_profiles.php
```

### Sync “wysłane” (dla kont enabled)
```sh
docker exec -i gemini_bdsm_app php scripts/sync_sent.php
```

### Automatyczna wysyłka wiadomości (dla kont enabled)
Domyślnie działa w trybie `dry-run` (nic nie wysyła na portal).

Dry-run:
```sh
docker exec -i gemini_bdsm_app php scripts/send_auto.php --max-per-account=5 --max-total=10 --dry-run=1
```

Realna wysyłka (wymaga `ALLOW_AUTO_SEND=1`):
```sh
docker exec -i gemini_bdsm_app sh -lc 'ALLOW_AUTO_SEND=1 php scripts/send_auto.php --max-per-account=5 --max-total=10 --dry-run=0'
```

Uwaga o `proxy` w tabeli `account`:
- jeśli w `account.proxy` jest goły adres IP (np. `147.135.x.x`), to w Dockerze zwykle skończy się błędem `bind failed errno 99` (to jest tryb `CURLOPT_INTERFACE`).
- aktualnie w Dockerze takie IP jest automatycznie ignorowane (żeby nie blokować pracy lokalnie); poza Dockerem zachowanie pozostaje bez zmian.
- w takim przypadku ustaw `proxy` na pusty string albo użyj prawdziwego proxy `host:port` / `login:haslo@host:port`.

Obsługiwane formaty `account.proxy`:
- puste: bez proxy
- `1.2.3.4` (goły IPv4): bind IP (CURLOPT_INTERFACE; w Dockerze ignorowane)
- `host:port` (host może być IP)
- `user:pass@host:port`
- `http://user:pass@host:port` (również `https://...`, `socks5://...`)

## Cron (na hoście, przez `docker exec`)

W cronie ustaw pełną ścieżkę do `docker` (na macOS często: `/opt/homebrew/bin/docker`).

Przykład `crontab -e`:
```cron
SHELL=/bin/bash
PATH=/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin

# Sync profili co 30 minut (pobierz + cleanup)
*/30 * * * * /opt/homebrew/bin/docker exec -i gemini_bdsm_app php scripts/sync_profiles.php 5 >> /tmp/gemini_bdsm_cron.log 2>&1
*/30 * * * * /opt/homebrew/bin/docker exec -i gemini_bdsm_app php scripts/cleanup_profiles.php >> /tmp/gemini_bdsm_cron.log 2>&1

# Sync "wysłane" co 6 godzin
0 */6 * * * /opt/homebrew/bin/docker exec -i gemini_bdsm_app php scripts/sync_sent.php >> /tmp/gemini_bdsm_cron.log 2>&1

# Auto-send co 10 minut (po 1 wiadomości na konto na uruchomienie)
# Auto-send co 10 minut (max 5 wiadomości na konto na uruchomienie)
*/10 * * * * /opt/homebrew/bin/docker exec -i gemini_bdsm_app sh -lc 'ALLOW_AUTO_SEND=1 php scripts/send_auto.php --max-per-account=5 --max-total=50 --dry-run=0' >> /tmp/gemini_bdsm_cron.log 2>&1
```
