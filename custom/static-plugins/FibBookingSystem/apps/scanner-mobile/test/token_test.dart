import 'dart:convert';

import 'package:booking_scanner/api/token.dart';
import 'package:flutter_test/flutter_test.dart';

/// Ports `apps/scanner/src/api.test.js` — the mobile client must accept and
/// reject exactly what the web client does, so both speak the identical
/// contract.
void main() {
  const validStatic = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
  const validRotating = 'FIBR1:T1001:0123456789abcdef';

  group('isLikelyScanToken', () {
    test('accepts a 64-char lowercase hex token', () {
      expect(isLikelyScanToken(validStatic), isTrue);
    });

    test('accepts a rotating wire string', () {
      expect(isLikelyScanToken(validRotating), isTrue);
    });

    test('rejects wrong length, uppercase, null and rubbish', () {
      expect(isLikelyScanToken('abc'), isFalse);
      expect(isLikelyScanToken(validStatic.toUpperCase()), isFalse);
      expect(isLikelyScanToken(null), isFalse);
      expect(isLikelyScanToken('FIBR1:T1001:NOTHEX0000000000'), isFalse);
      expect(isLikelyScanToken('FIBR1::0123456789abcdef'), isFalse);
    });
  });

  group('extractScanToken', () {
    test('returns a bare static token unchanged', () {
      expect(extractScanToken('  $validStatic  '), validStatic);
    });

    test('returns a rotating wire string unchanged', () {
      expect(extractScanToken(validRotating), validRotating);
    });

    test('extracts the token from the canonical JSON payload', () {
      final payload = jsonEncode({
        'type': 'fib_booking_ticket',
        'ticketNumber': 'T1001',
        'scanToken': validStatic,
      });
      expect(extractScanToken(payload), validStatic);
    });

    test('rejects JSON with the wrong type or a malformed token', () {
      expect(
        extractScanToken(jsonEncode({'type': 'other', 'scanToken': validStatic})),
        isNull,
      );
      expect(
        extractScanToken(jsonEncode({'type': 'fib_booking_ticket', 'scanToken': 'nope'})),
        isNull,
      );
    });

    test('rejects non-JSON garbage and oversized input', () {
      expect(extractScanToken('not a token'), isNull);
      expect(extractScanToken('x' * 4097), isNull);
      expect(extractScanToken(null), isNull);
    });
  });
}
