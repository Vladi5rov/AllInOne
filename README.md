 Air Quality Bulgaria - Full-Stack Application

A web application designed to track, analyze, and cache historical air quality data (PM10 and PM2.5) for the 28 regional capital cities in Bulgaria over the past 12 months

* **Live Demo:** http://www.vladievol.co4.in/
* **GitHub Repository:** https://github.com/Vladi5rov/AllInOne/tree/main

1. Architecture & Tech Stack
	-Frontend: React.js (Single Page Application) - handles the user interface and monthly data visualization.
	-Backend: PHP 8+ (Native) - acts as a lightweight REST API server handling external API communication and caching without heavy framework overhead.
	-Storage / Database: SQLite. Chosen because it is file-based, lightweight, requires no standalone server daemon (unlike MySQL/PostgreSQL), performs 
		exceptionally fast for this dataset size, and makes the entire project self-contained and easily deployable.

2. Data Sources & Resilience
	-Source: Open-Meteo Air Quality API.
	-Rationale: Completely free, requires no API keys or registration, and provides reliable historical hourly PM10 and PM2.5 concentration data based on geographic coordinates for all 28 Bulgarian regional capitals.

	-Resilience & Caching:
		The application does not call the third-party API on every user request. Data is fetched, aggregated, and cached locally in SQLite.
		If the third-party API is slow, down, or unreachable, users receive instant responses directly from the local database.
		Smart synchronization checks for missing months and fetches only required data when a new month begins.
	
3. Performance & Measurement
	-Since historical monthly averages are pre-calculated and stored in the database, API query responses consistently execute in under 15 milliseconds.
	-Performance was measured using Browser Developer Tools (Network tab) and PHP execution profiling to ensure zero latency spikes when navigating between periods.
	
4. Observability
	Application health and data sync status are embedded directly into the API response payload:
		-The 'status' => 'success' flag confirms normal operational health.
		-The 'database_status' key returns either 'up-to-date' or 'updated_now', indicating cache state.
		-Exception handling (try-catch) intercepts potential network or database failures and formats them into structured JSON error payloads.

5. Data Retention & Archiving
	-To prevent the database from growing indefinitely over time, an automated retention policy is enforced: if the SQLite store exceeds 36 months of data, 
	the oldest month is automatically exported to a JSON archive file in the /archives/ directory and purged from the active database.

6. AI Usage Note
	 -Workflow & Assistance: AI was utilized as a collaborative tool for system architecture design (SQLite schema definition), optimizing SQL aggregation queries, and setting up 
	the Open-Meteo API integration.
	 -Corrections & Corrections: Initial API suggestions included paid/key-restricted services, which were corrected to Open-Meteo to meet free, keyless access requirements. 
	Additionally, AI code was refined to handle edge cases regarding empty hourly data arrays and to strip injected third-party HTML code from free hosting providers.

How to Run Locally (Development)

1. Backend Setup (PHP & SQLite)
	1. Place the project directory into your local web server root (e.g., `htdocs/air-app` in XAMPP).
	2. Ensure that PHP has the `pdo_sqlite` and `fileinfo` extensions enabled.
	3. Upon making the first request to `air_data.php`, the script will automatically initialize the `air_quality.sqlite` database and fetch any missing historical data.

 2. Frontend Setup (React)
	1. Open a terminal inside the `frontend` folder:
	   cd frontend
	   
	2. Install all dependencies:
		npm install
		
3. Start the development server:
	npm start
	
The app will open locally at http://localhost:3000.
