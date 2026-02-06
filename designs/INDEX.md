# ConnectStats Server

> PHP/MySQL backend integrating with Garmin Health API to provide fitness activity data to the ConnectStats iOS app.

ConnectStats Server acts as:
1. **Webhook receiver** for Garmin Health API callbacks (activities, FIT files)
2. **Data store** for user fitness activities and FIT files
3. **API server** for the ConnectStats iOS app to retrieve activity data
4. **Background processor** for async file downloads and data extraction

## Modules

### architecture
Overall system architecture, components, and data flow.
→ Full doc: architecture.md

### database-schema
Database tables, relationships, and schema versioning.
→ Full doc: database-schema.md

### api-endpoints
All API endpoints with request/response formats.
→ Full doc: api-endpoints.md

### authentication
OAuth 1.0 implementation and security model.
→ Full doc: authentication.md

### garmin-integration
Garmin Health API integration and webhooks.
→ Full doc: garmin-integration.md

### queue-system
Background job processing architecture.
→ Full doc: queue-system.md

### storage
File storage options (S3, local, MySQL).
→ Full doc: storage.md

### weather-integration
Weather API integrations.
→ Full doc: weather-integration.md

### notifications
Push notification system (APNS).
→ Full doc: notifications.md

### backfill
Historical activity retrieval from Garmin (3-month auto, 2-year max).
→ Full doc: backfill.md

### bugreport
In-app bug report submission, storage, and admin review.
→ Full doc: bugreport.md

### testing
Test infrastructure: schema validation, API smoke tests, full integration tests.
Key exports: `test.py`, `connectstats_test/`
→ Full doc: testing.md

### oauth2-migration
**Migration plan**: OAuth 1.0 → OAuth 2.0 (deadline: Dec 2026).
→ Full doc: oauth2-migration.md
