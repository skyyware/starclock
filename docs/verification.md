# Prüfung vom 29. Oktober 2026

Starclock 0.1.0 wurde ausschließlich mit künstlichen Kontakten geprüft. Ausgehendes Netzwerk und PHP-Mail waren auf Betriebssystem- beziehungsweise PHP-Ebene gesperrt. Ein zusätzlicher Empfängerfilter hielt jede Nachricht vor dem Transport zurück.

| Umgebung | Ergebnis |
| --- | --- |
| PHP 8.1 und 8.4 | Je 24 Zeit- und Eingabeprüfungen bestanden. |
| WordPress 7.0, MailPoet 5.39.0, PHP 8.4 | 28 Integrationstests bestanden. |
| WordPress 7.1.4, MailPoet 5.44.0, PHP 8.4 | 28 Integrationstests bestanden. |
| WordPress 7.1.4, MailPoet 5.44.0, PHP 8.1 | 28 Integrationstests bestanden. |
| Verlorene Bestätigung nach tatsächlicher Queue-Erstellung | 5 Prüfungen bestanden; die native Queue wird pausiert, keine automatische Wiederholung. |
| Adminrechte, HTTP-Methode und Nonce | 4 Prüfungen bestanden; keine Mutation mit fehlenden Rechten oder ungültigem Nonce. |
| 8 parallele Reservierungen | 1 Gewinner, 7 abgewiesene Doppelungen. |
| 8 parallele vollständige MailPoet-Läufe | Genau 1 Kampagne mit Versandbeleg; danach sicher pausiert. |
| Native Browserprüfung | Speichern, Prüfen, gesperrte Testumgebung, isolierte Aktivierung und Pause geprüft. |
| Installiertes ZIP | Styles und Bedienung bei tatsächlichen CSS-Breiten 320, 769 und 1280 Pixel geprüft. Kein horizontaler Überlauf. |

Die Integration prüft unter anderem bestätigte Listenmitglieder, globale und listenbezogene Abmeldungen, unveränderte Vorlagen, geänderte Wochenkopien, abgelaufene Versandfenster, veraltete Formulare, ausgelassene Termine, geänderte Website-Adressen und die Deaktivierung mit offenen Kampagnen. Unabhängige MailPoet-Kampagnen bleiben freigegeben.

Bei der Paketprüfung blieb nach dem Wechsel vom Entwicklungs-Symlink zum entpackten Plugin ein alter PHP-Pfad im laufenden Testserver erhalten. Der falsche Stylesheet-Link wurde im Browser erkannt. Nach Neustart dieses isolierten Servers zeigte das tatsächliche Paket den richtigen Plugin-URL und die geprüften Styles. Bei einem solchen Installationswechsel den tatsächlichen Asset-URL prüfen; ein vorhandenes CSS im ZIP genügt nicht.

Die WordPress-Mitteilung über eine überfällige Action-Scheduler-Aufgabe stammt aus der isolierten Testinstallation mit bewusst abgeschaltetem Cron. Sie ist kein Befund über einen Kundenserver. Die Screenshots zeigen diese Testinstallation unverändert.

Nach den Tests: keine aktiven Starclock-Pläne, keine offenen eigenen Übergaben, kein Starclock-Cron und keine MailPoet-Versandstatistik. Testkonstante entfernt, UI-Plugin deaktiviert und die eigenen Testprozesse gestoppt. Die synthetischen Daten und Nachweise bleiben für Reproduktion lokal erhalten.

Nicht nachgewiesen sind SMTP-Zustellung, ein produktiver Hoster-Cron und die fachliche Freigabe einer echten Vorlage samt Empfängern. Es wurde keine Kundenautomatik installiert oder aktiviert. Die deklarierte Mindestversion WordPress 6.5 ist noch kein getesteter Matrixeintrag; die tatsächlich getesteten Versionen stehen oben.
