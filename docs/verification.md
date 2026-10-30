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

## Linux-Stage am 30. Oktober

Ein getrenntes synthetisches WordPress auf dem Stage-Server wurde mit WordPress
7.0.4, PHP 8.4.27 und MailPoet 5.44.0 geprüft: 24 Zeitprüfungen, 28 Integrationsprüfungen,
5 Prüfungen zum verlorenen Übergabebeleg und 4 Rechte-/Nonce-Prüfungen bestanden.
Acht parallele vollständige Ticks erzeugten genau eine Kampagne; acht parallele
Reservierungen genau einen Gewinner. Zweimalige Aktivierung und Deaktivierung
des unveränderten ZIP 0.1.0 bestanden.

Linux verweigert IPv4- und IPv6-Sockets mit Fehler 97. fsockopen gab unter dieser
Sperre jedoch Fehler 0 zurück; der bisherige macOS-spezifische Nachweis über Fehler 1
war deshalb nicht portabel. Der gemeinsame Test-Guard prüft auf Linux beide
Socket-Familien direkt. Er wurde ohne Sperre abgewiesen und mit Sperre akzeptiert;
die vorhandene macOS-Sandbox besteht weiterhin. Ein Timeout allein wird nicht als
Nachweis der Netzwerksperre akzeptiert. Der Test-Guard steht ab Commit 767d540;
der installierte Plugin-Code bleibt Release 0.1.0.

Die Datenbank war neu, ausschließlich synthetisch und nur über ihren privaten
Unix-Socket erreichbar. PHP-Mail, externe Netzwerkverbindungen, WordPress-HTTP
und asynchrone Action-Scheduler-Aufrufe waren gesperrt. Kein öffentlicher
Test-Endpunkt wurde angelegt. Nach Abschluss: null aktive Pläne, offene Übergaben,
Starclock-Cronereignisse oder Versandstatistik; Plugin im Testsystem deaktiviert
und privater Datenbankprozess gestoppt. Keine SMTP-Zustellung geprüft.

## Begrenzter Live-Test am 30. Oktober

Release 0.1.1 wurde auf WordPress 7.1.4, PHP 8.3.36 und MailPoet 5.44.0
installiert; alle 16 Paketdateien entsprechen dem Release-Manifest. Gegenüber
0.1.0 änderten sich Lizenz und Dokumentation, nicht der Scheduler.

Eine ausdrücklich erlaubte interne Empfangsprobe verwendete ausschließlich
eine kontrollierte Testadresse. Eine zusätzliche, installationsbezogene Sperre
prüfte Plan, Ablaufzeit und exakten Empfänger vor dem Batch und vor SMTP.
Cc/Bcc waren ausgeschlossen; eine atomare Reservierung erlaubte genau einen
Transportversuch. Der Produktivplan blieb ausgeschaltet und zusätzlich gesperrt.

WordPress-Cron löste den Test rund zwei Minuten nach dem geplanten Termin aus.
Starclock erzeugte genau einen Lauf, eine MailPoet-Kopie und einen Queue-Beleg.
Ein weiterer gezielter Tick erzeugte keine zweite Kampagne. Der SMTP-Beleg und
die tatsächlich empfangene Nachricht besitzen dieselbe Message-ID. Der Eingang
lag im Posteingang, nicht im Spam; SPF, DKIM und DMARC bestanden. Abgemeldete
und unbestätigte Testkontakte wurden nicht beliefert.

Personalisierung, HTML-/Textfassungen und Abmeldemöglichkeit wurden am Empfang
geprüft. Die MailPoet-Webansicht hatte bei 390 CSS-Pixeln keinen seitlichen
Überlauf; dies ist kein mobiler Mailclient-Test. Private Mailinhalte, Adressen,
Kontodaten und Screenshots bleiben außerhalb dieser öffentlichen Repo.

Nach Abschluss: Testfreigabe geschlossen, Testplan pausiert, kein aktiver Plan,
keine offene eigene Queue und kein Starclock-Cron. Testkontakte, -liste und
-kampagnen wurden wiederherstellbar entfernt; Lauf- und Transportbelege bleiben.
Der reguläre Versand wurde nicht aktiviert.

Dieser Test belegt die einmalige native Auslösung und Zustellung auf der
geprüften Kombination. Er belegt weder einen unabhängigen Hoster-Zeitgeber
noch zukünftige Zustellung an jeden Empfänger. Beides ist bei jeder Installation
gesondert zu prüfen; ein erfolgreicher Test aktiviert keine Kundenautomatik.
