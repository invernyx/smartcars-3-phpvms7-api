<h1 align="center">smartCARS phpVMS 7 API</h1>
<div align="center">
    <i>Web Script Files for smartCARS</i>
</div>

## Introduction
smartCARS is a web-based flight tracking system for virtual airlines. This repository contains the web script files for smartCARS in phpVMS 7.

## Requirements
- phpVMS 7.0.0 or Later (version 2.0.0 was tested on phpVMS 7.0.11; version 2.0.1 was reviewed against the phpVMS 7.0.11 and 7.0.13 sources and version 2.0.2 against the phpVMS 7.0.11 sources, neither yet tested on a running site)
- PHP 8.1 or higher
- A webserver that accepts the Authorization header (Apache, nginx, etc.)
    - If you are using a shared hosting provider, you may need to contact them to enable this feature.

## Installation
The smartCARS phpVMS 7 API is a drag and drop module. It does not require any configuration. We support phpVMS 7 in this repository.

For phpVMS 5 support, please go to the [phpVMS 5 repository](https://github.com/invernyx/smartcars-3-phpvms5-api) instead.

### Step 1
Download the latest release from the [releases page](https://github.com/invernyx/smartcars-3-phpvms7-api/releases).

### Step 2
Move this folder into your `modules` folder, and verify that the folder is named `SmartCARS3phpVMS7Api`.

### Step 3
Using the Admin Interface, go to Modules and Enable the module.

### Step 4
Verify that the installation was successful by visiting the base URL in your browser. You should see a JSON response with the version number of the API and the name of your handler.

Assuming you have placed phpVMS 7 in your `public_html`:
`https://yourdomainhere.com/api/smartcars/`

This URL will be the "Script URL" option in smartCARS Central when managing your community.

## Limitations

* v7 Flight Type codes are simplified to v5 flight type codes in the UI. This will not affect flight logging.
* The following phpVMS settings are ignored
  * Bids
    * Disable Flight On Bid
  * PIREPs
    * Restrict the flights to company

## Changelog

### 2.0.2

Version 2.0.2 adds free flights: a pilot can fly and file a flight with no schedule and no booking, which the VA reviews as any other PIREP. Free flights are off until the VA switches them on. A smartCARS client that does not use them needs no change: apart from the two new fields of `data/aircraft`, the placeholder aircraft left out of it and the placeholder airport left out of `data/airports`, every request and answer stays as it was. Reviewed against the phpVMS 7.0.11 sources.

**Upgrading**

- Replace the module folder, then run the module's migration (Admin > Modules, or `php artisan module:migrate SmartCARS3phpVMS7Api --force`). It adds the `smartcars.free_flights` setting, off by default.

**Added**

- Free flights: `POST /flights/free/start` starts a flight from a flight number, a departure and, optionally, an arrival, an aircraft, a route and a cruise level, and answers the PIREP's tracking ID. `flights/complete` files it like any other flight and also accepts the arrival and the flight number flown. See "Free flights".
- The setting **Free flights** (`smartcars.free_flights`, off by default). See "Settings".
- The capability `free-flights`, listed while the setting is on. See "Capabilities".
- The free-flight placeholders, created the first time a free flight is started: the airport `XXXX` (`Unknown / off-field`), for a departure or arrival that is not an airport, and the aircraft `SC-FREE` (subfleet `SCFREE`), for a flight flown in an aircraft that is not in the VA's fleet. See "Free flights".
- The refusal codes `free_flights_disabled`, `unknown_departure_airport`, `unknown_arrival_airport` and `start_in_progress`. See "Error codes".
- `data/aircraft` sends each aircraft's `airport` (where phpVMS has it) and `state` (`parked`, `in_use` or `in_air`), and leaves out the placeholder aircraft. See "Aircraft list".
- `data/airports` leaves out the placeholder airport `XXXX`, so smartCARS never offers it as an airport to pick. See "Free flights".

### 2.0.1

Version 2.0.1 fixes security and reliability issues found in 2.0.0. A smartCARS client needs no change: apart from the new refusals below, every request and answer stays as it was. Replace the module folder; there is no migration to run.

**Security**

- Every authenticated route checks the pilot's state, not only `pilot/login` and `pilot/resume`: a pilot who becomes pending, rejected or suspended after signing in is refused on the next request with the same answers as sign-in (`pilot_pending`, `pilot_rejected`, `pilot_suspended`). See "Sign-in".
- Signing in with an email and an API key compares the key strictly: the password must be exactly the pilot's API key, and a pilot without an API key cannot sign in this way.
- Failed sign-ins are limited to 10 a minute per pilot account. Past that, `pilot/login` answers `429` with the code `too_many_attempts` until the minute is over, even with the right password; resuming a saved session (`pilot/resume`) is not affected. The limit uses the site's cache, so it needs a persistent cache driver. See "Sign-in".
- `flights/book`, `flights/bookings` and `flights/unbook` always act for the signed-in pilot, taken from the session, never from a pilot id in the request.
- The admin pages `/admin/smartcars/import` and `/admin/smartcars/recalc` no longer start their job when opened: they show what the job does and a button that queues it, so a link or an embedded image can no longer start a job in an admin's browser. See "Legacy flight data import" and "Pirep Distance Recalculation".
- The module no longer writes whole `flights/charter` and `flights/start` requests to the site's log.

**Reliability**

- `flights/complete` files a flight in one database transaction, with the PIREP locked: two calls for the same flight cannot file it twice, and a filing that fails part-way is rolled back entirely, so a retry starts clean. A deadlock inside one of the optional steps (filed loads, fields, route, aircraft change) rolls back and retries the whole filing, up to 3 attempts, instead of carrying on.
- For admins: phpVMS's PIREP-filed listeners (notifications, for example) now run inside that transaction. With phpVMS's default `sync` queue, a listener that keeps failing (a Discord webhook that cannot be reached, for example) now stops the filing, and a notification may be sent again when a failed filing is retried. With a separate queue worker (redis, sqs), a queued notification could be processed before the filing is committed, so a worker can send a notification for a filing that is then rolled back, and send it again when the filing is retried. `flights/cancel` also runs in a transaction now, and phpVMS's PIREP status-change notification runs inside it: with the `sync` queue, a cancel fails while the Discord webhook cannot be reached.
- `flights/cancel` locks the PIREP too, so a cancel that arrives while the flight is being filed waits for the filing to end and then answers for the filed flight: a discard sent after a `flights/complete` that timed out on the client answers `409` `flight_already_filed` instead of cancelling or deleting the filed PIREP.
- The legacy flight data import (`/admin/smartcars/import`) imports one row at a time and removes each row from `smartCARS3_FlightData` once its PIREP holds the data (after the import is committed, so a stop in between leaves the row for the next run, which finds nothing left to copy). Each row's import locks its PIREP, so two overlapping runs do not duplicate anything, and a run that stops part-way continues where it stopped. A row whose PIREP does not exist, or that cannot be read or imported, stays in the table (a failure is logged), and the table is dropped only once it is empty. Copies made by an earlier interrupted 2.0.0 run stay as they are. See "Legacy flight data import".
- The distance recalculation (`/admin/smartcars/recalc`) only fills in smartCARS 3 PIREPs that have no distance, in batches; every other PIREP keeps its stored distance. A PIREP whose distance cannot be computed is skipped with a log line. A progress message that cannot be posted to the Discord webhook is logged and the recalculation carries on. See "Pirep Distance Recalculation".

### 2.0.0 (changes since 1.0.3)

Version 2.0.0 extends the 1.0.3 API. Every addition is optional for a client: one that sends and reads only the 1.0.3 fields keeps working, apart from the changes listed under "Changed behavior" and "Removed". Tested on phpVMS 7.0.11.

**Upgrading**

- Replace the module folder, then run the module's migration (Admin > Modules, or `php artisan module:migrate SmartCARS3phpVMS7Api --force`). It adds the `smartcars` settings group, the `sc_flight_analyses` table (with its four verification columns, `landing_verified`, `takeoff_verified`, `signed_landing_analysis` and `signed_takeoff_analysis`) and the `Network` and `Simulator` PIREP fields (unless the VA already has fields with those slugs). Every new setting defaults to the 1.0.3 behavior.
- A site that installed 2.0.0 before verified analyses were added runs the module's migration again: it adds four columns to `sc_flight_analyses`, and the rows stored before read as not verified, with no signed part. See "Flight analyses".
- Version 2.0.0 is the first one that can update itself; this install is a manual copy. See "Auto-update" to switch the updater on.

**Added**

- VA settings: airline code shown (IATA or ICAO), aircraft name format, and whether a cancelled flight is kept as cancelled or deleted. See "Settings".
- `GET /` lists the backend's optional features as `capabilities`, and each route behind one names it in an `X-SmartCARS-Capability` header. See "Capabilities".
- Richer filed flights: score, cruise level, block fuel, zero fuel weight, distance flown, block off and on times, passengers and cargo (as the PIREP's fares), a changed aircraft, network and simulator. Position reports fill IAS, vertical speed, height above ground, distance and flight time, squawk, autopilot, fuel flow, fuel on board and sim time, and a paused flight shows as paused (`PSD`). Every ACARS row the module writes carries a short source. See "Optional flight data".
- The SimBrief loads phpVMS rolled for the flight become the PIREP's fares at start, and the planned route is saved at filing so the PIREP page draws it.
- Live flight log: `POST /flights/events` stores the log lines while the flight is in progress; a line sent again is ignored, and filing does not store it twice. See "Flight events".
- Flight analyses: `POST /pireps/analyses` and `POST /pireps/analyses/search` store and look up the landing analysis, flight score, takeoff analysis, trails and debrief. See "Flight analyses".
- Verified flight analyses: the module checks the signature of the signed parts smartCARS sends with an upload, stores the signed analysis in place of the copy sent beside it and records which analyses are verified. The capability `verified-analyses` announces it, and `pireps/analyses/search` gives back `signedLandingAnalysis` and `signedTakeoffAnalysis`. See "Flight analyses".
- Diversions: `POST /flights/divert` records a diversion airport while the VA has phpVMS's diversion handling enabled.
- Arrival search: `POST /flights/search/arrivals` searches flights by arrival airport.
- Flight phases: `flights/update` also accepts smartCARS 4's generic phase names (`PRE_FLIGHT`, `TAXI_OUT`, `TAXI_IN`, `COMPLETE`), announced with the capability `flight-phases`. See "Capabilities".
- Flight destinations: `GET /flights/destinations?departure=<ICAO>` lists the arrival airports the VA's visible flights serve from an airport, with the number of flights to each (capability `flight-destinations`). While the `pilots.only_flights_from_current` setting is on, it answers `[]` for any airport other than the pilot's current one.
- Routes for a search: `GET /flights/destinations?routes=1` answers every departure and arrival airport pair of a `flights/search`-style search, with the same filters (capability `destination-routes`). Without `routes`, the answer is the one above. See "Routes for a search".
- Alternates: flights and bookings carry `alternateAirport`, stored on the PIREP at start. See "Alternate airport".
- Stable search order and paging with totals on `flights/search` and `flights/search/arrivals`. See "Flight search order and pages".
- `ETag` and `304 Not Modified` on `data/airports` and `data/aircraft`. See "Unchanged lists".
- Error codes: a refusal answers a stable `code` next to its `message`. See "Error codes".
- The `flights/complete` answer carries the PIREP's state after filing (`accepted`, `pending` or `rejected`). See "Filing status".
- phpVMS custom fields are sent as `extensions` on flights, bookings and the pilot. See "Extensions".
- Aircraft list: a per-pilot `restriction` (`rank` or `type_rating`) and the real passenger and cargo capacities. See "Aircraft list".
- `pireps/details` answers the flight log as lines (`flightLog`), and `data/news` names the author (`postedBy`).
- `pireps/search` and `pireps/latest` answer the PIREP's `score`, so a client that no longer holds the flight's own score can still show it. See "Pilot details and news".
- Auto-update, off by default: the smartCARS backend nudges the site when a release of the module is published and once a day, signed with a key verified against the public keys bundled in the module, and the updater installs the latest published release with a health check and rollback. Nothing to configure besides the switch. See "Auto-update".

**Changed behavior**

- Every distance sent and received is in nautical miles, whatever the VA's display unit (1.0.3 sent flight distances in the VA's display unit and PIREP distances in statute miles). See "Units".
- Sign-in refuses pending (`409`), rejected and suspended (`403`) pilots. An unknown user now answers `401` with the same body as a wrong password. See "Sign-in".
- `pilot/statistics` counts accepted PIREPs only.
- `rankLevel` is the pilot rank's real 0-based level. `rankLevel` and the aircraft's `minimumRank` are deprecated and still sent.
- Clients whose `smartCARS/<major>` User-Agent declares a new enough version get phpVMS's own flight type letters, and their PIREPs are filed under a versioned source name. Other clients keep the 1.0.3 values.
- The booking refusals on `flights/book` and `flights/charter` answer `409` with phpVMS's reason; a missing flight or booking answers `404`.

**Removed**

- `data/subfleets` and `data/flight_types`.

**Fixed**

- Every request that names a booking, a flight in progress or a PIREP only acts on the signed-in pilot's own records.
- Requests that crashed with a server error (a missing booking, flight or aircraft; a missing `comments` at filing) now answer `404`, `409` or `400`.
- A request without a session (`Authorization` header, or `session` at `pilot/resume` and `pilot/verify`) no longer signs in as a pilot who has no API key.
- `pilot/verify` answers an unknown session, and a pilot who may not fly, with `401` and an empty body: the smartCARS servers read any JSON body as a valid session.
- `pireps/search` and `pireps/latest` list only filed PIREPs (pending, accepted or rejected), not flights in progress, paused, cancelled, deleted or drafts.
- `flights/search` filters by `callsign`, and the searches and `flights/destinations` list only active flights. A flight with no route sends `route: []`, and the distance filters keep their decimals.
- A booking's aircraft is its own (set by `flights/book` or `flights/rebook`), else that of the pilot's own SimBrief briefing, at `flights/bookings` and `flights/start`; another pilot's briefing is never used, and a booking without an aircraft lists only the aircraft the pilot may fly.
- `flights/book` refuses an `aircraftID` that does not exist with `400`, and passes the aircraft to phpVMS so its "block aircraft" setting applies.
- `flights/charter` refuses an aircraft that does not exist (`422`, `invalid_request`) and an airport phpVMS cannot find or add (`422`, `unknown_airport`) instead of failing with a server error.
- `flights/complete` refuses a `flightData` that is not a base64 JSON array with `422` instead of failing with a server error.
- `flights/update` for a flight that is no longer in progress or paused answers `200` and changes nothing.
- `data/aircraft` sends each aircraft's `registration`.

## Settings

The module adds a `smartcars` group to the phpVMS admin settings (run the module's migration after an update). Each setting defaults to the module's original behavior; a missing setting behaves like its default.

* **Airline code** (`smartcars.airline_code`): the airline code smartCARS shows for bookings and schedules. `iata` (default): the IATA code, or the ICAO code when the airline has none. `icao`: the ICAO code. PIREP lists always use the ICAO code.
* **Aircraft name** (`smartcars.aircraft_name`): `detailed` (default): `name (registration) | status`. `plain`: the aircraft name only.
* **Cancelled flights** (`smartcars.cancel_mode`): `cancel` (default): the PIREP stays, marked cancelled. `delete`: the unfiled PIREP is deleted with its ACARS positions, logs and flight analyses, and a SimBrief OFP stays attached to the booked flight so the pilot can fly it again.
* **Free flights** (`smartcars.free_flights`): `false` (default): `flights/free/start` refuses every start with `free_flights_disabled`. `true`: pilots can fly free flights, flights with no schedule or booking, filed as PIREPs for review. See "Free flights".

## phpVMS Socialite SSO Login (Discord/VATSIM)

If a user creates their account on phpVMS 7 via Discord or VATSIM SSO, they do not have a typical email/password combination to properly login via smartCARS.

For these accounts, the user is to use a `email/api key` (where the user will enter their email as the username, and their api key as the password) combination. The API key for the logged in user can be accessed by viewing their profile on phpVMS 7.

## Charter Flights

Unlike phpVMS 5, Charter Flights behave differently in phpVMS 7.

For the callsign field, if just a number is supplied, your flight will be assigned automatically to the first airline in your system, or a airline ID you define in your .env file by using the `SC3_CHARTER_AIRLINE_ID` variable (e.g. `SC3_CHARTER_AIRLINE_ID=1` for airline id 1).

If you supply a ICAO or IATA code with the flight number (e.g. `DAL1421` or `DL1421`, the API will search to see if that code exists in the system. If it finds it, the flight will be flown under that code and flight number. If it cannot find it, it'll fallback to the first airline in your system or what's set in the env variable.

Only numeric flight numbers are supported. For example, `BAW47C` will turn into `BAW0` when you see the bid.

If your community has the Bids > Restrict Aircraft setting enabled, the aircraft selected will be attached to the bid. However, if the setting is not enabled, the subfleet for the same aircraft family will be attached to the flight for manual selection before beginning the flight.

## Free flights

A free flight is a flight with no schedule and no booking: the pilot flies where they like, and smartCARS files it as a PIREP the VA reviews like any other. Charter flights are unchanged. Free flights are off until the **Free flights** setting (`smartcars.free_flights`) is switched on; while it is on, `GET /` lists the capability `free-flights` and smartCARS offers them.

### Starting

`POST /flights/free/start` takes `{ flightNumber, departure, arrival, aircraftID, route, cruise }`:

- `flightNumber` (required): the PIREP's flight number, 1 to 10 characters after trimming (phpVMS's column holds 10).
- `departure` (required): the departure airport's ICAO code, case-insensitive; `XXXX` for the unknown airport.
- `arrival` (optional): the arrival airport's ICAO code; absent, null or empty for the unknown airport. It can be set at filing instead.
- `aircraftID` (optional): an aircraft ID from `data/aircraft`; absent or null for the placeholder aircraft.
- `route` (optional): the planned route as a list of waypoints, stored as the PIREP's route.
- `cruise` (optional): the planned cruise level in feet, stored as the PIREP's flight level when above 0.

`aircraftID` and `cruise` may be integers or strings of digits. The answer is `{ "trackingID": "<PIREP ID>" }`, the ID smartCARS then sends to `flights/update`, `flights/events`, `flights/complete` and `flights/cancel`.

The PIREP is created in progress with no flight attached, under the airline named by `SC3_CHARTER_AIRLINE_ID` in your .env file (else the first airline, as for charters), with the charter passenger flight type. A start repeated for the same pilot, airline, flight number and airports within phpVMS's duplicate check time ("PIREP duplicate time check", `pireps.duplicate_check_time`) gets the free flight PIREP it already created while that PIREP is in progress or paused (a PIREP of a scheduled flight is never reused); the starts of one pilot run one after another, so concurrent repeats get the same PIREP; a start after such a PIREP was filed creates a new one. The fares are rolled from the aircraft's subfleet fares with the VA's default load factors; a fare failure is logged and does not refuse the start.

An airport the VA does not have is added through phpVMS's airport lookup only while phpVMS's "Allow unadded airports" setting (`general.allow_unadded_airports`) is on, and only for an ident of at most 8 characters (phpVMS's airport ID column). With "Automatic airport lookup" (`general.auto_airport_lookup`) on, an ident the lookup does not know is refused; with it off, phpVMS saves the airport with the ident as its name, at 0, 0. Otherwise the start is refused with `unknown_departure_airport` or `unknown_arrival_airport` (`422`), and smartCARS can start again with `XXXX`.

A fleet aircraft goes through phpVMS's own checks, as on `flights/start`: the pilot must be at the departure airport while "Flights from Current" (`pilots.only_flights_from_current`) is on, the aircraft must be parked, at the departure airport while "Restrict Aircraft At Departure" (`pireps.only_aircraft_at_dpt_airport`) is on, allowed to the pilot while "Restrict Aircraft to Ranks" (`pireps.restrict_aircraft_to_rank`) is on (phpVMS then also applies "Restrict Aircraft by Type Ratings", `pireps.restrict_aircraft_to_typerating`), and not held by another pilot's SimBrief briefing while SimBrief's "Restrict Aircraft" (`simbrief.block_aircraft`) is on. While the bids' "Restrict Aircraft" (`bids.block_aircraft`) is on, an aircraft another pilot has booked is refused too. A start that follows a filed PIREP (see the duplicate check above) is not handed to phpVMS's prefile, which would hand back the filed PIREP: the module runs these checks itself (the rank and type rating rules whenever either setting is on), and also refuses an aircraft on another flight in progress with `aircraft_not_available`. An `aircraftID` that does not exist (an aircraft deleted since smartCARS read the fleet) is refused with `aircraft_not_available`, so smartCARS can start again with the placeholder aircraft. The placeholder aircraft is not subject to the aircraft rules; only the pilot location rule ("Flights from Current") applies to it.

Answers: `200` with the tracking ID; `401` for an invalid session; `409` with `pilot_not_at_airport`, `aircraft_not_available`, `aircraft_not_at_airport` or `aircraft_not_allowed` (another phpVMS refusal carries its message only); `422` with `free_flights_disabled`, `invalid_request` (a missing or malformed field), `unknown_departure_airport` or `unknown_arrival_airport`; `503` with `start_in_progress` when another start of the same pilot held its lock past the database lock wait timeout (nothing was created; send the start again).

### Filing

`flights/complete` files a free flight like any other flight. For a PIREP with no flight attached (a free flight) it also accepts:

- `arrival` (optional): the arrival airport flown to, replacing the one set at start, added as at start when the VA does not have it. One that cannot be added refuses the filing with `422` `unknown_arrival_airport` and nothing is saved, so smartCARS can file again with `XXXX`. Absent, null or empty keeps the PIREP's arrival; one of only spaces files to `XXXX`.
- `flightNumber` (optional): replaces the PIREP's flight number when it is 1 to 10 characters after trimming. Any other value is ignored with a line in the site's log, and the filing goes on.

### Placeholders

The first free flight start creates the placeholders, and every start restores one that was deleted:

- the airport `XXXX`, named `Unknown / off-field`, at 0, 0: the departure or arrival of a flight that did not start or end at an airport;
- the aircraft `SC-FREE`, named `Unknown aircraft` (ICAO `ZZZZ`), in the subfleet `SCFREE` (`smartCARS free flights`) of the first airline: the aircraft of a flight flown in an aircraft that is not in the VA's fleet. An admin may move it into another subfleet; if that subfleet is deleted, it goes back to `SCFREE`.

They stay when free flights are switched off, since PIREPs refer to them. `data/aircraft` never lists the placeholder aircraft, and `data/airports` never lists the placeholder airport.

## Pirep Distance Recalculation

Included with this API is a job that'll calculate the distance of older smartCARS PIREPs that have none, based on the ACARS telemetry logs on the server.

To execute this, navigate to `/admin/smartcars/recalc` in your web browser while signed in as an admin (e.g. `https://myva.com/admin/smartcars/recalc`). The page describes the job; press "Queue the job" to start it. Opening the page alone does nothing.

The job computes the distance from the positions only for the PIREPs filed under the module's default source name (`smartCARS 3`) whose distance is empty or 0, a batch at a time. Every other PIREP keeps its stored distance, including one filed with `distanceFlown` (see "Optional flight data" below) and every PIREP a client filed under a versioned source name. A PIREP whose distance cannot be computed is skipped with a line in the site's log, and the job carries on with the next one.

If you have a private discord notification channel setup, you will receive progress updates regarding the status of the job as it executes on the backend. A message that cannot be posted (the webhook cannot be reached or is rate limited) is logged and the job carries on.

## Legacy flight data import

Sites upgraded from the old smartCARS 3 module may still have its flight logs and flight paths in the `smartCARS3_FlightData` table. A job copies them into their PIREPs, as PIREP comments and ACARS flight path points.

To execute this, navigate to `/admin/smartcars/import` in your web browser while signed in as an admin (e.g. `https://myva.com/admin/smartcars/import`). The page describes the job; press "Queue the job" to start it. Opening the page alone does nothing.

The job imports one row at a time and deletes the row from `smartCARS3_FlightData` once its PIREP holds the data. Each row's import locks its PIREP, so two overlapping runs do not duplicate anything, and a run that stops part-way (phpVMS's cron stops a queued job after a while) continues where it stopped when queued again. A row whose PIREP does not exist stays in the table. A row that cannot be read or imported stays in the table too, with a line in the site's log (`smartCARS: legacy flight data for PIREP <id> could not be imported: ...`), and the job moves on to the next row. At the end, the table is dropped if it is empty; otherwise the log says how many rows are left.

A comment or a flight path point the PIREP already holds is not added again. Copies made by an earlier interrupted 2.0.0 run stay as they are: the job does not remove them.

## Optional flight data

A client may send optional fields on `flights/update` and `flights/complete` so the flight fills phpVMS's own fields (live map, PIREP page, admin views, finances) like a native ACARS client. Each field is used only when it is sent and plausible; a missing, null or implausible value keeps the behavior described for it. A client that sends none of them sees no change.

`flights/update`:

- `ias` (kt, > 0): the position's indicated airspeed (`acars.ias`). Without it the position has no IAS.
- `verticalSpeed` (fpm, any finite number, negative when descending): the position's vertical speed (`acars.vs`).
- `altitudeAgl` (ft, >= 0): the position's height above ground (`acars.altitude_agl`), with `altitude` as `altitude_msl`. Without it both take `altitude`.
- `distanceFlown` (nm, > 0): the position's distance (`acars.distance`) and the PIREP's distance (`pireps.distance`), stored in nautical miles, phpVMS's internal unit, whatever the VA's display unit. Without it the position's distance is the planned distance minus `distanceRemaining` and the PIREP's distance is left alone.
- `flightTime` (decimal hours, > 0): the PIREP's flight time (`pireps.flight_time`, stored in minutes). Without it the flight time is the time since the first position.
- `paused` (boolean): `true` sets the PIREP status to paused (`PSD`); the PIREP stays in progress, so it stays on the live map and can still be filed. phpVMS's hourly expiry crons skip paused flights, so a flight abandoned while paused is not deleted automatically (the pilot or an admin cancels it). `false` or missing maps `phase` as before.
- `transponder` (text, exactly 4 octal digits, e.g. `7000`): the position's squawk (`acars.transponder`, an integer column, so `0200` is stored as `200`).
- `autopilot` (boolean): the position's autopilot state (`acars.autopilot`, a text column: `1` engaged, `0` not).
- `fuelFlow` (lbs/h, >= 0): the total fuel flow of all engines (`acars.fuel_flow`), stored in lbs per hour, phpVMS's internal fuel unit, whatever the VA's display unit, rounded to 2 decimals.
- `fuel` (lbs, >= 0): the fuel on board (`acars.fuel`), stored in lbs, phpVMS's internal fuel unit, whatever the VA's display unit, rounded to 2 decimals. A negative, non-finite or out-of-range value is ignored.
- `simTime` (`{ zulu, local }`, each `YYYY-MM-DDTHH:MM:SS` without offset): `zulu` is the position's sim time (`acars.sim_time`, stored as `YYYY-MM-DD HH:MM:SS`, the form phpVMS's own ACARS API stores). A value not in that form, or not a real date, is ignored. phpVMS's position API (`GET /api/pireps/{id}/acars/position`) orders the positions by `sim_time`: there a position without a valid sim time sorts first, and a change of the sim clock during the flight reorders its points. The live map and the PIREP page order the positions by arrival time. `local` is accepted and not stored.
- `mach`, `headingTrue`, `headingMagnetic` (degrees), `pitch` (degrees, positive nose up) and `bank` (degrees): accepted and not stored, phpVMS has no column for them. `heading` stays the stored heading.

`flights/complete`:

- `score` (0 to 100): `pireps.score`, rounded. phpVMS's PIREP page shows the score and landing rate only when both are non-zero.
- `cruiseLevel` (ft, > 0): `pireps.level`, rounded.
- `blockFuel` (lbs, > 0): `pireps.block_fuel`. Without it the block fuel comes from the "Pushing back with N lbs/kgs of fuel" log line, as before.
- `zfw` (lbs, > 0): `pireps.zfw`.
- `distanceFlown` (nm, > 0): `pireps.distance`. Without it the distance is computed from the positions.
- `blockTime` (decimal hours, > 0): accepted and not stored. phpVMS's `pireps` table has no block time column (its `Pirep` model names one that no migration creates); the block off and on times below carry it.
- `blockOffTime` and `blockOnTime` (ISO 8601 UTC, e.g. `2026-11-06T14:05:00Z`): `pireps.block_off_time` and `pireps.block_on_time`. Without them the server times recorded by the updates stay (phpVMS uses the filing time for a missing block on time). The filed times are ignored, and the stored ones kept, when they would put the off-block time after the on-block time, including when only one is sent and the other is a stored server time.
- `passengers` (integer, >= 0) and `cargo` (lbs, >= 0): the loads flown. They replace the PIREP's fares: the passengers are split over the aircraft's passenger fares and the cargo over its cargo fares, in proportion to each fare's capacity (phpVMS's subfleet and flight fare overrides), as whole counts that add up to the total. Cargo is converted to kg when the VA's weight unit is kg, as phpVMS's own SimBrief form counts cargo fares in that unit. A kind that is not sent, or that the aircraft has no fare for, keeps the fares set at start.
- `aircraft` (aircraft ID): when it differs from the PIREP's aircraft, the PIREP takes it only if it exists, its subfleet is one the pilot may fly (phpVMS's rank and type rating restrictions), it is parked, no other flight in progress uses it, and (with phpVMS's SimBrief or bid aircraft blocking enabled) no other pilot holds it. Otherwise the PIREP keeps its aircraft and the reason is logged. Fares are not regenerated for the new aircraft.
- `network` and `simVersion` (text): stored as the PIREP's `Network` and `Simulator` field values (source ACARS), shown on the PIREP page. The module's migration creates the two PIREP field definitions unless the VA already has fields with those slugs.

Every position and log row the module writes (`flights/update`, `flights/events`, the log lines filed by `flights/complete`) carries a source (`acars.source`): `SC` followed by the major version from the client's `smartCARS/<major>` User-Agent, or the default major version without one. phpVMS's column holds 5 characters, too few for the PIREP's source name (`pireps.source_name`).

At filing the module also saves the planned route rows from the PIREP's route (phpVMS's `saveRoute`, as its own ACARS file endpoint does) when the PIREP has none, so the route is drawn on the PIREP page.

