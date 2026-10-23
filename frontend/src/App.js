
import React, { useState, useEffect } from 'react';

// Преобразуване на стринг "YYYY-MM" в числови стойности за сравнение
const parseMonthYear = (ymStr) => {
  const [year, month] = ymStr.split('-').map(Number);
  return year * 12 + month;
};

// Функция за изчисляване на тегловен резултат за замърсяването (по-малкото е по-чисто)
const calculateCleanlinessScore = (pm10, pm25) => {
  return pm10 * 0.4 + pm25 * 0.6;
};

export default function App() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  // Налични месеци от PHP
  const [availableMonths, setAvailableMonths] = useState([]);
  const [startMonth, setStartMonth] = useState('');
  const [endMonth, setEndMonth] = useState('');
  const [result, setResult] = useState(null);  

  // 1. Извличане на данните
  useEffect(() => {
    fetch('/air_data.php')  // http://localhost/Evolution/air-app/air_data.php '  /air_data.php
		.then(response => response.text())
		.then(rawText => {
			const cleanText = rawText.split('<div')[0].trim();  
			console.log(cleanText);
			
			const dataT = JSON.parse(cleanText);
			console.log(dataT);

			return dataT;
		})	
		.then((json) => {
			const payload = json.data || json;
			setData(payload);
			const months = Object.keys(payload).sort();
			setAvailableMonths(months);
			//console.log(months);

			if (months.length > 0) {
			  setStartMonth(months[0]);
			  setEndMonth(months[months.length - 1]);
			}
			setLoading(false);
		})
		.catch((err) => {
			setError(err.message);
			setLoading(false);
		});
  }, []);

  // 2. При натискане на бутона
  const handleCalculate = () => {
    if (!data) return;

    const startVal = parseMonthYear(startMonth);
    const endVal = parseMonthYear(endMonth);

    if (startVal > endVal) {
      setError('Началният месец не може да бъде след крайния.');
      setResult(null);
      return;
    }

    if (endVal - startVal > 11) {
      setError('Периодът не може да надвишава 12 последователни месеца.');
      setResult(null);
      return;
    }

    setError(null);

    const selectedMonths = availableMonths.filter((m) => {
      const val = parseMonthYear(m);
      return val >= startVal && val <= endVal;
    });

    if (selectedMonths.length === 0) {
      setError('Няма данни за избрания период.');
      setResult(null);
      return;
    }

	let citySums = {};

    selectedMonths.forEach((monthKey) => {
      const citiesInMonth = data[monthKey];
	  
      citiesInMonth.forEach((cityObj) => {
        const score = calculateCleanlinessScore(cityObj.pm10, cityObj.pm25);
		
		if (!citySums.hasOwnProperty(cityObj.city)) {
		  citySums[cityObj.city] = { "val": score, "br": 1 };
		} else {	
		  citySums[cityObj.city]["val"] += score;
		  citySums[cityObj.city]["br"] += 1;
		}
	  });

    });
	
	// средно аритметично за всеки град
	Object.keys(citySums).forEach(city => {
		const item = citySums[city];
		item.val = Number((item.val / item.br).toFixed(2));
	});

	// Намиране на обекта с най-ниска стойност на val
	const lowestCity = Object.entries(citySums).reduce((min, [cityName, data]) => {
		return data.val < min.val ? { city: cityName, ...data } : min;
	}, { city: null, val: Infinity});
	
	// console.log(lowestCity);
	// console.log(citySums);	

    setResult({
      cleanestCity: {city: lowestCity.city},
      cleanestMonth: {val: lowestCity.val},
      winTimes: {br: lowestCity.br},
      period: `${startMonth} до ${endMonth}`,
    });
  };

  if (loading) ; //console.log("");	  //if (loading ) return <div style={{ padding: 20 }}>Зареждане на данни...</div>; //>= setLoading
  //if (error && !data) return <div style={{ padding: 20, color: 'red' }}>Грешка: {error}</div>;

  return (
    <div style={{ maxWidth: 600, margin: '40px auto', fontFamily: 'sans-serif', padding: 20, border: '1px solid #ccc', borderRadius: 8 }}>
      <h2>Справка за чистотата на въздуха в България</h2>

      {error && (
        <div style={{ color: 'red', marginBottom: 15, padding: 10, backgroundColor: '#ffe6e6', borderRadius: 4 }}>
          {error}
        </div>
      )}
	  
      <h4>Моля изберете начало и край на период за справката</h4>

      <div style={{ display: 'flex', gap: 15, marginBottom: 20 }}>
        <div>
          <label style={{ display: 'block', marginBottom: 5 }}>Начален месец:</label>
          <select value={startMonth} onChange={(e) => setStartMonth(e.target.value)} style={{ padding: 8 }}>
            {availableMonths.map((m) => (
              <option key={m} value={m}>{m}</option>
            ))}
          </select>
        </div>

        <div>
          <label style={{ display: 'block', marginBottom: 5 }}>Краен месец:</label>
          <select value={endMonth} onChange={(e) => setEndMonth(e.target.value)} style={{ padding: 8 }}>
            {availableMonths.map((m) => (
              <option key={m} value={m}>{m}</option>
            ))}
          </select>
        </div>
      </div>

      <button
        onClick={handleCalculate}
        style={{ padding: '10px 20px', backgroundColor: '#007bff', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer' }}
      >
        Покажи кой град е с най-чист въздух за периода
      </button>

      {result && (
        <div style={{ marginTop: 25, padding: 15, backgroundColor: '#f8f9fa', borderRadius: 6, borderLeft: '4px solid #28a745' }}>
          <h3>Резултат за периода ({result.period})</h3>
          <p><strong>Най-чист град:</strong> {result.cleanestCity.city}</p>
          <p><strong>Средна стойност за града победител:</strong> {result.cleanestMonth.val}</p>
          <p><strong>Града е бил {result.winTimes.br} пъти в челната тройка на чистите градове</strong></p>
          <small style={{ color: '#6c757d' }}>
            Контекст: Стойности под 20 µg/m³ за PM10 и под 15 µg/m³ за PM2.5 се считат за сравнително чисти според нормите на СЗО.
			          Показателя за избор се изчислява по формула на СЗО.
          </small>
        </div>
      )}
    </div>
  );
}
