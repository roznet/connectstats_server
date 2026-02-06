# Storage

## Overview

The server stores FIT files and other binary assets using one of three storage backends:
1. **Amazon S3** (recommended for production)
2. **Local filesystem** (for development)
3. **MySQL database** (fallback, not recommended)

## Storage Configuration

```php
$api_config = array(
    // Option 1: Amazon S3
    'save_to_s3_bucket' => 'your-bucket-name',
    's3_access_key' => 'AKIAIOSFODNN7EXAMPLE',
    's3_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
    's3_region' => 'us-east-1',

    // Option 2: Local filesystem (uses special 'localhost:' prefix)
    // 'save_to_s3_bucket' => 'localhost:/var/www/storage',

    // Option 3: No 'save_to_s3_bucket' configured defaults to MySQL storage
);
```

## Amazon S3 Storage

### Configuration

```php
'save_to_s3_bucket' => 'connectstats-files',
's3_access_key' => 'your_access_key',
's3_secret_key' => 'your_secret_key',
's3_region' => 'us-west-2',
```

### File Organization

Files are stored with this path structure:
```
s3://bucket-name/assets/users/{cs_user_id}/{fileType}/{file_id}.{fileType}
```

### S3 Class Usage

Location: `api/S3.php`

```php
// Upload
$s3 = new S3($config['s3_access_key'], $config['s3_secret_key']);
$s3->putObject(
    $fit_file_data,
    $config['save_to_s3_bucket'],
    "assets/users/{cs_user_id}/fit/{file_id}.fit", // Example path
    S3::ACL_PRIVATE
);

// Download
$object = $s3->getObject(
    $config['save_to_s3_bucket'],
    "assets/users/{cs_user_id}/fit/{file_id}.fit" // Example path
);
$data = $object->body;
```

### Database Reference

S3 paths are tracked in `assets_s3`.
NOTE: The `assets_s3` table schema in code is identical to `assets`, including a `MEDIUMBLOB` field, which contradicts its name and the intention of storing only references.

```sql
CREATE TABLE assets_s3 (
    asset_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id BIGINT(20) UNSIGNED,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    tablename VARCHAR(128),
    filename VARCHAR(32),
    path VARCHAR(128),             -- Stores the s3 object path, e.g., 's3:assets/users/...'
    data MEDIUMBLOB,               -- (NOTE: This field's presence here contradicts the "references" purpose.)
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Local Filesystem Storage

### Configuration

Use the special `localhost:` prefix:

```php
'save_to_s3_bucket' => 'localhost:/var/www/storage',
```

### File Organization

```
/var/www/storage/
└── assets/
    └── users/
        └── {cs_user_id}/
            └── {fileType}/
                └── {file_id}.{fileType}
