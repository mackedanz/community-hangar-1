# Community-Hangar einrichten – Schritt für Schritt

Ziel: eine laufende Instanz auf einer VM mit Docker, Anmeldung über Discord und Onboarding per Bot (`/einrichten`).
Zeitaufwand: etwa 30 Minuten. Du brauchst:

- eine VM mit Docker und Docker Compose (Version 2) sowie eine Domain, die auf die VM zeigt,
- ein Discord-Konto mit Admin-Rechten auf dem Server, der die Orga sein soll,
- das fertige Image `ghcr.io/mackedanz/community-hangar-1:latest` (baut die GitHub-Action bei jedem Push auf `main`).

---

## 1. Discord-Anwendung anlegen

Im [Discord Developer Portal](https://discord.com/developers/applications) → **New Application** (am besten eine eigene
für die Testinstanz, damit die Produktion unberührt bleibt).

1. **General Information:**
   - **Application ID** kopieren, das ist `AUTH_DISCORD_ID`.
   - **Public Key** kopieren, das ist `DISCORD_PUBLIC_KEY`.
2. **OAuth2:**
   - **Client Secret** → *Reset Secret* → kopieren, das ist `AUTH_DISCORD_SECRET` (wird nur einmal angezeigt).
   - Unter **Redirects** eintragen: `https://<DOMAIN>/api/auth/callback/discord` (statt `<DOMAIN>` deine Adresse, z. B. `hangar-test.example.de`).
3. **Bot:**
   - **Reset Token** → kopieren, das ist `DISCORD_BOT_TOKEN` (ebenfalls nur einmal sichtbar).
   - **Public Bot** einschalten, damit auch Admins anderer Server den Bot einladen können (siehe Schritt 9). Wichtig:
     Dann unbedingt `ONBOARDING_GUILD_IDS` setzen (Schritt 3), sonst darf jeder Server eine Orga einrichten.
   - **Server Members Intent** einschalten (sonst kann der Bot die Mitgliederliste nicht lesen).
   - Message Content Intent und Presence Intent bleiben aus.
4. **Installation:** Bei **Install Link** *Discord Provided Link* belassen (bei öffentlichem Bot erlaubt). Unter
   *Installationskontexte* nur **Gildeninstallation** angehakt lassen, die Nutzerinstallation abwählen.

Notiere dir außerdem deine eigene **Discord-ID**: In Discord → Einstellungen → Erweitert → *Entwicklermodus* an, dann
Rechtsklick auf dein Profil → *Nutzer-ID kopieren*. Sie wird `SERVER_ADMIN_DISCORD_ID` (Betreiber mit Zugriff auf `/admin`).

---

## 2. Dateien auf die VM legen

Auf der VM einen Ordner anlegen und die zwei Dateien aus dem Repository dorthin kopieren:

```bash
mkdir -p ~/community-hangar && cd ~/community-hangar
curl -O https://raw.githubusercontent.com/mackedanz/community-hangar-1/main/docker-compose.yml
curl -o .env https://raw.githubusercontent.com/mackedanz/community-hangar-1/main/.env.docker.example
```

(Ist das Repository privat, lade die Dateien stattdessen über GitHub herunter und kopiere sie per SFTP.)

## 3. Einstellungen eintragen

`.env` bearbeiten (`nano .env`) und diese Werte setzen:

```ini
DOMAIN=hangar-test.example.de
AUTH_DISCORD_ID=<Application ID>
AUTH_DISCORD_SECRET=<Client Secret>
DB_PASSWORD=<frei gewähltes, langes Passwort>
SERVER_ADMIN_DISCORD_ID=<deine Discord-ID>
DISCORD_PUBLIC_KEY=<Public Key>
DISCORD_BOT_TOKEN=<Bot-Token>
LOGIN_REQUIRES_ALLOWLIST=1
ONBOARDING_GUILD_IDS=<ID deines Discord-Servers>
```

- `LOGIN_REQUIRES_ALLOWLIST=1` bedeutet: Anmelden darf sich nur, wer auf der Zugangsliste des Bots steht (oder
  `SERVER_ADMIN_DISCORD_ID` ist). Das ist von Anfang an unkritisch, weil du dich über `/einrichten` selbst auf die Liste setzt.
- `ONBOARDING_GUILD_IDS` ist die **Freigabeliste der Server**: Nur diese Discord-Server dürfen per `/einrichten` eine Orga
  anlegen. Mehrere trennst du mit Komma. Die Server-ID bekommst du im Discord-Entwicklermodus (Einstellungen →
  Erweitert): Rechtsklick auf den Server → *Server-ID kopieren*. Ohne diesen Eintrag darf **jeder** Server, auf dem der
  Bot ist, eine Orga anlegen, und die Mitglieder dieser Fremd-Orgas könnten sich dann anmelden. Bei öffentlichem Bot
  deshalb immer setzen.

