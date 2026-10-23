/**
 * main.cpp — ESP32-CAM STATION entry point (env `esp32cam`).
 *
 * The station (camera + RC522 + presence + capture + feedback + network
 * as ONE device) lives in station.h/.cpp; this file only constructs it
 * and delegates setup()/loop(). No business logic here.
 */
#include <Arduino.h>

#include "station.h"

static EcoStation::Station station;

void setup() {
    station.begin();
}

void loop() {
    station.update();
    delay(1);
}
