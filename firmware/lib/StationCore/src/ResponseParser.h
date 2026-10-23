/**
 * ResponseParser.h — maps an HTTP status + JSON body from B2B-Core into a
 * typed TapResult / PairResult. Locale-INDEPENDENT by design: it decides on
 * the HTTP status code and the "status" field, never on message text (the
 * backend localizes messages via Accept-Language, so text is display-only).
 * ResponseParser.h — mapea un código HTTP + cuerpo JSON de B2B-Core a un
 * TapResult / PairResult tipado. Independiente del idioma por diseño: decide
 * por código HTTP y campo "status", nunca por texto (el backend localiza los
 * mensajes, así que el texto es solo para mostrar).
 */
#pragma once

#include <string>

#include "CoreTypes.h"

namespace EcoStation {

/** Parse a POST /api/v1/events/tap response. */
TapResult parseTapResponse(int httpStatus, const std::string& body);

/** Parse a POST /api/v1/admin/cards/pair response. */
PairResult parsePairResponse(int httpStatus, const std::string& body);

/** capture_id of a POST /api/v1/recycling/capture response; -1 when absent,
 *  non-positive or the body is not JSON. */
long parseCaptureId(const std::string& body);

}  // namespace EcoStation
