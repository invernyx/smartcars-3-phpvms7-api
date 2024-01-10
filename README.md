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
* Charter/Free Flights do not work, unless you're using a module to create the flights.
* The following phpVMS settings are ignored
  * Bids
    * Disable Bid On Flight
    * Restrict Aircraft
  * PIREPs
    * Restrict Aircraft To Ranks
    * Restrict Aircraft To Type Ratings
    * Restrict Aircraft At Departure
  * Pilots
    * Flights from Current
    * Restrict the flights to company
