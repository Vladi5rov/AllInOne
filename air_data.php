<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // Позволява на React да чете данните локално

$dbFile = __DIR__ . '/air_quality.sqlite';
$archiveDir = __DIR__ . '/archives/';

// Създаване на директория за архиви, ако не съществува
if (!file_exists($archiveDir)) {
    mkdir($archiveDir, 0777, true);
}

// Списък с 28-те областни града в България с техните координати
$bulgarianCities = [
    'Благоевград' => ['lat' => 42.0111, 'lon' => 23.0938],
    'Бургас'       => ['lat' => 42.5048, 'lon' => 27.4626],
    'Варна'        => ['lat' => 43.2141, 'lon' => 27.9147],
    'Велико Търново' => ['lat' => 43.0757, 'lon' => 25.6172],
    'Видин'        => ['lat' => 43.9964, 'lon' => 22.8715],
    'Враца'        => ['lat' => 43.2046, 'lon' => 23.5537],
    'Габрово'      => ['lat' => 42.8742, 'lon' => 25.3225],
    'Добрич'       => ['lat' => 43.5667, 'lon' => 27.8333],
    'Кърджали'     => ['lat' => 41.6500, 'lon' => 25.3667],
    'Кюстендил'    => ['lat' => 42.2833, 'lon' => 22.6833],
    'Ловеч'        => ['lat' => 43.1333, 'lon' => 24.7167],
    'Монтана'      => ['lat' => 43.4098, 'lon' => 23.2259],
    'Пазарджик'    => ['lat' => 42.1934, 'lon' => 24.3311],
    'Перник'       => ['lat' => 42.6019, 'lon' => 23.0394],
    'Плевен'       => ['lat' => 43.4170, 'lon' => 24.6067],
    'Пловдив'      => ['lat' => 42.1354, 'lon' => 24.7453],
    'Разград'      => ['lat' => 43.5333, 'lon' => 26.5167],
    'Русе'         => ['lat' => 43.8563, 'lon' => 25.9707],
    'Силистра'     => ['lat' => 44.1147, 'lon' => 27.2608],
    'Сливен'       => ['lat' => 42.6819, 'lon' => 26.3228],
    'Смолян'       => ['lat' => 41.5774, 'lon' => 24.7011],
    'София'        => ['lat' => 42.6977, 'lon' => 23.3219],
    'Стара Загора' => ['lat' => 42.4258, 'lon' => 25.6348],
    'Търговище'    => ['lat' => 43.2500, 'lon' => 26.5833],
    'Хасково'      => ['lat' => 41.9340, 'lon' => 25.5556],
    'Шумен'        => ['lat' => 43.2712, 'lon' => 26.9360],
    'Ямбол'        => ['lat' => 42.4845, 'lon' => 26.5035]
];

