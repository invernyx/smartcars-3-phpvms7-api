<h1 align="center">smartCARS 3 phpVMS 7 API</h1>
<div align="center">
    <i>Web Script Files for smartCARS 3</i>
</div>

## Introduction
smartCARS 3 is a web-based flight tracking system for virtual airlines. It is a complete rewrite of the original smartCARS system, and is designed to be more flexible and easier to use. This repository contains the web script files for smartCARS 3 in phpVMS 7.

## Requirements
- phpVMS 7 Beta 5 or Later
- PHP 8.0 or higher
- A webserver that accepts the Authorization header (Apache, nginx, etc.)
    - If you are using a shared hosting provider, you may need to contact them to enable this feature.

## Installation
The smartCARS 3 phpVMS 7 API is a drag and drop module. It does not require any configuration. We support phpVMS 7 in this repository.

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

This URL will be the "Script URL" option in smartCARS 3 Central when managing your community.

## Limitations

* Schedule Search Only Supports Departure and Arrival Airport
* v7 Flight Type codes are simplified to v5 flight type codes in the UI. This will not affect flight logging.
* The following phpVMS settings are ignored
  * Bids
    * Disable Flight On Bid
  * PIREPs
    * Restrict the flights to company

## Charter Flights

Unlike phpVMS 5, Charter Flights behave differently in phpVMS 7.

For the callsign field, if just a number is supplied, your flight will be assigned automatically to the first airline in your system, or a airline ID you define in your .env file by using the `SC3_CHARTER_AIRLINE_ID` variable (e.g. `SC3_CHARTER_AIRLINE_ID=1` for airline id 1).

If you supply a ICAO or IATA code with the flight number (e.g. `DAL1421` or `DL1421`, the API will search to see if that code exists in the system. If it finds it, the flight will be flown under that code and flight number. If it cannot find it, it'll fallback to the first airline in your system or what's set in the env variable.

Only numeric flight numbers are supported. For example, `BAW47C` will turn into `BAW0` when you see the bid.

If your community has the Bids > Restrict Aircraft setting enabled, the aircraft selected will be attached to the bid. However, if the setting is not enabled, the subfleet for the same aircraft family will be attached to the flight for manual selection before beginning the flight.

## Pirep Distance Recalculation

Included with this API is a job that'll recalculate PIREP distances for all smartCARS pireps, based on the ACARS telemetry logs on the server.

To execute this, navigate directly to `/admin/smartcars/recalc` in your web browser (e.g. `https://myva.com/admin/smartcars/recalc`) to start the recalculation job.

If you have a private discord notification channel setup, you will receive progress updates regarding the status of the job as it executes on the backend.