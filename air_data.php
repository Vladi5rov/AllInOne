<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // Позволява на React (порт 3000) да чете данните локално

$dbFile = __DIR__ . '/air_quality.sqlite';
$apiEndpoint = "https://airbg.info"; // Заменете с реалния актуален URL/метод на ВъздухБГ

try {
    // 1. Инициализация на SQLite базата данни
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Създаване на таблица за кешираните данни по градове и месеци
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

    // Проверяваме кои от тези 12 месеца вече съществуват в базата данни
    $stmt = $pdo->prepare("SELECT DISTINCT month FROM city_monthly_air WHERE month IN (" . implode(',', array_fill(0, 12, '?')) . ")");
    $stmt->execute($requiredMonths);
    $existingMonths = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Намираме липсващите месеци
    $missingMonths = array_diff($requiredMonths, $existingMonths);

    // 3. Изтегляне на липсващите данни (на части / месец по месец)
    if (!empty($missingMonths)) {
        foreach ($missingMonths as $month) {
            // Симулиране или реално извикване на API за конкретния месец
            // Пример: $response = file_get_contents("$apiEndpoint?month=$month");
            // За целите на демонстрацията генерираме примерни данни, имитиращи отговор от API-то:
            $apiData = simulateAirBgApiData($month);

            // Записване на изтеглените данни в базата
            $insertStmt = $pdo->prepare("INSERT OR REPLACE INTO city_monthly_air (city, month, avg_pm10, avg_pm25) VALUES (:city, :month, :pm10, :pm25)");
            
            foreach ($apiData as $row) {
                $insertStmt->execute([
                    ':city'  => $row['city'],
                    ':month' => $month,
                    ':pm10'  => $row['pm10'],
                    ':pm25'  => $row['pm25']
                ]);
            }
            
            // Кратка пауза между заявките, за да не претоварим външното API
            usleep(200000); // 0.2 секунди
        }
    }

    // 4. Извличане и изчисляване на градовете с най-чист въздух по месеци
    // Считаме, че по-ниска стойност на фини прахови частици (avg_pm10) означава по-чист въздух
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

    // Групираме резултатите по месеци, за да покажем най-чистите градове за всеки месец
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
                'pm10' => $record['avg_pm10'],
                'pm25' => $record['avg_pm25']
            ];
        }
    }

    // Връщане на финалния JSON резултат към браузъра / React
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
 * Помощна функция за генериране на примерни данни от API за тестов период
 */
function simulateAirBgApiData($month) {
    $cities = ['София', 'Пловдив', 'Варна', 'Бургас', 'Русе', 'Стара Загора', 'Благоевград'];
    $data = [];
    foreach ($cities as $city) {
        // Примерни чисти градове според сезона (летните месеци са по-чисти)
        $seasonFactor = (int)substr($month, 5, 2);
        $base = ($seasonFactor >= 5 && $seasonFactor <= 8) ? 10 : 35;
        
        $data[] = [
            'city' => $city,
            'pm10' => rand($base, $base + 25),
            'pm25' => rand($base - 5, $base + 10)
        ];
    }
    return $data;
}
?>