> Die Datei `.env` enthält Geheimnisse. Nicht ins Repository legen und keine Screenshots davon teilen.

## 4. Zugriff aufs Image (nur bei privatem Paket)

Das Image liegt bei GitHub. Ist das Paket **öffentlich** (GitHub → Profil → Packages → `community-hangar-1` →
Package settings → *Change visibility*), entfällt dieser Schritt. Sonst einmalig auf der VM anmelden, mit einem
GitHub-Token (Settings → Developer settings → Personal access tokens, Recht `read:packages`):

```bash
echo <TOKEN> | docker login ghcr.io -u mackedanz --password-stdin
```

## 5. HTTPS über den Nginx Proxy Manager

Die mitgelieferte `docker-compose.yml` ist für den Betrieb hinter einem Reverse-Proxy gebaut: Der Dienst `app` gibt den
Port **8181** (änderbar mit `HANGAR_PORT` in der `.env`) auf dem Server frei. Prüfe vorher, dass er frei ist:
`ss -ltn | grep 8181` (keine Ausgabe = frei).

Im Proxy Manager einen Proxy-Host anlegen:
- Domain: `hangar-test.example.de` (muss per DNS auf den Proxy zeigen),
- Scheme `http`, Forward Hostname/IP: die IP des Servers, Forward Port `8181`,
- *Block Common Exploits* an, *Cache Assets* und *Websockets* aus,
- Reiter SSL: *Request a new certificate*, *Force SSL* und *HTTP/2* an, HSTS aus.

Bis der Container läuft, zeigt die Domain „502 Bad Gateway“. Das ist normal.

Ohne eigenen Reverse-Proxy steht oben in der `docker-compose.yml` im Kommentar, wie man einen Caddy-Dienst ergänzt.

## 6. Starten

```bash
docker compose up -d
docker compose logs -f app
```

Beim ersten Start passiert automatisch: Datenbank anlegen, Migrationen, danach (nach etwa 20 Sekunden) der Abgleich des
Schiffskatalogs aus der RSI Ship Matrix. Das dauert ein bis zwei Minuten. Prüfen: `https://<DOMAIN>/healthz` muss `ok` zeigen.

---

## 7. Endpunkt bei Discord eintragen

Zurück im Developer Portal → **General Information** → *Interactions Endpoint URL*:

```
https://<DOMAIN>/discord/interactions
```

Beim Speichern schickt Discord eine Testanfrage. Meldet das Portal einen Fehler, ist meist `DISCORD_PUBLIC_KEY` falsch oder
die Seite nicht erreichbar (Schritt 5 und 6 prüfen, danach `docker compose up -d` erneut, wenn du die `.env` geändert hast).

## 8. Slash-Befehl registrieren

```bash
docker compose exec app php bin/register-commands.php
```

Ausgabe: `Befehle registriert.` (Der Befehl erscheint bei Discord bis zu eine Stunde verzögert, meist sofort.)

## 9. Bot auf den Server einladen

Diesen Link im Browser öffnen, `<APPLICATION_ID>` ersetzen, den Server wählen:

```
https://discord.com/oauth2/authorize?client_id=<APPLICATION_ID>&scope=bot%20applications.commands&permissions=0
```

Der Bot braucht keine Rechte (`permissions=0`). Er muss nur auf dem Server sein, damit er die Mitgliederliste lesen kann.

**Weitere Server:** Bei öffentlichem Bot kann jeder Admin (Recht „Server verwalten“) denselben Link für seinen Server
nutzen. Der Bot ist dann dort, aber `/einrichten` funktioniert nur, wenn die Server-ID in `ONBOARDING_GUILD_IDS` steht.
Andernfalls meldet der Bot „Dieser Server ist nicht freigeschaltet“ und nennt die Server-ID. So gibst du einen Server frei:
1. Die Server-ID aus der Meldung (oder per Rechtsklick auf den Server im Entwicklermodus) kopieren.
2. In der `.env` an `ONBOARDING_GUILD_IDS` anhängen, mit Komma getrennt.
3. `docker compose up -d` ausführen. Danach kann der Admin dort `/einrichten` ausführen.

Jeder Server bekommt seine eigene Orga mit eigenen Rollen und eigener Zugangsliste. Die Orgas sind voneinander getrennt.

## 10. Orga einrichten

