# TinyTuyaAC für IP-Symcon

Version 1.1.0

Das Modul bindet eine TinyTuya-API an IP-Symcon an.

## Unterstützte Datenpunkte

- DP1 = Power
- DP2 = Solltemperatur
- DP3 = Isttemperatur
- DP4 = Betriebsmodus
- DP5 = Lüftergeschwindigkeit
- DP30 = Schwingen
- DP36 = LED Beleuchtung
- DP104 = Turbo Modus

### Betriebsmodus (DP4)

- `auto` = Automatik
- `cold` = Kühlen
- `wet` = Entfeuchten
- `wind` = Lüften
- `hot` = Heizen

### Lüftergeschwindigkeit (DP5)

- `auto` = Automatik
- `low` = Niedrig
- `middle` = Mittel
- `high` = Hoch

## Temperatur

Die Solltemperatur wird als Integer in IP-Symcon geführt. Bei `TemperatureFactor = 10` wird z.B. 24 °C als TinyTuya-Wert `240` übertragen.

Die Isttemperatur bleibt ein Float.

## API

- GET `/status/{DeviceID}`
- GET `/set/{DeviceID}/{DPS}/{Value}`
