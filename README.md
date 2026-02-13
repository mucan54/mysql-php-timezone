# SMS Message Queue - Laravel Implementation

A Laravel-based SMS message queue system with timezone-aware scheduling. This application provides an API endpoint for fetching SMS messages ready to be sent, respecting local timezone constraints (9 AM - 11 PM sending window).

## Features

- **Timezone-Aware Scheduling**: Messages are only sent during acceptable hours (9 AM - 11 PM) based on the recipient's local timezone
- **Optimized Database Queries**: Uses MySQL's built-in `CONVERT_TZ()` for accurate timezone handling
- **Concurrent Processing Support**: Uses `SELECT FOR UPDATE SKIP LOCKED` for safe multi-worker processing
- **RESTful API**: Clean API endpoints for fetching messages and statistics
- **Artisan Commands**: CLI tools for data population and message processing

## Requirements

- PHP 8.1+
- MySQL 8.0+ (with timezone tables loaded)
- Composer

## Installation

```bash
# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Configure your database in .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sms_queue
DB_USERNAME=root
DB_PASSWORD=

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate

# (MySQL only) Ensure timezone tables are loaded
mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root mysql
```

## Configuration

The application timezone is set to `Australia/Melbourne` as required. This is configured in `config/app.php`.

## Database Structure

The `logs_sms` table uses a simple, efficient structure:

| Field | Description |
|-------|-------------|
| `send_after` | Scheduled send time (stored in server timezone) |
| `time_zone` | Recipient's timezone for local hour filtering |

### Optimized Index

```sql
INDEX IDX_sms_optimized_query (status, provider, send_after, id)
```

## Query Optimization Strategy (Step 6)

The implementation uses an efficient two-phase approach for timezone-aware message filtering:

### Phase 1: Calculate Valid Timezones in PHP

```php
$validTimezones = [];
$supported = [
    'Australia/Melbourne', 'Australia/Sydney', 'Australia/Brisbane',
    'Australia/Adelaide', 'Australia/Perth', 'Australia/Hobart',
    'Pacific/Auckland', 'Asia/Kuala_Lumpur', 'Europe/Istanbul',
];

foreach ($supported as $tz) {
    $hour = (int) now()->setTimezone($tz)->format('G');
    if ($hour >= 9 && $hour <= 22) {
        $validTimezones[] = $tz;
    }
}
```

- Loop through all 9 supported timezones (O(9) = constant time)
- Check current local hour in each using Carbon
- Build list of timezones currently within 9 AM - 10 PM window
- Handles DST correctly via Carbon's timezone database

### Phase 2: Pure Indexed Query (No CONVERT_TZ)

```php
if (empty($validTimezones)) {
    return collect(); // No timezone in window
}

$messages = LogsSms::where('status', 0)
    ->where('provider', 'inhousesms')
    ->where('send_after', '<=', now())
    ->where(function ($q) use ($validTimezones) {
        $q->whereNull('time_zone')
          ->orWhereIn('time_zone', $validTimezones);
    })
    ->orderBy('priority', 'desc')
    ->orderBy('id', 'asc')
    ->limit(5)
    ->lockForUpdate()
    ->get();
```

### Benefits

- **No per-row function calls** in MySQL
- **Full index usage** for all query conditions
- **Accurate DST handling** via Carbon's timezone database
- **Simple query** structure using whereIn

### Index Strategy

The case study provides an original index:
```sql
INDEX IDX_logs_sms(provider, status, priority, id)
```

We **keep this original index** but also recommend an optimized index:
```sql
INDEX IDX_sms_optimized_query(status, provider, send_after, id)
```

**Why?** The original index starts with `provider`, but our query benefits more from filtering `status` first since only ~50k rows have `status=0` versus 1M+ with `status=1`.

### Lock Strategy
```sql
SELECT ... FOR UPDATE SKIP LOCKED
```
- Safe concurrent processing by multiple workers
- Non-blocking behavior (SKIP LOCKED)
- Atomic fetch-and-mark operations


### Performance Characteristics
- PHP timezone calculation: O(9) constant time (negligible)
- Index filters 1M+ rows down to small subset using `whereIn`
- No per-row function calls in MySQL
- Query uses indexes for ALL conditions
- Always accurate (Carbon handles DST correctly)

### Priority and ID Ordering
```sql
ORDER BY priority DESC, id ASC
```
- Messages with higher priority are sent first
- Within same priority, older messages (lower ID) are sent first
- This respects the original schema's `priority` field


### Design Decisions

#### Hour Boundary Interpretation
The spec says "between 9am and 11pm." This is ambiguous:
- **Option A**: 9:00 to 22:59 (send during hours 9-22, before 23:00)
- **Option B**: 9:00 to 23:59 (include the 11 PM hour)