Im Discord auf dem Server (als Admin oder mit „Server verwalten“) in einen beliebigen Kanal tippen:

```
/einrichten
```

Es erscheint eine Nachricht, die nur du siehst:

1. Die Orga wird mit dem Servernamen angelegt, du stehst automatisch auf der Zugangsliste.
2. Im ersten Menü **„Wer darf das Tool nutzen?“** wählst du die Rollen (bis zu 10), im zweiten **„Wer darf Events
   planen?“** die Planer-Rollen (optional).
3. Der Bot gleicht danach die Mitglieder ab und meldet: *„Fertig: n Mitglieder dürfen sich anmelden.“*

Rollen später ändern: erneut `/einrichten` (die bisherige Auswahl ist vorbelegt) oder auf der Webseite unter
*Orga verwalten*. Dort steht auch die Zugangsliste mit dem Knopf „Mitglieder jetzt abgleichen“.
Der Bot gleicht außerdem automatisch einmal pro Stunde ab: wer die Rolle verliert, kann sich nicht mehr anmelden.

## 11. Anmelden und prüfen

1. `https://<DOMAIN>` öffnen → *Mit Discord anmelden* → Discord-Abfrage bestätigen.
2. Du landest in der App und siehst deine Orga.
3. Ein Mitglied ohne Rolle sollte stattdessen sehen: *„Dieses Discord-Konto steht nicht auf der Zugangsliste.“*

---

## Betrieb

| Aufgabe | Befehl |
|---|---|
| Neue Version holen | `docker compose pull && docker compose up -d` |
| Logs ansehen | `docker compose logs -f app` |
| Katalog sofort abgleichen | `docker compose exec app php bin/catalog-sync.php` |
| Zugangslisten sofort abgleichen | `docker compose exec app php bin/sync-allowlist.php` |
| Datenbank sichern | `docker compose exec db sh -c 'mariadb-dump -u hangar -p"$MARIADB_PASSWORD" hangar' > sicherung.sql` |
| Stoppen | `docker compose down` (**nicht** `-v`, das löscht die Datenbank) |

Gesichert werden sollte die Datenbank. Die Schiffsbilder (Volume `ship-images`) lädt die App bei Bedarf selbst nach.

## Wenn etwas nicht klappt

| Symptom | Ursache und Lösung |
|---|---|
| Container startet nicht, „Fehlende Einstellungen: …“ | Werte in `.env` fehlen, `docker compose logs app` nennt sie. |
| Discord meldet „Invalid redirect_uri“ | Die Redirect-URL im Portal muss exakt `https://<DOMAIN>/api/auth/callback/discord` lauten. |
| Discord meldet „client_id … snowflake“ | `AUTH_DISCORD_ID` fehlt oder ist leer. |
| Endpunkt-URL wird vom Portal abgelehnt | `DISCORD_PUBLIC_KEY` prüfen, Seite von außen erreichbar? Nach `.env`-Änderung `docker compose up -d`. |
| `/einrichten` erscheint nicht | `register-commands.php` ausführen (Schritt 8), Bot eingeladen? (Schritt 9) |
| „Der Bot darf die Mitgliederliste nicht lesen“ | Im Portal unter *Bot* den **Server Members Intent** einschalten. |
| Bot meldet „Dieser Server ist nicht freigeschaltet“ | Server-ID in `ONBOARDING_GUILD_IDS` eintragen (Schritt 9, „Weitere Server“), dann `docker compose up -d`. |
| `/einrichten` zeigt „Die Anwendung reagiert nicht“ | Die *Interactions Endpoint URL* fehlt im Portal oder `DISCORD_PUBLIC_KEY` ist falsch (Schritt 7). `docker compose logs app \| grep interactions` zeigt, ob Anfragen ankommen. |
| Bot erscheint „offline“ | Normal: Der Bot arbeitet ohne Dauerverbindung und antwortet nur auf Befehle. |
| Ich bin ausgesperrt, Anmeldung sagt „nicht auf der Zugangsliste“ | Betreiber-ID in `SERVER_ADMIN_DISCORD_ID` kann sich immer anmelden. Oder kurz `LOGIN_REQUIRES_ALLOWLIST=0` setzen und `docker compose up -d`. |
| Katalog leer | `docker compose exec app php bin/catalog-sync.php` und die Ausgabe lesen (RSI-Server erreichbar?). |

## Von der alten Version (Next.js) übernehmen

Nicht Teil der Testinstanz. Wenn später die Produktionsdaten aus der SQLite-Datei übernommen werden sollen, steht das
Vorgehen im Abschnitt „Übernahme der Daten aus der alten Version“ der `README.md`.
