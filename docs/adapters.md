# Adapter-Vertrag

Eine Erweiterung implementiert `Starclock\Adapter` und registriert sie über den WordPress-Filter `starclock_adapters`. Der Schlüssel muss der `id()` entsprechen. Eine vorhandene Registrierung nicht überschreiben.

| Methode | Vertrag |
| --- | --- |
| `id()` | Stabiler Schlüssel aus Kleinbuchstaben, Ziffern, Unterstrich oder Bindestrich, höchstens 40 Zeichen. |
| `label()` | Name in der Vorlagenauswahl. |
| `available()` | Prüft Abhängigkeiten und getestete Versionen; wirft bei fehlender Unterstützung eine Ausnahme. |
| `choices()` | Liste mit positiven ganzzahligen `id` und lesbaren `label`. Nur unveröffentlichte Vorlagen anbieten. |
| `inspect()` | Liefert `fingerprint`, `subject`, `sender`, `reply_to` und `lists`. Der Fingerprint deckt Inhalt, Empfängerwahl und Absender ab, nicht den wechselnden Mitgliederstand einer Liste. Mit `requireDraft=false` wird auch eine bereits übergebene Kopie geprüft. |
| `duplicate()` | Erstellt einen ungesendeten Entwurf. Keine Warteschlange und kein Versand in dieser Methode. |
| `enqueue()` | Übergibt ausschließlich die Kopie und liefert einen dauerhaften Beleg. Eine unklare Antwort ist ein Fehler, keine Erlaubnis zur Wiederholung. |
| `pause()` | Pausiert die offene Kopie beim Anbieter. Bei bereits abgeschlossenem Versand darf die Methode ohne Änderung zurückkehren. Fehler müssen sichtbar bleiben. |
| `deliveryState()` | Liefert `draft`, `queued`, `completed`, `paused` oder `cancelled`. Ein Abschluss beim Anbieter bestätigt keine tatsächliche Zustellung. |

Die Erweiterung muss unmittelbar vor jedem Empfänger-Batch `Engine::permits(adapter, externalId, now)` prüfen. Sie muss bei einer Ausnahme geschlossen bleiben. Unbekannte Kampagnen lässt der Kern unverändert. Ohne eine solche letzte Prüfung darf der Adapter nicht als versandfähig registriert werden.

Der Kern reserviert einen Termin je Plan atomar. Er speichert die externe Kopien-ID vor `enqueue()`. Der eindeutige Datenbankschlüssel verhindert eine zweite Übergabe, auch bei Parallelität. Ein abgebrochener Prozess hinterlässt einen reservierten oder unklaren Lauf, der eine manuelle Prüfung erfordert.

MailPoet nutzt interne Klassen, weil die öffentliche API keine geeignete wiederkehrende Standardkampagne anbietet. Die genaue Versionsliste steht in `MailPoetAdapter::TESTED_VERSIONS`. Ein neuer Versionswert braucht einen erfolgreichen Integrationstest mit der echten MailPoet-Warteschlange und ihrem Empfängerfilter.
