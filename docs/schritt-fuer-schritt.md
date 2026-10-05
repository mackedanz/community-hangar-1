# Community-Hangar selbst betreiben – Schritt für Schritt

Diese Anleitung bringt eine eigene Instanz des Community-Hangars auf einen eigenen Server: mit Docker, Anmeldung über
Discord und Einrichtung der Orga per Discord-Bot (`/einrichten`). Du brauchst keine Programmierkenntnisse, aber
du solltest auf einem Linux-Server Befehle eingeben können. Zeitaufwand: etwa 45 Minuten.

## Was du vorher brauchst

| Was | Hinweis |
|---|---|
| Ein Linux-Server oder eine VM (auch ein Container) | Mindestens 1 GB RAM und 5 GB Platz. Docker muss laufen (Version 24 oder neuer, mit dem Befehl `docker compose`). |
| Eine **Domain** (oder Subdomain) | Z. B. `hangar.example.de`. Sie muss per DNS auf deinen Server bzw. deinen Reverse-Proxy zeigen. Discord verlangt HTTPS. |
| Ein **Discord-Konto** mit Admin-Rechten | Auf dem Server, der die Orga werden soll („Administrator“ oder „Server verwalten“). |
| Zugriff auf das Discord Developer Portal | Kostenlos, mit demselben Konto: <https://discord.com/developers/applications> |

Nichts davon kostet etwas. Das Docker-Image ist öffentlich und kommt von `ghcr.io/mackedanz/community-hangar-1`.

## Überblick: Was du gleich einträgst

Du holst dir im Discord Developer Portal fünf Werte und trägst sie in eine Datei `.env` auf dem Server ein:

| Wert in der `.env` | Woher | Geheim? |
|---|---|---|
| `DOMAIN` | deine Adresse ohne `https://` | nein |
| `AUTH_DISCORD_ID` | Portal → General Information → *Application ID* | nein |
| `DISCORD_PUBLIC_KEY` | Portal → General Information → *Public Key* | nein |
| `AUTH_DISCORD_SECRET` | Portal → OAuth2 → *Client Secret* | **ja** |
| `DISCORD_BOT_TOKEN` | Portal → Bot → *Token* | **ja** |
| `DB_PASSWORD` | frei wählbar, lang und zufällig | **ja** |
| `SERVER_ADMIN_DISCORD_ID` | deine eigene Discord-ID (Schritt 1) | nein |

Geheime Werte gehören nur in die `.env` auf dem Server. Nicht in Chats, Screenshots oder ein Repository.

---

## 1. Discord-Anwendung anlegen

