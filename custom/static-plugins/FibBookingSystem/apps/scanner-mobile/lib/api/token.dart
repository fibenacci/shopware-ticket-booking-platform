import 'dart:convert';

/// Pure scan-token logic — the Dart twin of `apps/scanner/src/api.js`. No
/// Flutter or HTTP dependency, so it is exhaustively unit-testable and the
/// existing JS test cases port across 1:1.

/// Static scan token: 64 lowercase hex chars.
final RegExp _staticTokenPattern = RegExp(r'^[0-9a-f]{64}$');

/// Rotating QR (TOTP) wire format: `FIBR1:<ticketNumber>:<code>`. The scanner
/// forwards it verbatim; the server routes static vs rotating by this shape.
final RegExp _rotatingPattern = RegExp(r'^FIBR1:[A-Za-z0-9-]{1,40}:[0-9a-f]{16}$');

const String directionCheckIn = 'check_in';
const String directionCheckOut = 'check_out';

/// A value is forwardable when it is either a bare static token or a rotating
/// wire string. Validated before anything is ever sent.
bool isLikelyScanToken(String? value) {
  if (value == null) {
    return false;
  }
  return _staticTokenPattern.hasMatch(value) || _rotatingPattern.hasMatch(value);
}

/// Extracts the scan value from raw QR content. Accepts:
/// - the canonical static JSON payload (`{type, ticketNumber, scanToken}`),
/// - a bare static token (64 hex),
/// - a rotating wire string (`FIBR1:<ticketNumber>:<code>`).
///
/// The value is forwarded to the server unchanged; the server decides static
/// vs rotating. Returns `null` when nothing valid is present.
String? extractScanToken(String? rawContent) {
  if (rawContent == null || rawContent.length > 4096) {
    return null;
  }

  final trimmed = rawContent.trim();

  if (isLikelyScanToken(trimmed)) {
    return trimmed;
  }

  try {
    final parsed = jsonDecode(trimmed);
    if (parsed is Map &&
        parsed['type'] == 'fib_booking_ticket' &&
        parsed['scanToken'] is String &&
        isLikelyScanToken(parsed['scanToken'] as String)) {
      return parsed['scanToken'] as String;
    }
  } catch (_) {
    // not JSON — fall through
  }

  return null;
}
