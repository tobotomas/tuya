
# Tuya Smart Meter API

Systém pro sledování spotřeby elektřiny pomocí Tuya elektroměru s ukládáním do databáze.

## 📁 Soubory

- `api.php` - Hlavní API endpoint
- `index.html` - Webové rozhraní pro testování
- `save_data.php` - Skript pro ukládání dat do databáze
- `README.md` - Tato dokumentace

## 🗄️ Databáze

### Migrace
Spusťte migraci pro vytvoření tabulek:
```bash
# Na serveru
cd /tobo.treq.cz/migrations
php migrate.php
```

### Tabulky
- `tuya_electricity_history` - Detailní historie měření
- `tuya_daily_consumption` - Denní agregace pro rychlé dotazy

## ⚙️ Nastavení Cron

Pro automatické ukládání dat nastavte cron:

```bash
# Každých 15 minut
*/15 * * * * /usr/bin/php /tobo.treq.cz/tuya/save_data.php

# Nebo každou hodinu
0 * * * * /usr/bin/php /tobo.treq.cz/tuya/save_data.php
```

## 🔗 API Endpointy

### Základní
- `GET /tuya/api.php` - Informace o API
- `GET /tuya/api.php/status` - Stav zařízení
- `GET /tuya/api.php/properties` - Vlastnosti zařízení
- `GET /tuya/api.php/consumption` - Aktuální spotřeba

### Historická data
- `GET /tuya/api.php/database` - Data z databáze
- `GET /tuya/api.php/database?start_date=2025-09-01&end_date=2025-09-02` - Filtrování podle data
- `GET /tuya/api.php/database?type=hourly` - Hodinová data
- `GET /tuya/api.php/history` - Tuya API historie (experimentální)

### Ovládání
- `POST /tuya/api.php/command` - Odeslání příkazu

## 📊 Příklady použití

### Denní spotřeba za posledních 7 dní
```
GET /tuya/api.php/database?start_date=2025-08-26&end_date=2025-09-02&type=daily
```

### Hodinová data za dnešní den
```
GET /tuya/api.php/database?start_date=2025-09-02&end_date=2025-09-02&type=hourly
```

### Aktuální spotřeba
```
GET /tuya/api.php/consumption
```

## 🧪 Testování

1. **Webové rozhraní**: `https://tobo.treq.cz/tuya/`
2. **Manuální test**: `https://tobo.treq.cz/tuya/save_data.php`
3. **API test**: `https://tobo.treq.cz/tuya/api.php/consumption`

## 📈 Data

### Aktuální data
- `total_forward_energy` - Celková spotřeba (kWh)
- `total_power` - Aktuální výkon (W)
- `voltage` - Napětí (V)
- `current` - Proud (A)
- `frequency` - Frekvence (Hz)

### Historická data
- Denní agregace s počtem měření
- Průměrný, maximální a minimální výkon
- Denní spotřeba (rozdíl mezi začátkem a koncem dne)

## 🔧 Konfigurace

API klíče jsou nastaveny v `api.php`:
- `accessKey` - Tuya Access ID
- `secretKey` - Tuya Secret Key  
- `projectCode` - Tuya Project Code
- `deviceId` - ID elektroměru

## 📝 Logování

Skript `save_data.php` loguje do konzole. Pro produkční použití doporučujeme přidat logování do souboru.