Im [Developer Portal](https://discord.com/developers/applications) → **New Application** → Name z. B. „Community-Hangar“.

1. **General Information:** *Application ID* und *Public Key* kopieren (siehe Tabelle oben).
2. **OAuth2:**
   - *Client Secret* → **Reset Secret** → kopieren (wird nur einmal angezeigt).
   - Unter **Redirects** eintragen: `https://<DOMAIN>/api/auth/callback/discord` (statt `<DOMAIN>` deine Adresse).
3. **Bot:**
   - **Reset Token** → kopieren (ebenfalls nur einmal sichtbar).
   - **Server Members Intent** einschalten (sonst kann der Bot die Mitgliederliste nicht lesen).
   - **Public Bot** ausgeschaltet lassen, wenn nur du den Bot einladen willst (empfohlen). Mehr dazu in Schritt 9.
   - Message Content Intent und Presence Intent bleiben aus.
4. **Installation:**
   - **Install Link** auf **None** stellen. Discord verlangt das bei privatem Bot und meldet sonst den Fehler
     „Private Anwendungen können keinen Standard-Autorisierungslink haben“.
   - Unter *Installationskontexte* nur **Gildeninstallation** angehakt lassen.

Deine **eigene Discord-ID** (wird `SERVER_ADMIN_DISCORD_ID`, Betreiber mit Zugang zum Bereich `/admin`): In Discord →
Einstellungen → Erweitert → *Entwicklermodus* einschalten, dann Rechtsklick auf dein Profil → *Nutzer-ID kopieren*.

---

## 2. Dateien auf den Server legen

```bash
mkdir -p ~/community-hangar && cd ~/community-hangar
curl -O https://raw.githubusercontent.com/mackedanz/community-hangar-1/main/docker-compose.yml
curl -o .env https://raw.githubusercontent.com/mackedanz/community-hangar-1/main/.env.docker.example
```

## 3. Einstellungen eintragen

`.env` bearbeiten (`nano .env`). Diese Zeilen müssen ausgefüllt sein; auskommentierte Zeilen beginnen mit `#`, davon
das `#` entfernen:

```ini
DOMAIN=hangar.example.de
AUTH_DISCORD_ID=<Application ID>
AUTH_DISCORD_SECRET=<Client Secret>
DB_PASSWORD=<langes zufälliges Passwort>
SERVER_ADMIN_DISCORD_ID=<deine Discord-ID>
DISCORD_PUBLIC_KEY=<Public Key>
DISCORD_BOT_TOKEN=<Bot-Token>
LOGIN_REQUIRES_ALLOWLIST=1
ONBOARDING_GUILD_IDS=<ID deines Discord-Servers>
```

- **`LOGIN_REQUIRES_ALLOWLIST=1`**: Anmelden darf sich nur, wer auf der Zugangsliste des Bots steht (oder
  `SERVER_ADMIN_DISCORD_ID` ist). Das ist von Anfang an sicher, weil du dich über `/einrichten` selbst auf die Liste setzt.
- **`ONBOARDING_GUILD_IDS`**: Nur diese Discord-Server dürfen per `/einrichten` eine Orga anlegen (mehrere mit Komma).
  Die Server-ID bekommst du im Entwicklermodus: Rechtsklick auf den Server → *Server-ID kopieren*. Ohne diesen Eintrag
  darf **jeder** Server, auf dem der Bot ist, eine Orga anlegen.
- Optional: `HANGAR_PORT` (Standard 8181), `LEGAL_IMPRINT_URL` und `LEGAL_PRIVACY_URL` (Links zu Impressum und
  Datenschutz des Betreibers).

Wenn du eine eigene Kopie des Images aus einem eigenen Repository betreibst, setze zusätzlich `HANGAR_IMAGE=ghcr.io/<du>/<repo>:latest`.

---

## 4. HTTPS einrichten: zwei Wege

Die App selbst spricht nur HTTP. Davor muss ein Reverse-Proxy HTTPS und das Zertifikat übernehmen. Die mitgelieferte
`docker-compose.yml` gibt dafür den Port **8181** auf dem Server frei (änderbar mit `HANGAR_PORT`).
Prüfe vorher, dass er frei ist: `ss -ltn | grep 8181` (keine Ausgabe = frei).

### Weg A: Du hast schon einen Reverse-Proxy (z. B. Nginx Proxy Manager)

Proxy-Host anlegen:
- Domain: deine Adresse (muss per DNS auf den Proxy zeigen),
- Scheme `http`, Forward Hostname/IP: die IP deines Servers, Forward Port `8181`,
- *Block Common Exploits* an, *Cache Assets* und *Websockets* aus,
- Reiter SSL: *Request a new certificate*, *Force SSL* und *HTTP/2* an, *HSTS* aus.

Bis der Container läuft, zeigt die Domain „502 Bad Gateway“. Das ist normal.

### Weg B: Du hast keinen Proxy (Caddy mitstarten)

Caddy holt das Zertifikat selbst. Dafür müssen die Ports 80 und 443 deines Servers aus dem Internet erreichbar sein und
die Domain per DNS auf den Server zeigen. Lege neben der `docker-compose.yml` eine Datei
`docker-compose.override.yml` an (Docker liest sie automatisch mit):

```yaml
services:
  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    command: caddy reverse-proxy --from ${DOMAIN} --to app:80
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - caddy-data:/data

volumes:
  caddy-data:
```

---

## 5. Starten

```bash
docker compose up -d
docker compose logs --tail 40 app
```

Beim ersten Start passiert automatisch: Datenbank anlegen, Migrationen, danach (nach etwa 20 Sekunden) der Abgleich des
Schiffskatalogs aus der RSI Ship Matrix (ein bis zwei Minuten). Die Logs sollten die Zeile `Datenbank ist aktuell.`
**einmal** zeigen. Wiederholt sie sich ständig, startet der Container neu: dann siehe „Wenn etwas nicht klappt“.

Prüfen: `https://<DOMAIN>/healthz` muss `ok` zeigen. Erst wenn das klappt, geht es mit Discord weiter.

---

## 6. Endpunkt bei Discord eintragen

Im Developer Portal → **General Information** → *Interactions Endpoint URL*:

```
https://<DOMAIN>/discord/interactions
```

Beim Speichern schickt Discord eine Testanfrage. Wenn das Portal einen Fehler meldet, ist meist `DISCORD_PUBLIC_KEY`
falsch oder die Seite nicht erreichbar (Schritt 5 prüfen). Nach einer `.env`-Änderung immer `docker compose up -d`.

## 7. Slash-Befehl registrieren

```bash
docker compose exec app php bin/register-commands.php
```

Ausgabe: `Befehle registriert.`

## 8. Bot auf den Server einladen

Diesen Link im Browser öffnen, `<APPLICATION_ID>` ersetzen, den Server wählen:

```
https://discord.com/oauth2/authorize?client_id=<APPLICATION_ID>&scope=bot%20applications.commands&permissions=0
```

Der Bot braucht keine Rechte (`permissions=0`). Er muss nur auf dem Server sein, damit er die Mitgliederliste lesen kann.
In der Mitgliederliste steht er danach als „offline“. Das ist richtig: Er hält keine Dauerverbindung und antwortet nur,
wenn jemand einen Befehl eingibt.

## 9. Orga einrichten

Im Discord auf dem Server (als Admin oder mit „Server verwalten“) in einen beliebigen Kanal tippen:

```
/einrichten
```

Es erscheint eine Nachricht, die nur du siehst:

1. Die Orga wird mit dem Servernamen angelegt, du stehst automatisch auf der Zugangsliste.
2. Im ersten Menü **„Wer darf das Tool nutzen?“** wählst du die Rollen (bis zu 10), im zweiten **„Wer darf Events
   planen?“** die Planer-Rollen (optional).
3. Der Bot gleicht danach die Mitglieder ab und meldet: *„Fertig: n Mitglieder dürfen sich anmelden.“*

Der Befehl lässt sich beliebig oft wiederholen. Er öffnet die vorhandene Orga mit vorbelegten Rollen. Rollen kannst du
auch auf der Webseite unter *Orga verwalten* ändern. Dort steht auch die Zugangsliste mit dem Knopf „Mitglieder jetzt
abgleichen“. Der Bot gleicht außerdem automatisch **einmal pro Stunde** ab: Wer die Rolle bekommt, darf sich anmelden,
wer sie verliert, nicht mehr (laufende Sitzungen enden sofort).

## 10. Anmelden und prüfen

1. `https://<DOMAIN>` öffnen → *Mit Discord anmelden* → Discord-Abfrage bestätigen.
2. Du landest in der App und siehst deine Orga.
3. Ein Mitglied ohne Rolle sieht stattdessen: *„Dieses Discord-Konto steht nicht auf der Zugangsliste.“*

Danach bekommt jedes Mitglied über die Seite *RSI-Sync* eine Anleitung, wie es seinen Hangar von der RSI-Pledge-Seite
überträgt.

---

## Weitere Server und öffentlicher Bot (optional)

Standardmäßig kann nur **du** den Bot einladen (*Public Bot* aus). Willst du, dass Admins anderer Server ihn selbst
einladen:

1. Im Portal unter *Bot* **Public Bot** einschalten und beim *Install Link* wieder *Discord Provided Link* wählen.
2. **Jeder** Server-Admin kann dann den Link aus Schritt 8 benutzen. `/einrichten` funktioniert aber nur, wenn die
   Server-ID in `ONBOARDING_GUILD_IDS` steht. Andernfalls meldet der Bot „Dieser Server ist nicht freigeschaltet“ und
   nennt die Server-ID.
3. Server freigeben: ID aus der Meldung in der `.env` an `ONBOARDING_GUILD_IDS` anhängen (mit Komma), dann
   `docker compose up -d`.

Jeder Server bekommt seine eigene Orga mit eigenen Rollen und eigener Zugangsliste, die Orgas sind voneinander getrennt.
Setze `ONBOARDING_GUILD_IDS` bei öffentlichem Bot immer, sonst darf jeder Server eine Orga anlegen und deren Mitglieder
dürften sich anmelden.

---

## Betrieb

| Aufgabe | Befehl |
|---|---|
| Neue Version holen | `docker compose pull && docker compose up -d` |
| Logs ansehen | `docker compose logs -f app` |
| Katalog sofort abgleichen | `docker compose exec app php bin/catalog-sync.php` |
| Zugangslisten sofort abgleichen | `docker compose exec app php bin/sync-allowlist.php` |
| Schiffsbilder vorab laden | `docker compose exec app php bin/warm-images.php` |
| Datenbank sichern | `docker compose exec db sh -c 'mariadb-dump -u hangar -p"$MARIADB_PASSWORD" hangar' > sicherung.sql` |
| Stoppen (Daten bleiben) | `docker compose down` |

Gesichert werden sollte die Datenbank. Die Schiffsbilder (Volume `ship-images`) lädt die App bei Bedarf selbst nach.

**Datenbank ansehen:** Sie ist von außen nicht erreichbar. Auf dem Server: `docker compose exec db sh -c 'mariadb -u hangar -p"$MARIADB_PASSWORD" hangar'`.
Für ein Programm wie HeidiSQL gibst du den Port nur im internen Netz frei, z. B. mit einer `docker-compose.override.yml`
mit `db: ports: ["<Server-IP>:3307:3306"]`. Nie ins offene Internet.

## Alles zurücksetzen (Neuinstallation)

**Achtung, das löscht alle Daten** (Datenbank, Orga, Mitglieder, Bilder):

```bash
docker compose down -v
```

Danach den Ordner leeren, die zwei Dateien aus Schritt 2 neu holen und mit Schritt 3 weitermachen. Beim Discord-Konto
bleiben Anwendung, Bot und registrierter Befehl bestehen. Für einen komplett neuen Start legst du auch eine neue
Anwendung im Developer Portal an (Schritt 1).

---

## Wenn etwas nicht klappt

| Symptom | Ursache und Lösung |
|---|---|
| Container startet ständig neu („Restarting“) | `docker compose logs --tail 40 app` lesen. Die Meldung nennt den Grund, z. B. „Fehlende Einstellungen: …“ (Wert in `.env` fehlt) oder einen Datenbankfehler. |
| „Datenbank nicht erreichbar“ nach Passwortänderung | `DB_PASSWORD` wurde nach dem ersten Start geändert. Die Datenbank behält das erste Passwort. Entweder das alte wieder eintragen oder bei leeren Daten `docker compose down -v` und neu starten. |
| `https://<DOMAIN>/healthz` zeigt „502 Bad Gateway“ | Der Reverse-Proxy erreicht die App nicht: Port, IP und Schema (`http`) im Proxy-Host prüfen, `docker compose ps` (läuft `app`?). |
| Discord meldet „Invalid redirect_uri“ | Die Redirect-URL im Portal muss exakt `https://<DOMAIN>/api/auth/callback/discord` lauten. |
| Discord meldet „client_id … snowflake“ | `AUTH_DISCORD_ID` fehlt oder ist leer. |
| Portal: „Private Anwendungen können keinen Standard-Autorisierungslink haben“ | Unter *Installation* den *Install Link* auf **None** stellen (Schritt 1). |
| Endpunkt-URL wird vom Portal abgelehnt | `DISCORD_PUBLIC_KEY` prüfen, Seite von außen erreichbar? Nach `.env`-Änderung `docker compose up -d`. |
| `/einrichten` erscheint nicht | Befehl registrieren (Schritt 7), Bot eingeladen (Schritt 8)? Discord neu laden (Strg+R). |
| `/einrichten` zeigt „Die Anwendung reagiert nicht“ | Die *Interactions Endpoint URL* fehlt im Portal oder ist falsch (Schritt 6). `docker compose logs app \| grep interactions` zeigt, ob Anfragen ankommen. |
| „Der Bot darf die Mitgliederliste nicht lesen“ | Im Portal unter *Bot* den **Server Members Intent** einschalten. |
| „Dieser Server ist nicht freigeschaltet“ | Server-ID in `ONBOARDING_GUILD_IDS` eintragen, dann `docker compose up -d`. |
| Anmeldung sagt „nicht auf der Zugangsliste“ | Die Person hat keine der gewählten Rollen oder der Abgleich lief noch nicht (läuft stündlich, oder Knopf „Mitglieder jetzt abgleichen“). Du selbst kommst über `SERVER_ADMIN_DISCORD_ID` immer herein. Notfalls `LOGIN_REQUIRES_ALLOWLIST=0` setzen und `docker compose up -d`. |
| Katalog leer | `docker compose exec app php bin/catalog-sync.php` und die Ausgabe lesen (RSI-Server erreichbar?). |
| Bilder fehlen anfangs | Normal: Die App lädt sie beim ersten Anzeigen von FleetYards. Mit `bin/warm-images.php` vorab laden. |

## Von der alten Version (Next.js) übernehmen

Nur relevant, wenn du schon eine Instanz der früheren Version mit SQLite-Datenbank betreibst. Das Vorgehen steht im
Abschnitt „Übernahme der Daten aus der alten Version“ der `README.md`.
