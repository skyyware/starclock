# Starclock

Wiederkehrende E-Mails in WordPress planen. MailPoet verwaltet Inhalt, Listen, Abmeldungen und Versand. Starclock ergänzt Wochenplan, Pause und nachvollziehbare Übergaben.

Version 0.1.1. Neue Pläne sind ausgeschaltet. Die Installation aktiviert keinen Versand und richtet keinen Server-Cron ein.

Open Source unter der [MIT-Lizenz](LICENSE). Keine Lizenzkosten, Lizenzschlüssel oder Aktivierung. Der Copyright- und Lizenzhinweis bleibt bei Weitergabe erhalten. WordPress und MailPoet behalten ihre eigenen Lizenzen und werden nicht mitgeliefert.

## Einrichten

1. Das Paket `starclock-0.1.1.zip` unter **Plugins → Installieren → Plugin hochladen** installieren und aktivieren.
2. In MailPoet einen Standard-Newsletter als Entwurf anlegen. Betreff, Listen, Absender, Antwortadresse und Abmeldelink ergänzen. Diesen Entwurf als Vorlage behalten.
3. Unter **Starclock → Neuer Zeitplan** die Vorlage und den wöchentlichen Termin wählen. Zeitzone, Startdatum und Versandfenster kontrollieren. **Pausiert speichern**.
4. **Vorlage und Zeitplan prüfen** wählen. Die angezeigten Empfänger und Absender kontrollieren; den Inhalt in MailPoet prüfen.
5. Den WordPress-Takt beim Hoster prüfen. Für zuverlässige Termine muss der Hoster WordPress-Cron regelmäßig ausführen. Danach **Automatik aktivieren** wählen.

Die Automatik beginnt beim nächsten Termin. Bei jedem Lauf entsteht eine eigene Standardkampagne in MailPoet. Die Vorlage bleibt unverändert. Neue bestätigte Kontakte der gewählten Listen werden berücksichtigt; abgemeldete Kontakte werden durch MailPoet ausgeschlossen.

**Pausieren** stoppt kommende Läufe und pausiert noch offene eigene MailPoet-Kampagnen. Ein bereits laufender Versand kann noch Empfänger erreichen; versandte E-Mails werden nicht zurückgerufen. **Nächsten Lauf auslassen** überspringt genau einen Termin.

Änderungen an Vorlage, Absender oder Listen erfordern eine neue Prüfung. Ein Fehler bei der Übergabe wird nicht automatisch wiederholt. Den Status zuerst in MailPoet prüfen, dann den unterbrochenen Lauf in Starclock schließen. Der reservierte Termin bleibt gesperrt; der folgende Termin kann wieder aktiviert werden.

## Betrieb

- PHP ab 8.1, WordPress ab 6.5. Einzelne Websites in Multisite sind möglich; Netzwerkaktivierung ist gesperrt.
- Der MailPoet-Adapter erlaubt nur nachgewiesene Versionen. Bei einer anderen Version bleiben Übergaben gesperrt. Keine automatische Erweiterung des Versionsbereichs.
- Der Wochenplan verwendet eine IANA-Zeitzone wie `Europe/Berlin`. In der Zeitumstellung entfällt eine nicht existierende Uhrzeit. Eine doppelte Uhrzeit wird beim ersten Auftreten einmal verwendet.
- Das Versandfenster begrenzt verspätete Übergaben und offene Restmengen. Es endet spätestens um Mitternacht. Verpasste Tage werden nicht nachgeholt.
- WordPress-Cron hängt ohne externen Takt von Seitenaufrufen ab. Die Statusanzeige zeigt den letzten Starclock-Aufruf. Die Einrichtung des Host-Takts bleibt beim Betreiber. [WordPress-Dokumentation](https://developer.wordpress.org/plugins/cron/)
- Ein geänderter Website-Host oder eine Nicht-Produktionsumgebung sperrt die Ausführung. Vor dem Klonen einer Website zusätzlich MailPoet und den Mailtransport isolieren.
- Rechte: Website-Verwaltung und MailPoet-E-Mail-Verwaltung. Änderungen benötigen einen gültigen WordPress-Nonce und den aktuellen Bearbeitungsstand.
- Starclock speichert Pläne, Vorlagen-Metadaten und Laufbelege in der eigenen WordPress-Datenbank. Es kopiert keine Empfängerlisten und hat keine Telemetrie.

## Wartung

Ein Update wird als geprüftes ZIP manuell installiert. Keine Fernsteuerung und kein automatischer GitHub-Updater. Beim Deaktivieren werden eigene offene MailPoet-Kampagnen zuerst pausiert. Schlägt das fehl, verhindert Starclock die Deaktivierung, damit der Schutz vor verspätetem Versand erhalten bleibt.

Pläne und Laufbelege bleiben beim Deaktivieren oder Löschen erhalten. Sie verhindern doppelte Übergaben nach einer Neuinstallation. Datenbankänderungen vor einem Downgrade prüfen; ein Code-Rollback allein stellt keine Daten wieder her.

[Entwicklung und Tests](docs/development.md) · [Prüfergebnisse](docs/verification.md) · [Adapter-Vertrag](docs/adapters.md) · [Änderungen](CHANGELOG.md)