At start, when the pilot generated a SimBrief briefing in phpVMS for the flight with the started aircraft, the module saves the loads phpVMS rolled for that briefing (`simbrief.fare_data`) as the PIREP's fares. Otherwise it rolls the loads from the load factors, as before.

## Flight events

A client may send the flight log lines while the flight is in progress with `POST /flights/events`, so they show in the flight log on the PIREP page and in the admin before the flight is filed. The body is `{ uuid, bidID, events: [ { index, type, message, timestamp } ] }`:

- `uuid`: the PIREP ID of the ongoing flight (`bidID` is accepted and not used)
- `index` (integer, >= 0): the line's 0-based position in the flight log, the same number the line has as `eventId` in the `flightData` filed with `flights/complete`
- `type`: `info`, `event` or `comment`; checked, not stored
- `message` (text, may be empty): the line, stored cut to 255 characters (phpVMS's `acars.log` column). phpVMS's request middleware trims it and turns an empty or blank one into null, which is stored as null (no log text)
- `timestamp`: the line's UTC time, the `flightData` item's `eventTimestamp` (e.g. `2026-11-06 14:05:00z`), parsed as at filing

Each line may also carry these optional fields. A missing or malformed one is ignored and never refuses the request; the line is stored without it:

- `simTime` (`{ zulu, local }`, each `YYYY-MM-DDTHH:MM:SS` without offset): `zulu` is the row's sim time (`acars.sim_time`, stored as `YYYY-MM-DD HH:MM:SS`), checked as on `flights/update`. `local` is accepted and not stored.
- `position` (`{ latitude, longitude, altitude }`, degrees and feet MSL): where the aircraft was when the line was written, stored as the row's `lat`, `lon` and `altitude_msl` when all three are finite numbers and latitude and longitude are in range. Otherwise the row keeps phpVMS's default position (0, 0, 0). The row's `altitude_agl` is not filled.
- `timeIntoFlight` (minutes since the flight started) and `variables` (an object of tracking values keyed by variable name): accepted and not stored, phpVMS has no column for them.

Each line becomes a phpVMS ACARS row of type LOG for the PIREP, with the timestamp as its `created_at`. The row ID is derived from the PIREP ID and `index`, so the request is idempotent: a line sent again is ignored and the row stored first stays. At `flights/complete`, a `flightData` item whose `eventId` matches a stored line is not stored again; every other item is stored as before, so a client that never calls `flights/events` sees no change. The raw `flightData` copy in `sc_pirep_logs` is kept in full either way.

A request carries at most 100 lines. Answers: 200 when stored, 401 for an invalid session, 404 when the pilot has no such flight, 409 when the flight is no longer in progress or paused (filed or cancelled), and 422 when `events` is not an array, is empty or has more than 100 entries, or an entry lacks an integer `index` >= 0, a valid `type`, a `message` (text, empty allowed) or a parseable `timestamp`. A batch is checked as a whole: one invalid entry stores nothing. Expect a client to retry on any other status (a 500, for example): it sends the same lines again later.

## Flight analyses

smartCARS can upload the analyses of a finished flight (landing analysis, flight score, takeoff analysis, approach and takeoff trails, debrief). The module stores them in the `sc_flight_analyses` table, one row per PIREP, and answers `POST /pireps/analyses` (upload) and `POST /pireps/analyses/search` (lookup by PIREP IDs). Cancelling a flight deletes its row when the cancelled flights setting is `delete`.

Columns:

- `pirep_id`: the PIREP the row belongs to (unique)
- `user_id`: the pilot who uploaded it
- `format_version`: version of the payload format: 1 from smartCARS 3, 2 and 3 from clients with a `smartCARS/` User-Agent of major version 4 or later (3 adds the signed parts)
- `touchdown_rate_fpm`: touchdown rate in feet per minute, for reports and queries
- `landing_grade`: `excellent`, `good`, `fair` or `poor`
- `flight_score`: blended flight score out of 100
- `landing_analysis`, `flight_score_detail`, `takeoff_analysis`, `debrief`, `approach_trail`, `takeoff_trail`: the full smartCARS payloads as JSON text, each can be null. They are stored exactly as sent: the module reads them from the raw request body, so phpVMS's `TrimStrings` / `ConvertEmptyStringsToNull` middleware does not alter them and `{}` stays an object
- `landing_verified`, `takeoff_verified`: 1 when the row's landing (takeoff) analysis came out of a signed part that passed every check, else 0 (the default, and what a row stored before the migration reads)
- `signed_landing_analysis`, `signed_takeoff_analysis`: the signed part as JSON text `{"payload": ..., "signature": ...}` with the two strings exactly as sent, or null. A part being present does not mean it verified: see the flags above

### Verified analyses

An upload can carry `signedLandingAnalysis` and `signedTakeoffAnalysis` next to the unsigned analyses, each `{ "payload": "<JSON text>", "signature": "<base64>" }` or `null`. The module does not look at `formatVersion` to decide: it checks the signed parts of any upload that carries them. smartCARS's backend computes the analysis, then signs `smartcars-analysis-v1.` followed by the payload text, with RSASSA-PKCS1-v1_5 and SHA-256 (the same algorithm as the auto-update nudge). The module checks each part that was sent, in this order, and stops at the first check that fails:

1. The part is an object whose `payload` and `signature` are non-empty strings.
2. The signature is valid for `smartcars-analysis-v1.` plus the payload text exactly as received (UTF-8), against the public keys in `Support/NudgeKeys.php`. The payload is not parsed and re-serialized before this check, and nothing in it is read before it passes.
3. The payload is a JSON object.
4. Its `version` is the integer 1.
5. Its `kind` is `landing` for `signedLandingAnalysis`, `takeoff` for `signedTakeoffAnalysis`.
6. Its `trackingId` is the PIREP ID of the upload.
7. When its `pilot` is an object of kind `airline`, `pilot.id` is the integer id of the pilot who uploaded. A `pilot` of any other kind (a TFDi account) is not compared.
8. Its `analysis` is an object.

smartCARS never signs a payload larger than 64 KiB; the module does not enforce a limit.

The smartCARS 4 implementing guide in the public smartCARS documentation (section "Verifying an analysis") describes the same checks for other backends, with a test vector.

Nothing is ever refused because of a signed part: a part that is missing, malformed or fails a check does not fail the upload, which answers `200` as before. The analysis sent beside it is stored as sent and the flag stays 0. A missing part (`null`) has no reason and logs nothing; for a part that was sent and is malformed or fails a check, the reason (`envelope`, `signature`, `payload`, `version`, `kind`, `tracking_id`, `pilot`, `empty` or `error`) goes to the Laravel log as a warning that names the kind, the PIREP ID and the reason only; for `error` it also names the class of the exception that was caught, never its message. A verified analysis that cannot be stored as JSON (a number too large to hold) is treated the same way: `error`, the flag stays 0 and the analysis sent beside it is kept.

Every part that is a well-formed `{ payload, signature }` is stored in its `signed_*` column exactly as sent, whether or not it verified, including one whose signature check failed. Only a part that is missing or is not a well-formed envelope (reason `envelope`) is stored as `NULL`. So a signed part being present does not mean it verified: only `landing_verified` / `takeoff_verified` say that, and the search answer does not carry those flags.

When a signed part verifies, `landing_analysis` (`takeoff_analysis`) holds the payload's `analysis` instead of the copy sent beside it, which is neither compared with it nor stored, and `touchdown_rate_fpm` and `landing_grade` are read from it. A touchdown rate, landing grade or flight score that does not fit its column is stored as `NULL` instead of failing the upload. An upload replaces the PIREP's whole row, so a later upload without a valid signed part clears the flag.

What a verified analysis proves:

- smartCARS's servers computed this analysis, for this kind and this PIREP, from the samples a client sent.
- It was not changed on its way to this module. The check happens once, when the analysis is uploaded; it does not cover later edits to the database.

What it does not prove:

- That the flight data was real: a modified client can send invented samples, and smartCARS signs the analysis it computes from them.
- That it is the only genuine analysis of the flight. More than one can exist for the same PIREP, in these separate ways, and the module stores the one uploaded last:
    - An analysis computed under another smartCARS community id for the same tracking id verifies here too. The module cannot reject it because it does not know its own smartCARS community id, so it does not compare `communityId`.
    - An analysis computed under a TFDi account verifies whichever account asked for it: smartCARS computes one for any account that sends this tracking id, and the module cannot map a TFDi account to one of the VA's pilots. So a pilot can upload one computed under their TFDi account, under their airline-only session, or under someone else's TFDi account. Only the pilot who owns the PIREP can upload to it. An analysis computed under an airline-only session is always `basic` and names a `pilot` of kind `airline`; the module checks that pilot's id. That id is the one the backend of the community named in `communityId` answered, so without the community id check it ties the analysis to this VA's pilot only when the analysis was computed for this VA.
    - smartCARS answers a repeated request with the analysis it already holds, but it does not hold every analysis for ever, so the same flight can be computed again later. The payload's `computedAt` is the signed time of the computation, which can be compared with the PIREP's own times.
- Who the pilot is, when the `pilot` in the payload is a TFDi account: that id cannot be mapped to one of the VA's pilots, so it is not compared.
- Anything about `flight_score`, the trails and the debrief: the app produces them and they are never verified.

`level` (`full` with smartCARS Pro, `basic` otherwise: the same scores and touchdown facts without the coaching moments and the report) is inside the payload and not a column; it says how detailed the analysis is, not how far to trust it.

For reports, filter on `landing_verified = 1` (or `takeoff_verified = 1`).

## Capabilities

`GET /` also answers `capabilities`, the optional features this backend supports (today `flight-analyses`, `verified-analyses` (both on the two analyses routes; smartCARS does not read it today), `flight-events`, `arrival-search`, `flight-destinations`, `destination-routes` (`flights/destinations` also answers the routes of a search, see "Routes for a search"), `flight-phases` (`flights/update` accepts smartCARS 4's generic phase names `PRE_FLIGHT`, `TAXI_OUT`, `TAXI_IN` and `COMPLETE`, mapped like `BOARDING`, `TAXI`, `TAXI_TO_GATE` and `DEBOARDING`, next to smartCARS 3's names), `iata-flight-types` (clients sending a `smartCARS/<major>` User-Agent with major 4 or later get the phpVMS flight type letter as stored in the flight `type`; other clients get P or C), while the `pireps.handle_diversion` setting is enabled `divert`, while the `smartcars.free_flights` setting is enabled `free-flights` (`flights/free/start`, see "Free flights"), and while auto-update is enabled `auto-update`). Each endpoint behind one also names it in the `X-SmartCARS-Capability` response header; `flight-phases` and `iata-flight-types` have no endpoint of their own and are named in that header on `GET /`. Both come from the `scCapability` default on the route, so a new capability is declared once, on its route.

## Unchanged lists

`GET /data/airports` and `GET /data/aircraft` answer with an `ETag` header, the SHA-1 of the exact JSON body. A client may send it back as `If-None-Match`; an unchanged list then answers `304` with no body and the same `ETag`. The lists are still read from the database on every request, so the saving is the transfer and the client's own processing. `OPTIONS` answers are unchanged.

## Alternate airport

Every flight of `GET /flights/bookings`, `GET /flights/search` and `POST /flights/search/arrivals` carries `alternateAirport`, the alternate airport published on the flight, or `null` when it has none. `POST /flights/start` stores it as the alternate of the PIREP it creates.

## Error codes

A refusal answers `{ "message": "...", "code": "..." }`. `message` is the reason, readable by the pilot, and keeps the same text as before. `code` is a stable code for the reason, so a client can say what happened and what to do next. It is optional: a reason the module has no code for (an unexpected phpVMS refusal, a server error) carries the message only.

| Code                        | When                                                                                                                                                       | Routes in this module                                                                                                                                        |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `aircraft_not_available`    | the aircraft is in use by another flight or not available (on `flights/free/start`, also an aircraft that does not exist or that another pilot has booked) | `flights/start`, `flights/free/start`                                                                                                                        |
| `aircraft_not_at_airport`   | the aircraft is parked elsewhere                                                                                                                           | `flights/start`, `flights/free/start`                                                                                                                        |
| `aircraft_not_allowed`      | the pilot's rank or type ratings do not allow the aircraft                                                                                                 | `flights/start`, `flights/free/start`                                                                                                                        |
| `pilot_not_at_airport`      | the pilot is not at the departure airport                                                                                                                  | `flights/start`, `flights/free/start`                                                                                                                        |
| `free_flights_disabled`     | the VA does not accept free flights                                                                                                                        | `flights/free/start`                                                                                                                                         |
| `unknown_departure_airport` | the departure is not in the VA's data and phpVMS cannot add it                                                                                             | `flights/free/start`                                                                                                                                         |
| `unknown_arrival_airport`   | the arrival is not in the VA's data and phpVMS cannot add it                                                                                               | `flights/free/start`, `flights/complete` (free flights)                                                                                                      |
| `start_in_progress`         | another free flight start of the same pilot held its lock past the database lock wait timeout                                                              | `flights/free/start`                                                                                                                                         |
| `no_aircraft`               | the booking has no aircraft and the VA needs one                                                                                                           | `flights/start`                                                                                                                                              |
| `flight_already_booked`     | the flight is already booked (by this pilot, or exclusively by someone else)                                                                               | `flights/book`                                                                                                                                               |
| `aircraft_already_booked`   | another booking holds the aircraft                                                                                                                         | `flights/book`, `flights/charter`                                                                                                                            |
| `booking_limit`             | the pilot has reached the VA's booking limit                                                                                                               | `flights/book`, `flights/charter`                                                                                                                            |
| `booking_not_found`         | the booking does not exist (any more)                                                                                                                      | `flights/start`, `flights/rebook`, `flights/unbook`                                                                                                          |
| `no_active_flight`          | the pilot has no such flight                                                                                                                               | `flights/update`, `flights/events`, `flights/divert`, `flights/complete`                                                                                     |
| `flight_not_in_progress`    | the flight is already filed, cancelled or otherwise closed                                                                                                 | `flights/events`, `flights/divert`, `flights/complete`                                                                                                       |
| `flight_already_filed`      | the flight was filed already                                                                                                                               | `flights/cancel`                                                                                                                                             |
| `diversion_disabled`        | the VA does not accept diversions                                                                                                                          | `flights/divert`                                                                                                                                             |
| `unknown_airport`           | an airport the request names is not in the VA's data                                                                                                       | `flights/divert`, `flights/charter`                                                                                                                          |
| `invalid_request`           | the request is malformed, or names a flight or an aircraft that does not exist                                                                             | `flights/book`, `flights/rebook`, `flights/charter`, `flights/free/start`, `flights/events`, `flights/complete`, `flights/search`, `flights/search/arrivals` |
| `pilot_pending`             | the pilot account is waiting for approval (`409`)                                                                                                          | `pilot/login`, `pilot/resume`, every authenticated route                                                                                                     |
| `pilot_rejected`            | the pilot account was rejected (`403`)                                                                                                                     | `pilot/login`, `pilot/resume`, every authenticated route                                                                                                     |
| `pilot_suspended`           | the pilot account is suspended, deleted or in any other state that is not active (`403`)                                                                   | `pilot/login`, `pilot/resume`, every authenticated route (a deleted pilot: sign-in only)                                                                     |
| `too_many_attempts`         | too many failed sign-ins for the account in the last minute (`429`)                                                                                        | `pilot/login`                                                                                                                                                |

The routes column says where this module sends each code; it is not a restriction. Any route may send any code, and a backend may send codes outside this list, so a client falls back to the message for a code it does not know.

The booking refusals phpVMS raises on `flights/book` and `flights/charter` (the flight or the aircraft is already booked, the booking limit) answer `409` with phpVMS's reason as `message`, instead of phpVMS's own error body; a refused `flights/charter` deletes the flight it created for the charter. `flights/book` with a flight that does not exist, and `flights/start` and `flights/unbook` with a booking that does not exist or belongs to another pilot, answer `404`.

## Sign-in

`pilot/login` and `pilot/resume` check the pilot's state: an active or on-leave pilot signs in; a pending one is refused with `409` and `pilot_pending`, a rejected one with `403` and `pilot_rejected`, and a suspended or deleted one, or one in any other state, with `403` and `pilot_suspended`. An unknown user and a wrong password at `pilot/login` both answer `401` with the same body, an unknown session at `pilot/resume` answers `401`.

Every authenticated route (every route that takes the session in the `Authorization` header) checks the pilot's state on each request with the same answers, so a pilot who becomes pending, rejected or suspended after signing in is refused from the next request on. A pilot deleted after signing in gets `401` `Invalid Token` on an authenticated route instead, since the session lookup does not find a deleted pilot; only `pilot/login` and `pilot/resume` answer `pilot_suspended` for one.

`pilot/login` counts failed attempts per pilot account: after 10 failed attempts within a minute, it answers `429` with `{ "message": "Too many sign-in attempts, try again in a minute", "code": "too_many_attempts" }` until the minute is over, even with the right password. A successful sign-in clears the count, and an unknown user is answered `401` as above without counting. `pilot/resume` and `pilot/verify` are not limited, so a pilot with a saved session keeps signing in while the limit applies. The count is kept in the site's cache, so the limit needs a persistent cache driver (file, redis, database or memcached; with `array` it does nothing). Under many parallel requests the count is approximate, and a few more than 10 attempts can get through in one minute. Since the count is per account, anyone who knows a pilot's username can block that pilot's sign-in with a password or API key at `pilot/login` for a minute with 10 wrong attempts; resuming a saved session keeps working.

`pilot/verify`, called by the smartCARS servers, which read any JSON body as a valid session, answers an unknown session and a pilot who may not fly with `401` and an empty body. `rankLevel` is the 0-based position of the pilot's rank among the ranks ordered by hours; it is deprecated and still sent, since each aircraft's `restriction` says which aircraft the pilot may fly.

`pilot/statistics` counts accepted PIREPs only (`flightsFlown`, `pirepsFiled`, `hoursFlown`, `averageLandingRate`); `averageLandingRate` is 0 when there are none.

## Units

Every distance the module sends is in nautical miles (`nmi`), whatever the VA's distance display unit: the `distance` of the flights of `flights/bookings`, `flights/search` and `flights/search/arrivals`, and the `distance` of the PIREPs of `pireps/search` and `pireps/latest`. The PIREP routes send the distance flown, or the planned distance when the flown one is null or 0. A distance a client sends is in nautical miles too (see "Optional flight data").

## Aircraft list

`GET /data/aircraft` describes each aircraft for the signed-in pilot:

- `restriction`: why the pilot may not fly the aircraft, following phpVMS's `Restrict aircraft to rank` and `Restrict aircraft to type rating` settings: `"rank"` (the pilot's rank does not allow the aircraft's subfleet), `"type_rating"` (a type rating is missing), or `null` when the pilot may fly it. A client treats any other value, or none, as allowed.
- `registration`: the aircraft's registration, or `null` when it has none.
- `maximumPassengers` and `maximumCargo`: the sums of the subfleet's passenger and cargo fare capacities (0 without fares).
- `minimumRank`: the lowest rank level allowed on the subfleet, or `null` when no rank is set on it. Deprecated and still sent: `restriction` says whether the pilot may fly the aircraft.
- `serviceCeiling`: always `null`, phpVMS has no such value.
- `airport`: the ID of the airport where the aircraft is (phpVMS's aircraft location), or `null` when phpVMS has none.
- `state`: phpVMS's aircraft state, `parked`, `in_use` or `in_air`. Only a parked aircraft can be started; a state phpVMS may add later is sent as `in_use`.

The free-flight placeholder aircraft (`SC-FREE`, see "Free flights") is never listed.

## Pilot details and news

`pireps/details` answers `flightLog`, an array of lines `<eventTimestamp> - <message>`, and `flightData`, both from the flight log stored at filing when present, otherwise from the PIREP's comments. `data/news` answers `postedBy`, the author's private name (first name plus last initial), or `null` when the author is unknown.

Every PIREP of `pireps/search` and `pireps/latest` carries `score`, the score stored on the PIREP at filing (a whole number from 1 to 100), or `null` when phpVMS holds none. phpVMS stores 0 for a PIREP filed without a score, so 0 is sent as `null`.

## Other changes

* `flights/rebook` with an `aircraftID` that does not exist answers `400` with `invalid_request`; an `aircraftID` of `null` is accepted.
* `flights/complete` no longer fails when `comments` is missing.
* `flights/update` with an empty, missing or unknown `phase` keeps the PIREP's current status (1.0.3 cleared it). smartCARS sends an empty phase before the first one is known.
* Removed: `data/subfleets` and `data/flight_types`.

## Filing status

The `flights/complete` answer carries `status` next to `pirepID`: the PIREP's state once phpVMS has processed the filing, `accepted`, `pending` or `rejected`. A VA's own rules (a module listening to phpVMS's `PirepFiled` event) and the rank's auto-approve setting both act before the answer, so a PIREP accepted or rejected at filing says so right away. `status` is absent when the PIREP is in any other state. The answer for a PIREP filed already (a retry) carries its current state the same way.

## Extensions

Every flight of `GET /flights/search` and `POST /flights/search/arrivals`, every booking of `GET /flights/bookings` (the booked flight's values), and the pilot of `pilot/login`, `pilot/resume` and `pilot/verify` may carry `extensions`, an object of the VA's own data that the client keeps and passes on (to its plugins, for example) without using it.

This module sends phpVMS's custom fields there, as `{ "fields": { "<field name>": "<value>" } }`, keyed by the field's name as the VA shows it, each value as phpVMS stores it (text):

- flights: the flight's field values;
- the pilot: the pilot's user field values, private fields included (they are the pilot's own data, sent only to the pilot), with inactive and internal fields (which can hold a module's data) left out.

A field without a value is left out, and `extensions` is absent when nothing is left. An `extensions` object may hold at most 16 KiB of JSON text, so the module leaves it out when it would be larger. Airports and aircraft have no custom fields in phpVMS, so they carry no `extensions` from this module.

This module is the default implementation and an example: a VA that customizes phpVMS or runs its own backend can send any other extensions, with any keys, on airports (`data/airports`), aircraft (`data/aircraft`), flights, bookings and the pilot, as long as `extensions` is a JSON object of at most 16 KiB. For example, an aircraft's cabin layout:

```json
"extensions": { "cabin": { "first": 8, "business": 42, "premiumEconomy": 24, "economy": 180 } }
```

or the terminals and gates an airport uses:

```json
"extensions": { "terminals": ["1", "2"], "gates": { "1": ["A1", "A2", "A3"], "2": ["B1", "B2"] } }
```

## Flight search order and pages

`GET /flights/search` and `POST /flights/search/arrivals` return their flights in a stable order: by route distance, shortest first, then departure airport, then arrival airport, then flight ID. The route distance is the great-circle distance between the two airports' coordinates, the same for every flight of a route (not the flight's own `distance` field), so a route's flights are always adjacent. A flight whose airport has no coordinates (unset, or 0/0) comes last.

A page holds at most `limit` flights (250 at most, and 250 when `limit` is missing or below 1). The answer carries three headers:

- `X-SmartCARS-Total-Flights`: every flight matching the search, across all pages
- `X-SmartCARS-Total-Routes`: the number of distinct departure and arrival airport pairs among them
- `X-SmartCARS-Next-Cursor`: present when more results follow, absent on the last page

To get the next page, repeat the request unchanged with the cursor added as `cursor` (a query parameter on `flights/search`, a body field on `flights/search/arrivals`). The cursor is opaque and never expires: it holds the position of the last flight sent, so a flight added or hidden between pages is neither skipped nor repeated among the remaining results. A change to an airport's coordinates between two page requests can move that route's flights. A malformed cursor answers `400` with `{"message": "Invalid cursor", "code": "invalid_request"}`. The totals use the same filters as the page, including the pilot's current airport when only flights from the current airport are allowed.

The body is the same JSON array of flights as before, and the headers are sent to every client; a client that ignores them gets the first page. On `flights/search/arrivals`, `perArrivalLimit` turns paging off: the answer keeps at most that many flights per arrival airport, in the order above, carries none of the three headers and ignores `cursor`.

## Routes for a search

`GET /flights/destinations?routes=1` (or `routes=true`; any other value is the departure form) lists the routes of a search instead of the arrivals of one departure (capability `destination-routes`). It takes `flights/search`'s filters: `departureAirport`, `arrivalAirport`, `minimumDistance`, `maximumDistance`, `minimumFlightTime`, `maximumFlightTime` (hours), `callsign` and `aircraft`, and ignores `departure`, `limit`, `cursor` and `flightTypes`.

The answer is a JSON array with one item per distinct departure and arrival airport pair among the matching flights: `departure` and `arrival` (the airport IDs stored on the flights, the ICAO codes in phpVMS 7), `flights` (the number of matching flights), `distance` (the shortest `distance` among them, in nautical miles), and `minimumFlightTime` and `maximumFlightTime` (the shortest and longest flight time, in hours, rounded to 2 decimals). Routes are directed: A to B and B to A are two items.

The order is `flights/search`'s: route distance, shortest first, then departure, then arrival. At most 2000 items are sent; `X-SmartCARS-Total-Routes` carries the number of every matching pair, counted as `flights/search` counts it.

It differs from the departure form in two cases, because it follows `flights/search` exactly: a `departureAirport` the community does not know drops the filter (the answer covers every departure) instead of answering `[]`, and while `pilots.only_flights_from_current` is on, the pilot's current airport is the departure whatever `departureAirport` says, instead of answering `[]` for another airport.

## Auto-update

The module can update itself whenever a new release of it is published on GitHub. The updater is off by default and does nothing until it is switched on.

Settings (phpVMS Settings page, smartCARS group):

- `Auto-update`: turns the updater on. While it is off, the nudge endpoint answers without doing anything and nothing is downloaded, written or cleared.
- `Auto-update installed commit` and `Auto-update last result`: written by the updater (the commit installed by the last successful update, and the time, commit and outcome of the last attempt).

There is nothing else to configure: no secret, and no GitHub webhook on your side.

The smartCARS backend sends the nudge: a `POST <your site>/api/smartcars/auto-update` when a release of the module is published on GitHub, and once a day as a catch-up (for a site that was down during a release, or whose capability listing was still cached as off). The body is `{"reason": "release" | "daily", "tag": "<tag>" | null}` and never decides what is installed. Two headers sign it:

- `X-SmartCARS-Timestamp`: Unix time in seconds.
- `X-SmartCARS-Signature`: the base64 RSA PKCS#1 v1.5 SHA-256 signature over `<timestamp>.<raw body>`, verified against the public keys bundled in the module (`Support/NudgeKeys.php`). The same keys verify flight analyses, which sign `smartcars-analysis-v1.<payload>`, so neither signature passes for the other.

The answers:

- `200`: auto-update is disabled, nothing done.
- `401`: the timestamp is invalid or more than 5 minutes from the server's clock, or the signature is invalid.
- `429`: a nudge was accepted less than an hour ago. The hour is counted only from a nudge whose signature passed, and the daily catch-up covers a release published within it.
- `202`: the update job is queued.

The update job asks GitHub itself (`api.github.com`) for the latest published release of `invernyx/smartcars-3-phpvms7-api` (drafts and prereleases excluded) and downloads that release's commit from `codeload.github.com`, never anything named by the request, so a replayed nudge cannot install an older release. "Installed" is still a commit. When GitHub reports the already installed release but the nudge names a different tag, the job waits a few seconds and asks once more before skipping, since GitHub's API can answer from a cache. The job:

1. skips when there is no published release, when that release's commit is already installed, and when that release was rolled back before (it is not retried until a different release is published or the marker file is deleted, see below); it runs one update at a time (a lock file under `storage/app/smartcars-auto-update/`), and a nudge arriving during an update queues one more run after it;
2. refuses when the web server user cannot write the module folder or `modules/`;
3. unpacks the archive under `storage/app/smartcars-auto-update/`, checks it is this module, checks every PHP file parses, writes the commit into `Support/InstalledCommit.php`, and refuses a release whose `module.json` version is lower than the installed one;
4. moves the current module to a backup there (never under `modules/`, where phpVMS would load it as a second module) and moves the new one in;
5. runs `module:migrate SmartCARS3phpVMS7Api --force`, then clears the application, route, config and view caches and OPcache;
6. requests `GET /api/smartcars` under the site's `APP_URL`; unless it answers 200 with `handler` `phpvms7` and the new `commit` (read from `Support/InstalledCommit.php`, so cached old code reports the old commit), it puts the backup back, clears the caches again and remembers the release as rolled back.

A failure before the swap (GitHub unreachable, a failed download, an unwritable folder, an older version) is retried at the next nudge.

When a rolled-back release is skipped, the last result keeps the reason of the rollback in parentheses. To retry a release rolled back for a reason on your side (an unreachable `APP_URL`, a busy PHP-FPM), fix the cause, then delete `storage/app/smartcars-auto-update/failed-commit`: the next nudge tries the release again.

Requirements and limits:

- The phpVMS cron must run (it runs queued jobs every minute, with `queue:work`'s 60 s timeout; the job keeps its network calls well under it). With the default `sync` queue, the update runs right after the nudge's response, inside that PHP-FPM worker, and the health check needs a second free worker (`pm.max_children` of at least 2); PHP-FPM's `request_terminate_timeout` must allow about a minute.
- `APP_URL` must be reachable from the server itself, or every update rolls back with the reason in the last result.
- Migrations only add tables, columns or settings (or remove a setting): a rollback restores the old code but never runs a migration's `down`.
- With `opcache.validate_timestamps=0`, the web server keeps serving the old code until PHP-FPM restarts; the health check then sees the old commit, rolls back and says so.
- `GET /` lists `auto-update` through phpVMS's cached `setting()`, so the listing can lag the switch until the settings cache expires; the nudge endpoint and the job read the switch uncached.
- The module cannot depend on Composer packages beyond what phpVMS ships, since the updater cannot run Composer.
- The first install of a version with the updater is a manual copy, like any other module update.
