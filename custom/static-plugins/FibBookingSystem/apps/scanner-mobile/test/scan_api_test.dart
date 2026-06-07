import 'dart:convert';

import 'package:booking_scanner/api/scan_api.dart';
import 'package:booking_scanner/api/token.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

const _validToken = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

ScanApi _api(MockClient client) => ScanApi(
      baseUrl: 'https://shop.example.com',
      client: client,
      now: () => 0,
    );

void main() {
  test('login stores the token and authenticates', () async {
    final api = _api(MockClient((request) async {
      expect(request.url.path, '/api/oauth/token');
      expect(jsonDecode(request.body)['grant_type'], 'password');
      return http.Response(
        jsonEncode({'access_token': 'a', 'refresh_token': 'r', 'expires_in': 600}),
        200,
      );
    }));

    await api.login('scanner', 'pw');
    expect(api.isAuthenticated, isTrue);
  });

  test('login maps 401 to an invalid-credentials message', () async {
    final api = _api(MockClient((request) async => http.Response('', 401)));
    expect(
      () => api.login('scanner', 'bad'),
      throwsA(isA<ScanException>().having((e) => e.message, 'message', 'Invalid credentials.')),
    );
  });

  test('scanTicket sends a bearer request and returns the verdict', () async {
    final api = _api(MockClient((request) async {
      if (request.url.path == '/api/oauth/token') {
        return http.Response(jsonEncode({'access_token': 'a', 'expires_in': 600}), 200);
      }
      expect(request.url.path, '/api/_action/fib-booking/ticket/scan');
      expect(request.headers['Authorization'], 'Bearer a');
      expect(jsonDecode(request.body)['direction'], directionCheckIn);
      return http.Response(jsonEncode({'result': 'admitted'}), 200);
    }));

    await api.login('scanner', 'pw');
    final verdict = await api.scanTicket(_validToken);
    expect(verdict['result'], 'admitted');
  });

  test('scanTicket rejects a malformed token before any request', () async {
    var calls = 0;
    final api = _api(MockClient((request) async {
      calls++;
      return http.Response('', 200);
    }));

    expect(() => api.scanTicket('nope'), throwsA(isA<ScanException>()));
    expect(calls, 0);
  });

  test('a 401 on scan triggers one refresh and replays', () async {
    var scanCalls = 0;
    var refreshed = false;
    final api = _api(MockClient((request) async {
      if (request.url.path == '/api/oauth/token') {
        final grant = jsonDecode(request.body)['grant_type'];
        if (grant == 'password') {
          return http.Response(jsonEncode({'access_token': 'old', 'refresh_token': 'r', 'expires_in': 600}), 200);
        }
        refreshed = true;
        return http.Response(jsonEncode({'access_token': 'new', 'expires_in': 600}), 200);
      }
      scanCalls++;
      // First scan is 401 (expired), replay after refresh succeeds.
      if (scanCalls == 1) {
        return http.Response('', 401);
      }
      expect(request.headers['Authorization'], 'Bearer new');
      return http.Response(jsonEncode({'result': 'admitted'}), 200);
    }));

    await api.login('scanner', 'pw');
    final verdict = await api.scanTicket(_validToken);

    expect(refreshed, isTrue);
    expect(scanCalls, 2);
    expect(verdict['result'], 'admitted');
  });

  test('a 403 on scan surfaces the ACL message', () async {
    final api = _api(MockClient((request) async {
      if (request.url.path == '/api/oauth/token') {
        return http.Response(jsonEncode({'access_token': 'a', 'expires_in': 600}), 200);
      }
      return http.Response('', 403);
    }));

    await api.login('scanner', 'pw');
    expect(
      () => api.scanTicket(_validToken),
      throwsA(isA<ScanException>().having((e) => e.message, 'message', contains('fib_booking.ticket_scan'))),
    );
  });
}
