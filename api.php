<?php
/**
 * Tuya API JSON Endpoint
 * Vrací data o zařízeních ve formátu JSON
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Zpracování OPTIONS požadavku pro CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

class TuyaAPI {
    private $config;
    private $accessKey;
    private $secretKey;
    private $projectCode;
    private $baseUrl;
    
    private $accessToken = null;
    private $refreshToken = null;
    private $expiresIn = null;
    
    public function __construct() {
        // Načtení konfigurace
        $this->config = require_once __DIR__ . '/config.php';
        $this->accessKey = $this->config['accessKey'];
        $this->secretKey = $this->config['secretKey'];
        $this->projectCode = $this->config['projectCode'];
        $this->baseUrl = $this->config['baseUrl'];
        
        $this->login();
    }
    
    /**
     * Přihlášení do Tuya API a získání tokenu
     */
    private function login() {
        $result = $this->getToken();
        if ($result->success === false) {
            throw new Exception('Nepodařilo se přihlásit do Tuya API: ' . ($result->msg ?? 'Neznámá chyba'));
        }
        
        $this->accessToken = $result->result->access_token;
        $this->refreshToken = $result->result->refresh_token;
        $this->expiresIn = $result->result->expire_time;
    }
    
    /**
     * Získání přístupového tokenu
     */
    private function getToken() {
        $result = $this->callApi('/v1.0/token?grant_type=1');
        return json_decode($result);
    }
    
    /**
     * Získání stavu zařízení
     */
    public function getDeviceStatus($deviceId) {
        $result = $this->callApi('/v2.0/cloud/thing/batch?device_ids=' . $deviceId, 'GET', $this->accessToken);
        return json_decode($result);
    }
    
    /**
     * Získání vlastností zařízení
     */
    public function getDeviceProperties($deviceId) {
        $result = $this->callApi('/v2.0/cloud/thing/' . $deviceId . '/shadow/properties', 'GET', $this->accessToken);
        return json_decode($result);
    }
    
    /**
     * Získání denní spotřeby z vlastností zařízení
     */
    public function getDailyConsumption($deviceId) {
        $result = $this->getDeviceProperties($deviceId);
        
        if ($result->success === false) {
            return $result;
        }
        
        // Hledáme data o spotřebě v properties
        $consumptionData = [];
        $currentPower = 0;
        $totalEnergy = 0;
        
        if (isset($result->result->properties)) {
            foreach ($result->result->properties as $property) {
                if (isset($property->code) && strpos($property->code, 'total') !== false) {
                    // Převod hodnot podle typu
                    $value = $property->value ?? 'N/A';
                    if ($property->code === 'total_forward_energy' && $value !== 'N/A') {
                        $value = (float)$value / 100; // Wh -> kWh
                    } elseif ($property->code === 'total_power' && $value !== 'N/A') {
                        $value = (float)$value / 1000; // W -> kW
                    }
                    
                    $consumptionData[] = [
                        'code' => $property->code,
                        'value' => $value,
                        'time' => isset($property->time) ? date('Y-m-d H:i:s', $property->time/1000) : 'N/A',
                        'name' => $property->custom_name ?? $property->code
                    ];
                    
                    // Získáme aktuální výkon a celkovou spotřebu
                    if ($property->code === 'total_power') {
                        $currentPower = (float)($property->value ?? 0) / 1000; // Převod W -> kW
                    }
                    if ($property->code === 'total_forward_energy') {
                        $totalEnergy = (float)($property->value ?? 0) / 100; // Wh -> kWh (dělení 100)
                    }
                }
            }
        }
        
        // Výpočet odhadu denní spotřeby
        $estimatedDailyConsumption = 0;
        if ($currentPower > 0) {
            $estimatedDailyConsumption = $currentPower * 24; // kWh za den při konstantním výkonu (už v kW)
        }
        
        return (object) [
            'success' => true,
            'result' => [
                'device_id' => $deviceId,
                'consumption_data' => $consumptionData,
                'current_power_w' => $currentPower * 1000, // Zpět na W pro zobrazení
                'current_power_kw' => round($currentPower, 3),
                'total_energy_kwh' => $totalEnergy,
                'estimated_daily_consumption_kwh' => round($estimatedDailyConsumption, 2),
                'estimated_monthly_consumption_kwh' => round($estimatedDailyConsumption * 30, 2),
                'note' => 'Odhad denní spotřeby je založen na aktuálním výkonu. Skutečná spotřeba se může lišit.',
                'total_properties' => count($result->result->properties ?? [])
            ]
        ];
    }
    
    /**
     * Získání historických dat zařízení (denní spotřeba)
     */
    public function getDeviceHistory($deviceId, $startTime = null, $endTime = null, $type = 'day') {
        // Pokud nejsou zadány časy, použijeme dnešní den
        if ($startTime === null) {
            $startTime = strtotime('today') * 1000; // Začátek dnešního dne v milisekundách
        }
        if ($endTime === null) {
            $endTime = strtotime('tomorrow') * 1000 - 1; // Konec dnešního dne v milisekundách
        }
        
        // Zkusíme různé endpointy pro historická data
        $endpoints = [
            "/v1.0/iot-03/devices/{$deviceId}/logs?start_time={$startTime}&end_time={$endTime}&type={$type}",
            "/v1.0/iot-03/devices/{$deviceId}/history?start_time={$startTime}&end_time={$endTime}&type={$type}",
            "/v2.0/cloud/thing/{$deviceId}/logs?start_time={$startTime}&end_time={$endTime}&type={$type}",
            "/v1.0/iot-03/devices/{$deviceId}/statistics?type={$type}",
            "/v1.0/iot-03/devices/{$deviceId}/consumption?start_time={$startTime}&end_time={$endTime}"
        ];
        
        foreach ($endpoints as $endpoint) {
            try {
                $result = $this->callApi($endpoint, 'GET', $this->accessToken);
                $decoded = json_decode($result);
                if ($decoded && $decoded->success !== false) {
                    return $decoded;
                }
            } catch (Exception $e) {
                // Pokračujeme na další endpoint
                continue;
            }
        }
        
        return (object) ['success' => false, 'msg' => 'Žádný endpoint pro historická data nefunguje'];
    }
    
    /**
     * Získání statistik spotřeby zařízení
     */
    public function getDeviceStatistics($deviceId, $type = 'day') {
        $endpoints = [
            "/v1.0/iot-03/devices/{$deviceId}/statistics?type={$type}",
            "/v2.0/cloud/thing/{$deviceId}/statistics?type={$type}",
            "/v1.0/iot-03/devices/{$deviceId}/consumption?type={$type}"
        ];
        
        foreach ($endpoints as $endpoint) {
            try {
                $result = $this->callApi($endpoint, 'GET', $this->accessToken);
                $decoded = json_decode($result);
                if ($decoded && $decoded->success !== false) {
                    return $decoded;
                }
            } catch (Exception $e) {
                // Pokračujeme na další endpoint
                continue;
            }
        }
        
        return (object) ['success' => false, 'msg' => 'Žádný endpoint pro statistiky nefunguje'];
    }
    

    
    /**
     * Volání Tuya API pomocí cURL
     */
    private function callApi($endpoint, $method = 'GET', $token = null, $payload = null) {
        $time = time() * 1000;
        $token = $token ?? '';
        
        $stringToSign = implode("\n", [
            strtoupper($method),
            hash('sha256', (string) $payload),
            '',
            $endpoint,
        ]);
        
        $headers = [
            'sign_method: HMAC-SHA256',
            'client_id: ' . $this->accessKey,
            't: ' . $time,
            'mode: cors',
            'Content-Type: application/json',
            'sign: ' . $this->getSign($time, $token, $stringToSign),
            'access_token: ' . $token,
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: " . $httpCode . " - " . $response);
        }
        
        return $response;
    }
    
    /**
     * Generování HMAC-SHA256 podpisu
     */
    private function getSign($time, $token, $stringToSign) {
        return strtoupper(hash_hmac('sha256', $this->accessKey . $token . $time . $stringToSign, $this->secretKey));
    }
}

