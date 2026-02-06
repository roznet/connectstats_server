# Weather Integration

## Overview

The server can fetch historical weather data for activities, adding context like temperature, wind speed, and conditions. Three weather API providers are supported:

1. **VisualCrossing** (recommended)
2. **OpenWeatherMap**
3. **DarkSky** (deprecated, but still supported)

## Configuration

```php
$api_config = array(
    // Option 1: VisualCrossing (recommended)
    'visualCrossingKey' => 'your_api_key',

    // Option 2: OpenWeatherMap
    'openWeatherMapKey' => 'your_api_key',

    // Option 3: DarkSky (deprecated)
    'darkSkyKey' => 'your_api_key',
);
```

## When Weather Is Fetched

Weather data is queried during FIT file extraction:

```
FIT file extracted
        │
        ▼
┌─────────────────────┐
│ Extract location    │
│ (lat/lon) and time  │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│ Query weather API   │
│ for that location   │
│ and timestamp       │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│ Store in weather    │
│ table               │
└─────────────────────┘
```

## Weather Query Flow

Location: `api/shared.php`

```php
function weather_query($file_id, $lat, $lon, $start_time) {
    $json = array();

    // Try VisualCrossing first
    if (isset($this->config['visualCrossingKey'])) {
        $json['visualCrossing'] = $this->weather_query_visualCrossing(
            $this->config['visualCrossingKey'],
            $lat, $lon, $start_time
        );
    }

    // Or try other providers...

    // Store result
    $this->sql->insert_or_update('weather', array(
        'file_id' => $file_id,
        'json' => json_encode($json)
    ), array('file_id'));
}
```

## VisualCrossing API

### Endpoint

```
https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/{lat},{lon}/{timestamp}
```

### Request

```php
function weather_query_visualCrossing($key, $lat, $lon, $timestamp) {
    $date = date('Y-m-d', $timestamp);

    $url = "https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/$lat,$lon/$date";
    $url .= "?key=$key&include=current&unitGroup=metric";

    $response = file_get_contents($url);
    return json_decode($response, true);
}
```

### Response Structure

```json
{
  "visualCrossing": {
    "latitude": 37.7749,
    "longitude": -122.4194,
    "resolvedAddress": "San Francisco, CA",
    "timezone": "America/Los_Angeles",
    "days": [{
      "datetime": "2024-01-15",
      "temp": 12.5,
      "feelslike": 10.2,
      "humidity": 75,
      "windspeed": 15.3,
      "winddir": 270,
      "conditions": "Partly Cloudy",
      "icon": "partly-cloudy-day"
    }]
  }
}
```

## OpenWeatherMap API

### Endpoint

```
https://api.openweathermap.org/data/2.5/onecall/timemachine
```

### Request

```php
function weather_query_openWeatherMap($key, $lat, $lon, $timestamp) {
    $url = "https://api.openweathermap.org/data/2.5/onecall/timemachine";
    $url .= "?lat=$lat&lon=$lon&dt=$timestamp&appid=$key&units=metric";

    $response = file_get_contents($url);
    return json_decode($response, true);
}
```

### Response Structure

```json
{
  "openWeatherMap": {
    "lat": 37.7749,
    "lon": -122.4194,
    "timezone": "America/Los_Angeles",
    "current": {
      "dt": 1705334400,
      "temp": 12.5,
      "feels_like": 10.2,
      "humidity": 75,
      "wind_speed": 4.25,
      "weather": [{
        "main": "Clouds",
        "description": "scattered clouds"
      }]
    }
  }
}
```

## DarkSky API (Deprecated)

DarkSky was acquired by Apple and is no longer accepting new signups, but existing keys still work.

### Endpoint

```
https://api.darksky.net/forecast/{key}/{lat},{lon},{timestamp}
```

### Request

```php
function weather_query_darkSky($key, $lat, $lon, $timestamp) {
    $url = "https://api.darksky.net/forecast/$key/$lat,$lon,$timestamp";
    $url .= "?units=si&exclude=minutely,hourly,daily,alerts,flags";

    $response = file_get_contents($url);
    return json_decode($response, true);
}
```

### Response Structure

```json
{
  "darkSky": {
    "latitude": 37.7749,
    "longitude": -122.4194,
    "timezone": "America/Los_Angeles",
    "currently": {
      "time": 1705334400,
      "summary": "Partly Cloudy",
      "temperature": 12.5,
      "apparentTemperature": 10.2,
      "humidity": 0.75,
      "windSpeed": 4.25,
      "windBearing": 270
    }
  }
}
```

## Database Storage

Weather data is stored in the `weather` table:

```sql
CREATE TABLE weather (
    weather_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id BIGINT(20) UNSIGNED,
    json MEDIUMTEXT,
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

The JSON contains all provider responses for flexibility:

```json
{
  "visualCrossing": { ... },
  "darkSky": { ... }
}
```

## Retrieving Weather Data

### API Endpoint

`GET /api/connectstats/json?token_id=X&activity_id=Y&table=weather`

### Query Logic

```php
function query_json($tables, $paging) {
    if (in_array('weather', $tables)) {
        $file_id = $this->get_file_id_for_activity($paging->activity_id);

        $row = $this->sql->query_first_row(
            "SELECT json FROM weather WHERE file_id = $file_id"
        );

        if ($row) {
            $result['weather'] = json_decode($row['json'], true);
        }
    }
}
```

## Location Extraction from FIT

Weather queries require lat/lon from the activity. This is extracted from FIT files:

```php
function fit_extract($file_id, $fit_data) {
    // Parse FIT file
    $fit = new phpFITFileAnalysis($fit_data);

    // Get first record with position
    foreach ($fit->records as $record) {
        if (isset($record['position_lat']) && isset($record['position_long'])) {
            $lat = $record['position_lat'];
            $lon = $record['position_long'];
            break;
        }
    }

    // Get start time
    $start_time = $fit->session['start_time'];

    // Query weather
    if ($lat && $lon && $start_time) {
        $this->weather_query($file_id, $lat, $lon, $start_time);
    }
}
```

## Error Handling

Weather API failures don't block activity processing:

```php
try {
    $weather = $this->weather_query_visualCrossing($key, $lat, $lon, $ts);
} catch (Exception $e) {
    // Log error but continue
    error_log("Weather query failed: " . $e->getMessage());
    $weather = null;
}
```

## Rate Limiting Considerations

| Provider | Free Tier Limit |
|----------|-----------------|
| VisualCrossing | 1000 calls/day |
| OpenWeatherMap | 1000 calls/day (historical) |
| DarkSky | 1000 calls/day |

To avoid hitting limits:
- Cache weather data (don't re-query)
- Batch activities in same location/time
- Consider paid tiers for high volume

## App Usage

The ConnectStats iOS app displays weather data alongside activity metrics:

- Temperature at start
- Wind speed and direction
- Weather conditions (sunny, cloudy, rain, etc.)
- Humidity

This enriches the activity view with environmental context.
