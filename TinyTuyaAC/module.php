<?php

class TinyTuyaAC extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('APIHost', '192.168.1.3');
        $this->RegisterPropertyInteger('APIPort', 8888);
        $this->RegisterPropertyInteger('PollInterval', 30);
        $this->RegisterPropertyInteger('TemperatureFactor', 1);
        $this->RegisterPropertyBoolean('AutoPoll', true);

        $this->CreateSelectionProfiles();

        $this->RegisterVariableBoolean('Power', 'Power', [], 10);
        $this->RegisterVariableInteger(
            'TargetTemperature',
            'Solltemperatur',
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT, 'SUFFIX' => ' °C'],
            20
        );
        $this->RegisterVariableFloat(
            'CurrentTemperature',
            'Isttemperatur',
            ['SUFFIX' => ' °C'],
            30
        );
        $this->RegisterVariableInteger(
            'Mode',
            'Betriebsmodus',
            ['PROFILE' => 'TTAC.Mode'],
            40
        );
        $this->RegisterVariableInteger(
            'FanSpeed',
            'Lüftergeschwindigkeit',
            ['PROFILE' => 'TTAC.FanSpeed'],
            50
        );
        $this->RegisterVariableBoolean('Swing', 'Schwingen', [], 60);
        $this->RegisterVariableBoolean('LED', 'LED Beleuchtung', [], 70);
        $this->RegisterVariableBoolean('Turbo', 'Turbo Modus', [], 80);

        $this->EnableAction('Power');
        $this->EnableAction('TargetTemperature');
        $this->EnableAction('Mode');
        $this->EnableAction('FanSpeed');
        $this->EnableAction('Swing');
        $this->EnableAction('LED');
        $this->EnableAction('Turbo');

        $this->RegisterTimer('PollTimer', 0, 'TTAC_Poll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $interval = max(5, $this->ReadPropertyInteger('PollInterval'));
        $enabled = $this->ReadPropertyBoolean('AutoPoll')
            && trim($this->ReadPropertyString('DeviceID')) !== '';

        $this->SetTimerInterval('PollTimer', $enabled ? $interval * 1000 : 0);

        if ($enabled) {
            $this->Poll();
        } else {
            $this->SetStatus(104);
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Power':
                $this->SetDPS(1, (bool)$Value);
                break;

            case 'TargetTemperature':
                $value = (int)$Value;
                $factor = max(1, $this->ReadPropertyInteger('TemperatureFactor'));

                if ($factor > 1) {
                    $value *= $factor;
                }

                $this->SetDPS(2, $value);
                break;

            case 'Mode':
                $value = (int)$Value;
                $modes = $this->GetModes();

                if (!array_key_exists($value, $modes)) {
                    throw new Exception('Ungültiger Betriebsmodus: ' . $value);
                }

                $this->SetDPS(4, $modes[$value]);
                break;

            case 'FanSpeed':
                $value = (int)$Value;
                $fanSpeeds = $this->GetFanSpeeds();

                if (!array_key_exists($value, $fanSpeeds)) {
                    throw new Exception('Ungültige Lüftergeschwindigkeit: ' . $value);
                }

                $this->SetDPS(5, $fanSpeeds[$value]);
                break;

            case 'Swing':
                $this->SetDPS(30, (bool)$Value);
                break;

            case 'LED':
                $this->SetDPS(36, (bool)$Value);
                break;

            case 'Turbo':
                $this->SetDPS(104, (bool)$Value);
                break;

            default:
                throw new Exception('Unbekannte Variable: ' . $Ident);
        }
    }

    public function Poll(): void
    {
        $deviceID = trim($this->ReadPropertyString('DeviceID'));

        if ($deviceID === '') {
            $this->SetStatus(104);
            return;
        }

        $url = $this->BaseURL() . '/status/' . rawurlencode($deviceID);
        $response = $this->HttpGet($url);

        if ($response === false) {
            $this->SetStatus(200);
            return;
        }

        $json = json_decode($response, true);

        if (!is_array($json)) {
            $this->SetStatus(201);
            $this->LogMessage(
                'TinyTuya: Ungültige JSON-Antwort: ' . substr($response, 0, 500),
                KL_ERROR
            );
            return;
        }

        if (isset($json['Error']) || isset($json['Err'])) {
            $this->SetStatus(202);
            $this->LogMessage(
                'TinyTuya API Fehler: ' . json_encode($json, JSON_UNESCAPED_SLASHES),
                KL_ERROR
            );
            return;
        }

        if (!isset($json['dps']) || !is_array($json['dps'])) {
            $this->SetStatus(203);
            $this->LogMessage(
                'TinyTuya: Antwort enthält kein dps-Objekt: ' . json_encode($json),
                KL_ERROR
            );
            return;
        }

        $dps = $json['dps'];
        $factor = max(1, $this->ReadPropertyInteger('TemperatureFactor'));

        if (array_key_exists('1', $dps)) {
            $this->SetValue('Power', (bool)$dps['1']);
        }

        if (array_key_exists('2', $dps) && is_numeric($dps['2'])) {
            $value = (int)round((float)$dps['2'] / $factor);
            $this->SetValue('TargetTemperature', $value);
        }

        if (array_key_exists('3', $dps) && is_numeric($dps['3'])) {
            $value = (float)$dps['3'];

            if ($factor > 1) {
                $value /= $factor;
            }

            $this->SetValue('CurrentTemperature', $value);
        }

        if (array_key_exists('4', $dps)) {
            $this->SetSelectionValue('Mode', (string)$dps['4'], $this->GetModes());
        }

        if (array_key_exists('5', $dps)) {
            $this->SetSelectionValue('FanSpeed', (string)$dps['5'], $this->GetFanSpeeds());
        }

        if (array_key_exists('30', $dps)) {
            $this->SetValue('Swing', (bool)$dps['30']);
        }

        if (array_key_exists('36', $dps)) {
            $this->SetValue('LED', (bool)$dps['36']);
        }

        if (array_key_exists('104', $dps)) {
            $this->SetValue('Turbo', (bool)$dps['104']);
        }

        $this->SetStatus(102);
    }

    private function SetSelectionValue(string $ident, string $apiValue, array $mapping): void
    {
        $index = array_search($apiValue, $mapping, true);

        if ($index !== false) {
            $this->SetValue($ident, (int)$index);
        } else {
            $this->LogMessage(
                'TinyTuya: Unbekannter Wert für ' . $ident . ': ' . $apiValue,
                KL_WARNING
            );
        }
    }

    private function GetModes(): array
    {
        return [
            0 => 'auto',
            1 => 'cold',
            2 => 'wet',
            3 => 'wind',
            4 => 'hot'
        ];
    }

    private function GetFanSpeeds(): array
    {
        return [
            0 => 'auto',
            1 => 'low',
            2 => 'middle',
            3 => 'high'
        ];
    }

    private function CreateSelectionProfiles(): void
    {
        $this->CreateIntegerProfile(
            'TTAC.Mode',
            $this->GetModes(),
            [
                'auto' => 'Automatik',
                'cold' => 'Kühlen',
                'wet' => 'Entfeuchten',
                'wind' => 'Lüften',
                'hot' => 'Heizen'
            ]
        );

        $this->CreateIntegerProfile(
            'TTAC.FanSpeed',
            $this->GetFanSpeeds(),
            [
                'auto' => 'Automatik',
                'low' => 'Niedrig',
                'middle' => 'Mittel',
                'high' => 'Hoch'
            ]
        );
    }

    private function CreateIntegerProfile(string $profileName, array $values, array $captions): void
    {
        if (!IPS_VariableProfileExists($profileName)) {
            IPS_CreateVariableProfile($profileName, 1);
        }

        IPS_SetVariableProfileValues($profileName, 0, count($values) - 1, 0);

        foreach (array_values($values) as $index => $value) {
            $caption = $captions[$value] ?? $value;
            IPS_SetVariableProfileAssociation($profileName, $index, $caption, '', -1);
        }
    }

    private function SetDPS(int $dps, mixed $value): void
    {
        $deviceID = trim($this->ReadPropertyString('DeviceID'));

        if ($deviceID === '') {
            throw new Exception('Keine Device ID konfiguriert.');
        }

        if (is_bool($value)) {
            $apiValue = $value ? 'true' : 'false';
        } else {
            $apiValue = (string)$value;
        }

        $url = $this->BaseURL()
            . '/set/'
            . rawurlencode($deviceID)
            . '/'
            . $dps
            . '/'
            . rawurlencode($apiValue);

        $response = $this->HttpGet($url);

        if ($response === false) {
            throw new Exception('TinyTuya API nicht erreichbar.');
        }

        $json = json_decode($response, true);

        if (is_array($json) && (isset($json['Error']) || isset($json['Err']))) {
            throw new Exception(
                'TinyTuya API Fehler: ' . json_encode($json, JSON_UNESCAPED_SLASHES)
            );
        }

        // Nach dem Schreiben den tatsächlichen Gerätestatus erneut lesen.
        sleep(1);
        $this->Poll();
    }

    private function BaseURL(): string
    {
        $host = trim($this->ReadPropertyString('APIHost'));
        $port = $this->ReadPropertyInteger('APIPort');

        return 'http://' . $host . ':' . $port;
    }

    private function HttpGet(string $url): string|false
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nConnection: close\r\n"
            ]
        ]);

        $this->LogMessage('TinyTuya HTTP GET: ' . $url, KL_DEBUG);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $this->LogMessage(
                'TinyTuya HTTP GET fehlgeschlagen: ' . $url,
                KL_ERROR
            );
        }

        return $result;
    }
}
