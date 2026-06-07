import 'package:booking_scanner/api/session.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('Session', () {
    test('is unauthenticated before any login', () {
      final session = Session();
      expect(session.isAuthenticated(0), isFalse);
    });

    test('stores tokens with a 30s safety margin on expiry', () {
      final session = Session();
      session.store({'access_token': 'a', 'refresh_token': 'r', 'expires_in': 600}, 1000);

      expect(session.accessToken, 'a');
      expect(session.refreshToken, 'r');
      // (600 - 30) * 1000 + nowMs
      expect(session.expiresAtMs, 1000 + 570 * 1000);
      expect(session.isAuthenticated(1000), isTrue);
      expect(session.isAuthenticated(session.expiresAtMs), isFalse);
    });

    test('keeps the existing refresh token when a response omits one', () {
      final session = Session()
        ..store({'access_token': 'a1', 'refresh_token': 'r1', 'expires_in': 600}, 0);
      session.store({'access_token': 'a2', 'expires_in': 600}, 0);

      expect(session.accessToken, 'a2');
      expect(session.refreshToken, 'r1');
    });

    test('clamps a non-positive lifetime to immediate expiry', () {
      final session = Session()..store({'access_token': 'a', 'expires_in': 10}, 5000);
      // 10 - 30 < 0 → margin clamped to 0 → expires at now
      expect(session.expiresAtMs, 5000);
      expect(session.isAuthenticated(5000), isFalse);
    });

    test('logout clears everything', () {
      final session = Session()
        ..store({'access_token': 'a', 'refresh_token': 'r', 'expires_in': 600}, 0);
      session.logout();

      expect(session.accessToken, isNull);
      expect(session.refreshToken, isNull);
      expect(session.isAuthenticated(0), isFalse);
    });
  });
}
