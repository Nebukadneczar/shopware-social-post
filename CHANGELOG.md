# Changelog

## 1.0.1 (10.11.2026)
- Textvorschlag aus Produktbeschreibung: Absätze und Zeilenumbrüche werden zu Leerzeichen (kein „KHDrei“ mehr).

## 1.0.0 (10.11.2026)
- Erste Version: Beiträge zu Produkten, Blogartikeln (Landingpages) und eigenem Text auf Facebook (Seite), Instagram (Business), Pinterest, X, Mastodon und Telegram.
- Admin-Modul „Social Media“ (Marketing): Beitragsliste mit Filtern, Editor mit Quellen-Suche, Vorschau je Netzwerk (Kürzung, Hinweise), Planung, Ergebnis je Netzwerk mit Link und Fehlermeldung, Duplizieren, Löschen.
- Einstellungen im Admin: Zugangsdaten je Netzwerk mit „Speichern und Verbindung prüfen“ (ohne Veröffentlichung), Testmodus (Standard: an), Kampagnen-Parameter, Hashtags, Standard-Bild, Textvorlagen.
- Automatik: neue aktive Produkte und Blogartikel legen einen Entwurf oder einen geplanten Beitrag an (Wartezeit, Tageslimit, Altersgrenze, Schutz vor Massenanlage).
- Geplante Aufgabe (alle 5 Minuten) und Befehl `bin/console social:senden`.