try {
    // 1. Инициализация на SQLite базата данни
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS city_monthly_air (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        city TEXT,
        month TEXT, -- Формат: YYYY-MM
        avg_pm10 REAL,
        avg_pm25 REAL,
        UNIQUE(city, month)
    )");

    // 2. Проверка за актуалност (Последните 12 месеца)
    $currentDate = new DateTime();
    $requiredMonths = [];
    
    for ($i = 0; $i < 12; $i++) {
        $date = clone $currentDate;
        $date->modify("-$i month");
        $requiredMonths[] = $date->format('Y-m');
    }

    $stmt = $pdo->prepare("SELECT DISTINCT month FROM city_monthly_air WHERE month IN (" . implode(',', array_fill(0, 12, '?')) . ")");
    $stmt->execute($requiredMonths);
    $existingMonths = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $missingMonths = array_diff($requiredMonths, $existingMonths);

    // 3. Изтегляне на липсващите данни от Open-Meteo API
    if (!empty($missingMonths)) {
        foreach ($missingMonths as $month) {
            foreach ($bulgarianCities as $cityName => $coords) {
                // Извличаме реални данни за съответния месец и град
                $airData = fetchRealAirDataFromOpenMeteo($month, $coords['lat'], $coords['lon']);
                
                if ($airData) {
                    $insertStmt = $pdo->prepare("INSERT OR REPLACE INTO city_monthly_air (city, month, avg_pm10, avg_pm25) VALUES (:city, :month, :pm10, :pm25)");
                    $insertStmt->execute([
                        ':city'  => $cityName,
                        ':month' => $month,
                        ':pm10'  => $airData['pm10'],
                        ':pm25'  => $airData['pm25']
                    ]);
                }
                // Пауза за коректност към външното безплатно API
                usleep(150000); // 0.15 секунди
            }
        }
    }

    // 4. Проверка за ограничение от 36 месеца и архивиране на най-стария
    $stmtCount = $pdo->query("SELECT DISTINCT month FROM city_monthly_air ORDER BY month ASC");
    $allMonthsInDb = $stmtCount->fetchAll(PDO::FETCH_COLUMN);

    if (count($allMonthsInDb) > 36) {
        $oldestMonth = $allMonthsInDb[0]; // Нај-старият месец
        
        // Извличане на данните за този месец за архив
        $archiveStmt = $pdo->prepare("SELECT city, avg_pm10, avg_pm25 FROM city_monthly_air WHERE month = ?");
        $archiveStmt->execute([$oldestMonth]);
        $oldData = $archiveStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Запис във файл на сървъра
        file_put_contents($archiveDir . "air_quality_archive_$oldestMonth.json", json_encode($oldData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        // Изтриване от базата данни
        $deleteStmt = $pdo->prepare("DELETE FROM city_monthly_air WHERE month = ?");
        $deleteStmt->execute([$oldestMonth]);
    }

    // 5. Извличане и изчисляване на градовете с най-чист въздух по месеци
    $query = "
        SELECT city, month, avg_pm10, avg_pm25 
        FROM city_monthly_air 
        WHERE month IN (" . implode(',', array_fill(0, 12, '?')) . ")
        GROUP BY month, city
        ORDER BY month DESC, avg_pm10 ASC
    ";
    
    $resultStmt = $pdo->prepare($query);
    $resultStmt->execute($requiredMonths);
    $allRecords = $resultStmt->fetchAll(PDO::FETCH_ASSOC);

    $output = [];
    foreach ($allRecords as $record) {
        $m = $record['month'];
        if (!isset($output[$m])) {
            $output[$m] = [];
        }
        // Взимаме първите 3 най-чисти града за съответния месец
        if (count($output[$m]) < 3) {
            $output[$m][] = [
                'city' => $record['city'],
                'pm10' => (string)$record['avg_pm10'],
                'pm25' => (string)$record['avg_pm25']
            ];
        }
    }

    // Връщане на финалния JSON резултат към React
    echo json_encode([
        'status' => 'success',
        'database_status' => empty($missingMonths) ? 'up-to-date' : 'updated_now',
        'data' => $output
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * Функция за извличане на реални средномесечни данни от Open-Meteo Air Quality API
 */
function fetchRealAirDataFromOpenMeteo($month, $lat, $lon) {
    // Определяне на начална и крайна дата за въпросния месец (напр. 2026-05-01 до 2026-05-31)
    $startDate = "$month-01";
    $endDate = date("Y-m-t", strtotime($startDate));
    
    // Ако месецът е текущият и все още няма завършени дни до края, взимаме до днешна дата
    $today = date('Y-m-d');
    if ($endDate > $today) {
        $endDate = date('Y-m-d', strtotime('-1 day')); // Взимаме до вчера за сигурност
        if ($startDate > $endDate) return null;
    }

    $url = "https://air-quality-api.open-meteo.com/v1/air-quality?latitude=$lat&longitude=$lon&start_date=$startDate&end_date=$endDate&hourly=pm10,pm2_5";

    $options = [
        "http" => [
            "header" => "User-Agent: PHP-AirQuality-App/1.0\r\n",
            "timeout" => 5
        ]
    ];
    $context = stream_context_create($options);
    
    $response = @file_get_contents($url, false, $context);
    if ($response === FALSE) {
        return null;
    }

    $data = json_decode($response, true);
    if (!isset($data['hourly']['pm10']) || !isset($data['hourly']['pm2_5'])) {
        return null;
    }

    // Изчисляване на средна стойност за месеца от часовите масиви
    $pm10Values = array_filter($data['hourly']['pm10'], function($v) { return !is_null($v); });
    $pm25Values = array_filter($data['hourly']['pm2_5'], function($v) { return !is_null($v); });

    $avgPm10 = count($pm10Values) > 0 ? array_sum($pm10Values) / count($pm10Values) : 0;
    $avgPm25 = count($pm25Values) > 0 ? array_sum($pm25Values) / count($pm25Values) : 0;

    return [
        'pm10' => round($avgPm10, 1),
        'pm25' => round($avgPm25, 1)
    ];
}
?>