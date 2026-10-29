# Starclock prüfen und paketieren

## Lokale Prüfungen

PHP 8.1 oder neuer verwenden:

```sh
php tests/unit.php
python3 bin/package.py
```

Der Paketierer prüft PHP-Syntax, die Zeittests und die erlaubten Paketdateien. Ausgabe liegt unter `.runtime/dist/`. Das ZIP enthält das Verzeichnis `starclock/` und keine Tests, lokalen Daten oder Entwicklungswerkzeuge.

## MailPoet-Integration prüfen

Eine getrennte WordPress-Installation mit ausschließlich künstlichen Kontakten verwenden. Niemals eine Kundenkopie als Testdatenbank verwenden. Der Test verlangt:

- `DB_NAME` beginnt mit `starclock_test_`.
- `WP_HOME` ist `http://starclock-test.invalid`.
- `WP_ENVIRONMENT_TYPE` ist `local`, `DISABLE_WP_CRON` ist `true`.
- Die PHP-Funktion `mail` ist deaktiviert. Das Betriebssystem verweigert ausgehende Netzwerkverbindungen; nur der private Unix-Datenbanksocket ist erreichbar.
- Ein MU-Guard blockiert WordPress-HTTP, `wp_mail` und den asynchronen Action-Scheduler.

MailPoet aktivieren und die passende Version bereitstellen. Über den isolierten PHP-Aufruf ausführen:

```sh
php /path/to/wp-cli.phar --path=/path/to/synthetic-wordpress eval 'require "/path/to/starclock/tests/integration.php";'
```

`eval-file` verwenden wir nicht: WP-CLI stellt der Datei Code voran und verletzt damit `strict_types`. `eval` lädt die Datei über `require`, sodass PHP sie normal prüft.

Der Test legt eindeutig markierte Listen, Kontakte, Vorlagen und Laufbelege an. Der echte MailPoet-Worker ermittelt Empfänger. Ein nachgeschalteter Testfilter verwirft alle Empfänger vor dem gesperrten Transport. Die Testpläne enden pausiert. Nach dem Test offene eigene Jobs prüfen und den isolierten Datenbankprozess beenden. Nachweise behalten; keine ungezielte Bereinigung.

Die temporäre Konstante `STARCLOCK_ALLOW_NONPRODUCTION` erlaubt Aktivierung ausschließlich für separat gesicherte Tests. Sie stellt selbst keine Versandsperre her und gehört nicht in ein Kunden-Stage-System.

## Release prüfen

Vor einer neuen Version die Zeittests, die echte MailPoet-Integration und konkurrierende Reservierungen prüfen. Den Admin ohne MailPoet, mit ungültigem Formular, als unberechtigter Benutzer und in schmaler Ansicht kontrollieren. Eine erfolgreiche Übergabe ist kein SMTP- oder Zustelltest.

Versionsnummer, dokumentierte Kompatibilität und Paket zusammen ändern. Ein neues ZIP lokal installieren, aktivieren und wieder deaktivieren. Prüfen, dass keine Starclock-Jobs und keine offenen eigenen MailPoet-Queues verbleiben. Erst dann den geprüften Quellstand und das Paket veröffentlichen. GitHub Actions bleibt deaktiviert.