We use `HOUR >= 9 AND HOUR < 23`, meaning messages can be sent from 9:00:00 to 22:59:59 local time. This is **Option A** - we don't send during the 11 PM hour. Clarify with stakeholders if this interpretation is incorrect.

#### Update Fields
Per spec: "status and **sent** columns are set to 1 and sent_at column is set to current time."
Our update sets all three: `status=1`, `sent=1`, `sent_at=NOW()`.

## Usage

### Artisan Commands

#### Populate Test Data

```bash
# Default: 1,000,000 sent + 50,000 pending messages
php artisan sms:populate

# Custom counts
php artisan sms:populate --sent=100000 --pending=5000 --batch=10000
```

#### Get Messages to Send

```bash
# Get 5 messages (default)
php artisan sms:get-messages

# Custom limit and provider
php artisan sms:get-messages --limit=10 --provider=inhousesms

# Preview without marking as sent
php artisan sms:get-messages --dry-run
```

### API Endpoints

#### Get Messages to Send

```
GET /api/sms/messages?limit=5&provider=inhousesms
```

**Response:**
```json
{
  "success": true,
  "count": 5,
  "data": [
    {
      "id": 1,
      "phone": "0412345678",
      "message": "Your order is ready...",
      "provider": "inhousesms",
      "time_zone": "Australia/Melbourne",
      "send_after": "2024-01-15T10:30:00+11:00",
      "sent_at": "2024-01-15T11:00:00+11:00"
    }
  ]
}
```

#### Get Statistics

```
GET /api/sms/statistics
```

**Response:**
```json
{
  "success": true,
  "data": {
    "total": 1050000,
    "pending": 50000,
    "sent": 1000000,
    "delivered": 0,
    "by_provider": [...],
    "by_timezone": [...]
  }
}
```

## Testing

### Quick Start Testing Steps

**Step 1: Create Database and Configure Environment**
```bash
# Create MySQL database
mysql -u root -p -e "CREATE DATABASE sms_queue;"

# Copy environment file and update database credentials
cp .env.example .env

# Edit .env file with your database settings:
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=sms_queue
# DB_USERNAME=root
# DB_PASSWORD=your_password

# Generate application key
php artisan key:generate
```

**Step 2: Run Migrations**
```bash
php artisan migrate
```

**Step 3: Populate Random Test Data**
```bash
# Default: 1,000,000 sent + 50,000 pending messages
php artisan sms:populate

# Or with smaller dataset for quick testing
php artisan sms:populate --sent=10000 --pending=1000
```

**Step 4: Test via CLI**
```bash
# Get messages to send
php artisan sms:get-messages

# Preview without marking as sent
php artisan sms:get-messages --dry-run --limit=10
```

**Step 5: Test via API**
```bash
# Start the development server
php artisan serve

# In another terminal, test the API endpoints:
curl http://localhost:8000/api/sms/messages
curl http://localhost:8000/api/sms/statistics
```

### Run Unit Tests

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Feature/SmsServiceTest.php
php artisan test tests/Feature/SmsApiTest.php
```

## Supported Timezones

- Australia/Melbourne
- Australia/Sydney
- Australia/Brisbane
- Australia/Adelaide
- Australia/Perth
- Australia/Hobart (Tasmania)
- Pacific/Auckland
- Asia/Kuala_Lumpur
- Europe/Istanbul

### ⚠️ Important: Australia/Tasmania Timezone Issue

The original case study specifies `Australia/Tasmania` as a valid timezone. However, **this is NOT a valid timezone identifier in MySQL's timezone tables**. Using `CONVERT_TZ()` with `Australia/Tasmania` will return `NULL`, causing those rows to be silently excluded from query results.

The correct timezone for Tasmania is `Australia/Hobart`.

If you have data with `Australia/Tasmania`, you should correct it:
```sql
UPDATE logs_sms SET time_zone = 'Australia/Hobart' WHERE time_zone = 'Australia/Tasmania';
```

This implementation uses `Australia/Hobart` in all code to ensure compatibility with MySQL's `CONVERT_TZ()` function.

## Supported Providers

- inhousesms
- wholesalesms
- prowebsms
- onverify
- inhousesms-nz
- inhousesms-my
- inhousesms-au
- inhousesms-au-marketing
- inhousesms-nz-marketing

## Project Structure

```
app/
├── Console/Commands/
│   ├── PopulateRandomData.php    # Step 4: Data population
│   └── GetMessagesToSend.php     # Step 5: Message retrieval
├── Http/Controllers/Api/
│   └── SmsController.php         # API endpoints
├── Models/
│   └── LogsSms.php               # Eloquent model
└── Services/
    └── SmsService.php            # Business logic with CONVERT_TZ optimization

database/migrations/
└── 2024_01_01_000000_create_logs_sms_table.php  # Step 2: Table creation

tests/Feature/
├── SmsServiceTest.php            # Service tests
└── SmsApiTest.php                # API tests
```

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