```

### Implementation

The server detects `localhost:` prefix and uses filesystem operations (e.g., in `GarminProcess::save_to_s3_bucket`):

```php
if (strpos($bucket, 'localhost:') === 0) {
    $path = str_replace('localhost:', '', $bucket);
    $full_path = "$path/assets/users/{cs_user_id}/fit/{file_id}.fit"; // Example

    // Ensure directory exists
    mkdir(dirname($full_path), 0755, true);

    // Write file
    file_put_contents($full_path, $data);
}
```

### Database Reference

Still tracked in `assets_s3` with local path:

```sql
INSERT INTO assets_s3 (file_id, tablename, path)
VALUES (123, 'fitfiles', 'assets/users/1/fit/123.fit'); -- Example local path in 'path' column
```

## MySQL Database Storage

### When Used

- No `save_to_s3_bucket` configured
- Fallback when S3/filesystem fails (though typically one is configured for production)

### Schema

```sql
CREATE TABLE assets (
    asset_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id BIGINT(20) UNSIGNED,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    tablename VARCHAR(128),
    filename VARCHAR(32),
    path VARCHAR(128),
    data MEDIUMBLOB,               -- Binary FIT file data (up to 16MB)
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Usage

```php
// Store (via GarminProcess::file_callback_one when no s3_bucket is configured)
// An entry is inserted/updated into the 'assets' table with the binary data.

// Retrieve
$row = $this->sql->query_first_row(
    "SELECT data FROM assets WHERE file_id = $file_id"
);
$data = $row['data'];
```

### Limitations

- **Performance**: Large BLOBs can slow down queries significantly.
- **Backups**: Increases database backup size.
- **Memory**: Loading large files into PHP memory can be inefficient.

## Storage Selection Logic (Implementation)

Location: `api/shared.php` (`GarminProcess` class)

The storage selection logic is primarily managed within the `GarminProcess::file_callback_one($table, $cbid)` method:

```php
function file_callback_one($table, $cbid) {
    // ... (retrieve file metadata and callbackURL)

    if (isset($this->api_config['save_to_s3_bucket'])) {
        $bucket = $this->api_config['save_to_s3_bucket'];
        // Download data from Garmin's callbackURL using OAuth
        $data = $this->get_url_data($url, $userAccessToken, $userAccessTokenSecret);

        if ($data) {
            // This method handles both 'localhost:' (local filesystem) and actual S3 buckets
            $this->save_to_s3_bucket($bucket, $s3_path_generated, $data);

            // Insert reference into assets_s3 table
            $this->sql->insert_or_update('assets_s3', array(
                'file_id' => $cbid,
                'tablename' => $table,
                'path' => sprintf('s3:%s', $s3_path_generated) // Stored path prefix indicates S3/local
            ));
        }
    } else {
        // If 'save_to_s3_bucket' is NOT configured, store directly in MySQL 'assets' table
        $data = $this->get_url_data($url, $userAccessToken, $userAccessTokenSecret);
        if ($data) {
            $this->sql->insert_or_update('assets', array(
                'file_id' => $cbid,
                'tablename' => $table,
                'filename' => $generated_filename,
                'data' => $data // Binary data stored here
            ));
        }
    }
    // ...
}
```

The actual `GarminProcess::save_to_s3_bucket($bucket, $path, $data)` method then handles the distinction between `localhost:` (local filesystem) and S3 for writing the file and potentially caching locally.

## Retrieval Logic

Location: `api/shared.php` (`GarminProcess` class)

Retrieval of asset data is handled by `GarminProcess::data_from_asset_row($row)`:

```php
function data_from_asset_row($row){
    $rv = NULL;
    if( isset( $row['path'] ) ){
        if( substr( $row['path'], 0, 3 ) == 's3:' ){
            // Retrieve from S3 or local filesystem if 'path' indicates an external storage
            $s3_bucket = $this->api_config['save_to_s3_bucket'];
            $s3_path = substr( $row['path'], 3, strlen( $row['path'] ) );
            $rv = $this->retrieve_from_s3_bucket( $s3_bucket, $s3_path);
        }
    }
    if( $rv == NULL && isset($row['data']) ){
        // Fallback: if 'path' is not set or S3 retrieval fails, check 'data' field (MySQL storage)
        $rv = $row['data'];
    }
    return $rv;
}
```

## FIT File Storage Flow

```
FIT file downloaded from Garmin
            │
            ▼
┌─────────────────────────┐
│ Validate FIT format     │
│ (check header bytes)    │
└─────────────────────────┘
            │
            ▼
┌─────────────────────────┐
│ Compress (optional)     │
└─────────────────────────┘
            │
            ▼
┌─────────────────────────┐
│ Determine Storage       │
│ Backend (S3/local/MySQL)│
└─────────────────────────┘
            │
            ▼
┌─────────────────────────┐
│ Store File and Update   │
│ Database Reference      │
└─────────────────────────┘
```

## Migration Between Backends

To migrate from MySQL `assets.data` to S3:

```php
// Pseudo-code for migration script (e.g., api/connectstats/migrate_assets.php)
// It iterates through assets in MySQL, uploads data to S3, and updates 'path'
// in the assets_s3 table (or assets table if using single table for both)
//
// The 'data' field in the original 'assets' table can then be cleared.
$rows = $sql->query_as_array(
    "SELECT asset_id, file_id, tablename, filename, data
     FROM assets WHERE data IS NOT NULL"
);

foreach ($rows as $row) {
    // Upload to S3/local
    $s3_path = $this->file_path_for_file_row($row_from_fitfiles); // Simplified
    $this->save_to_s3_bucket($this->api_config['save_to_s3_bucket'], $s3_path, $row['data']);

    // Update 'assets_s3' table or the 'assets' table itself with path reference
    $sql->insert_or_update('assets_s3', array(
        'file_id' => $row['file_id'],
        'tablename' => $row['tablename'],
        'path' => sprintf('s3:%s', $s3_path)
    ), array('file_id', 'tablename'));

    // Clear MySQL blob (optional)
    $sql->execute_query(
        "UPDATE assets SET data = NULL WHERE asset_id = {$row['asset_id']}"
    );
}
```

## Backup Considerations

### S3
- Use S3 versioning for protection
- Cross-region replication for disaster recovery
- Lifecycle policies for old file cleanup

### Local Filesystem
- Include storage directory in backup scripts
- Consider rsync to remote location

### MySQL
- Standard database backup includes files
- Consider separate backup for assets table due to size

## Performance Tips

1. **Use S3 for production**: Better scalability and reliability
2. **Enable compression**: FIT files compress well
3. **Parallel downloads**: Queue system allows concurrent processing
4. **CDN**: Put CloudFront in front of S3 for faster downloads
5. **Indexing**: Ensure `file_id` index on assets tables
