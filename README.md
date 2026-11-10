# Social-Media-Posting für Shopware 6 · Social Media Posting

Neue Produkte und Blogartikel automatisch oder per Klick auf Facebook, Instagram, Pinterest, X, Mastodon und Telegram veröffentlichen. Mit Vorschau je Netzwerk, Planung, Testmodus und Verwaltung im Shopware-Admin.

Publish new products and blog articles to Facebook, Instagram, Pinterest, X, Mastodon and Telegram automatically or with one click, with per-network preview, scheduling, a test mode and an admin module. **Shopware 6.7**, PHP 8.3. License: **MIT**.

> **Status / Hinweis:** Neu und noch nicht im Live-Betrieb erprobt. Bitte zuerst im **Testmodus** (Standard) und auf einem Testsystem prüfen. Die Anbieter ändern Zugänge, Kosten und Regeln; das musst du beim jeweiligen Anbieter prüfen. Nutzung auf eigene Verantwortung (siehe Lizenz).

## Funktionen
- Quellen: Produkt, Blogartikel (Landingpage) oder eigener Text; Text, Bild und Link werden aus der Quelle vorgeschlagen
- Netzwerke: Facebook-Seite, Instagram (Business), Pinterest, X, Mastodon, Telegram-Kanal
- Vorschau je Netzwerk (Kürzung auf die Länge des Netzwerks, Hinweise zu fehlendem Bild)
- Sofort senden oder planen; Ergebnis je Netzwerk mit Link zum Beitrag und Fehlermeldung; erneutes Senden überspringt bereits gesendete Netzwerke
- Automatik: neue aktive Produkte und Blogartikel legen einen Entwurf oder einen geplanten Beitrag an, mit Wartezeit, Tageslimit, Altersgrenze und Schutz vor Massenanlage
- Testmodus (Standard: an), „Verbindung prüfen“ ohne Veröffentlichung, Kampagnen-Parameter am Link (utm_source)
- Geplante Aufgabe (alle 5 Minuten) und Befehl `bin/console social:senden` für Cronjobs

## Installation
1. ZIP aus dem Release herunterladen (Ordner `SocialPost` im ZIP), im Admin unter Erweiterungen → Meine Erweiterungen → „Erweiterung hochladen“, installieren und aktivieren.
2. Im Shop-Verzeichnis: `bin/console assets:install`, `bin/console scheduled-task:register` und `bin/console cache:clear`.
3. Einstellungen im Admin unter Marketing → Social Media → Reiter „Einstellungen“. Zuerst ein Netzwerk einrichten und „Speichern und Verbindung prüfen“ klicken.
4. Prüfen: `bin/console lint:container`.

## Zugangsdaten je Netzwerk (Kurzfassung)
Eine ausführliche Anleitung steht im Admin-Modul unter „Einstellungen“ → „Wo bekomme ich die Zugangsdaten?“ und im Reiter „Anleitung“.
- **Telegram:** Bot mit @BotFather anlegen, als Administrator zum Kanal hinzufügen, Token und @kanalname eintragen (einfachster Einstieg).
- **Mastodon:** Einstellungen → Entwicklung → Neue Anwendung, Recht `write:statuses`, Token kopieren.
- **Facebook:** Seiten-ID und langlebiges Seiten-Token mit `pages_manage_posts`.
- **Instagram:** Business-Konto, mit Facebook-Seite verknüpft, Konto-ID und Token mit `instagram_content_publish`; nur JPEG-Bilder.
- **Pinterest:** App mit Zugang für Pins, Token mit `boards:read` und `pins:write`, Pinnwand-ID.
- **X:** Entwickler-App mit Lese- und Schreibrechten, vier Schlüssel; der API-Zugang kann je nach Tarif kosten.

## Wichtige Regeln
- Zugangsdaten liegen in der Systemkonfiguration (Datenbank) und werden im Admin nicht wieder angezeigt. Beim Speichern bedeutet ein leeres geheimes Feld „unverändert“. Schnittstellen im Admin brauchen die Rechte `system_config:read` (Lesen) und `system_config:update` (Ändern und Senden).
- Veröffentlichte Beiträge löscht das Plugin nicht; das geht nur im Netzwerk selbst.
- Instagram und Pinterest brauchen ein Bild (Instagram: JPEG, öffentlich erreichbar). Links in Instagram-Texten sind nicht anklickbar.
- Die Automatik legt höchstens 10 Beiträge pro Stunde an und verwendet jede Quelle nur einmal.
- Das Admin-Skript trägt eine Versionsnummer in der Adresse (`src/Resources/public/administration/.vite/entrypoints.json`), damit Browser nach einem Update die neue Oberfläche laden. Bei einer neuen Version die Nummer dort hochzählen.

## Zeitplan
Geplante Beiträge sendet die geplante Aufgabe `social_post.send_due` (alle 5 Minuten, braucht den Messenger-Worker von Shopware). Alternativ per Cronjob alle 5 Minuten: `bin/console social:senden`.

## Anpassen
- Textvorlagen für Produkte und Blogartikel: im Admin unter Einstellungen → „Textvorlagen“ (Platzhalter `{name}`, `{description}`, `{hashtags}`).
- Weitere Netzwerke: Klasse in `src/Network/` anlegen (Schnittstelle `NetworkInterface`), in `services.xml` mit dem Tag `social_post.network` eintragen.
- Texte stehen im Admin-Skript (`src/Resources/public/administration/assets/social-post.js`, reines JavaScript ohne Build-Schritt).

## Mitmachen
Fehler und Wünsche gern als Issue, Verbesserungen als Pull Request. Bitte immer mit Shopware-Version und, wenn möglich, Log-Ausschnitt.

## English summary
Install the ZIP via Extensions → My extensions → Upload, activate it, then run `bin/console assets:install && bin/console scheduled-task:register && bin/console cache:clear`. Configure networks under Marketing → Social Media → Settings and use "Save and check connection" (publishes nothing). The plugin starts in test mode: posts are simulated, nothing is published until you switch it off. New active products and blog articles can create drafts or scheduled posts automatically. Test on a staging system first; use at your own risk (MIT license).

---
Ideengeber und Hersteller: Markus Czarnetzki, Oberhausen, NRW · [macdata.de](https://macdata.de)
© 1999 macdata.de · Plugin © 2026 Markus Czarnetzki · MIT License
Programmierung: Markus Czarnetzki
