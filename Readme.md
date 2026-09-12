# TinyTuyaAC für IP-Symcon

IP-Symcon-Modul zur Anbindung des TinyTuya API Servers.

Getestete Zielplattform:
- IP-Symcon 9.x
- TinyTuya API Server auf Port 8888

## Repository-Struktur

```text
TinyTuyaAC/
├── library.json
├── README.md
└── TinyTuyaAC/
    ├── module.json
    ├── module.php
    └── form.json
```

## TinyTuya API

Status:
`GET http://192.168.1.3:8888/status/{DeviceID}`

DPS setzen:
`GET http://192.168.1.3:8888/set/{DeviceID}/{DPS}/{Value}`

DPS der Klimaanlagen:
- DP1 = Power
- DP2 = Solltemperatur
- DP3 = Isttemperatur

## Geräte

- Isabella: `bf8743955dd81b5b33hu1x`
- Paul: `bfedc354dd84af4ddf3c8s`
- Arbeitszimmer: `bf3312402467be01b9onb5`
- Schlafzimmer: `bf9a270ab3ee2f2bd4aqu7`

## Installation

1. Repository auf GitHub pushen.
2. In IP-Symcon Modulverwaltung das Repository über die GitHub-URL hinzufügen.
3. IP-Symcon lädt die Bibliothek unter `/var/lib/symcon/modules/TinyTuyaAC`.
4. Danach über "Instanz hinzufügen" -> "TinyTuyaAC" eine Instanz pro Klimaanlage anlegen.

## Sicherheit

Keine Local Keys, Passwörter oder andere Zugangsdaten in dieses Repository eintragen.
