# RIDE_LOCATION_DATA_FLOW_REPORT

## Existing Schema Findings
The `ride_requests` table has the following location-related columns:
- `pickup_lat` (DECIMAL 10,8)
- `pickup_lng` (DECIMAL 11,8)
- `destination` (VARCHAR 255) - Contains the address string
- `destination_lat` (DECIMAL 10,8)
- `destination_lng` (DECIMAL 11,8)

The table *lacks* `pickup_address` and `place_id` fields for both pickup and destination.

## Actual Ride Record Example
`[id] => 6, [pickup_lat] => 6.56316893, [pickup_lng] => 3.38636950, [destination] => oshodi, lagos, [destination_lat] => 6.56816893, [destination_lng] => 3.40136950`

## Root Cause
The `ride_requests` table already stores coordinates (`lat/lng`) for both pickup and destination, but it *lacks* the human-readable `pickup_address` and Google Maps `place_id` values. The driver-side pricing engine likely relies on these missing address strings or place IDs to perform the calculation, and without them, it forces manual re-selection.

## Minimal Fix Required
1.  **Database Migration:** Add `pickup_address` (VARCHAR 255) and `destination_place_id` (VARCHAR 100) to `ride_requests`.
2.  **API Update:** Ensure `create-ride-request.php` (or equivalent rider-side API) populates these fields.
3.  **Driver API Update:** Update driver-side request retrieval to use these fields in the pricing calculation.

## Test Result
Pending implementation.