// Zpracování požadavků
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = str_replace('/tuya/api.php', '', $path);
    
    $tuya = new TuyaAPI();
    
    // Router pro různé endpointy
    switch ($path) {
        case '/status':
        case '/status/':
            // GET /tuya/api.php/status - stav zařízení
            $deviceId = $_GET['device_id'] ?? 'bf6588df88241b2942f5fq';
            $result = $tuya->getDeviceStatus($deviceId);
            
            if ($result->success === false) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $result->msg ?? 'Neznámá chyba',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => true,
                    'data' => $result->result,
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case '/properties':
        case '/properties/':
            // GET /tuya/api.php/properties - vlastnosti zařízení
            $deviceId = $_GET['device_id'] ?? 'bf6588df88241b2942f5fq';
            $result = $tuya->getDeviceProperties($deviceId);
            
            if ($result->success === false) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $result->msg ?? 'Neznámá chyba',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => true,
                    'data' => $result->result,
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            break;
            

            
        case '/history':
        case '/history/':
            // GET /tuya/api.php/history - historická data (denní spotřeba)
            $deviceId = $_GET['device_id'] ?? 'bf6588df88241b2942f5fq';
            $type = $_GET['type'] ?? 'day';
            $startTime = isset($_GET['start_time']) ? (int)$_GET['start_time'] : null;
            $endTime = isset($_GET['end_time']) ? (int)$_GET['end_time'] : null;
            
            $result = $tuya->getDeviceHistory($deviceId, $startTime, $endTime, $type);
            
            if ($result->success === false) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $result->msg ?? 'Neznámá chyba',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => true,
                    'data' => $result->result,
                    'period' => [
                        'type' => $type,
                        'start_time' => $startTime ? date('Y-m-d H:i:s', $startTime/1000) : 'dnešní den',
                        'end_time' => $endTime ? date('Y-m-d H:i:s', $endTime/1000) : 'dnešní den'
                    ],
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case '/statistics':
        case '/statistics/':
            // GET /tuya/api.php/statistics - statistiky spotřeby
            $deviceId = $_GET['device_id'] ?? 'bf6588df88241b2942f5fq';
            $type = $_GET['type'] ?? 'day';
            
            $result = $tuya->getDeviceStatistics($deviceId, $type);
            
            if ($result->success === false) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $result->msg ?? 'Neznámá chyba',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => true,
                    'data' => $result->result,
                    'statistics_type' => $type,
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case '/consumption':
        case '/consumption/':
            // GET /tuya/api.php/consumption - denní spotřeba z properties
            $deviceId = $_GET['device_id'] ?? 'bf6588df88241b2942f5fq';
            
            $result = $tuya->getDailyConsumption($deviceId);
            
            if ($result->success === false) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $result->msg ?? 'Neznámá chyba',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => true,
                    'data' => $result->result,
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case '/database':
        case '/database/':
            // GET /tuya/api.php/database - historická data z databáze
            require_once __DIR__ . '/../dbconnect.php';
            require_once __DIR__ . '/save_data.php';
            
            $deviceId = $_GET['device_id'] ?? 'bf6588df88241b2942f5fq';
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            $type = $_GET['type'] ?? 'daily';
            
            try {
                // Načteme data a aktualizujeme denní agregace
                $saver = new TuyaDataSaver($pdo, $deviceId);
                
                // Aktualizujeme denní agregace pro vybrané období
                if ($type === 'daily') {
                    $saver->updateDailyAggregationsForPeriod($startDate, $endDate);
                }
                
                $data = $saver->getHistoryData($startDate, $endDate, $type);
                
                echo json_encode([
                    'success' => true,
                    'data' => $data,
                    'filters' => [
                        'device_id' => $deviceId,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'type' => $type
                    ],
                    'count' => count($data),
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case '/info':
        case '/info/':
            // GET /tuya/api.php/info - informace o API
            echo json_encode([
                'success' => true,
                'data' => [
                    'api_name' => 'Tuya Smart Meter API',
                    'version' => '1.0.0',
                    'device_id' => 'bf6588df88241b2942f5fq',
                    'device_name' => 'Elektroměr Kypr',
                    'endpoints' => [
                        'GET /status' => 'Získání stavu zařízení',
                        'GET /properties' => 'Získání vlastností zařízení',
                        'GET /consumption' => 'Denní spotřeba z properties',
                        'GET /database' => 'Historická data z databáze',
                        'GET /history' => 'Historická data (experimentální)',
                        'GET /statistics' => 'Statistiky spotřeby',
                        'GET /info' => 'Informace o API'
                    ],
                    'history_params' => [
                        'type' => 'day|hour|month (výchozí: day)',
                        'start_time' => 'Unix timestamp v milisekundách (výchozí: začátek dnešního dne)',
                        'end_time' => 'Unix timestamp v milisekundách (výchozí: konec dnešního dne)'
                    ],
                    'database_params' => [
                        'start_date' => 'YYYY-MM-DD (výchozí: před 30 dny)',
                        'end_date' => 'YYYY-MM-DD (výchozí: dnes)',
                        'type' => 'daily|hourly (výchozí: daily)'
                    ],

                ],
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
            
        case '':
        case '/':
            // GET /tuya/api.php - základní informace
            echo json_encode([
                'success' => true,
                'message' => 'Tuya Smart Meter API je aktivní',
                'device' => 'Elektroměr Kypr (bf6588df88241b2942f5fq)',
                'endpoints' => [
                    '/status' => 'Stav zařízení',
                    '/properties' => 'Vlastnosti zařízení',
                    '/consumption' => 'Denní spotřeba z properties',
                    '/database' => 'Historická data z databáze',
                    '/history' => 'Historická data (experimentální)',
                    '/statistics' => 'Statistiky spotřeby',
                    '/info' => 'Informace o API'
                ],
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Endpoint not found',
                'available_endpoints' => [
                    '/status' => 'Stav zařízení',
                    '/properties' => 'Vlastnosti zařízení',
                    '/consumption' => 'Denní spotřeba z properties',
                    '/database' => 'Historická data z databáze',
                    '/history' => 'Historická data (experimentální)',
                    '/statistics' => 'Statistiky spotřeby',
                    '/info' => 'Informace o API'
                ],
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
