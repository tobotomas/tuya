<?php
/**
 * Tuya Data Saver
 * Skript pro ukládání dat z Tuya API do databáze
 * 
 * Použití v crontab:
 * každých 15 minut: /usr/bin/php /cesta/k/projektu/tuya/save_data.php
 * každou hodinu: 0 * * * * /usr/bin/php /cesta/k/projektu/tuya/save_data.php
 */

// Nastavení časové zóny
date_default_timezone_set('Europe/Prague');

// Načtení konfigurace a databáze
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../dbconnect.php';

// Načtení Tuya API třídy
require_once __DIR__ . '/api.php';

class TuyaDataSaver {
    private $pdo;
    private $tuya;
    private $deviceId;
    
    public function __construct($pdo, $deviceId = 'bf6588df88241b2942f5fq') {
        $this->pdo = $pdo;
        $this->deviceId = $deviceId;
        
        try {
            $this->tuya = new TuyaAPI();
        } catch (Exception $e) {
            $this->log("Chyba při inicializaci Tuya API: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Uložení aktuálních dat do databáze
     */
    public function saveCurrentData() {
        try {
            // Získání dat z API
            $result = $this->tuya->getDeviceProperties($this->deviceId);
            
            if ($result->success === false) {
                throw new Exception("API vrátilo chybu: " . ($result->msg ?? 'Neznámá chyba'));
            }
            
            // Parsování dat
            $data = $this->parsePropertiesData($result->result);
            
            // Uložení do databáze
            $this->insertHistoryRecord($data);
            
            // Aktualizace denní agregace
            $this->updateDailyAggregation($data);
            
            $this->log("Data úspěšně uložena pro zařízení {$this->deviceId}");
            return true;
            
        } catch (Exception $e) {
            $this->log("Chyba při ukládání dat: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Parsování dat z properties
     */
    private function parsePropertiesData($properties) {
        $data = [
            'device_id' => $this->deviceId,
            'timestamp' => date('Y-m-d H:i:s'),
            'date' => date('Y-m-d'),
            'total_forward_energy' => null,
            'total_power' => null,
            'voltage' => null,
            'current' => null,
            'frequency' => null,
            'power_factor' => null,
            'is_online' => 1,
            'raw_data' => json_encode($properties)
        ];
        
        if (isset($properties->properties)) {
            foreach ($properties->properties as $property) {
                switch ($property->code) {
                    case 'total_forward_energy':
                        $data['total_forward_energy'] = (float)($property->value ?? 0) / 100; // Wh -> kWh (dělení 100)
                        break;
                    case 'total_power':
                        $data['total_power'] = (float)($property->value ?? 0) / 1000; // W -> kW
                        break;
                    case 'voltage':
                        $data['voltage'] = (float)($property->value ?? 0);
                        break;
                    case 'current':
                        $data['current'] = (float)($property->value ?? 0);
                        break;
                    case 'frequency':
                        $data['frequency'] = (float)($property->value ?? 0);
                        break;
                    case 'power_factor':
                        $data['power_factor'] = (float)($property->value ?? 0);
                        break;
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Vložení záznamu do historie
     */
    private function insertHistoryRecord($data) {
        $sql = "INSERT INTO tuya_electricity_history 
                (device_id, timestamp, date, total_forward_energy, total_power, 
                 voltage, current, frequency, power_factor, is_online, raw_data)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $data['device_id'],
            $data['timestamp'],
            $data['date'],
            $data['total_forward_energy'],
            $data['total_power'],
            $data['voltage'],
            $data['current'],
            $data['frequency'],
            $data['power_factor'],
            $data['is_online'],
            $data['raw_data']
        ]);
    }
    
    /**
     * Aktualizace denní agregace
     */
    private function updateDailyAggregation($data) {
        $date = $data['date'];
        
        // Získání aktuálních denních statistik - chronologicky první a poslední
        $sql = "SELECT 
                    (SELECT total_forward_energy FROM tuya_electricity_history 
                     WHERE device_id = ? AND date = ? ORDER BY timestamp ASC LIMIT 1) as start_energy,
                    (SELECT total_forward_energy FROM tuya_electricity_history 
                     WHERE device_id = ? AND date = ? ORDER BY timestamp DESC LIMIT 1) as end_energy,
                    AVG(total_power) as avg_power,
                    MAX(total_power) as max_power,
                    MIN(total_power) as min_power,
                    COUNT(*) as measurements_count
                FROM tuya_electricity_history 
                WHERE device_id = ? AND date = ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->deviceId, $date, $this->deviceId, $date, $this->deviceId, $date]);
        $stats = $stmt->fetch();
        
        if ($stats) {
            $dailyConsumption = null;
            if ($stats['start_energy'] !== null && $stats['end_energy'] !== null) {
                $dailyConsumption = $stats['end_energy'] - $stats['start_energy']; // Už v kWh
            }
            
            // Upsert denní agregace
            $sql = "INSERT INTO tuya_daily_consumption 
                    (device_id, date, start_energy, end_energy, daily_consumption, 
                     avg_power, max_power, min_power, measurements_count)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    start_energy = VALUES(start_energy),
                    end_energy = VALUES(end_energy),
                    daily_consumption = VALUES(daily_consumption),
                    avg_power = VALUES(avg_power),
                    max_power = VALUES(max_power),
                    min_power = VALUES(min_power),
                    measurements_count = VALUES(measurements_count),
                    updated_at = CURRENT_TIMESTAMP";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $this->deviceId,
                $date,
                $stats['start_energy'],
                $stats['end_energy'],
                $dailyConsumption,
                $stats['avg_power'],
                $stats['max_power'],
                $stats['min_power'],
                $stats['measurements_count']
            ]);
        }
    }
    
    /**
     * Aktualizace denních agregací pro dané období
     */
    public function updateDailyAggregationsForPeriod($startDate, $endDate) {
        // Získáme všechny dny v období
        $currentDate = new DateTime($startDate);
        $endDateTime = new DateTime($endDate);
        
        while ($currentDate <= $endDateTime) {
            $date = $currentDate->format('Y-m-d');
            
            // Získáme data pro tento den
            $sql = "SELECT * FROM tuya_electricity_history 
                    WHERE device_id = ? AND date = ? 
                    ORDER BY timestamp ASC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->deviceId, $date]);
            $dayData = $stmt->fetchAll();
            
            if (!empty($dayData)) {
                // Aktualizujeme denní agregaci
                $this->updateDailyAggregationForDate($date);
            }
            
            $currentDate->add(new DateInterval('P1D'));
        }
    }
    
    /**
     * Aktualizace denní agregace pro konkrétní datum
     */
    private function updateDailyAggregationForDate($date) {
        // Získání aktuálních denních statistik - chronologicky první a poslední
        $sql = "SELECT 
                    (SELECT total_forward_energy FROM tuya_electricity_history 
                     WHERE device_id = ? AND date = ? ORDER BY timestamp ASC LIMIT 1) as start_energy,
                    (SELECT total_forward_energy FROM tuya_electricity_history 
                     WHERE device_id = ? AND date = ? ORDER BY timestamp DESC LIMIT 1) as end_energy,
                    AVG(total_power) as avg_power,
                    MAX(total_power) as max_power,
                    MIN(total_power) as min_power,
                    COUNT(*) as measurements_count
                FROM tuya_electricity_history 
                WHERE device_id = ? AND date = ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->deviceId, $date, $this->deviceId, $date, $this->deviceId, $date]);
        $stats = $stmt->fetch();
        
        if ($stats) {
            $dailyConsumption = null;
            if ($stats['start_energy'] !== null && $stats['end_energy'] !== null) {
                $dailyConsumption = $stats['end_energy'] - $stats['start_energy']; // Už v kWh
            }
            
            // Upsert denní agregace
            $sql = "INSERT INTO tuya_daily_consumption 
                    (device_id, date, start_energy, end_energy, daily_consumption, 
                     avg_power, max_power, min_power, measurements_count)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    start_energy = VALUES(start_energy),
                    end_energy = VALUES(end_energy),
                    daily_consumption = VALUES(daily_consumption),
                    avg_power = VALUES(avg_power),
                    max_power = VALUES(max_power),
                    min_power = VALUES(min_power),
                    measurements_count = VALUES(measurements_count),
                    updated_at = CURRENT_TIMESTAMP";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $this->deviceId, $date, $stats['start_energy'], $stats['end_energy'], 
                $dailyConsumption, $stats['avg_power'], $stats['max_power'], 
                $stats['min_power'], $stats['measurements_count']
            ]);
        }
    }
    
    /**
     * Získání historických dat s filtrováním
     */
    public function getHistoryData($startDate = null, $endDate = null, $type = 'daily') {
        if ($startDate === null) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if ($endDate === null) {
            $endDate = date('Y-m-d');
        }
        
        if ($type === 'daily') {
            $sql = "SELECT * FROM tuya_daily_consumption 
                    WHERE device_id = ? AND date BETWEEN ? AND ?
                    ORDER BY date DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->deviceId, $startDate, $endDate]);
        } elseif ($type === 'hourly') {
            // Hodinová agregace dat
            $sql = "SELECT 
                        DATE(timestamp) as date,
                        HOUR(timestamp) as hour,
                        MIN(total_forward_energy) as start_energy,
                        MAX(total_forward_energy) as end_energy,
                        MAX(total_forward_energy) - MIN(total_forward_energy) as hourly_consumption,
                        AVG(total_power) as avg_power,
                        MAX(total_power) as max_power,
                        MIN(total_power) as min_power,
                        COUNT(*) as measurements_count
                    FROM tuya_electricity_history 
                    WHERE device_id = ? AND DATE(timestamp) BETWEEN ? AND ?
                    GROUP BY DATE(timestamp), HOUR(timestamp)
                    ORDER BY date DESC, hour DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->deviceId, $startDate, $endDate]);
        } else {
            $sql = "SELECT * FROM tuya_electricity_history 
                    WHERE device_id = ? AND date BETWEEN ? AND ?
                    ORDER BY timestamp DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->deviceId, $startDate, $endDate]);
        }
        
        return $stmt->fetchAll();
    }
    
    /**
     * Logování
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] {$message}\n";
        
        // Můžete také logovat do souboru
        // file_put_contents(__DIR__ . '/tuya_data.log', "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}

// Spuštění skriptu pouze při přímém volání (ne při includování)
if (basename($_SERVER['PHP_SELF']) === 'save_data.php') {
    if (php_sapi_name() === 'cli') {
        // Spuštění z příkazové řádky (cron)
        try {
            $saver = new TuyaDataSaver($pdo);
            $success = $saver->saveCurrentData();
            
            if ($success) {
                echo "OK\n";
                exit(0);
            } else {
                echo "ERROR\n";
                exit(1);
            }
        } catch (Exception $e) {
            echo "FATAL ERROR: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        // Spuštění z webu (test) - pouze logování, žádný JSON výstup
        try {
            $saver = new TuyaDataSaver($pdo);
            $success = $saver->saveCurrentData();
            
            if ($success) {
                echo "Data úspěšně uložena\n";
            } else {
                echo "Chyba při ukládání dat\n";
            }
            
        } catch (Exception $e) {
            echo "Chyba: " . $e->getMessage() . "\n";
        }
    }
}
?>
