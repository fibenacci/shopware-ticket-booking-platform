import 'package:booking_scanner/config/server_config.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('normalizeBaseUrl', () {
    test('keeps scheme, host and port, drops path and trailing slash', () {
      expect(normalizeBaseUrl('https://shop.example.com/'), 'https://shop.example.com');
      expect(normalizeBaseUrl('  https://shop.example.com/admin '), 'https://shop.example.com');
      expect(normalizeBaseUrl('https://shop.example.com:8443'), 'https://shop.example.com:8443');
    });

    test('rejects non-https, missing host and garbage (no silent default)', () {
      expect(normalizeBaseUrl('http://shop.example.com'), isNull);
      expect(normalizeBaseUrl('shop.example.com'), isNull);
      expect(normalizeBaseUrl('https://'), isNull);
      expect(normalizeBaseUrl(''), isNull);
    });
  });
}
