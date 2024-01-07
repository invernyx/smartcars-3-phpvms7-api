<h1 align="center">smartCARS 3 phpVMS 7 Module</h1>
<div align="center">
    <i>Web Script Files for smartCARS 3</i>
</div>

## Introduction
smartCARS 3 is a web-based flight tracking system for virtual airlines. It is a complete rewrite of the original smartCARS
system, and is designed to be more flexible and easier to use.

This repository contains a phpVMS 7 Module implementation of the API.
## Requirements
- phpVMS 7 Beta 5 or Later

## Installation
**This Module is designed to work with phpVMS 7 only. If you desire phpVMS 5, see 
[invernyx/smartcars-3-public-api](https://github.com/invernyx/smartcars-3-public-api).**

### Step 1
Download the latest release from the [releases page](https://github.com/invernyx/smartcars-3-public-api/releases).

### Step 2
Move this folder into your `modules` folder, and verify that the folder is named `SmartCARS3phpVMS7Api`.

### Step 3
Using the Admin Interface, go to Modules and Enable the module.

### Step 4
Finally, enter the following url for your community in smartCARS Central:
```text
https://yourdomainhere.com/api/smartcars/
```

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
