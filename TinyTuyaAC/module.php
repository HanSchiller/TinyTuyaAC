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

        $this->RegisterVariableBoolean('Power', 'Power', [], 10);
        $this->RegisterVariableInteger('TargetTemperature', 'Solltemperatur', [], 20);
        $this->RegisterVariableFloat('CurrentTemperature', 'Isttemperatur', [], 30);

        $this->EnableAction('Power');
        $this->EnableAction('TargetTemperature');

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
                    $value = round($value * $factor);
                }

                $this->SetDPS(2, $value);
                break;

            default:
                throw new Exception('Unbekannte Variable: ' . $Ident);
        }
        $this->LogMessage('TinyTuya RequestAction: ' . $Ident . ' = ' . strval($Value), KL_MESSAGE);
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
            $value = (float)$dps['2'];

            if ($factor > 1) {
                $value /= $factor;
            }

            $this->SetValue('TargetTemperature', $value);
        }

        if (array_key_exists('3', $dps) && is_numeric($dps['3'])) {
            $value = (float)$dps['3'];

            if ($factor > 1) {
                $value /= $factor;
            }

            $this->SetValue('CurrentTemperature', $value);
        }

        $this->SetStatus(102);
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

        $this->LogMessage('TinyTuya HTTP POST: ' . $url, KL_MESSAGE);

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
