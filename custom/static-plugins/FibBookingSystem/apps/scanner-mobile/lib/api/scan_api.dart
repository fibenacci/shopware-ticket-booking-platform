import 'dart:convert';

import 'package:http/http.dart' as http;

import 'session.dart';
import 'token.dart';

/// A scan/auth error carrying the same intent flags the web client uses, so
/// the UI can branch (force re-login, hide a forbidden panel) instead of
/// string-matching messages.
class ScanException implements Exception {
  ScanException(this.message, {this.requiresLogin = false, this.forbidden = false});

  final String message;
  final bool requiresLogin;
  final bool forbidden;

  @override
  String toString() => message;
}

typedef NowFn = int Function();

/// Admin-API client — the Dart port of `apps/scanner/src/api.js`. Same strict
/// posture: tokens in memory only, one-shot refresh on 401, scan token
/// validated client-side before it is ever sent. Unlike the web app (which is
/// same-origin) this targets a configured [baseUrl].
class ScanApi {
  ScanApi({
    required this.baseUrl,
    http.Client? client,
    Session? session,
    NowFn? now,
  })  : _client = client ?? http.Client(),
        _session = session ?? Session(),
        _now = now ?? (() => DateTime.now().millisecondsSinceEpoch);

  /// Scheme+host(+port) of the Shopware instance, e.g. `https://shop.example.com`.
  final String baseUrl;
  final http.Client _client;
  final Session _session;
  final NowFn _now;

  bool get isAuthenticated => _session.isAuthenticated(_now());

  void logout() => _session.logout();

  Uri _uri(String path) => Uri.parse('$baseUrl$path');

  Future<void> login(String username, String password) async {
    final response = await _client.post(
      _uri('/api/oauth/token'),
      headers: const {'Content-Type': 'application/json'},
      body: jsonEncode({
        'grant_type': 'password',
        'client_id': 'administration',
        'scopes': 'write',
        'username': username,
        'password': password,
      }),
    );

    if (response.statusCode != 200) {
      _session.logout();
      throw ScanException(
        response.statusCode == 400 || response.statusCode == 401
            ? 'Invalid credentials.'
            : 'Login failed (${response.statusCode}).',
      );
    }

    _session.store(_decode(response.body), _now());
  }

  Future<bool> _refreshSession() async {
    final token = _session.refreshToken;
    if (token == null) {
      return false;
    }

    final response = await _client.post(
      _uri('/api/oauth/token'),
      headers: const {'Content-Type': 'application/json'},
      body: jsonEncode({
        'grant_type': 'refresh_token',
        'client_id': 'administration',
        'refresh_token': token,
      }),
    );

    if (response.statusCode != 200) {
      _session.logout();
      return false;
    }

    _session.store(_decode(response.body), _now());
    return true;
  }

  Future<Map<String, dynamic>> scanTicket(
    String scanToken, {
    String direction = directionCheckIn,
  }) async {
    if (!isLikelyScanToken(scanToken)) {
      throw ScanException('Malformed scan token.');
    }
    if (direction != directionCheckIn && direction != directionCheckOut) {
      throw ScanException('Malformed scan direction.');
    }

    final response = await _authorizedRequest(
      'POST',
      '/api/_action/fib-booking/ticket/scan',
      body: {'scanToken': scanToken, 'direction': direction},
    );

    if (response.statusCode == 403) {
      throw ScanException('Missing permission to scan tickets (fib_booking.ticket_scan).');
    }
    if (response.statusCode != 200) {
      throw ScanException('Scan failed (${response.statusCode}).');
    }

    return _decode(response.body);
  }

  /// Feature flags for the UI (e.g. whether check-out mode is enabled). Falls
  /// back to safe defaults when the endpoint is unavailable.
  Future<Map<String, dynamic>> fetchScannerConfig() async {
    final response = await _authorizedRequest('GET', '/api/_action/fib-booking/scanner/config');

    if (response.statusCode != 200) {
      return {'checkOutEnabled': false};
    }

    return _decode(response.body);
  }

  /// Operator statistics. Requires the `fib_booking.statistics` ACL — a 403
  /// surfaces as [ScanException.forbidden] so the dashboard hides itself.
  Future<Map<String, dynamic>> fetchStatistics() async {
    final response = await _authorizedRequest('GET', '/api/_action/fib-booking/statistics');

    if (response.statusCode == 403) {
      throw ScanException(
        'Missing permission to view statistics (fib_booking.statistics).',
        forbidden: true,
      );
    }
    if (response.statusCode != 200) {
      throw ScanException('Loading statistics failed (${response.statusCode}).');
    }

    return _decode(response.body);
  }

  /// Sends an authenticated request; on 401 it tries ONE token refresh and
  /// replays the request. A second 401 ends the session.
  Future<http.Response> _authorizedRequest(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    var response = await _bearerFetch(method, path, body: body);

    if (response.statusCode == 401 && await _refreshSession()) {
      response = await _bearerFetch(method, path, body: body);
    }

    if (response.statusCode == 401) {
      _session.logout();
      throw ScanException('Session expired — please log in again.', requiresLogin: true);
    }

    return response;
  }

  Future<http.Response> _bearerFetch(String method, String path, {Map<String, dynamic>? body}) {
    final headers = {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ${_session.accessToken}',
    };
    final uri = _uri(path);

    if (method == 'GET') {
      return _client.get(uri, headers: headers);
    }

    return _client.post(uri, headers: headers, body: body == null ? null : jsonEncode(body));
  }

  Map<String, dynamic> _decode(String body) => jsonDecode(body) as Map<String, dynamic>;
}
